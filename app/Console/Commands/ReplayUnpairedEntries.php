<?php

namespace App\Console\Commands;

use App\Enums\PlateDirection;
use App\Jobs\MatchVisits;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Visit;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Give MatchVisits another pass at reads it already stamped but never turned
 * into a visit: entries that opened nothing, and exits that closed nothing.
 *
 * Processed events are otherwise frozen. After the pairing rule changes
 * (one-character OCR misses, repeat photos), historic rows stay on the old
 * result until this replays them.
 */
class ReplayUnpairedEntries extends Command
{
    protected $signature = 'visits:replay-unpaired
        {--site= : Only replay events for one site id}
        {--dry-run : Show what would be un-stamped without changing anything}';

    protected $description = 'Re-queue plate events that never became part of a visit';

    public function handle(): int
    {
        $siteId = $this->option('site') === null ? null : (int) $this->option('site');

        $entries = $this->unpairedEvents(PlateDirection::In, 'entry_event_id', $siteId);
        $exits = $this->unpairedEvents(PlateDirection::Out, 'exit_event_id', $siteId);

        $entryCount = $entries->count();
        $exitCount = $exits->count();

        if ($entryCount === 0 && $exitCount === 0) {
            $this->components->info('No unpaired entries to replay.');

            return self::SUCCESS;
        }

        if ($entryCount > 0) {
            $this->components->info("Found {$entryCount} entry event(s) with no paired visit.");
        }

        if ($exitCount > 0) {
            $this->components->info("Found {$exitCount} exit event(s) that closed no visit.");
        }

        if ($this->option('dry-run')) {
            $this->preview($entries);
            $this->preview($exits);

            return self::SUCCESS;
        }

        $ids = $entries->pluck('id')->merge($exits->pluck('id'));

        PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $ids)
            ->update(['processed_at' => null]);

        $this->components->info('Un-stamped '.$ids->count().' event(s). Dispatching MatchVisits...');

        MatchVisits::dispatchSync($siteId);

        $this->components->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @return Builder<PlateEvent>
     */
    protected function unpairedEvents(PlateDirection $direction, string $visitColumn, ?int $siteId)
    {
        return PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('direction', $direction)
            ->whereNotNull('processed_at')
            ->whereNull('superseded_by_event_id')
            ->whereNotIn('id', Visit::query()
                ->withoutGlobalScope(SiteScope::class)
                ->whereNotNull($visitColumn)
                ->select($visitColumn)
            )
            ->when($siteId !== null, fn ($query) => $query->forSite($siteId))
            ->orderBy('captured_at');
    }

    /**
     * @param  Builder<PlateEvent>  $events
     */
    protected function preview($events): void
    {
        $count = $events->count();

        $events->limit(20)->get(['id', 'plate_number', 'captured_at'])
            ->each(fn ($event) => $this->line(sprintf(
                '  #%d  %s  captured=%s',
                $event->id,
                $event->plate_number,
                $event->captured_at->toDateTimeString(),
            )));

        if ($count > 20) {
            $this->line('  ... and '.($count - 20).' more');
        }
    }
}
