<?php

namespace App\Jobs;

use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * POPIA retention: plate numbers are personal data, so events and visits are
 * deleted once they pass the site's retention window.
 *
 * Deletion is genuine, not anonymisation, because a plate is the whole record.
 * Aggregate counts already computed for reports are unaffected.
 */
class PrunePlateData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    /**
     * Delete in batches so a long-neglected site does not lock the tables.
     */
    protected const BATCH = 2000;

    public function __construct(public ?int $siteId = null) {}

    public function uniqueId(): string
    {
        return (string) ($this->siteId ?? 'all');
    }

    public function handle(): void
    {
        foreach ($this->sites() as $site) {
            $cutoff = now()->subDays($this->retentionDays($site));

            $visits = $this->pruneVisits($site, $cutoff);
            $events = $this->pruneEvents($site, $cutoff);
            $captures = $this->pruneCaptures($site, $cutoff);

            if ($visits === 0 && $events === 0 && $captures === 0) {
                continue;
            }

            Log::info('Pruned plate data past retention', [
                'site_id' => $site->getKey(),
                'cutoff' => $cutoff->toDateString(),
                'visits_deleted' => $visits,
                'events_deleted' => $events,
                'capture_files_deleted' => $captures,
            ]);
        }
    }

    /**
     * @return iterable<Site>
     */
    protected function sites(): iterable
    {
        return Site::query()
            ->withoutGlobalScope(SiteScope::class)
            ->when($this->siteId !== null, fn ($query) => $query->whereKey($this->siteId))
            ->cursor();
    }

    /**
     * Sites may shorten retention but not extend it past the platform ceiling.
     */
    protected function retentionDays(Site $site): int
    {
        return max(
            (int) config('trafficflow.retention_min_days'),
            min($site->retentionDays(), (int) config('trafficflow.retention_max_days')),
        );
    }

    protected function pruneVisits(Site $site, CarbonInterface $cutoff): int
    {
        $deleted = 0;

        do {
            $batch = Visit::query()
                ->withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->getKey())
                ->where('entered_at', '<', $cutoff)
                ->limit(self::BATCH)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += Visit::query()
                ->withoutGlobalScope(SiteScope::class)
                ->whereIn('id', $batch)
                ->delete();
        } while ($batch->count() === self::BATCH);

        return $deleted;
    }

    protected function pruneEvents(Site $site, CarbonInterface $cutoff): int
    {
        $cameraIds = Camera::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->pluck('id');

        if ($cameraIds->isEmpty()) {
            return 0;
        }

        $deleted = 0;

        do {
            $batch = PlateEvent::query()
                ->withoutGlobalScope(SiteScope::class)
                ->whereIn('camera_id', $cameraIds)
                ->where('captured_at', '<', $cutoff)
                ->limit(self::BATCH)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += PlateEvent::query()
                ->withoutGlobalScope(SiteScope::class)
                ->whereIn('id', $batch)
                ->delete();
        } while ($batch->count() === self::BATCH);

        return $deleted;
    }

    /**
     * Capture JPEGs live under plate-captures/{camera}/{Y}/{m}/{d}/ so a
     * day folder older than the cutoff can be removed without walking
     * every remaining event. The date in the path is the process day,
     * which tracks captured_at closely enough for retention.
     */
    protected function pruneCaptures(Site $site, CarbonInterface $cutoff): int
    {
        $cameraIds = Camera::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->pluck('id');

        if ($cameraIds->isEmpty()) {
            return 0;
        }

        $disk = Storage::disk('local');
        $cutoffDate = $cutoff->toDateString();
        $deleted = 0;

        foreach ($cameraIds as $cameraId) {
            $root = ProcessHikvisionWebhook::CAPTURES_DIR.'/'.$cameraId;

            if (! $disk->exists($root)) {
                continue;
            }

            foreach ($this->datedCaptureDirectories($disk, $root) as $directory => $date) {
                if ($date >= $cutoffDate) {
                    continue;
                }

                $deleted += count($disk->allFiles($directory));
                $disk->deleteDirectory($directory);
            }
        }

        return $deleted;
    }

    /**
     * @return array<string, string> directory path => Y-m-d
     */
    protected function datedCaptureDirectories(Filesystem $disk, string $root): array
    {
        $dated = [];

        foreach ($disk->directories($root) as $yearDir) {
            foreach ($disk->directories($yearDir) as $monthDir) {
                foreach ($disk->directories($monthDir) as $dayDir) {
                    $parts = explode('/', str_replace('\\', '/', $dayDir));
                    $day = $parts[array_key_last($parts)] ?? null;
                    $month = $parts[array_key_last($parts) - 1] ?? null;
                    $year = $parts[array_key_last($parts) - 2] ?? null;

                    if ($year === null || $month === null || $day === null) {
                        continue;
                    }

                    if (! preg_match('/^\d{4}$/', $year) || ! preg_match('/^\d{2}$/', $month) || ! preg_match('/^\d{2}$/', $day)) {
                        continue;
                    }

                    $dated[$dayDir] = $year.'-'.$month.'-'.$day;
                }
            }
        }

        return $dated;
    }
}
