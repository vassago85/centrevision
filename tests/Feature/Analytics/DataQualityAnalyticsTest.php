<?php

use App\Enums\PlateDirection;
use App\Enums\VisitStatus;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Support\Analytics\DataQualityAnalytics;
use App\Support\Analytics\DateRange;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow('2026-08-24 15:00:00');

    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create();
    $this->entrance = Camera::factory()->for($this->site)->entrance()->create([
        'last_event_at' => now()->subMinutes(2),
    ]);
    $this->exit = Camera::factory()->for($this->site)->exit()->create([
        'last_event_at' => now()->subHours(4),
    ]);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $this->quality = app(DataQualityAnalytics::class);
    $this->range = DateRange::make('today');
});

it('reports pairing quality from reads and closed visits', function () {
    $entry = PlateEvent::factory()->for($this->entrance)->create([
        'plate_number' => 'PAIR0001',
        'direction' => PlateDirection::In,
        'captured_at' => Date::now()->subHours(2),
    ]);
    $exit = PlateEvent::factory()->for($this->exit)->create([
        'plate_number' => 'PAIR0001',
        'direction' => PlateDirection::Out,
        'captured_at' => Date::now()->subHour(),
    ]);
    PlateEvent::factory()->for($this->entrance)->create([
        'plate_number' => 'ORPHAN01',
        'direction' => PlateDirection::In,
        'captured_at' => Date::now()->subMinutes(30),
    ]);

    Visit::factory()->for($this->site)->create([
        'plate_number' => 'PAIR0001',
        'entry_event_id' => $entry->id,
        'exit_event_id' => $exit->id,
        'entered_at' => $entry->captured_at,
        'exited_at' => $exit->captured_at,
        'dwell_minutes' => 60,
        'status' => VisitStatus::Closed,
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'ORPHAN01',
        'entry_event_id' => PlateEvent::query()->where('plate_number', 'ORPHAN01')->value('id'),
        'entered_at' => Date::now()->subMinutes(30),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Orphaned,
    ]);

    $summary = $this->quality->summary($this->range);

    expect($summary['reads'])->toBe(3)
        ->and($summary['paired_visits'])->toBe(1)
        ->and($summary['orphan_entries'])->toBe(1)
        ->and($summary['pairing_quality'])->toBe(66.7)
        ->and($summary['cameras_offline'])->toBe(1);
});
