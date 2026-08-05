<?php

namespace App\Console\Commands;

use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Report everything a camera sent us on a given day.
 *
 * There are three places a Hikvision notification can land, and they tell
 * different stories:
 *
 * - `plate_events` — the POST was received, parsed, and became a plate
 *   detection. This is what shows on the dashboard.
 * - `hikvision-webhook-inbox/{id}/*` — the raw body is on disk and a queue
 *   job is either about to run, is running, or has just crashed before it
 *   could clean up. A permanent inbox backlog means the queue worker is
 *   stuck.
 * - `hikvision-webhook-quarantine/{id}/*` — the parser tried and gave up.
 *   Usually a malformed multipart or a heartbeat that we could not classify.
 *
 * The command surfaces all three per camera so you can tell at a glance
 * whether the camera is quiet (no events, no files), silently broken
 * (files in quarantine, no events), or the pipeline itself is stuck
 * (files in inbox older than a few seconds).
 */
class CameraActivity extends Command
{
    protected $signature = 'camera:activity
        {--day= : Day to inspect (YYYY-MM-DD). Defaults to today.}
        {--camera= : Only report on one camera id.}
        {--all : Show every camera, even ones with zero activity today.}';

    protected $description = 'Show received Hikvision notifications per camera for a given day';

    public function handle(): int
    {
        $day = $this->option('day') !== null
            ? Carbon::parse((string) $this->option('day'))
            : Carbon::today();

        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        $cameraId = $this->option('camera') !== null ? (int) $this->option('camera') : null;

        $cameras = Camera::query()
            ->withoutGlobalScope(SiteScope::class)
            ->with('site:id,name')
            ->when($cameraId !== null, fn ($q) => $q->whereKey($cameraId))
            ->orderBy('site_id')
            ->orderBy('name')
            ->get();

        if ($cameras->isEmpty()) {
            $this->components->error('No cameras found.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info(sprintf(
            'Camera activity for %s (%s → %s)',
            $day->toDateString(),
            $start->format('H:i'),
            $end->format('H:i'),
        ));

        $rows = [];
        $totals = ['events' => 0, 'inbox' => 0, 'quarantine' => 0];

        foreach ($cameras as $camera) {
            $eventCount = PlateEvent::query()
                ->withoutGlobalScope(SiteScope::class)
                ->where('camera_id', $camera->getKey())
                ->whereBetween('captured_at', [$start, $end])
                ->count();

            $firstEvent = PlateEvent::query()
                ->withoutGlobalScope(SiteScope::class)
                ->where('camera_id', $camera->getKey())
                ->whereBetween('captured_at', [$start, $end])
                ->min('captured_at');

            $lastEvent = PlateEvent::query()
                ->withoutGlobalScope(SiteScope::class)
                ->where('camera_id', $camera->getKey())
                ->whereBetween('captured_at', [$start, $end])
                ->max('captured_at');

            [$inboxCount, $inboxLastMtime] = $this->countFilesForDay(
                'hikvision-webhook-inbox/'.$camera->getKey(),
                $start,
                $end,
            );

            [$quarantineCount, $quarantineLastMtime] = $this->countFilesForDay(
                'hikvision-webhook-quarantine/'.$camera->getKey(),
                $start,
                $end,
            );

            $totals['events'] += $eventCount;
            $totals['inbox'] += $inboxCount;
            $totals['quarantine'] += $quarantineCount;

            $hasAnyActivity = $eventCount > 0 || $inboxCount > 0 || $quarantineCount > 0;

            if (! $hasAnyActivity && ! $this->option('all')) {
                continue;
            }

            $rows[] = [
                'camera' => $camera->name,
                'site' => $camera->site?->name ?? '—',
                'role' => $camera->role->value ?? '—',
                'events' => number_format($eventCount),
                'first' => $firstEvent instanceof \DateTimeInterface
                    ? Carbon::instance($firstEvent)->format('H:i:s')
                    : '—',
                'last' => $lastEvent instanceof \DateTimeInterface
                    ? Carbon::instance($lastEvent)->format('H:i:s')
                    : '—',
                'inbox' => $inboxCount > 0
                    ? $inboxCount.($inboxLastMtime ? ' ('.$inboxLastMtime->diffForHumans(short: true).')' : '')
                    : '0',
                'quarantine' => $quarantineCount > 0
                    ? $quarantineCount.($quarantineLastMtime ? ' ('.$quarantineLastMtime->diffForHumans(short: true).')' : '')
                    : '0',
                'last_seen' => $camera->webhook_last_seen_at
                    ? $camera->webhook_last_seen_at->diffForHumans(short: true)
                    : '—',
            ];
        }

        if ($rows === []) {
            $this->components->warn('No camera produced any activity on '.$day->toDateString().'. Use --all to list quiet cameras too.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Camera', 'Site', 'Role', 'Events', 'First', 'Last', 'Inbox', 'Quarantine', 'Last seen'],
            $rows,
        );

        $this->newLine();
        $this->components->info(sprintf(
            'Totals — parsed events: %s   stuck in inbox: %s   quarantined: %s',
            number_format($totals['events']),
            number_format($totals['inbox']),
            number_format($totals['quarantine']),
        ));

        if ($totals['inbox'] > 0) {
            $this->components->warn(
                'Inbox is not empty — the queue worker may be stuck. '.
                'Check `docker compose logs queue` and confirm the worker is running.',
            );
        }

        if ($totals['quarantine'] > 0) {
            $this->components->warn(
                'Quarantined payloads mean the parser could not read what the camera sent. '.
                'Inspect one with: docker compose exec app cat '.
                'storage/app/private/hikvision-webhook-quarantine/{camera_id}/{ulid}.bin',
            );
        }

        return self::SUCCESS;
    }

    /**
     * Count files in a storage directory whose filesystem modified time
     * falls inside the day window. Returns [count, lastModifiedAsCarbon].
     *
     * @return array{0: int, 1: ?Carbon}
     */
    protected function countFilesForDay(string $directory, Carbon $start, Carbon $end): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($directory)) {
            return [0, null];
        }

        $files = $disk->files($directory);
        $count = 0;
        $latest = null;

        foreach ($files as $path) {
            $mtime = Carbon::createFromTimestamp($disk->lastModified($path));

            if ($mtime->lt($start) || $mtime->gt($end)) {
                continue;
            }

            $count++;

            if ($latest === null || $mtime->gt($latest)) {
                $latest = $mtime;
            }
        }

        return [$count, $latest];
    }
}
