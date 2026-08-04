<?php

namespace App\Support\Analytics;

use App\Enums\PlateTagType;
use App\Enums\VisitStatus;
use App\Models\PlateTag;
use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Every shopper-facing traffic figure in the app.
 *
 * Queries run through the Visit model, so the SiteScope global scope already
 * restricts them to the sites the current tenant may reach and to the site
 * chosen in the switcher. Nothing here needs to know who is asking.
 *
 * Staff and tenant vehicles would drown out shopper behaviour, so everything
 * here excludes recurring-tagged plates. The Security views deliberately query
 * separately, without that exclusion.
 */
class TrafficAnalytics
{
    /**
     * Buckets for the dwell distribution chart, in minutes.
     *
     * @var array<int, array{label: string, from: int, to: int|null}>
     */
    public const DWELL_BUCKETS = [
        ['label' => '<15m', 'from' => 0, 'to' => 15],
        ['label' => '15-30m', 'from' => 15, 'to' => 30],
        ['label' => '30-45m', 'from' => 30, 'to' => 45],
        ['label' => '45-60m', 'from' => 45, 'to' => 60],
        ['label' => '1-2h', 'from' => 60, 'to' => 120],
        ['label' => '2h+', 'from' => 120, 'to' => null],
    ];

    public function totalVisits(DateRange $range): int
    {
        return $this->baseQuery($range)->count();
    }

    /**
     * Distinct plates that showed up in the window. Answers "how many separate
     * customers walked past our cameras" rather than "how many trips did they
     * make", which is what totalVisits reports.
     */
    public function uniqueVehicles(DateRange $range): int
    {
        return $this->baseQuery($range)
            ->distinct('plate_number')
            ->count('plate_number');
    }

    /**
     * The proportion of visits made by plates seen more than once in the
     * window. Complementary to repeatVisitorPercentage, which counts the
     * *plates*; this weighs by visit count and answers "what share of our
     * turnstile clicks came from a returning customer?".
     */
    public function returnRatePercentage(DateRange $range): ?float
    {
        $total = $this->totalVisits($range);

        if ($total === 0) {
            return null;
        }

        // Postgres-specific: sum(count > 1) counts how many visits belong to
        // plates that showed up multiple times, expressed as a single query.
        $repeat = DB::query()->fromSub(
            $this->baseQuery($range)
                ->select('plate_number')
                ->selectRaw('count(*) as trips')
                ->groupBy('plate_number')
                ->havingRaw('count(*) > 1')
                ->toBase(),
            'repeat_visits',
        )->sum('trips');

        return round((int) $repeat / $total * 100, 1);
    }

    /**
     * Average and median dwell, in whole minutes, over closed visits only:
     * an open visit has no dwell yet and would drag the average down.
     *
     * @return array{average: int|null, median: int|null}
     */
    public function dwellSummary(DateRange $range): array
    {
        $row = $this->baseQuery($range)
            ->closed()
            ->whereNotNull('dwell_minutes')
            ->selectRaw('avg(dwell_minutes) as average')
            ->selectRaw('percentile_cont(0.5) within group (order by dwell_minutes) as median')
            // toBase keeps the scopes but returns a plain row: these are
            // aggregates, not visits, and hydrating a model would imply they
            // were.
            ->toBase()
            ->first();

        return [
            'average' => $row?->average === null ? null : (int) round((float) $row->average),
            'median' => $row?->median === null ? null : (int) round((float) $row->median),
        ];
    }

    /**
     * Visit counts for all 24 hours, zero-filled so the chart keeps its shape
     * on a quiet day.
     *
     * @return Collection<int, array{hour: int, label: string, count: int}>
     */
    public function visitsByHour(DateRange $range): Collection
    {
        $counts = $this->baseQuery($range)
            ->selectRaw('extract(hour from entered_at)::int as hour, count(*) as total')
            ->groupBy('hour')
            ->pluck('total', 'hour');

        return collect(range(0, 23))->map(fn (int $hour) => [
            'hour' => $hour,
            'label' => sprintf('%02d:00', $hour),
            'count' => (int) ($counts[$hour] ?? 0),
        ]);
    }

