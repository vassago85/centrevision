<?php

namespace App\Support\Analytics;

use App\Enums\CameraRole;
use App\Enums\PlateDirection;
use App\Enums\PlateTagType;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\PlateTag;
use App\Models\Site;
use App\Models\Visit;
use App\Support\Tenancy;
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
     * Default audience is shopper traffic. Reports can clone this instance
     * with forAudience() without changing the live dashboard.
     */
    protected string $audience = 'shopper';

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

    /**
     * @var array<int, array{label: string, from: int, to: int|null}>
     */
    public const FREQUENCY_BUCKETS = [
        ['label' => '1 visit', 'from' => 1, 'to' => 1],
        ['label' => '2–3 visits', 'from' => 2, 'to' => 3],
        ['label' => '4–5 visits', 'from' => 4, 'to' => 5],
        ['label' => '6–10 visits', 'from' => 6, 'to' => 10],
        ['label' => '10+ visits', 'from' => 11, 'to' => null],
    ];

    /** @var list<string> */
    public const WEEKDAY_LABELS = [
        'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
    ];

    /**
     * Reports-only: shopper (default), staff, or all vehicles. Cloning keeps
     * the container singleton used by the dashboard on the shopper path.
     */
    public function forAudience(string $audience): static
    {
        $clone = clone $this;
        $clone->audience = in_array($audience, ['staff', 'all'], true) ? $audience : 'shopper';

        return $clone;
    }

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
     * Open visits as a percent of the site's declared parking capacity.
     * Null when capacity is unset or the tenant spans multiple sites.
     */
    public function occupancyPercent(?Site $site = null): ?float
    {
        $site ??= app(Tenancy::class)->currentSite();

        if ($site === null) {
            return null;
        }

        $capacity = $site->parkingCapacity();

        if ($capacity === null) {
            return null;
        }

        return round(($this->currentlyOnSite() / $capacity) * 100, 1);
    }

    /**
     * True when any site the caller can currently see has at least one
     * camera capable of reporting exits. Used by the dashboard to decide
     * whether "Currently on site" and dwell KPIs are honest to show, or
     * whether the site is running entries-only and those numbers should
     * be reshaped.
     */
    public function hasExitTracking(): bool
    {
        return $this->hasExitTracking ??= Camera::query()
            ->whereIn('role', [CameraRole::Exit->value, CameraRole::Both->value])
            ->exists();
    }

    /**
     * Cached result of hasExitTracking() so per-request analytics don't hit
     * the cameras table for every KPI.
     */
    protected ?bool $hasExitTracking = null;

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
     * The last N plate detections, as a raw transaction log — entries and
     * exits both, in capture-time order.
     *
     * Backed by plate_events rather than visits so a vehicle that came in at
     * 12:00 and again at 15:00 shows up as two rows (one per detection). The
     * "Latest activity" card on the overview uses this — the visits table
     * hid the second entry inside the first vehicle's still-open visit,
     * which is what the user was seeing when the timestamp looked stale.
     *
     * Each row also carries whether the plate currently has an open visit,
     * so the UI can flag "still on site" without a per-row query.
     *
     * @return Collection<int, PlateEvent>
     */
    public function recentDetections(int $limit = 10, ?int $cameraId = null): Collection
    {
        $entries = PlateEvent::query()
            ->whereNotNull('direction')
            ->when($cameraId, fn ($q, $id) => $q->where('camera_id', $id))
            ->with('camera:id,name')
            ->orderByDesc('captured_at')
            ->limit($limit)
            ->get();

        if ($entries->isEmpty()) {
            return $entries;
        }

        // One query for the "is this plate still on site" pill, rather than
        // one per row.
        $onSitePlates = Visit::query()
            ->open()
            ->whereIn('plate_number', $entries->pluck('plate_number')->unique())
            ->pluck('plate_number')
            ->flip();

        return $entries->each(function (PlateEvent $event) use ($onSitePlates): void {
            $event->setAttribute('on_site_now', $onSitePlates->has($event->plate_number));
        });
    }

    /**
     * @deprecated Use recentDetections(). Kept for backwards compatibility;
     * still returns entries only so any callers that assumed an entry log
     * are not surprised.
     *
     * @return Collection<int, PlateEvent>
     */
    public function recentEntries(int $limit = 10): Collection
    {
        return $this->recentDetections($limit * 2)
            ->filter(fn (PlateEvent $event) => $event->direction === PlateDirection::In)
            ->take($limit)
            ->values();
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

        // Pill only carries the arrow + magnitude; the "vs previous period"
        // context lives in the adjacent caption on the KPI card, so we
        // avoid saying "vs previous" twice on the same line.
        return [
            'label' => sprintf('%s %s%%', $delta >= 0 ? '▲' : '▼', number_format(abs($delta), 1)),
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
     * Visits in the window that belong to staff/regular plates. Shown on
     * Reports as "how much staff traffic was excluded" — always counted
     * against the recurring tag, never against the current audience.
     */
    public function excludedVisitCount(DateRange $range): int
    {
        return Visit::query()
            ->onlyRecurring()
            ->enteredBetween($range->from, $range->to)
            ->count();
    }

    /**
     * Unique plates in the window that also visited this site before it.
     */
    public function returningVehicles(DateRange $range): int
    {
        $platesInPeriod = $this->baseQuery($range)->select('visits.plate_number')->distinct();

        return $this->identityQuery()
            ->where('entered_at', '<', $range->from)
            ->whereIn('plate_number', $platesInPeriod)
            ->distinct('plate_number')
            ->count('plate_number');
    }

    public function firstTimeVehicles(DateRange $range): int
    {
        return max(0, $this->uniqueVehicles($range) - $this->returningVehicles($range));
    }

    /**
     * Share of unique vehicles in the window that had visited before it.
     */
    public function returningVehicleRate(DateRange $range): ?float
    {
        $unique = $this->uniqueVehicles($range);

        if ($unique === 0) {
            return null;
        }

        return round($this->returningVehicles($range) / $unique * 100, 1);
    }

    /**
     * Of unique vehicles in the window, how many also visited in the 30 days
     * immediately before it. Null when the window itself is empty.
     */
    public function returnRate30Day(DateRange $range): ?float
    {
        $unique = $this->uniqueVehicles($range);

        if ($unique === 0) {
            return null;
        }

        $lookback = new DateRange(
            'lookback_30',
            'Prior 30 days',
            $range->from->copy()->subDays(30),
            $range->from->copy()->subSecond(),
        );

        $priorPlates = $this->baseQuery($lookback)->select('visits.plate_number')->distinct();

        $returned = $this->baseQuery($range)
            ->whereIn('plate_number', $priorPlates)
            ->distinct('plate_number')
            ->count('plate_number');

        return round($returned / $unique * 100, 1);
    }

    /**
     * @return Collection<int, array{label: string, count: int, percent: float}>
     */
    public function visitFrequency(DateRange $range): Collection
    {
        $query = DB::query()->fromSub(
            $this->baseQuery($range)
                ->select('plate_number')
                ->selectRaw('count(*) as trips')
                ->groupBy('plate_number')
                ->toBase(),
            'freq',
        );

        foreach (self::FREQUENCY_BUCKETS as $index => $bucket) {
            $query->selectRaw(
                'count(*) filter (where trips >= ?'.($bucket['to'] === null ? '' : ' and trips <= ?').") as bucket_{$index}",
                $bucket['to'] === null ? [$bucket['from']] : [$bucket['from'], $bucket['to']],
            );
        }

        $row = $query->first();
        $total = collect(self::FREQUENCY_BUCKETS)->keys()->sum(fn (int $i) => (int) ($row->{"bucket_{$i}"} ?? 0));

        return collect(self::FREQUENCY_BUCKETS)->map(fn (array $bucket, int $index) => [
            'label' => $bucket['label'],
            'count' => $count = (int) ($row->{"bucket_{$index}"} ?? 0),
            'percent' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
        ]);
    }

    /**
     * Average visits per weekday, Monday first, so a month with five
     * Saturdays does not make Saturday look busier than it is.
     *
     * @return Collection<int, array{label: string, count: int}>
     */
    public function visitsByWeekday(DateRange $range): Collection
    {
        $rows = $this->baseQuery($range)
            ->selectRaw('extract(isodow from entered_at)::int as weekday')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(distinct entered_at::date) as days')
            ->groupBy('weekday')
            ->toBase()
            ->get()
            ->keyBy('weekday');

        return collect(range(1, 7))->map(function (int $dow) use ($rows) {
            $row = $rows->get($dow);

            return [
                'label' => self::WEEKDAY_LABELS[$dow - 1],
                'count' => $row === null ? 0 : (int) round($row->total / max(1, (int) $row->days)),
            ];
        });
    }

    /**
     * Day × hour grid of average visits. Rows are Monday–Sunday; cells are
     * the mean for that weekday/hour across the window.
     *
     * @return Collection<int, array{weekday: int, label: string, hours: array<int, array{hour: int, average: float}>}>
     */
    public function dayHourHeatmap(DateRange $range): Collection
    {
        $rows = $this->baseQuery($range)
            ->selectRaw('extract(isodow from entered_at)::int as weekday')
            ->selectRaw('extract(hour from entered_at)::int as hour')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(distinct entered_at::date) as days')
            ->groupBy('weekday', 'hour')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row) => $row->weekday.'-'.$row->hour);

        return collect(range(1, 7))->map(function (int $dow) use ($rows) {
            $hours = collect(range(0, 23))->map(function (int $hour) use ($rows, $dow) {
                $row = $rows->get($dow.'-'.$hour);

                return [
                    'hour' => $hour,
                    'average' => $row === null
                        ? 0.0
                        : round((float) $row->total / max(1, (int) $row->days), 1),
                ];
            });

            return [
                'weekday' => $dow,
                'label' => self::WEEKDAY_LABELS[$dow - 1],
                'hours' => $hours->all(),
            ];
        });
    }

    /**
     * Historical series for the Reports "visits over time" chart. Grain
     * follows the window; metric is visits, unique vehicles, or exits.
     *
     * @return Collection<int, array{date: string, label: string, count: int}>
     */
    public function seriesOverTime(DateRange $range, string $metric = 'visits'): Collection
    {
        return match ($range->grain()) {
            'hour' => $this->seriesByHour($range, $metric),
            'week' => $this->seriesByWeek($range, $metric),
            default => $this->seriesByDay($range, $metric),
        };
    }

    /**
     * @return Collection<int, array{date: string, label: string, count: int}>
     */
    protected function seriesByHour(DateRange $range, string $metric): Collection
    {
        $counts = $this->metricQuery($range, $metric)
            ->selectRaw("to_char(date_trunc('hour', {$this->metricTimestamp($metric)}), 'YYYY-MM-DD HH24:00:00') as bucket, {$this->metricSelect($metric)} as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $hours = collect();

        for ($cursor = $range->from->copy()->startOfHour(); $cursor->lte($range->to); $cursor = $cursor->addHour()) {
            $key = $cursor->format('Y-m-d H:00:00');

            $hours->push([
                'date' => $cursor->toDateTimeString(),
                'label' => $cursor->format('H:i'),
                'count' => (int) ($counts[$key] ?? 0),
            ]);
        }

        return $hours;
    }

    /**
     * @return Collection<int, array{date: string, label: string, count: int}>
     */
    protected function seriesByDay(DateRange $range, string $metric): Collection
    {
        $counts = $this->metricQuery($range, $metric)
            ->selectRaw("{$this->metricTimestamp($metric)}::date as day, {$this->metricSelect($metric)} as total")
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
     * @return Collection<int, array{date: string, label: string, count: int}>
     */
    protected function seriesByWeek(DateRange $range, string $metric): Collection
    {
        $counts = $this->metricQuery($range, $metric)
            ->selectRaw("date_trunc('week', {$this->metricTimestamp($metric)})::date as week, {$this->metricSelect($metric)} as total")
            ->groupBy('week')
            ->pluck('total', 'week');

        $weeks = collect();

        for ($cursor = $range->from->copy()->startOfWeek(); $cursor->lte($range->to); $cursor = $cursor->addWeek()) {
            $key = $cursor->toDateString();

            $weeks->push([
                'date' => $key,
                'label' => $cursor->format('j M'),
                'count' => (int) ($counts[$key] ?? 0),
            ]);
        }

        return $weeks;
    }

    /**
     * @return Builder<Visit>
     */
    protected function metricQuery(DateRange $range, string $metric): Builder
    {
        if ($metric === 'exits') {
            return $this->identityQuery()
                ->closed()
                ->whereBetween('exited_at', [$range->from, $range->to]);
        }

        return $this->baseQuery($range);
    }

    protected function metricTimestamp(string $metric): string
    {
        return $metric === 'exits' ? 'exited_at' : 'entered_at';
    }

    protected function metricSelect(string $metric): string
    {
        return $metric === 'unique' ? 'count(distinct plate_number)' : 'count(*)';
    }

    /**
     * @return Builder<Visit>
     */
    protected function identityQuery(): Builder
    {
        $query = Visit::query();

        return match ($this->audience) {
            'staff' => $query->onlyRecurring(),
            'all' => $query,
            default => $query->excludingRecurring(),
        };
    }

    /**
     * @return Builder<Visit>
     */
    protected function baseQuery(DateRange $range): Builder
    {
        // Orphaned visits are still real arrivals — the vehicle actually
        // drove past the entrance camera. We used to strip them so live
        // "currently on site" wouldn't be inflated by ghosts, but that also
        // deleted them from historic arrival counts and every day older
        // than "orphan_after_hours" would silently vanish overnight.
        //
        // Dwell-based queries call ->closed() on top of this, which already
        // excludes Orphaned rows where excluding them is correct, so we can
        // safely leave them in the base set.
        return $this->identityQuery()
            ->enteredBetween($range->from, $range->to);
    }
}
