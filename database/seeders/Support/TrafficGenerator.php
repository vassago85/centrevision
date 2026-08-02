<?php

namespace Database\Seeders\Support;

use App\Enums\PlateDirection;
use App\Enums\VisitStatus;
use App\Models\Camera;
use App\Models\Site;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates plausible traffic for a demo site.
 *
 * The shape deliberately mirrors the dashboard mockup: an evening peak around
 * 17:00, dwell times clustering between 15 and 45 minutes, and Saturday the
 * busiest day of the week.
 */
class TrafficGenerator
{
    /**
     * Relative arrival volume by hour of day, indexed 0-23.
     */
    protected const HOURLY_WEIGHTS = [
        0 => 2, 1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 5,
        6 => 12, 7 => 28, 8 => 45, 9 => 80, 10 => 110, 11 => 140,
        12 => 168, 13 => 155, 14 => 120, 15 => 138, 16 => 175, 17 => 312,
        18 => 240, 19 => 150, 20 => 90, 21 => 40, 22 => 14, 23 => 6,
    ];

    /**
     * Relative volume by ISO weekday (1 = Monday).
     */
    protected const WEEKDAY_WEIGHTS = [
        1 => 0.50, 2 => 0.50, 3 => 0.52, 4 => 0.58, 5 => 0.86, 6 => 1.00, 7 => 0.74,
    ];

    /**
     * Dwell buckets as [minMinutes, maxMinutes, relativeWeight], matching the
     * distribution chart in the mockup.
     */
    protected const DWELL_BUCKETS = [
        [5, 15, 420],
        [15, 30, 980],
        [30, 45, 1120],
        [45, 60, 640],
        [60, 90, 380],
        [90, 210, 302],
    ];

    /** @var list<array<string, mixed>> */
    protected array $events = [];

    /** @var list<array<string, mixed>> */
    protected array $visits = [];

    /** @var Collection<int, Camera> */
    protected Collection $entranceCameras;

    /** @var Collection<int, Camera> */
    protected Collection $exitCameras;

    public function __construct(
        protected Site $site,
        protected float $volumeScale = 1.0,
    ) {
        $cameras = $site->cameras()->get();

        $this->entranceCameras = $cameras->filter(fn (Camera $c) => $c->role->impliedDirection() !== PlateDirection::Out);
        $this->exitCameras = $cameras->filter(fn (Camera $c) => $c->role->impliedDirection() !== PlateDirection::In);
    }

    /**
     * Build and persist the traffic for a window of days ending today.
     */
    public function run(CarbonInterface $from, CarbonInterface $to): void
    {
        $this->seedShopperTraffic($from, $to);
        $this->seedStaffTraffic($from, $to);
        $this->seedOddHourVisitors($from, $to);
        $this->seedMultiEntryPlates();
        $this->seedOrphanedVisits($from);
        $this->seedCurrentlyOnSite();

        $this->flush();
        $this->linkVisitsToEvents();
    }

