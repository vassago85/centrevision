<?php

use App\Enums\PlateDirection;
use App\Enums\PlateTagType;
use App\Enums\VisitStatus;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\PlateTag;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Support\Analytics\SecurityAnalytics;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create();
    $this->camera = Camera::factory()->for($this->site)->entrance()->create(['name' => 'North entrance']);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $this->security = app(SecurityAnalytics::class);
});

it('lists open visits past the threshold, longest first', function () {
    $long = Visit::factory()->for($this->site)->create([
        'plate_number' => 'BX91GP',
        'entered_at' => Date::now()->subHours(7),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    Visit::factory()->for($this->site)->create([
        'plate_number' => 'KT07GP',
        'entered_at' => Date::now()->subHours(5),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    // Under the threshold, and a closed visit that is no longer on site.
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'SHORT1GP',
        'entered_at' => Date::now()->subHours(1),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    Visit::factory()->for($this->site)->create([
        'plate_number' => 'GONE1GP',
        'entered_at' => Date::now()->subHours(9),
        'exited_at' => Date::now()->subHour(),
        'dwell_minutes' => 480,
        'status' => VisitStatus::Closed,
    ]);

    $rows = $this->security->overThreshold(4);

    expect($rows->pluck('plate_number')->all())->toBe(['BX91GP', 'KT07GP'])
        ->and($rows->first()->is($long))->toBeTrue();
});

it('still shows staff plates to security', function () {
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'STAFF001',
        'entered_at' => Date::now()->subHours(8),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    PlateTag::create([
        'site_id' => $this->site->id,
        'plate_number' => 'STAFF001',
        'tag' => PlateTagType::RecurringPattern,
        'tagged_at' => now(),
    ]);

    expect($this->security->overThreshold(4)->pluck('plate_number')->all())->toBe(['STAFF001']);
});

it('flags plates seen in the small hours on more than one day', function () {
    // Two nights at roughly 23:30.
    foreach ([1, 3] as $daysAgo) {
        PlateEvent::factory()->for($this->camera)->create([
            'plate_number' => 'TZ18GP',
            'direction' => PlateDirection::In,
            'captured_at' => Date::now()->subDays($daysAgo)->setTime(23, 30),
        ]);
    }

    // A single late night is not a pattern.
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'ONCE01GP',
        'direction' => PlateDirection::In,
        'captured_at' => Date::now()->subDays(2)->setTime(23, 45),
    ]);

    // Daytime traffic is not odd-hour at all.
    foreach ([1, 2] as $daysAgo) {
        PlateEvent::factory()->for($this->camera)->create([
            'plate_number' => 'DAY001GP',
            'direction' => PlateDirection::In,
            'captured_at' => Date::now()->subDays($daysAgo)->setTime(14, 0),
        ]);
    }

    $rows = $this->security->oddHourRecurring();

    expect($rows->pluck('plate_number')->all())->toBe(['TZ18GP'])
        ->and($rows->first()['days'])->toBe(2)
        ->and($rows->first()['typical_time'])->toBe('23:30');
});

it('averages odd-hour arrival times across midnight without landing at noon', function () {
    // 23:00 one night and 01:00 the next averages to midnight, not midday.
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'MP63GP',
        'direction' => PlateDirection::In,
        'captured_at' => Date::now()->subDays(3)->setTime(23, 0),
    ]);

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'MP63GP',
        'direction' => PlateDirection::In,
        'captured_at' => Date::now()->subDays(2)->setTime(1, 0),
    ]);

    expect($this->security->oddHourRecurring()->first()['typical_time'])->toBe('00:00');
});

it('counts repeat entries today above the configured threshold', function () {
    foreach ([5, 3, 1] as $hoursAgo) {
        PlateEvent::factory()->for($this->camera)->create([
            'plate_number' => 'JD45GP',
            'direction' => PlateDirection::In,
            'captured_at' => Date::now()->subHours($hoursAgo),
        ]);
    }

    // Two entries is below the threshold of three.
    foreach ([4, 2] as $hoursAgo) {
        PlateEvent::factory()->for($this->camera)->create([
            'plate_number' => 'HK12GP',
            'direction' => PlateDirection::In,
            'captured_at' => Date::now()->subHours($hoursAgo),
        ]);
    }

    // Exits do not count as entries.
    foreach ([5, 4, 3] as $hoursAgo) {
        PlateEvent::factory()->for($this->camera)->create([
            'plate_number' => 'OUT001GP',
            'direction' => PlateDirection::Out,
            'captured_at' => Date::now()->subHours($hoursAgo),
        ]);
    }

    $rows = $this->security->multipleEntriesToday();

    expect($rows->pluck('plate_number')->all())->toBe(['JD45GP'])
        ->and($rows->first()['entries'])->toBe(3);
})->skip(fn () => Date::now()->hour < 6, 'Needs at least six hours elapsed today.');

it('counts recent visits that never recorded an exit', function () {
    Visit::factory()->for($this->site)->create([
        'entered_at' => Date::now()->subDays(2),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Orphaned,
    ]);

    Visit::factory()->for($this->site)->create([
        'entered_at' => Date::now()->subDays(30),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Orphaned,
    ]);

    expect($this->security->orphanedCount())->toBe(1);
});

it('does not leak another owner security data', function () {
    $otherSite = Site::factory()->create();

    Visit::factory()->for($otherSite)->create([
        'plate_number' => 'OTHER1GP',
        'entered_at' => Date::now()->subHours(8),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    expect($this->security->overThreshold(4))->toBeEmpty();
});