    /**
     * @return array{hour: int, label: string, count: int}|null
     */
    public function peakHour(DateRange $range): ?array
    {
        $peak = $this->visitsByHour($range)->sortByDesc('count')->first();

        return ($peak['count'] ?? 0) > 0 ? $peak : null;
    }

    /**
     * Hourly arrivals for one specific day, zero-filled. Used by the Today
     * dashboard's grouped "today vs yesterday" chart, where each series is
     * one calendar day rather than an arbitrary window.
     *
     * @return Collection<int, array{hour: int, label: string, count: int}>
     */
    public function visitsByHourOnDay(CarbonInterface $day): Collection
    {
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        $counts = Visit::query()
            ->excludingRecurring()
            ->whereNot('status', VisitStatus::Orphaned)
            ->enteredBetween($start, $end)
            ->selectRaw('extract(hour from entered_at)::int as hour, count(*) as total')
            ->groupBy('hour')
            ->pluck('total', 'hour');

        return collect(range(0, 23))->map(fn (int $hour) => [
            'hour' => $hour,
            'label' => sprintf('%02d:00', $hour),
            'count' => (int) ($counts[$hour] ?? 0),
        ]);
    }

    /**
     * Vehicles currently on site (open visits). Not a windowed metric — it is
     * a live count, so the Today dashboard can show pulse-of-the-day figures
     * without pretending they're historical.
     */
    public function currentlyOnSite(): int
    {
        return Visit::query()
            ->excludingRecurring()
            ->open()
            ->count();
    }

    /**
     * @return Collection<int, array{label: string, count: int, percent: float}>
     */
    public function dwellDistribution(DateRange $range): Collection
    {
        $query = $this->baseQuery($range)->closed()->whereNotNull('dwell_minutes');

        foreach (self::DWELL_BUCKETS as $index => $bucket) {
            $query->selectRaw(
                'count(*) filter (where dwell_minutes >= ?'.($bucket['to'] === null ? '' : ' and dwell_minutes < ?').") as bucket_{$index}",
                $bucket['to'] === null ? [$bucket['from']] : [$bucket['from'], $bucket['to']],
            );
        }

        $row = $query->toBase()->first();
        $total = collect(self::DWELL_BUCKETS)->keys()->sum(fn (int $i) => (int) ($row->{"bucket_{$i}"} ?? 0));

        return collect(self::DWELL_BUCKETS)->map(fn (array $bucket, int $index) => [
            'label' => $bucket['label'],
            'count' => $count = (int) ($row->{"bucket_{$index}"} ?? 0),
            'percent' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
        ]);
    }

    /**
     * Days of the week ranked by average traffic, so a range covering two
     * Saturdays does not make Saturday look twice as busy.
     *
     * @return Collection<int, array{label: string, count: int, percent: float}>
     */
    public function busiestDays(DateRange $range): Collection
    {
        $rows = $this->baseQuery($range)
            ->selectRaw('extract(isodow from entered_at)::int as weekday')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(distinct entered_at::date) as days')
            ->groupBy('weekday')
            ->toBase()
            ->get();

        $ranked = $rows
            ->map(fn (object $row) => [
                'label' => Date::now()->startOfWeek()->addDays((int) $row->weekday - 1)->format('D'),
                'count' => (int) round($row->total / max(1, (int) $row->days)),
            ])
            ->sortByDesc('count')
            ->values();

        $max = $ranked->max('count') ?: 1;

        return $ranked->map(fn (array $day) => [
            ...$day,
            'percent' => round($day['count'] / $max * 100, 1),
        ]);
    }

