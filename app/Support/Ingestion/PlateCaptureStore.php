<?php

namespace App\Support\Ingestion;

use App\Jobs\ProcessHikvisionWebhook;
use App\Models\PlateEvent;
use Illuminate\Support\Facades\Storage;

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

            foreach ($disk->files($directory) as $path) {
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
