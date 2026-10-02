<?php

namespace App\Support\Analytics;

use App\Enums\PlateDirection;
use App\Enums\VisitStatus;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Visit;
use App\Support\PlateNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Camera and pairing health for a reporting window.
 *
 * Counts every plate read and visit — staff exclusion is a shopper-analytics
 * concern, not a data-quality one. Owner/operator only.
 */
class DataQualityAnalytics
{
    /**
     * @return array{
     *   reads: int,
     *   pairable_reads: int,
     *   excluded_duplicates: int,
     *   excluded_unreadable: int,
     *   excluded_no_direction: int,
     *   eligible_entries: int,
     *   eligible_exits: int,
     *   open_visits: int,
     *   entries: int,
     *   exits: int,
     *   paired_visits: int,
     *   orphan_entries: int,
     *   orphan_exits: int,
     *   pairing_quality: float|null,
     *   unmatched_reads: int,
     *   camera_uptime: float|null,
     *   cameras_offline: int,
     *   cameras_total: int
     * }
     */
    public function summary(DateRange $range): array
    {
        $reads = PlateEvent::query()
            ->whereBetween('captured_at', [$range->from, $range->to])
            ->count();

        // Pairing quality answers "of the reads that could become a visit,
        // how many did". A failed OCR, a camera that sent no direction, and
        // a second photo of a drive-through already counted are not failures.
        $pairable = PlateEvent::query()
            ->whereBetween('captured_at', [$range->from, $range->to])
            ->where(fn (Builder $query) => $this->pairable($query))
            ->count();

        $entries = PlateEvent::query()
            ->where('direction', PlateDirection::In)
            ->whereBetween('captured_at', [$range->from, $range->to])
            ->count();

        $exits = PlateEvent::query()
            ->where('direction', PlateDirection::Out)
            ->whereBetween('captured_at', [$range->from, $range->to])
            ->count();

        $paired = Visit::query()
            ->closed()
            ->enteredBetween($range->from, $range->to)
            ->count();

        $orphanEntries = Visit::query()
            ->where('status', VisitStatus::Orphaned)
            ->enteredBetween($range->from, $range->to)
            ->count();

        $orphanExits = PlateEvent::query()
            ->where('direction', PlateDirection::Out)
            ->whereBetween('captured_at', [$range->from, $range->to])
            ->where(fn (Builder $query) => $this->pairable($query))
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('visits')
                    ->whereColumn('visits.exit_event_id', 'plate_events.id');
            })
            ->count();

        // Why received reads are not eligible, one reason per read so the
        // three counts add up to reads − pairable_reads. Checked in this
        // order: a duplicate photo first, then an unreadable plate, then a
        // missing direction.
        $unknown = PlateNumber::normalise('unknown');
        $breakdown = PlateEvent::query()
            ->whereBetween('captured_at', [$range->from, $range->to])
            ->selectRaw('count(*) filter (where superseded_by_event_id is not null) as duplicates')
            ->selectRaw('count(*) filter (where superseded_by_event_id is null and plate_number = ?) as unreadable', [$unknown])
            ->selectRaw('count(*) filter (where superseded_by_event_id is null and plate_number != ? and direction is null) as no_direction', [$unknown])
            ->selectRaw("count(*) filter (where superseded_by_event_id is null and plate_number != ? and direction = 'in') as eligible_entries", [$unknown])
            ->selectRaw("count(*) filter (where superseded_by_event_id is null and plate_number != ? and direction = 'out') as eligible_exits", [$unknown])
            ->toBase()
            ->first();

        $openVisits = Visit::query()
            ->open()
            ->enteredBetween($range->from, $range->to)
            ->count();

        $cameras = Camera::query()->where('is_active', true)->get();
        $offline = $cameras->filter(fn (Camera $camera) => ! $camera->isReachable())->count();
        $total = $cameras->count();

        return [
            'reads' => $reads,
            'pairable_reads' => $pairable,
            'excluded_duplicates' => (int) ($breakdown->duplicates ?? 0),
            'excluded_unreadable' => (int) ($breakdown->unreadable ?? 0),
            'excluded_no_direction' => (int) ($breakdown->no_direction ?? 0),
            'eligible_entries' => (int) ($breakdown->eligible_entries ?? 0),
            'eligible_exits' => (int) ($breakdown->eligible_exits ?? 0),
            'open_visits' => $openVisits,
            'entries' => $entries,
            'exits' => $exits,
            'paired_visits' => $paired,
            'orphan_entries' => $orphanEntries,
            'orphan_exits' => $orphanExits,
            'pairing_quality' => $pairable > 0 ? round(($paired * 2) / $pairable * 100, 1) : null,
            'unmatched_reads' => max(0, $pairable - ($paired * 2)),
            'camera_uptime' => $total > 0 ? round((($total - $offline) / $total) * 100, 1) : null,
            'cameras_offline' => $offline,
            'cameras_total' => $total,
        ];
    }

    /**
     * @return Collection<int, array{date: string, label: string, count: int}>
     */
    public function readsByDay(DateRange $range): Collection
    {
        $counts = PlateEvent::query()
            ->whereBetween('captured_at', [$range->from, $range->to])
            ->selectRaw('captured_at::date as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $days = collect();

        for ($cursor = $range->from->copy(); $cursor->lte($range->to); $cursor = $cursor->addDay()) {
            $key = $cursor->toDateString();

            $days->push([
                'date' => $key,
                'label' => $cursor->format('j M'),
                'count' => (int) ($counts[$key] ?? 0),
            ]);
        }

        return $days;
    }

    /**
     * Reads that matching is allowed to turn into a visit.
     *
     * @param  Builder<PlateEvent>  $query
     */
    protected function pairable(Builder $query): void
    {
        $query->whereNotNull('direction')
            ->where('plate_number', '!=', PlateNumber::normalise('unknown'))
            ->whereNull('superseded_by_event_id');
    }
}
