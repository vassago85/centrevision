<?php

use App\Enums\PlateDirection;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\Site;
use App\Models\User;
use App\Support\Analytics\PlateActivityLog;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create();
    $this->camera = Camera::factory()->for($this->site)->entrance()->create(['name' => 'North']);
    $this->exitCamera = Camera::factory()->for($this->site)->exit()->create(['name' => 'South']);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $this->log = app(PlateActivityLog::class);
});

it('returns events inside the window, most recent first', function () {
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'ONE0001',
        'captured_at' => Date::now()->subHours(2),
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'TWO0002',
        'captured_at' => Date::now()->subMinutes(15),
    ]);

    $result = $this->log->paginate(
        Date::now()->subDay()->startOfDay(),
        Date::now()->endOfDay(),
    );

    expect($result->total())->toBe(2)
        ->and($result->items()[0]->plate_number)->toBe('TWO0002');
});

it('drops events outside the window', function () {
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'INSIDE1',
        'captured_at' => Date::now()->subHour(),
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'BEFORE1',
        'captured_at' => Date::now()->subDays(10),
    ]);

    $result = $this->log->paginate(
        Date::now()->subDays(2)->startOfDay(),
        Date::now()->endOfDay(),
    );

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->plate_number)->toBe('INSIDE1');
});

it('applies a camera filter', function () {
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'ATNORTH',
        'captured_at' => Date::now()->subHour(),
    ]);
    PlateEvent::factory()->for($this->exitCamera)->create([
        'plate_number' => 'ATSOUTH',
        'captured_at' => Date::now()->subHour(),
    ]);

    $result = $this->log->paginate(
        Date::now()->subDay()->startOfDay(),
        Date::now()->endOfDay(),
        cameraId: $this->camera->id,
    );

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->plate_number)->toBe('ATNORTH');
});

it('normalises plate search across common formats', function () {
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'JD45GP',
        'direction' => PlateDirection::In,
        'captured_at' => Date::now()->subHour(),
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'KT07GP',
        'captured_at' => Date::now()->subHour(),
    ]);

    $result = $this->log->paginate(
        Date::now()->subDay()->startOfDay(),
        Date::now()->endOfDay(),
        plateNumber: 'jd 45 gp',
    );

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->plate_number)->toBe('JD45GP');
});

it('scopes results to the tenant sites', function () {
    $foreignSite = Site::factory()->create();
    $foreignCamera = Camera::factory()->for($foreignSite)->entrance()->create();
    PlateEvent::factory()->for($foreignCamera)->create([
        'plate_number' => 'THEIRS1',
        'captured_at' => Date::now()->subHour(),
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'MINE001',
        'captured_at' => Date::now()->subHour(),
    ]);

    $result = $this->log->paginate(
        Date::now()->subDay()->startOfDay(),
        Date::now()->endOfDay(),
    );

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->plate_number)->toBe('MINE001');
});

it('counts distinct plates independent of event count', function () {
    // Same plate seen three times — should collapse to a single distinct
    // plate in the summary metric.
    foreach (range(1, 3) as $hoursAgo) {
        PlateEvent::factory()->for($this->camera)->create([
            'plate_number' => 'SAME001',
            'captured_at' => Date::now()->subHours($hoursAgo),
        ]);
    }
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'DIFF002',
        'captured_at' => Date::now()->subHour(),
    ]);

    $unique = $this->log->uniquePlates(
        Date::now()->subDay()->startOfDay(),
        Date::now()->endOfDay(),
    );

    expect($unique)->toBe(2);
});
