<?php

use App\Enums\VisitStatus;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Support\Analytics\DateRange;
use App\Support\Analytics\OccupancyAnalytics;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow('2026-08-24 15:00:00');

    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create([
        'settings' => ['parking_capacity' => 10],
    ]);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
    app(Tenancy::class)->setCurrentSiteId($this->site->id);

    $this->occupancy = app(OccupancyAnalytics::class);
    $this->range = DateRange::make('today');
});

it('returns nothing when the selected site has no parking capacity', function () {
    $this->site->update(['settings' => []]);

    expect($this->occupancy->summary($this->range))->toBeNull();
});

it('derives peak occupancy from entry and exit state, not raw reads', function () {
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'IN100001',
        'entered_at' => Date::parse('2026-08-24 09:00:00'),
        'exited_at' => Date::parse('2026-08-24 12:00:00'),
        'dwell_minutes' => 180,
        'status' => VisitStatus::Closed,
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'IN100002',
        'entered_at' => Date::parse('2026-08-24 10:00:00'),
        'exited_at' => Date::parse('2026-08-24 11:00:00'),
        'dwell_minutes' => 60,
        'status' => VisitStatus::Closed,
    ]);

    $summary = $this->occupancy->summary($this->range);

    expect($summary['peak'])->toBe(2)
        ->and($summary['peak_at'])->not->toBeNull()
        ->and($summary['capacity'])->toBe(10);
});
