<?php

use App\Enums\PlateDirection;
use App\Enums\VisitStatus;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Site;
use App\Models\Visit;

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->entrance = Camera::factory()->for($this->site)->entrance()->create();
});

it('re-pairs an entry that was stamped processed but never became a visit', function () {
    // Two entries by the same plate, minutes apart. The first one paired
    // correctly and produced a visit; the second was stamped processed by
    // the old MatchVisits logic (which silently skipped re-entries) but
    // never got a visit.
    $first = PlateEvent::factory()->for($this->entrance)->create([
        'plate_number' => 'FF98ZTGP',
        'direction' => PlateDirection::In,
        'captured_at' => now()->subHours(2),
        'processed_at' => now()->subHours(2),
    ]);

    Visit::factory()->for($this->site)->create([
        'plate_number' => 'FF98ZTGP',
        'entry_event_id' => $first->id,
        'entered_at' => $first->captured_at,
        'status' => VisitStatus::Open,
    ]);

    $orphan = PlateEvent::factory()->for($this->entrance)->create([
        'plate_number' => 'FF98ZTGP',
        'direction' => PlateDirection::In,
        'captured_at' => now()->subMinutes(30),
        'processed_at' => now()->subMinutes(29),
    ]);

    $this->artisan('visits:replay-unpaired')->assertSuccessful();

    $visits = Visit::query()->where('plate_number', 'FF98ZTGP')->orderBy('entered_at')->get();

    // The old visit is retired and a new one is opened for the previously
    // orphaned entry — same behaviour as if the event were fresh.
    expect($visits)->toHaveCount(2)
        ->and($visits[0]->status)->toBe(VisitStatus::Orphaned)
        ->and($visits[1]->entry_event_id)->toBe($orphan->id)
        ->and($visits[1]->status)->toBe(VisitStatus::Open);
});

it('leaves paired entries alone', function () {
    $entry = PlateEvent::factory()->for($this->entrance)->create([
        'plate_number' => 'PAIRED01',
        'direction' => PlateDirection::In,
        'captured_at' => now()->subHours(1),
        'processed_at' => now()->subHours(1),
    ]);

    Visit::factory()->for($this->site)->create([
        'plate_number' => 'PAIRED01',
        'entry_event_id' => $entry->id,
        'entered_at' => $entry->captured_at,
        'status' => VisitStatus::Open,
    ]);

    $this->artisan('visits:replay-unpaired')->expectsOutputToContain('No unpaired entries')->assertSuccessful();

    expect(Visit::query()->count())->toBe(1);
});

it('the dry-run reports without changing anything', function () {
    PlateEvent::factory()->for($this->entrance)->create([
        'plate_number' => 'DRY0001GP',
        'direction' => PlateDirection::In,
        'captured_at' => now()->subMinutes(20),
        'processed_at' => now()->subMinutes(19),
    ]);

    $this->artisan('visits:replay-unpaired', ['--dry-run' => true])->assertSuccessful();

    expect(PlateEvent::query()->whereNotNull('processed_at')->count())->toBe(1)
        ->and(Visit::query()->count())->toBe(0);
});
