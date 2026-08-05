<?php

use App\Enums\PlateDirection;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Sheffield']);
    $this->camera = Camera::factory()->for($this->site)->entrance()->create(['name' => 'Main Gate']);
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
});

it('lists cameras with events on the requested day', function () {
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'AAA111',
        'direction' => PlateDirection::In,
        'captured_at' => now()->startOfDay()->addHours(9),
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'BBB222',
        'direction' => PlateDirection::In,
        'captured_at' => now()->startOfDay()->addHours(14),
    ]);

    $this->artisan('camera:activity')
        ->expectsOutputToContain('Main Gate')
        ->expectsOutputToContain('parsed events: 2')
        ->assertSuccessful();
});

it('hides quiet cameras by default and reveals them with --all', function () {
    // No events, no files, no activity today.
    $this->artisan('camera:activity')
        ->expectsOutputToContain('No camera produced any activity')
        ->assertSuccessful();

    $this->artisan('camera:activity', ['--all' => true])
        ->expectsOutputToContain('Main Gate')
        ->assertSuccessful();
});

it('counts files sitting in the inbox and warns when the queue is backed up', function () {
    Storage::disk('local')->put(
        'hikvision-webhook-inbox/'.$this->camera->id.'/'.\Illuminate\Support\Str::ulid().'.bin',
        'raw body',
    );

    $this->artisan('camera:activity')
        ->expectsOutputToContain('Inbox is not empty')
        ->assertSuccessful();
});

it('counts files parked in quarantine and warns about parser failures', function () {
    Storage::disk('local')->put(
        'hikvision-webhook-quarantine/'.$this->camera->id.'/'.\Illuminate\Support\Str::ulid().'.bin',
        'unparseable',
    );

    $this->artisan('camera:activity')
        ->expectsOutputToContain('Quarantined payloads mean')
        ->assertSuccessful();
});

it('lists every event with plate and direction when --list is set', function () {
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'FF98ZTGP',
        'direction' => PlateDirection::In,
        'captured_at' => now()->startOfDay()->addHours(7)->addMinutes(15),
        'confidence' => 0.92,
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'BY96NWGP',
        'direction' => PlateDirection::In,
        'captured_at' => now()->startOfDay()->addHours(7)->addMinutes(32),
        'confidence' => 0.87,
    ]);

    $this->artisan('camera:activity', ['--list' => true])
        ->expectsOutputToContain('Events in capture order')
        ->expectsOutputToContain('FF98ZTGP')
        ->expectsOutputToContain('BY96NWGP')
        ->expectsOutputToContain('In')
        ->assertSuccessful();
});

it('caps --list output at --limit and reports the total', function () {
    for ($i = 0; $i < 5; $i++) {
        PlateEvent::factory()->for($this->camera)->create([
            'plate_number' => 'AAA'.$i.'11',
            'direction' => PlateDirection::In,
            'captured_at' => now()->startOfDay()->addHours(8)->addMinutes($i),
        ]);
    }

    $this->artisan('camera:activity', ['--list' => true, '--limit' => 2])
        ->expectsOutputToContain('Showing first 2 of 5 events')
        ->assertSuccessful();
});

it('scopes to a single camera when --camera is provided', function () {
    $otherCamera = Camera::factory()->for($this->site)->entrance()->create(['name' => 'Back Gate']);

    PlateEvent::factory()->for($this->camera)->create([
        'direction' => PlateDirection::In,
        'captured_at' => now(),
    ]);
    PlateEvent::factory()->for($otherCamera)->create([
        'direction' => PlateDirection::In,
        'captured_at' => now(),
    ]);

    $this->artisan('camera:activity', ['--camera' => $this->camera->id])
        ->expectsOutputToContain('Main Gate')
        ->doesntExpectOutputToContain('Back Gate')
        ->assertSuccessful();
});
