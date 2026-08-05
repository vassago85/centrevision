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

it('ignores orphaned visits on sites that can measure exits', function () {
    // With an exit camera in play, "orphaned" means we opened a visit for an
    // entry but never saw the vehicle leave — a data-quality issue we do not
    // want polluting the shopper figures.
    \App\Models\Camera::factory()->exit()->create(['site_id' => $this->site->id]);

    visitAt($this->site, 'AA11GP', 2);

    Visit::factory()->for($this->site)->create([
        'entered_at' => Date::now()->subHours(3),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Orphaned,
    ]);

    expect($this->analytics->totalVisits($this->range))->toBe(1);
});

it('counts orphaned visits on entry-only sites so yesterday still shows up', function () {
    // No exit camera — every visit will eventually be marked orphaned by the
    // MatchVisits job, but each of those still represents a real arrival
    // that should show on the dashboard.
    \App\Models\Camera::factory()->entrance()->create(['site_id' => $this->site->id]);

    visitAt($this->site, 'AA11GP', 2);

    Visit::factory()->for($this->site)->create([
        'plate_number' => 'BB22GP',
        'entered_at' => Date::now()->subHours(20),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Orphaned,
    ]);

    expect($this->analytics->totalVisits($this->range))->toBe(2);
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

it('counts hourly arrivals for one specific day', function () {
    // Two arrivals today, one yesterday at the same hour so we can prove the
    // day filter is doing its job.
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'TODAY1GP',
        'entered_at' => Date::now()->startOfDay()->addHours(9)->addMinutes(15),
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'TODAY2GP',
        'entered_at' => Date::now()->startOfDay()->addHours(9)->addMinutes(50),
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'YESTER1GP',
        'entered_at' => Date::now()->subDay()->startOfDay()->addHours(9),
    ]);

    $today = $this->analytics->visitsByHourOnDay(Date::now());
    $yesterday = $this->analytics->visitsByHourOnDay(Date::now()->subDay());

    expect($today)->toHaveCount(24)
        ->and($today[9]['count'])->toBe(2)
        ->and($yesterday[9]['count'])->toBe(1);
});

it('lists the last plate detections, entries and exits, showing a re-entry as its own row', function () {
    $entrance = \App\Models\Camera::factory()->for($this->site)->entrance()->create();
    $exitCamera = \App\Models\Camera::factory()->for($this->site)->exit()->create();

    // Same plate seen entering twice, hours apart — the caller needs both
    // rows, not the visits-deduplicated view.
    $first = \App\Models\PlateEvent::factory()->for($entrance)->create([
        'plate_number' => 'FF98ZTGP',
        'direction' => \App\Enums\PlateDirection::In,
        'captured_at' => Date::now()->subHours(3),
    ]);
    $second = \App\Models\PlateEvent::factory()->for($entrance)->create([
        'plate_number' => 'FF98ZTGP',
        'direction' => \App\Enums\PlateDirection::In,
        'captured_at' => Date::now()->subMinutes(20),
    ]);

    // Exits belong in Latest activity too — every detection is a row.
    $exit = \App\Models\PlateEvent::factory()->for($exitCamera)->create([
        'plate_number' => 'GONE001',
        'direction' => \App\Enums\PlateDirection::Out,
        'captured_at' => Date::now()->subMinutes(5),
    ]);

    // The most recent entry has a corresponding open visit → "on site now".
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'FF98ZTGP',
        'entry_event_id' => $second->id,
        'entered_at' => $second->captured_at,
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    $entries = $this->analytics->recentDetections();

    expect($entries)->toHaveCount(3)
        ->and($entries[0]->id)->toBe($exit->id)
        ->and($entries[0]->direction)->toBe(\App\Enums\PlateDirection::Out)
        ->and($entries[1]->id)->toBe($second->id)
        ->and($entries[1]->direction)->toBe(\App\Enums\PlateDirection::In)
        ->and($entries[1]->getAttribute('on_site_now'))->toBeTrue()
        ->and($entries[2]->id)->toBe($first->id)
        // FF98ZTGP has an open visit today, so both of its rows are flagged
        // "on site now" — the pill reflects the plate's current status.
        ->and($entries[2]->getAttribute('on_site_now'))->toBeTrue();
});

it('keeps the deprecated recentEntries() returning entries only', function () {
    $entrance = \App\Models\Camera::factory()->for($this->site)->entrance()->create();

    \App\Models\PlateEvent::factory()->for($entrance)->create([
        'plate_number' => 'ENTRY01',
        'direction' => \App\Enums\PlateDirection::In,
        'captured_at' => Date::now()->subMinutes(30),
    ]);
    \App\Models\PlateEvent::factory()->for($entrance)->create([
        'plate_number' => 'EXIT001',
        'direction' => \App\Enums\PlateDirection::Out,
        'captured_at' => Date::now()->subMinutes(5),
    ]);

    $entries = $this->analytics->recentEntries();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->plate_number)->toBe('ENTRY01');
});

it('counts currently on-site vehicles across every accessible site', function () {
    // One open visit, one closed. Only the open one should be counted.
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'ONSITE01',
        'entered_at' => Date::now()->subMinutes(30),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'GONE001',
        'entered_at' => Date::now()->subHours(4),
        'exited_at' => Date::now()->subHours(2),
        'dwell_minutes' => 120,
        'status' => VisitStatus::Closed,
    ]);

    expect($this->analytics->currentlyOnSite())->toBe(1);
});
