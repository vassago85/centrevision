<?php

use App\Enums\PlateDirection;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\Site;
use App\Models\User;
use App\Support\Analytics\SecurityLogExporter;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);
    $this->camera = Camera::factory()->for($this->site)->entrance()->create(['name' => 'North entrance']);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
});

function capturedCsv(Site $site, \Carbon\CarbonInterface $date): string
{
    $response = app(SecurityLogExporter::class)->streamDay($site, $date);

    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

it('exports every plate detection for the day as CSV', function () {
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'JD45GP',
        'direction' => PlateDirection::In,
        'captured_at' => Date::now()->startOfDay()->addHours(8)->addMinutes(15),
    ]);

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'JD45GP',
        'direction' => PlateDirection::Out,
        'captured_at' => Date::now()->startOfDay()->addHours(9)->addMinutes(30),
    ]);

    $csv = capturedCsv($this->site, Date::now());

    expect($csv)
        ->toContain('Time,Plate,Camera,Direction,Confidence')
        ->toContain('JD45GP')
        ->toContain('North entrance')
        ->toContain('in')
        ->toContain('out');
});

it('excludes plate events from other sites', function () {
    $otherSite = Site::factory()->create();
    $otherCamera = Camera::factory()->for($otherSite)->entrance()->create();

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'MINE01GP',
        'captured_at' => Date::now()->startOfDay()->addHours(10),
    ]);

    PlateEvent::factory()->for($otherCamera)->create([
        'plate_number' => 'THEIRS1GP',
        'captured_at' => Date::now()->startOfDay()->addHours(10),
    ]);

    $csv = capturedCsv($this->site, Date::now());

    expect($csv)->toContain('MINE01GP')
        ->not->toContain('THEIRS1GP');
});

it('does not include events from other days', function () {
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'YESTER1GP',
        'captured_at' => Date::now()->subDay()->setTime(14, 0),
    ]);

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'TODAY01GP',
        'captured_at' => Date::now()->startOfDay()->addHours(11),
    ]);

    $csv = capturedCsv($this->site, Date::now());

    expect($csv)->toContain('TODAY01GP')
        ->not->toContain('YESTER1GP');
});

it('exports plate events for an arbitrary historic day', function () {
    // Three events on three consecutive days. Requesting the middle day
    // should return only that day's events.
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'DAY1AA1GP',
        'captured_at' => Date::now()->subDays(3)->setTime(10, 0),
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'DAY2BB2GP',
        'captured_at' => Date::now()->subDays(2)->setTime(10, 0),
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'DAY3CC3GP',
        'captured_at' => Date::now()->subDay()->setTime(10, 0),
    ]);

    $csv = capturedCsv($this->site, Date::now()->subDays(2));

    expect($csv)->toContain('DAY2BB2GP')
        ->not->toContain('DAY1AA1GP')
        ->not->toContain('DAY3CC3GP');
});
