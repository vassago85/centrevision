<?php

use App\Enums\VisitStatus;
use App\Jobs\MatchVisits;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Site;
use App\Models\Visit;

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->entrance = Camera::factory()->entrance()->create(['site_id' => $this->site->id]);
    $this->exit = Camera::factory()->exit()->create(['site_id' => $this->site->id]);
});

it('pairs an entry and an exit into a closed visit', function () {
    $enteredAt = now()->subMinutes(75);
    $exitedAt = now()->subMinutes(30);

    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering($enteredAt)->create();
    PlateEvent::factory()->for($this->exit)->plateNumber('JD45GP')->exiting($exitedAt)->create();

    MatchVisits::dispatchSync();

    $visit = Visit::query()->sole();

    expect($visit->status)->toBe(VisitStatus::Closed)
        ->and($visit->plate_number)->toBe('JD45GP')
        ->and($visit->dwell_minutes)->toBe(45)
        ->and($visit->entered_at->toDateTimeString())->toBe($enteredAt->toDateTimeString())
        ->and($visit->exited_at->toDateTimeString())->toBe($exitedAt->toDateTimeString())
        ->and($visit->entry_event_id)->not->toBeNull()
        ->and($visit->exit_event_id)->not->toBeNull();
});

it('leaves a visit open when no exit has been seen', function () {
    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subMinutes(20))->create();

    MatchVisits::dispatchSync();

    $visit = Visit::query()->sole();

    expect($visit->status)->toBe(VisitStatus::Open)
        ->and($visit->exited_at)->toBeNull()
        ->and($visit->dwell_minutes)->toBeNull();
});

it('marks events processed so a second run does not reconsider them', function () {
    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHour())->create();
    PlateEvent::factory()->for($this->exit)->plateNumber('JD45GP')->exiting(now()->subMinutes(10))->create();

    MatchVisits::dispatchSync();
    MatchVisits::dispatchSync();

    expect(Visit::query()->count())->toBe(1)
        ->and(PlateEvent::query()->whereNull('processed_at')->count())->toBe(0);
});

it('does not reopen a visit from an exit event replayed on a later run', function () {
    // An exit with no entry is dropped, but must still be stamped processed so
    // it is not reconsidered forever.
    PlateEvent::factory()->for($this->exit)->plateNumber('BX91GP')->exiting(now()->subMinutes(5))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->count())->toBe(0)
        ->and(PlateEvent::query()->whereNull('processed_at')->count())->toBe(0);
});

it('treats a second entrance read as the same visit, not a new one', function () {
    $other = Camera::factory()->entrance()->create(['site_id' => $this->site->id]);

    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subMinutes(40))->create();
    PlateEvent::factory()->for($other)->plateNumber('JD45GP')->entering(now()->subMinutes(39))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->count())->toBe(1)
        ->and(Visit::query()->sole()->status)->toBe(VisitStatus::Open);
});

it('starts a fresh visit when the plate returns after leaving', function () {
    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHours(6))->create();
    PlateEvent::factory()->for($this->exit)->plateNumber('JD45GP')->exiting(now()->subHours(5))->create();
    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHours(2))->create();
    PlateEvent::factory()->for($this->exit)->plateNumber('JD45GP')->exiting(now()->subHour())->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->count())->toBe(2)
        ->and(Visit::query()->closed()->count())->toBe(2);
});

it('orphans a visit that outlives the site threshold', function () {
    $this->site->update(['settings' => ['orphan_after_hours' => 4]]);

    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHours(9))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->sole()->status)->toBe(VisitStatus::Orphaned);
});

it('leaves a visit open when it is still inside the threshold', function () {
    $this->site->update(['settings' => ['orphan_after_hours' => 12]]);

    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHours(9))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->sole()->status)->toBe(VisitStatus::Open);
});

it('does not close a visit with an exit recorded before the entry', function () {
    PlateEvent::factory()->for($this->exit)->plateNumber('JD45GP')->exiting(now()->subHours(3))->create();
    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHour())->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->sole()->status)->toBe(VisitStatus::Open);
});

it('ignores events from a camera that cannot tell direction', function () {
    $both = Camera::factory()->create(['site_id' => $this->site->id]);

    PlateEvent::factory()->for($both)->plateNumber('JD45GP')->create(['direction' => null]);

    MatchVisits::dispatchSync();

    expect(Visit::query()->count())->toBe(0);
});

it('keeps sites separate', function () {
    $otherSite = Site::factory()->create();
    $otherEntrance = Camera::factory()->entrance()->create(['site_id' => $otherSite->id]);

    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHour())->create();
    PlateEvent::factory()->for($otherEntrance)->plateNumber('JD45GP')->entering(now()->subHour())->create();
    PlateEvent::factory()->for($this->exit)->plateNumber('JD45GP')->exiting(now()->subMinutes(20))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->where('site_id', $this->site->id)->sole()->status)->toBe(VisitStatus::Closed)
        ->and(Visit::query()->where('site_id', $otherSite->id)->sole()->status)->toBe(VisitStatus::Open);
});

it('can be limited to one site', function () {
    $otherSite = Site::factory()->create();
    $otherEntrance = Camera::factory()->entrance()->create(['site_id' => $otherSite->id]);

    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHour())->create();
    PlateEvent::factory()->for($otherEntrance)->plateNumber('HK12GP')->entering(now()->subHour())->create();

    MatchVisits::dispatchSync($this->site->id);

    expect(Visit::query()->count())->toBe(1)
        ->and(Visit::query()->sole()->site_id)->toBe($this->site->id);
});
