<?php

namespace App\Support\Analytics;

use App\Enums\PlateDirection;
use App\Enums\VisitStatus;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Visit;
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
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('visits')
                    ->whereColumn('visits.exit_event_id', 'plate_events.id');
            })
            ->count();

        $cameras = Camera::query()->where('is_active', true)->get();
        $offline = $cameras->filter(fn (Camera $camera) => ! $camera->isReachable())->count();
        $total = $cameras->count();

        return [
            'reads' => $reads,
            'entries' => $entries,
            'exits' => $exits,
            'paired_visits' => $paired,
            'orphan_entries' => $orphanEntries,
            'orphan_exits' => $orphanExits,
            'pairing_quality' => $reads > 0 ? round(($paired * 2) / $reads * 100, 1) : null,
            'unmatched_reads' => max(0, $reads - ($paired * 2)),
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
}
