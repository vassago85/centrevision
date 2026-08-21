<?php

namespace App\Support\Analytics;

use App\Enums\CameraRole;
use App\Enums\PlateDirection;
use App\Enums\VisitStatus;
use App\Models\Camera;
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
     * Entry-only sites are excluded: without an exit camera the app cannot
     * tell whether a vehicle is still on site or has left through an
     * unmonitored gate, so flagging every four-hour-old entry as a dwell
     * breach would drown the security team in false positives.
     *
     * $cameraId narrows the set to visits whose entry event came from that
     * camera. Useful when a site has multiple entrance lanes and the
     * operator wants to look at one of them.
     *
     * @return Collection<int, Visit>
     */
    public function overThreshold(int $thresholdHours, ?int $cameraId = null): Collection
    {
        return Visit::query()
            ->open()
            ->where('entered_at', '<=', Date::now()->subHours($thresholdHours))
            ->whereIn('site_id', $this->sitesWithExitTracking())
            ->when($cameraId, fn ($q, $id) => $q->whereHas('entryEvent', fn ($ev) => $ev->where('camera_id', $id)))
            ->with(['site:id,name', 'entryEvent.camera:id,name'])
            ->orderBy('entered_at')
            ->get();
    }

    /**
     * Site ids the current tenant can reach that have at least one camera
     * capable of reporting exits. Cached per instance because the security
     * page hits it from three different methods on every render.
     *
     * @return array<int, int>
     */
    protected function sitesWithExitTracking(): array
    {
        return $this->sitesWithExitTrackingCache ??= Camera::query()
            ->whereIn('role', [CameraRole::Exit->value, CameraRole::Both->value])
            ->distinct()
            ->pluck('site_id')
            ->all();
    }

    /** @var array<int, int>|null */
    protected ?array $sitesWithExitTrackingCache = null;

    /**
     * Plates seen entering between the small hours, on more than one day
     * inside the window. A single late night is noise; a pattern is not.
     *
     * @return Collection<int, array{plate_number: string, days: int, window_days: int, typical_time: string}>
     */
    public function oddHourRecurring(?int $cameraId = null): Collection
    {
        $config = config('trafficflow.security');
        $windowDays = (int) $config['odd_hour_window_days'];
        $start = (int) $config['odd_hours']['start'];
        $end = (int) $config['odd_hours']['end'];

        return PlateEvent::query()
            ->where('direction', PlateDirection::In)
            ->when($cameraId, fn ($q, $id) => $q->where('camera_id', $id))
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
     * @return Collection<int, array{plate_number: string, entries: int, last_seen: string, times: array<int, string>}>
     */
    public function multipleEntriesToday(?int $cameraId = null): Collection
    {
        $threshold = (int) config('trafficflow.security.multi_entry_threshold');
        $dayStart = Date::now()->startOfDay();

        $counts = PlateEvent::query()
            ->where('direction', PlateDirection::In)
            ->when($cameraId, fn ($q, $id) => $q->where('camera_id', $id))
            ->where('captured_at', '>=', $dayStart)
            ->selectRaw('plate_number, count(*) as entries')
            ->groupBy('plate_number')
            ->havingRaw('count(*) >= ?', [$threshold])
            ->orderByRaw('count(*) desc')
            ->limit(20)
            ->toBase()
            ->get();

        if ($counts->isEmpty()) {
            return new Collection;
        }

        // Second query fetches every capture time for the flagged plates so we
        // can show the security team when each entry actually happened, rather
        // than only the last one.
        $times = PlateEvent::query()
            ->where('direction', PlateDirection::In)
            ->when($cameraId, fn ($q, $id) => $q->where('camera_id', $id))
            ->where('captured_at', '>=', $dayStart)
            ->whereIn('plate_number', $counts->pluck('plate_number')->all())
            ->orderBy('captured_at')
            ->get(['plate_number', 'captured_at'])
            ->groupBy('plate_number');

        return $counts->map(function (object $row) use ($times) {
            $plate = (string) $row->plate_number;
            $plateTimes = $times->get($plate, new Collection);
            $formatted = $plateTimes->map(fn ($event) => $event->captured_at->format('H:i'))->values()->all();

            return [
                'plate_number' => $plate,
                'entries' => (int) $row->entries,
                'last_seen' => $formatted !== [] ? end($formatted) : '',
                'times' => $formatted,
            ];
        })->values();
    }

    /**
     * Visits that never produced an exit event, which usually means a camera
     * missed the vehicle leaving rather than that it is still there.
     *
     * Entry-only sites are excluded — for them a missing exit is expected,
     * not an anomaly, so counting them would make every site look broken.
     */
    public function orphanedCount(int $days = 7, ?int $cameraId = null): int
    {
        return Visit::query()
            ->where('status', VisitStatus::Orphaned)
            ->where('entered_at', '>=', Date::now()->subDays($days))
            ->whereIn('site_id', $this->sitesWithExitTracking())
            ->when($cameraId, fn ($q, $id) => $q->whereHas('entryEvent', fn ($ev) => $ev->where('camera_id', $id)))
            ->count();
    }
}
