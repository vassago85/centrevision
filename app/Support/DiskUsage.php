<?php

namespace App\Support;

use App\Jobs\ProcessHikvisionWebhook;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * How full the volume behind storage/app is, plus how much of it is
 * day-old plate JPEGs. Cheap enough for a dashboard poll: volume stats
 * are two syscalls, and the capture-folder walk is cached for a minute.
 */
class DiskUsage
{
    public function __construct(
        public readonly int $totalBytes,
        public readonly int $freeBytes,
        public readonly int $usedBytes,
        public readonly float $usedPercent,
        public readonly int $captureBytes,
    ) {}

    public static function snapshot(): self
    {
        $path = Storage::disk('local')->path('');

        if (! is_dir($path)) {
            $path = storage_path();
        }

        $total = disk_total_space($path);
        $free = disk_free_space($path);

        $totalBytes = is_numeric($total) ? (int) $total : 0;
        $freeBytes = is_numeric($free) ? (int) $free : 0;
        $usedBytes = max(0, $totalBytes - $freeBytes);
        $usedPercent = $totalBytes > 0
            ? round(($usedBytes / $totalBytes) * 100, 1)
            : 0.0;

        return new self(
            totalBytes: $totalBytes,
            freeBytes: $freeBytes,
            usedBytes: $usedBytes,
            usedPercent: $usedPercent,
            captureBytes: self::directoryBytes(ProcessHikvisionWebhook::CAPTURES_DIR),
        );
    }

    public function variant(): string
    {
        return match (true) {
            $this->usedPercent >= 90 => 'danger',
            $this->usedPercent >= 75 => 'warn',
            default => 'default',
        };
    }

    public function usedLabel(): string
    {
        return self::formatBytes($this->usedBytes);
    }

    public function freeLabel(): string
    {
        return self::formatBytes($this->freeBytes);
    }

    public function totalLabel(): string
    {
        return self::formatBytes($this->totalBytes);
    }

    public function captureLabel(): string
    {
        return self::formatBytes($this->captureBytes);
    }

    public function percentLabel(): string
    {
        return rtrim(rtrim(number_format($this->usedPercent, 1, '.', ''), '0'), '.').'%';
    }

    public static function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        if ($unit === 0) {
            return $bytes.' '.$units[$unit];
        }

        $decimals = $value >= 10 ? 1 : 2;
        $formatted = number_format($value, $decimals, '.', '');

        return rtrim(rtrim($formatted, '0'), '.').' '.$units[$unit];
    }

    protected static function directoryBytes(string $directory): int
    {
        $sum = fn (): int => self::sumDirectory(Storage::disk('local')->path($directory));

        if (app()->runningUnitTests()) {
            return $sum();
        }

        return (int) Cache::remember('disk-usage:'.$directory, 60, $sum);
    }

    protected static function sumDirectory(string $absolutePath): int
    {
        if (! is_dir($absolutePath) || ! is_readable($absolutePath)) {
            return 0;
        }

        $total = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $total += $file->getSize();
                }
            }
        } catch (\Throwable $e) {
            // A single unreadable subdirectory shouldn't take down the dashboard.
            // Report and return whatever we managed to sum before the failure.
            report($e);
        }

        return $total;
    }
}
