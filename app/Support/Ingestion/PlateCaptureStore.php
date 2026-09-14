<?php

namespace App\Support\Ingestion;

use App\Jobs\ProcessHikvisionWebhook;
use App\Models\PlateEvent;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Locate the day-old plate/vehicle JPEGs stored next to a plate event.
 *
 * Files live at plate-captures/{camera}/{Y}/{m}/{d}/{eventId}-{index}.{ext}.
 * The folder date is the process day, which is captured_at in almost every
 * case; we also peek at the neighbouring calendar day so a capture that
 * crossed midnight still resolves.
 */
class PlateCaptureStore
{
    /**
     * @return list<string>
     */
    public function pathsFor(PlateEvent $event): array
    {
        $disk = Storage::disk('local');
        $prefix = $event->getKey().'-';
        $found = [];

        foreach ($this->dayFolders($event) as $directory) {
            if (! $disk->exists($directory)) {
                continue;
            }

            // A day-folder written by a queue worker running under the wrong
            // uid can be unreadable to PHP-FPM (root:root 0700 was the
            // symptom that took down the Activity plate view). Skip that
            // folder rather than 500ing the whole component — the plate row
            // still renders, we just cannot show its captures.
            try {
                $files = $disk->files($directory);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            foreach ($files as $path) {
                if (str_starts_with(basename($path), $prefix)) {
                    $found[] = $path;
                }
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    public function pathFor(PlateEvent $event, int $index): ?string
    {
        return $this->pathsFor($event)[$index] ?? null;
    }

    /**
     * @return list<string>
     */
    protected function dayFolders(PlateEvent $event): array
    {
        $root = ProcessHikvisionWebhook::CAPTURES_DIR.'/'.$event->camera_id;
        $captured = $event->captured_at;

        return array_values(array_unique([
            $root.'/'.$captured->format('Y/m/d'),
            $root.'/'.$captured->copy()->addDay()->format('Y/m/d'),
            $root.'/'.$captured->copy()->subDay()->format('Y/m/d'),
        ]));
    }
}