    /**
     * Ordinary shoppers: arrive, stay 5 to 210 minutes, leave.
     */
    protected function seedShopperTraffic(CarbonInterface $from, CarbonInterface $to): void
    {
        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $dayWeight = self::WEEKDAY_WEIGHTS[$day->dayOfWeekIso];

            foreach (self::HOURLY_WEIGHTS as $hour => $hourWeight) {
                $arrivals = (int) round($hourWeight * $dayWeight * $this->volumeScale);

                for ($i = 0; $i < $arrivals; $i++) {
                    $enteredAt = $day->copy()
                        ->setTime($hour, random_int(0, 59), random_int(0, 59));

                    // Do not invent traffic that has not happened yet.
                    if ($enteredAt->isFuture()) {
                        continue;
                    }

                    $this->recordVisit(PlateFaker::shopper(), $enteredAt, $this->randomDwell());
                }
            }
        }
    }

    /**
     * Staff and tenants: on site most weekdays, arriving within a tight window,
     * which is exactly what TagRecurringPlates should later flag.
     */
    protected function seedStaffTraffic(CarbonInterface $from, CarbonInterface $to): void
    {
        foreach (PlateFaker::staff() as $index => $plate) {
            // Each staff member has their own habitual arrival time.
            $baseHour = 7 + ($index % 3);
            $baseMinute = [10, 25, 40, 55][$index % 4];

            for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                if ($day->isWeekend()) {
                    continue;
                }

                // Miss the occasional day, as real staff do.
                if (random_int(1, 100) <= 8) {
                    continue;
                }

                $enteredAt = $day->copy()
                    ->setTime($baseHour, $baseMinute, 0)
                    ->addMinutes(random_int(-12, 12));

                if ($enteredAt->isFuture()) {
                    continue;
                }

                // A full shift, so staff never look like shoppers on dwell.
                $this->recordVisit($plate, $enteredAt, random_int(420, 540));
            }
        }
    }

    /**
     * A couple of plates that keep turning up in the small hours, which the
     * Security view surfaces as odd-hour recurring visits.
     */
    protected function seedOddHourVisitors(CarbonInterface $from, CarbonInterface $to): void
    {
        foreach (PlateFaker::oddHour() as $index => $plate) {
            $hour = $index === 0 ? 23 : 5;

            for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                if (random_int(1, 100) > 45) {
                    continue;
                }

                $enteredAt = $day->copy()->setTime($hour, random_int(0, 55), 0);

                if ($enteredAt->isFuture()) {
                    continue;
                }

                $this->recordVisit($plate, $enteredAt, random_int(20, 70));
            }
        }
    }

    /**
     * Plates that re-enter several times today, which Security counts as
     * multiple entries.
     */
    protected function seedMultiEntryPlates(): void
    {
        // Spread the entries back from now rather than forward from midnight,
        // so the panel is populated whatever time of day the seeder runs.
        $minutesElapsed = max(90, now()->startOfDay()->diffInMinutes(now()));

        foreach (PlateFaker::multiEntry() as $plate) {
            $entries = random_int(3, 4);
            $gap = (int) floor($minutesElapsed / ($entries + 1));

            for ($i = 0; $i < $entries; $i++) {
                $enteredAt = now()->subMinutes($gap * ($i + 1));

                $this->recordVisit($plate, $enteredAt, random_int(10, min(45, $gap - 5)));
            }
        }
    }

    /**
     * A few vehicles whose exit was never captured, so the Security page can
     * report the camera coverage gap that MatchVisits would otherwise orphan.
     */
    protected function seedOrphanedVisits(CarbonInterface $from): void
    {
        for ($i = 0; $i < 3; $i++) {
            $enteredAt = $from->copy()
                ->addDays(random_int(0, 3))
                ->setTime(random_int(9, 18), random_int(0, 59), 0);

            if ($enteredAt->isFuture()) {
                continue;
            }

            $plate = PlateFaker::shopper();

            $this->events[] = $this->event($this->entranceCameras->random(), $plate, $enteredAt, PlateDirection::In);

            $this->visits[] = [
                'site_id' => $this->site->getKey(),
                'plate_number' => $plate,
                'entered_at' => $enteredAt,
                'exited_at' => null,
                'dwell_minutes' => null,
                'status' => VisitStatus::Orphaned->value,
                'created_at' => $enteredAt,
                'updated_at' => $enteredAt,
            ];
        }
    }

    /**
     * Vehicles still on site right now, including several past the dwell
     * threshold so the Security page has live rows on first run.
     */
    protected function seedCurrentlyOnSite(): void
    {
        // Well over any threshold.
        foreach (PlateFaker::overThreshold() as $plate) {
            $this->recordVisit($plate, now()->subMinutes(random_int(290, 420)), null);
        }

        // Ordinary shoppers mid-visit.
        for ($i = 0; $i < 14; $i++) {
            $this->recordVisit(PlateFaker::shopper(), now()->subMinutes(random_int(5, 70)), null);
        }
    }

    /**
     * Queue an entry event, an optional exit event, and the visit joining them.
     */
    protected function recordVisit(string $plate, CarbonInterface $enteredAt, ?int $dwellMinutes): void
    {
        $entranceCamera = $this->entranceCameras->random();

        $this->events[] = $this->event($entranceCamera, $plate, $enteredAt, PlateDirection::In);

        $exitedAt = $dwellMinutes === null ? null : $enteredAt->copy()->addMinutes($dwellMinutes);

        if ($exitedAt !== null && ! $exitedAt->isFuture()) {
            $this->events[] = $this->event($this->exitCameras->random(), $plate, $exitedAt, PlateDirection::Out);
        } else {
            // Still on site: no exit event and no dwell yet.
            $exitedAt = null;
            $dwellMinutes = null;
        }

        $this->visits[] = [
            'site_id' => $this->site->getKey(),
            'plate_number' => $plate,
            'entered_at' => $enteredAt,
            'exited_at' => $exitedAt,
            'dwell_minutes' => $dwellMinutes,
            'status' => ($exitedAt === null ? VisitStatus::Open : VisitStatus::Closed)->value,
            'created_at' => $enteredAt,
            'updated_at' => $exitedAt ?? $enteredAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function event(Camera $camera, string $plate, CarbonInterface $at, PlateDirection $direction): array
    {
        return [
            'camera_id' => $camera->getKey(),
            'plate_number' => $plate,
            'direction' => $direction->value,
            'captured_at' => $at,
            'confidence' => round(random_int(78, 99) / 100, 2),
            'raw_payload' => null,
            // Demo events arrive already matched, so MatchVisits has nothing
            // left to do with them.
            'processed_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    protected function randomDwell(): int
    {
        $total = array_sum(array_column(self::DWELL_BUCKETS, 2));
        $roll = random_int(1, $total);

        foreach (self::DWELL_BUCKETS as [$min, $max, $weight]) {
            $roll -= $weight;

            if ($roll <= 0) {
                return random_int($min, $max);
            }
        }

        return 30;
    }

    /**
     * Bulk insert, chunked to stay well inside the parameter limit.
     */
    protected function flush(): void
    {
        // The same plate can legitimately be captured twice at one instant in
        // generated data; drop those so the dedupe index holds.
        $seen = [];
        $events = [];

        foreach ($this->events as $event) {
            $key = $event['camera_id'].'|'.$event['plate_number'].'|'.$event['captured_at']->toDateTimeString();

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $events[] = $event;
        }

        foreach (array_chunk($events, 1000) as $chunk) {
            DB::table('plate_events')->insert($chunk);
        }

        foreach (array_chunk($this->visits, 1000) as $chunk) {
            DB::table('visits')->insert($chunk);
        }

        $this->events = [];
        $this->visits = [];
    }

    /**
     * Point each visit at the plate events it was built from, matching on
     * site, plate and timestamp.
     */
    protected function linkVisitsToEvents(): void
    {
        foreach ([['entry_event_id', 'entered_at', 'in'], ['exit_event_id', 'exited_at', 'out']] as [$column, $timeColumn, $direction]) {
            DB::statement(<<<SQL
                UPDATE visits v
                SET {$column} = pe.id
                FROM plate_events pe
                JOIN cameras c ON c.id = pe.camera_id
                WHERE v.site_id = ?
                  AND c.site_id = v.site_id
                  AND pe.plate_number = v.plate_number
                  AND pe.direction = ?
                  AND pe.captured_at = v.{$timeColumn}
                  AND v.{$column} IS NULL
            SQL, [$this->site->getKey(), $direction]);
        }
    }
}
