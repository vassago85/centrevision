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
        foreach ($this->sites() as $site) {
            $this->matchSite($site);
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

    protected function matchSite(Site $site): void
    {
        $cameraIds = Camera::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->pluck('id');

        if ($cameraIds->isEmpty()) {
            return;
        }

        PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('camera_id', $cameraIds)
            ->whereNull('processed_at')
            ->whereNotNull('direction')
            ->orderBy('captured_at')
            ->orderBy('id')
            ->chunkById(500, function ($events) use ($site): void {
                foreach ($events as $event) {
                    DB::transaction(fn () => $this->apply($site, $event));
                }
            });
    }

    protected function apply(Site $site, PlateEvent $event): void
    {
        $event->direction === PlateDirection::In
            ? $this->openVisit($site, $event)
            : $this->closeVisit($site, $event);

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

        if ($existing !== null) {
            $secondsSincePreviousEntry = $existing->entered_at->diffInSeconds($event->captured_at);

            if ($secondsSincePreviousEntry <= self::REENTRY_DEDUP_SECONDS) {
                // Same drive-through, second camera — do not create a new visit.
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
     */
    protected function closeVisit(Site $site, PlateEvent $event): void
    {
        $visit = $this->openVisitQuery($site, $event->plate_number)
            ->where('entered_at', '<=', $event->captured_at)
            ->orderByDesc('entered_at')
            ->first();

        if ($visit === null) {
            return;
        }

        $visit->forceFill([
            'exit_event_id' => $event->getKey(),
            'exited_at' => $event->captured_at,
            'dwell_minutes' => (int) round($visit->entered_at->diffInMinutes($event->captured_at)),
            'status' => VisitStatus::Closed,
        ])->save();
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
