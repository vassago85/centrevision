<?php

namespace App\Jobs;

use App\Enums\PlateDirection;
use App\Enums\VisitStatus;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Visit;
use App\Support\Alerts\AlertEvaluator;
use App\Support\PlateNumber;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Pairs entry events with exit events to build visits, and retires visits that
 * never got an exit.
 *
 * Events are consumed in capture order and stamped with processed_at, so a
 * later run never reconsiders them — which matters because an unmatched exit
 * event would otherwise be replayed on every pass.
 */
class MatchVisits implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    /**
     * Two entrance reads of the same plate closer together than this are
     * treated as one drive-through past multiple cameras (multi-camera
     * de-duplication). Anything further apart is a genuine re-arrival and
     * gets its own visit record.
     */
    protected const REENTRY_DEDUP_SECONDS = 120;

    public function __construct(public ?int $siteId = null) {}

    public function uniqueId(): string
    {
        return (string) ($this->siteId ?? 'all');
    }

    public function handle(): void
    {
        // A capture can land while this pass is already reading. Loop until
        // a pass finds nothing new so that event is not stuck until the
        // scheduled backstop — that lag is what made Latest activity show
        // an exit as still on site, and an entry as not.
        do {
            $processedAny = false;

            foreach ($this->sites() as $site) {
                $processedAny = $this->matchSite($site) || $processedAny;
            }
        } while ($processedAny && $this->hasUnprocessedEvents());

        foreach ($this->sites() as $site) {
            $this->orphanStaleVisits($site);
            app(AlertEvaluator::class)->evaluateDwellForSite($site);
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
     * @return bool True when at least one event was paired on this pass.
     */
    protected function matchSite(Site $site): bool
    {
        $cameraIds = Camera::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->pluck('id');

        if ($cameraIds->isEmpty()) {
            return false;
        }

        $processedAny = false;

        PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('camera_id', $cameraIds)
            ->whereNull('processed_at')
            ->whereNotNull('direction')
            ->orderBy('captured_at')
            ->orderBy('id')
            ->chunkById(500, function ($events) use ($site, &$processedAny): void {
                foreach ($events as $event) {
                    $applied = DB::transaction(function () use ($site, $event): bool {
                        // Another worker (the scheduler, or a pass kicked off
                        // by a different capture) may already have taken this
                        // row. Skip it rather than opening a second visit.
                        $locked = PlateEvent::query()
                            ->withoutGlobalScope(SiteScope::class)
                            ->whereKey($event->getKey())
                            ->whereNull('processed_at')
                            ->lockForUpdate()
                            ->first();

                        if ($locked === null) {
                            return false;
                        }

                        $this->apply($site, $locked);

                        return true;
                    });

                    $processedAny = $processedAny || $applied;
                }
            });

        return $processedAny;
    }

    /**
     * True when a capture with a direction is still waiting to be paired.
     * Scoped the same way as {@see matchSite()} so a direction-less event
     * cannot keep the pass spinning.
     */
    protected function hasUnprocessedEvents(): bool
    {
        $siteIds = Site::query()
            ->withoutGlobalScope(SiteScope::class)
            ->when($this->siteId !== null, fn ($query) => $query->whereKey($this->siteId))
            ->pluck('id');

        if ($siteIds->isEmpty()) {
            return false;
        }

        $cameraIds = Camera::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('site_id', $siteIds)
            ->pluck('id');

        if ($cameraIds->isEmpty()) {
            return false;
        }

        return PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('camera_id', $cameraIds)
            ->whereNull('processed_at')
            ->whereNotNull('direction')
            ->exists();
    }

    protected function apply(Site $site, PlateEvent $event): void
    {
        // "UNKNOWN" is the camera admitting OCR failed. It is not a vehicle,
        // and pairing those rows with each other invents visits.
        if (! PlateNumber::isUnknown($event->plate_number)) {
            $event->direction === PlateDirection::In
                ? $this->openVisit($site, $event)
                : $this->closeVisit($site, $event);
        }

        $event->forceFill(['processed_at' => now()])->saveQuietly();
    }

    /**
     * Turn an entry event into a visit.
     *
     * Two entrance reads seconds apart are the same drive-through past multiple
     * cameras and collapse to one visit. A re-arrival minutes or hours later is
     * its own visit: the previous open one is retired as `orphaned` (we never
     * saw an exit for it, so we cannot honestly close it), and a fresh visit
     * is opened so the latest arrival is always the top of the visits list.
     */
    protected function openVisit(Site $site, PlateEvent $event): void
    {
        $existing = $this->openVisitQuery($site, $event->plate_number)
            ->orderByDesc('entered_at')
            ->first();

        if ($existing === null) {
            $existing = $this->recentMisreadEntry($site, $event);
        }

        if ($existing !== null) {
            $secondsSincePreviousEntry = $existing->entered_at->diffInSeconds($event->captured_at);

            if ($secondsSincePreviousEntry <= self::REENTRY_DEDUP_SECONDS) {
                // Same drive-through, second camera — do not create a new visit.
                $this->markSuperseded($event, $existing->entry_event_id);

                return;
            }

            // A genuine re-arrival. Retire the previous open visit and create
            // a new one for the latest entry.
            $existing->forceFill([
                'status' => VisitStatus::Orphaned,
                'updated_at' => now(),
            ])->save();
        }

        $visit = Visit::query()->withoutGlobalScope(SiteScope::class)->create([
            'site_id' => $site->getKey(),
            'plate_number' => $event->plate_number,
            'entry_event_id' => $event->getKey(),
            'entered_at' => $event->captured_at,
            'status' => VisitStatus::Open,
        ]);

        app(AlertEvaluator::class)->evaluateWatchlistHit(
            $site,
            $event->plate_number,
            $visit->getKey(),
        );
    }

    /**
     * Close the vehicle's open visit. An exit with no matching entry is dropped
     * rather than guessed at: it is usually the tail of a visit that began
     * before the camera was installed.
     *
     * A one-character OCR miss still closes the visit, but only when a single
     * open plate is that close — two candidates means we cannot pick safely.
     * A second photo of a departure that just closed is marked superseded so
     * it does not show up as an orphan exit.
     */
    protected function closeVisit(Site $site, PlateEvent $event): void
    {
        $visit = $this->openVisitQuery($site, $event->plate_number)
            ->where('entered_at', '<=', $event->captured_at)
            ->orderByDesc('entered_at')
            ->first();

        if ($visit === null) {
            $visit = $this->fuzzyOpenVisit($site, $event);
        }

        if ($visit !== null) {
            $visit->forceFill([
                'exit_event_id' => $event->getKey(),
                'exited_at' => $event->captured_at,
                'dwell_minutes' => (int) round($visit->entered_at->diffInMinutes($event->captured_at)),
                'status' => VisitStatus::Closed,
            ])->save();

            return;
        }

        $this->markSuperseded($event, $this->repeatExitEventId($site, $event));
    }

    /**
     * The one open visit whose plate is a single OCR edit from this exit.
     */
    protected function fuzzyOpenVisit(Site $site, PlateEvent $event): ?Visit
    {
        if (! config('trafficflow.fuzzy_match_enabled')) {
            return null;
        }

        $matches = $this->openVisitQuery($site)
            ->where('entered_at', '<=', $event->captured_at)
            ->get()
            ->filter(fn (Visit $visit): bool => PlateNumber::isProbableMisread($event->plate_number, $visit->plate_number))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * An entrance read one edit away from a visit that opened in the
     * dedupe window is the same drive-through seen by a second camera.
     */
    protected function recentMisreadEntry(Site $site, PlateEvent $event): ?Visit
    {
        if (! config('trafficflow.fuzzy_match_enabled')) {
            return null;
        }

        $since = $event->captured_at->copy()->subSeconds(self::REENTRY_DEDUP_SECONDS);

        $matches = $this->openVisitQuery($site)
            ->where('entered_at', '>=', $since)
            ->where('entered_at', '<=', $event->captured_at)
            ->get()
            ->filter(fn (Visit $visit): bool => PlateNumber::isProbableMisread($event->plate_number, $visit->plate_number))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * The exit event that already closed this departure, when this read is
     * another photo of it. Exact plate matches across cameras for the same
     * window as a double entrance. A one-character miss only counts on the
     * same camera inside the short dedupe burst, so a different vehicle
     * leaving a minute later is not swallowed.
     */
    protected function repeatExitEventId(Site $site, PlateEvent $event): ?int
    {
        $recent = Visit::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->where('status', VisitStatus::Closed)
            ->whereNotNull('exit_event_id')
            ->whereBetween('exited_at', [
                $event->captured_at->copy()->subSeconds(self::REENTRY_DEDUP_SECONDS),
                $event->captured_at,
            ])
            ->orderByDesc('exited_at')
            ->get(['id', 'plate_number', 'exit_event_id', 'exited_at']);

        $exact = $recent->first(
            fn (Visit $visit): bool => $visit->plate_number === $event->plate_number,
        );

        if ($exact !== null) {
            return (int) $exact->exit_event_id;
        }

        if (! config('trafficflow.fuzzy_match_enabled')) {
            return null;
        }

        $burst = (int) config('trafficflow.dedupe_window_seconds');

        $candidates = $recent->filter(function (Visit $visit) use ($event, $burst): bool {
            return $visit->exited_at->diffInSeconds($event->captured_at) <= $burst
                && PlateNumber::isProbableMisread($event->plate_number, $visit->plate_number);
        })->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $sameCameraExitIds = PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $candidates->pluck('exit_event_id'))
            ->where('camera_id', $event->camera_id)
            ->pluck('id');

        $matched = $candidates
            ->filter(fn (Visit $visit): bool => $sameCameraExitIds->contains($visit->exit_event_id))
            ->values();

        if ($matched->count() !== 1) {
            return null;
        }

        return (int) $matched->first()->exit_event_id;
    }

    protected function markSuperseded(PlateEvent $event, ?int $keptEventId): void
    {
        if ($keptEventId === null || $keptEventId === $event->getKey()) {
            return;
        }

        $event->superseded_by_event_id = $keptEventId;
    }

    /**
     * A vehicle still marked as on site long past the site's threshold left
     * without being seen: mark it orphaned so it stops skewing live counts.
     *
     * Skipped for entry-only sites — with no exit camera there is never an
     * exit event to "close" a visit, so every open visit would eventually
     * age out and vanish from the dashboard. On those sites, an unclosed
     * visit is not an error; it is simply an arrival.
     */
    protected function orphanStaleVisits(Site $site): void
    {
        if (! $site->hasExitTracking()) {
            return;
        }

        $cutoff = now()->subHours($site->orphanAfterHours());

        $this->openVisitQuery($site)
            ->where('entered_at', '<', $cutoff)
            ->update([
                'status' => VisitStatus::Orphaned->value,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return Builder<Visit>
     */
    protected function openVisitQuery(Site $site, ?string $plate = null)
    {
        return Visit::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->where('status', VisitStatus::Open)
            ->when($plate !== null, fn ($query) => $query->where('plate_number', $plate));
    }
}
