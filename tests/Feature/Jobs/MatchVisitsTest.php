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

    // Two cameras at the same entrance seeing the same drive-through 30
    // seconds apart — should collapse into one visit.
    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subMinutes(40))->create();
    PlateEvent::factory()->for($other)->plateNumber('JD45GP')->entering(now()->subMinutes(40)->addSeconds(30))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->count())->toBe(1)
        ->and(Visit::query()->sole()->status)->toBe(VisitStatus::Open)
        ->and(PlateEvent::query()->orderBy('captured_at')->skip(1)->first()->superseded_by_event_id)
        ->toBe(PlateEvent::query()->orderBy('captured_at')->first()->id);
});

it('pairs an exit that is one substituted character off the entry', function () {
    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHour())->create();
    PlateEvent::factory()->for($this->exit)->plateNumber('JD46GP')->exiting(now()->subMinutes(10))->create();

    MatchVisits::dispatchSync();

    $visit = Visit::query()->sole();

    expect($visit->status)->toBe(VisitStatus::Closed)
        ->and($visit->plate_number)->toBe('JD45GP')
        ->and($visit->exit_event_id)->not->toBeNull();
});

it('pairs an exit that dropped one character from the entry plate', function () {
    PlateEvent::factory()->for($this->entrance)->plateNumber('MX06KHGP')->entering(now()->subHour())->create();
    PlateEvent::factory()->for($this->exit)->plateNumber('M06KHGP')->exiting(now()->subMinutes(10))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->sole()->status)->toBe(VisitStatus::Closed)
        ->and(Visit::query()->sole()->plate_number)->toBe('MX06KHGP');
});

it('pairs an exit that is two characters off the entry', function () {
    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHour())->create();
    PlateEvent::factory()->for($this->exit)->plateNumber('JD46NP')->exiting(now()->subMinutes(10))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->sole()->status)->toBe(VisitStatus::Closed)
        ->and(Visit::query()->sole()->plate_number)->toBe('JD45GP');
});

it('closes the one-character visit when a two-character visit is also open', function () {
    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHour())->create();
    PlateEvent::factory()->for($this->entrance)->plateNumber('JD47NP')->entering(now()->subMinutes(50))->create();
    PlateEvent::factory()->for($this->exit)->plateNumber('JD46GP')->exiting(now()->subMinutes(10))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->where('plate_number', 'JD45GP')->sole()->status)->toBe(VisitStatus::Closed)
        ->and(Visit::query()->where('plate_number', 'JD47NP')->sole()->status)->toBe(VisitStatus::Open);
});

it('does not guess when two open plates are one character from the exit', function () {
    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHour())->create();
    PlateEvent::factory()->for($this->entrance)->plateNumber('JD47GP')->entering(now()->subMinutes(50))->create();
    PlateEvent::factory()->for($this->exit)->plateNumber('JD46GP')->exiting(now()->subMinutes(10))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->where('status', VisitStatus::Open)->count())->toBe(2)
        ->and(Visit::query()->closed()->count())->toBe(0);
});

it('treats a second photo of the same departure as a repeat, not a new exit', function () {
    $leftAt = now()->subMinutes(10);

    PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subHour())->create();
    $exit = PlateEvent::factory()->for($this->exit)->plateNumber('JD45GP')->exiting($leftAt)->create();
    $repeat = PlateEvent::factory()->for($this->exit)->plateNumber('JD46GP')->exiting($leftAt->copy()->addSeconds(2))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->closed()->count())->toBe(1)
        ->and($repeat->fresh()->superseded_by_event_id)->toBe($exit->id);
});

it('collapses a one-character entrance miss on a second camera into the open visit', function () {
    $other = Camera::factory()->entrance()->create(['site_id' => $this->site->id]);

    $first = PlateEvent::factory()->for($this->entrance)->plateNumber('JD45GP')->entering(now()->subMinutes(30))->create();
    $second = PlateEvent::factory()->for($other)->plateNumber('JD46GP')->entering(now()->subMinutes(30)->addSeconds(20))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->count())->toBe(1)
        ->and($second->fresh()->superseded_by_event_id)->toBe($first->id);
});

it('does not open a visit for a camera unknown read', function () {
    PlateEvent::factory()->for($this->entrance)->plateNumber('UNKNOWN')->entering(now()->subMinutes(10))->create();

    MatchVisits::dispatchSync();

    expect(Visit::query()->count())->toBe(0);
});

it('opens a new visit when a plate is re-detected long after entering', function () {
    // Same plate seen entering twice, hours apart, with no exit in between —
    // treat the second reading as a genuine re-arrival and orphan the earlier
    // open visit so the latest entry is the one that shows in "Latest visits".
    PlateEvent::factory()->for($this->entrance)->plateNumber('FF98ZTGP')->entering(now()->subHours(2))->create();
    PlateEvent::factory()->for($this->entrance)->plateNumber('FF98ZTGP')->entering(now()->subMinutes(5))->create();

    MatchVisits::dispatchSync();

    $visits = Visit::query()->orderByDesc('entered_at')->get();

    expect($visits)->toHaveCount(2)
        ->and($visits[0]->status)->toBe(VisitStatus::Open)
        ->and($visits[1]->status)->toBe(VisitStatus::Orphaned);
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

it('does not orphan stale visits on entry-only sites', function () {
    // Entry-only site — remove the exit camera so hasExitTracking() is false.
    // On such sites there will never be an exit event to close a visit, so
    // orphaning by age would silently erase every historic day from the
    // dashboard. The visit must stay Open.
    $this->exit->delete();
    $this->site->update(['settings' => ['orphan_after_hours' => 4]]);

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
