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
 * Drop stale webhook staging files and day-old camera JPEGs so a busy
 * site cannot fill the storage volume.
 *
 * Inbox files should vanish in seconds once the worker parses them.
 * Plate-capture JPEGs are kept for one day. Leftover quarantine from
 * earlier builds is wiped outright.
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

        $quarantineDeleted = $this->deleteDirectory(
            $disk,
            ProcessHikvisionWebhook::QUARANTINE_DIR,
        );

        $capturesDeleted = $this->pruneOlderThan(
            $disk,
            ProcessHikvisionWebhook::CAPTURES_DIR,
            now()->subHours((int) config('trafficflow.webhook_capture_hours')),
        );

        if ($inboxDeleted === 0 && $quarantineDeleted === 0 && $capturesDeleted === 0) {
            return;
        }

        Log::info('Pruned stale Hikvision webhook staging files', [
            'inbox_deleted' => $inboxDeleted,
            'quarantine_deleted' => $quarantineDeleted,
            'captures_deleted' => $capturesDeleted,
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

    protected function deleteDirectory(Filesystem $disk, string $directory): int
    {
        if (! $disk->exists($directory)) {
            return 0;
        }

        $deleted = count($disk->allFiles($directory));
        $disk->deleteDirectory($directory);

        return $deleted;
    }
}
