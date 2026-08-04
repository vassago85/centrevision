<?php

namespace App\Console\Commands;

use App\Enums\PlateDirection;
use App\Jobs\MatchVisits;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Visit;
use Illuminate\Console\Command;

/**
 * Un-stamp entry plate events that were processed by MatchVisits but never
 * became a visit, then dispatch MatchVisits so the current pairing logic
 * gets a second chance at them.
 *
 * The original MatchVisits::openVisit() silently skipped an entry when the
 * plate already had an open visit, but still stamped the event as processed.
 * After changing the rule (re-entries now open new visits), those historic
 * entries stay frozen because processed_at is not null. This replays them.
 */
class ReplayUnpairedEntries extends Command
{
    protected $signature = 'visits:replay-unpaired
        {--site= : Only replay entries for one site id}
        {--dry-run : Show what would be un-stamped without changing anything}';

    protected $description = 'Re-queue historic entry plate events that never produced a visit';

    public function handle(): int
    {
        $siteId = $this->option('site') === null ? null : (int) $this->option('site');

        $orphans = PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('direction', PlateDirection::In)
            ->whereNotNull('processed_at')
            ->whereNotIn('id', Visit::query()
                ->withoutGlobalScope(SiteScope::class)
                ->whereNotNull('entry_event_id')
                ->select('entry_event_id')
            )
            ->when($siteId !== null, fn ($query) => $query->forSite($siteId))
            ->orderBy('captured_at');

        $count = $orphans->count();

        if ($count === 0) {
            $this->components->info('No unpaired entries to replay.');

            return self::SUCCESS;
        }

        $this->components->info("Found {$count} entry event(s) with no paired visit.");

        if ($this->option('dry-run')) {
            $orphans->limit(20)->get(['id', 'plate_number', 'captured_at'])
                ->each(fn ($event) => $this->line(sprintf(
                    '  #%d  %s  captured=%s',
                    $event->id,
                    $event->plate_number,
                    $event->captured_at->toDateTimeString(),
                )));

            if ($count > 20) {
                $this->line('  ... and '.($count - 20).' more');
            }

            return self::SUCCESS;
        }

        // Clone the query so the update does not fight the whereNotIn subquery
        // (Postgres would still handle it, but making the intent explicit).
        $ids = $orphans->pluck('id');

        PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $ids)
            ->update(['processed_at' => null]);

        $this->components->info('Un-stamped '.$ids->count().' event(s). Dispatching MatchVisits...');

        MatchVisits::dispatchSync($siteId);

        $this->components->info('Done.');

        return self::SUCCESS;
    }
}