    /**
     * Share of plates that visited more than once in the window. A useful
     * loyalty signal once staff plates are out of the way.
     */
    public function repeatVisitorPercentage(DateRange $range): ?float
    {
        $row = $this->baseQuery($range)
            ->selectRaw('count(distinct plate_number) as plates')
            ->toBase()
            ->first();

        $plates = (int) ($row->plates ?? 0);

        if ($plates === 0) {
            return null;
        }

        // Counting a grouped query means counting its rows, which Postgres
        // will only do through a subquery.
        $repeat = DB::query()->fromSub(
            $this->baseQuery($range)
                ->select('plate_number')
                ->groupBy('plate_number')
                ->havingRaw('count(*) > 1')
                ->toBase(),
            'repeat_plates',
        )->count();

        return round($repeat / $plates * 100, 1);
    }

    /**
     * @return Collection<int, Visit>
     */
    public function recentVisits(DateRange $range, int $limit = 10): Collection
    {
        // Eager-load the entry event's camera so the dashboard's "Latest
        // visits" card can render an entry point without an N+1 walk.
        return $this->baseQuery($range)
            ->with([
                'site:id,name',
                'entryEvent:id,camera_id',
                'entryEvent.camera:id,name',
            ])
            ->orderByDesc('entered_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Visits per calendar day, zero-filled, for the reports trend line.
     *
     * @return Collection<int, array{date: string, label: string, count: int}>
     */
    public function visitsByDay(DateRange $range): Collection
    {
        $counts = $this->baseQuery($range)
            ->selectRaw('entered_at::date as day, count(*) as total')
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
     * Percentage change against the equivalent preceding window, or null when
     * there is no earlier traffic to compare against.
     */
    public function visitsDelta(DateRange $range): ?float
    {
        $previous = $this->totalVisits($range->previous());

        if ($previous === 0) {
            return null;
        }

        return round(($this->totalVisits($range) - $previous) / $previous * 100, 1);
    }

    /**
     * Format a period-over-period comparison as a delta caption. Handles the
     * null and zero-baseline cases so callers get something useful either way.
     *
     * @return array{label: string, tone: string}
     */
    public function comparison(int|float|null $current, int|float|null $previous): array
    {
        if ($current === null || $previous === null || (float) $previous === 0.0) {
            return ['label' => 'No prior data', 'tone' => 'muted'];
        }

        $delta = round((((float) $current - (float) $previous) / (float) $previous) * 100, 1);

        return [
            'label' => sprintf('%s %s%% vs previous', $delta >= 0 ? '▲' : '▼', number_format(abs($delta), 1)),
            'tone' => $delta >= 0 ? 'up' : 'down',
        ];
    }

    /**
     * Share of arrivals per entrance camera. Combines the "how did they get
     * in" question and gives us a mini-chart on the overview that answers it.
     *
     * @return Collection<int, array{label: string, count: int, percent: float}>
     */
    public function topEntryPoints(DateRange $range, int $limit = 5): Collection
    {
        $rows = $this->baseQuery($range)
            ->whereNotNull('entry_event_id')
            ->join('plate_events as entry_event', 'entry_event.id', '=', 'visits.entry_event_id')
            ->join('cameras', 'cameras.id', '=', 'entry_event.camera_id')
            ->selectRaw('cameras.name as label, count(*) as total')
            ->groupBy('cameras.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->toBase()
            ->get();

        $max = $rows->max('total') ?: 1;

        return $rows->map(fn (object $row) => [
            'label' => (string) $row->label,
            'count' => (int) $row->total,
            'percent' => round((int) $row->total / $max * 100, 1),
        ])->values();
    }

    /**
     * How many plates are tagged as staff or tenant patterns, which is what
     * every figure above is leaving out.
     */
    public function excludedPlateCount(): int
    {
        return PlateTag::query()->where('tag', PlateTagType::RecurringPattern)->count();
    }

    /**
     * @return Builder<Visit>
     */
    protected function baseQuery(DateRange $range): Builder
    {
        return Visit::query()
            ->excludingRecurring()
            ->whereNot('status', VisitStatus::Orphaned)
            ->enteredBetween($range->from, $range->to);
    }
}
