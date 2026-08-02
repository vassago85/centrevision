<?php

namespace App\Jobs;

use App\Enums\PlateTagType;
use App\Models\PlateTag;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Visit;
use App\Support\Analytics\WeekdayArrival;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Identifies staff and tenant vehicles so they can be excluded from shopper
 * metrics.
 *
 * The signal is habit, not volume: a plate that turns up on most weekdays AND
 * arrives at close to the same time each day. Requiring both keeps a genuinely
 * loyal shopper — who visits often but at scattered times — out of the tag.
 *
 * Only the plate is tagged. No name, no profile, nothing that would make this
 * a personal record beyond the plate itself.
 */
class TagRecurringPlates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public ?int $siteId = null) {}

    public function uniqueId(): string
    {
        return (string) ($this->siteId ?? 'all');
    }

    public function handle(): void
    {
        foreach ($this->sites() as $site) {
            $this->tagSite($site);
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

    protected function tagSite(Site $site): void
    {
        $windowDays = (int) $site->setting('recurring_window_days', config('trafficflow.recurring_window_days'));
        $minRatio = (float) $site->setting('recurring_min_weekday_ratio', config('trafficflow.recurring_min_weekday_ratio'));
        $maxStdDev = (float) $site->setting('recurring_max_arrival_stddev_minutes', config('trafficflow.recurring_max_arrival_stddev_minutes'));

        $from = now()->subDays($windowDays)->startOfDay();
        $weekdaysInWindow = $this->weekdaysBetween($from, now());

        if ($weekdaysInWindow === 0) {
            return;
        }

        $qualifying = [];

        foreach ($this->weekdayArrivalsByPlate($site, $from) as $plate => $arrivals) {
            $days = $arrivals->map(fn (WeekdayArrival $arrival) => $arrival->day)->unique()->count();

            if (($days / $weekdaysInWindow) < $minRatio) {
                continue;
            }

            $stdDev = $this->standardDeviation(
                $arrivals->map(fn (WeekdayArrival $arrival) => $arrival->minuteOfDay)->values()->all(),
            );

            if ($stdDev === null || $stdDev >= $maxStdDev) {
                continue;
            }

            $qualifying[$plate] = [
                'weekdays_present' => $days,
                'weekdays_in_window' => $weekdaysInWindow,
                'arrival_stddev_minutes' => round($stdDev, 1),
                'window_days' => $windowDays,
            ];
        }

        $this->syncTags($site, $qualifying);
    }

    /**
     * Weekday arrivals in the window, grouped by plate.
     *
     * Weekends are excluded because a mall's weekend staff roster is far more
     * variable than its weekday one, which would depress the ratio for genuine
     * staff and inflate the deviation.
     *
     * @return Collection<array-key, Collection<int, WeekdayArrival>>
     */
    protected function weekdayArrivalsByPlate(Site $site, CarbonInterface $from): Collection
    {
        return Visit::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->where('entered_at', '>=', $from)
            // Postgres ISODOW: 1 = Monday, 7 = Sunday.
            ->whereRaw('extract(isodow from entered_at) <= 5')
            ->selectRaw("plate_number, to_char(entered_at, 'YYYY-MM-DD') as day, extract(hour from entered_at) * 60 + extract(minute from entered_at) as minute_of_day")
            // Aggregate columns, not visits, so these stay as plain rows.
            ->toBase()
            ->get()
            ->map(fn (object $row) => new WeekdayArrival(
                plateNumber: (string) $row->plate_number,
                day: (string) $row->day,
                minuteOfDay: (int) $row->minute_of_day,
            ))
            ->groupBy(fn (WeekdayArrival $arrival) => $arrival->plateNumber);
    }

    /**
     * Add tags for plates that now qualify and remove those that no longer do,
     * so a staff member who leaves stops being excluded from the metrics.
     *
     * @param  array<string, array<string, mixed>>  $qualifying
     */
    protected function syncTags(Site $site, array $qualifying): void
    {
        $existing = PlateTag::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->where('tag', PlateTagType::RecurringPattern)
            ->get()
            ->keyBy('plate_number');

        foreach ($qualifying as $plate => $evidence) {
            $tag = $existing->get($plate);

            if ($tag !== null) {
                $tag->forceFill(['evidence' => $evidence])->save();

                continue;
            }

            PlateTag::query()->withoutGlobalScope(SiteScope::class)->create([
                'site_id' => $site->getKey(),
                'plate_number' => $plate,
                'tag' => PlateTagType::RecurringPattern,
                'tagged_at' => now(),
                'evidence' => $evidence,
            ]);
        }

        $stale = $existing->keys()->diff(array_keys($qualifying));

        if ($stale->isNotEmpty()) {
            PlateTag::query()
                ->withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->getKey())
                ->where('tag', PlateTagType::RecurringPattern)
                ->whereIn('plate_number', $stale)
                ->delete();
        }
    }

    protected function weekdaysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $count = 0;

        for ($day = $from->copy()->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if (! $day->isWeekend()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Population standard deviation, in minutes.
     *
     * @param  array<int, int>  $values
     */
    protected function standardDeviation(array $values): ?float
    {
        $count = count($values);

        if ($count < 2) {
            return null;
        }

        $mean = array_sum($values) / $count;

        $variance = array_sum(array_map(
            fn (int $value) => ($value - $mean) ** 2,
            $values,
        )) / $count;

        return sqrt($variance);
    }
}
