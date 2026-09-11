<?php

namespace App\Jobs;

use App\Http\Controllers\HikvisionWebhookController;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Drop stale webhook staging files so a busy or mis-linked camera cannot
 * fill the storage volume.
 *
 * Inbox files should vanish in seconds once the worker parses them.
 * Quarantine holds genuinely unparseable bodies for a short diagnosis
 * window. Anything older than the configured TTL is gone.
 */
class PruneWebhookStaging implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'webhook-staging';
    }

    public function handle(): void
    {
        $disk = Storage::disk('local');

        $inboxDeleted = $this->pruneOlderThan(
            $disk,
            HikvisionWebhookController::INBOX_DIR,
            now()->subHours((int) config('trafficflow.webhook_inbox_max_hours')),
        );

        $quarantineDeleted = $this->pruneOlderThan(
            $disk,
            ProcessHikvisionWebhook::QUARANTINE_DIR,
            now()->subDays((int) config('trafficflow.webhook_quarantine_days')),
        );

        if ($inboxDeleted === 0 && $quarantineDeleted === 0) {
            return;
        }

        Log::info('Pruned stale Hikvision webhook staging files', [
            'inbox_deleted' => $inboxDeleted,
            'quarantine_deleted' => $quarantineDeleted,
        ]);
    }

    protected function pruneOlderThan(Filesystem $disk, string $directory, DateTimeInterface $cutoff): int
    {
        if (! $disk->exists($directory)) {
            return 0;
        }

        $deleted = 0;
        $cutoffTimestamp = $cutoff->getTimestamp();

        foreach ($disk->allFiles($directory) as $path) {
            if ($disk->lastModified($path) >= $cutoffTimestamp) {
                continue;
            }

            $disk->delete($path);
            $deleted++;
        }

        return $deleted;
    }
}
