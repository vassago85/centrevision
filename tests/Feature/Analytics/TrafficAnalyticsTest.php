<?php

use App\Enums\PlateTagType;
use App\Enums\VisitStatus;
use App\Models\Organization;
use App\Models\PlateTag;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Support\Analytics\DateRange;
use App\Support\Analytics\TrafficAnalytics;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create();

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $this->analytics = app(TrafficAnalytics::class);
    $this->range = DateRange::make('7d');
});

/**
 * @param  int  $hoursAgo  When the visit started.
 */
function visitAt(Site $site, string $plate, int $hoursAgo, ?int $dwell = 30): Visit
{
    $enteredAt = Date::now()->subHours($hoursAgo);

    return Visit::factory()->for($site)->create([
        'plate_number' => $plate,
        'entered_at' => $enteredAt,
        'exited_at' => $dwell === null ? null : $enteredAt->copy()->addMinutes($dwell),
        'dwell_minutes' => $dwell,
        'status' => $dwell === null ? VisitStatus::Open : VisitStatus::Closed,
    ]);
}

it('counts visits inside the window and ignores older ones', function () {
    visitAt($this->site, 'AA11GP', 2);
    visitAt($this->site, 'BB22GP', 30);
    visitAt($this->site, 'CC33GP', 24 * 40);

    expect($this->analytics->totalVisits($this->range))->toBe(2);
});

it('leaves staff-pattern plates out of every shopper figure', function () {
    visitAt($this->site, 'SHOPPER1', 2);
    visitAt($this->site, 'STAFF001', 2);
    visitAt($this->site, 'STAFF001', 26);

    PlateTag::create([
        'site_id' => $this->site->id,
        'plate_number' => 'STAFF001',
        'tag' => PlateTagType::RecurringPattern,
        'tagged_at' => now(),
    ]);

    expect($this->analytics->totalVisits($this->range))->toBe(1)
        ->and($this->analytics->repeatVisitorPercentage($this->range))->toBe(0.0);
});

it('ignores orphaned visits, which never really happened as measured', function () {
    visitAt($this->site, 'AA11GP', 2);

    Visit::factory()->for($this->site)->create([
        'entered_at' => Date::now()->subHours(3),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Orphaned,
    ]);

    expect($this->analytics->totalVisits($this->range))->toBe(1);
});

it('reports average and median dwell over closed visits only', function () {
    visitAt($this->site, 'AA11GP', 2, 10);
    visitAt($this->site, 'BB22GP', 3, 20);
    visitAt($this->site, 'CC33GP', 4, 60);
    visitAt($this->site, 'DD44GP', 1, null);

    $summary = $this->analytics->dwellSummary($this->range);

    expect($summary['average'])->toBe(30)
        ->and($summary['median'])->toBe(20);
});

it('zero-fills all twenty four hours', function () {
    visitAt($this->site, 'AA11GP', 2);

    $hours = $this->analytics->visitsByHour($this->range);

    expect($hours)->toHaveCount(24)
        ->and($hours->sum('count'))->toBe(1)
        ->and($hours->pluck('hour')->all())->toBe(range(0, 23));
});

it('finds the peak hour and returns null when nothing happened', function () {
    $quiet = $this->analytics->peakHour($this->range);

    expect($quiet)->toBeNull();

    $hour = Date::now()->subHours(2)->hour;

    visitAt($this->site, 'AA11GP', 2);
    visitAt($this->site, 'BB22GP', 2);
    visitAt($this->site, 'CC33GP', 5);

    expect($this->analytics->peakHour($this->range))
        ->toMatchArray(['hour' => $hour, 'count' => 2]);
});

it('buckets dwell times and reports each share', function () {
    visitAt($this->site, 'AA11GP', 2, 5);
    visitAt($this->site, 'BB22GP', 2, 20);
    visitAt($this->site, 'CC33GP', 2, 25);
    visitAt($this->site, 'DD44GP', 2, 200);

    $buckets = $this->analytics->dwellDistribution($this->range)->keyBy('label');

    expect($buckets['<15m']['count'])->toBe(1)
        ->and($buckets['15-30m']['count'])->toBe(2)
        ->and($buckets['15-30m']['percent'])->toBe(50.0)
        ->and($buckets['2h+']['count'])->toBe(1)
        ->and($buckets['30-45m']['count'])->toBe(0);
});

it('averages busiest days rather than totalling them', function () {
    // Two visits on one weekday a week apart is one per day, not two.
    $monday = Date::now()->startOfWeek();

    foreach ([$monday, $monday->copy()->subWeek()] as $day) {
        Visit::factory()->for($this->site)->create([
            'entered_at' => $day->copy()->setTime(12, 0),
            'exited_at' => $day->copy()->setTime(12, 30),
            'dwell_minutes' => 30,
            'status' => VisitStatus::Closed,
        ]);
    }

    $days = app(TrafficAnalytics::class)
        ->busiestDays(DateRange::make('30d'))
        ->keyBy('label');

    expect($days['Mon']['count'])->toBe(1);
});

it('computes the share of plates that came back', function () {
    visitAt($this->site, 'AA11GP', 2);
    visitAt($this->site, 'AA11GP', 26);
    visitAt($this->site, 'BB22GP', 3);
    visitAt($this->site, 'CC33GP', 4);
    visitAt($this->site, 'DD44GP', 5);

    expect($this->analytics->repeatVisitorPercentage($this->range))->toBe(25.0);
});

it('returns no delta when the previous period had no traffic', function () {
    visitAt($this->site, 'AA11GP', 2);

    expect($this->analytics->visitsDelta($this->range))->toBeNull();
});

it('compares against the equivalent preceding window', function () {
    // Four this week against two in the week before.
    foreach ([2, 3, 4, 5] as $hours) {
        visitAt($this->site, 'A'.$hours.'GP', $hours);
    }

    foreach ([24 * 8, 24 * 9] as $hours) {
        visitAt($this->site, 'B'.$hours.'GP', $hours);
    }

    expect($this->analytics->visitsDelta($this->range))->toBe(100.0);
});

it('zero-fills days with no traffic', function () {
    visitAt($this->site, 'AA11GP', 2);

    $days = $this->analytics->visitsByDay($this->range);

    expect($days)->toHaveCount(7)
        ->and($days->sum('count'))->toBe(1)
        ->and($days->last()['count'])->toBe(1);
});
