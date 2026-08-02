<?php

namespace App\Support\Analytics;

use App\Enums\PlateDirection;
use App\Enums\VisitStatus;
use App\Models\PlateEvent;
use App\Models\Visit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The security desk's view of the same data.
 *
 * Unlike TrafficAnalytics this deliberately does *not* drop recurring plates:
 * a vehicle that is on site every night at 23:40 is exactly what security
 * wants to see. Everything here is plate-level and therefore owner-only; the
 * PlateDataPolicy keeps shops out.
 */
class SecurityAnalytics
{
    /**
     * Open visits that have been on site longer than the threshold, longest
     * first. Ordering by entry time ascending puts the worst case at the top.
     *
     * @return Collection<int, Visit>
     */
    public function overThreshold(int $thresholdHours): Collection
    {
        return Visit::query()
            ->open()
            ->where('entered_at', '<=', Date::now()->subHours($thresholdHours))
            ->with(['site:id,name', 'entryEvent.camera:id,name'])
            ->orderBy('entered_at')
            ->get();
    }

    /**
     * Plates seen entering between the small hours, on more than one day
     * inside the window. A single late night is noise; a pattern is not.
     *
     * @return Collection<int, array{plate_number: string, days: int, window_days: int, typical_time: string}>
     */
    public function oddHourRecurring(): Collection
    {
        $config = config('trafficflow.security');
        $windowDays = (int) $config['odd_hour_window_days'];
        $start = (int) $config['odd_hours']['start'];
        $end = (int) $config['odd_hours']['end'];

        return PlateEvent::query()
            ->where('direction', PlateDirection::In)
            ->where('captured_at', '>=', Date::now()->subDays($windowDays))
            // The window wraps midnight, so it is a union of two ranges.
            ->whereRaw('(extract(hour from captured_at) >= ? or extract(hour from captured_at) < ?)', [$start, $end])
            ->selectRaw('plate_number')
            ->selectRaw('count(distinct captured_at::date) as days')
            // Averaging clock times across midnight would land at midday, so
            // shift the late-evening half back before averaging and undo it.
            ->selectRaw(
                'avg(extract(epoch from captured_at::time) - case when extract(hour from captured_at) >= ? then 86400 else 0 end) as offset_seconds',
                [$start],
            )
            ->groupBy('plate_number')
            ->havingRaw('count(distinct captured_at::date) > 1')
            ->orderByRaw('count(distinct captured_at::date) desc')
            ->limit(20)
            // These rows are aggregates, not plate events, so they stay as
            // plain rows rather than being hydrated into models.
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'plate_number' => (string) $row->plate_number,
                'days' => (int) $row->days,
                'window_days' => $windowDays,
                'typical_time' => Date::now()
                    ->startOfDay()
                    ->addSeconds((int) round(fmod((float) $row->offset_seconds + 86400, 86400)))
                    ->format('H:i'),
            ]);
    }

    /**
     * Plates that entered repeatedly today. Deliveries look like this, and so
     * does someone circling a parking lot.
     *
     * @return Collection<int, array{plate_number: string, entries: int, last_seen: string}>
     */
    public function multipleEntriesToday(): Collection
    {
        $threshold = (int) config('trafficflow.security.multi_entry_threshold');

        return PlateEvent::query()
            ->where('direction', PlateDirection::In)
            ->where('captured_at', '>=', Date::now()->startOfDay())
            ->selectRaw('plate_number, count(*) as entries, max(captured_at) as last_seen')
            ->groupBy('plate_number')
            ->havingRaw('count(*) >= ?', [$threshold])
            ->orderByRaw('count(*) desc')
            ->limit(20)
            // These rows are aggregates, not plate events, so they stay as
            // plain rows rather than being hydrated into models.
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'plate_number' => (string) $row->plate_number,
                'entries' => (int) $row->entries,
                'last_seen' => Date::parse($row->last_seen)->format('H:i'),
            ]);
    }

    /**
     * Visits that never produced an exit event, which usually means a camera
     * missed the vehicle leaving rather than that it is still there.
     */
    public function orphanedCount(int $days = 7): int
    {
        return Visit::query()
            ->where('status', VisitStatus::Orphaned)
            ->where('entered_at', '>=', Date::now()->subDays($days))
            ->count();
    }
}
