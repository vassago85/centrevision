<?php

use App\Jobs\PrunePlateData;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Site;
use App\Models\Visit;

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->camera = Camera::factory()->entrance()->create(['site_id' => $this->site->id]);
});

it('deletes plate data past the retention window', function () {
    $this->site->update(['settings' => ['retention_days' => 90]]);

    PlateEvent::factory()->for($this->camera)->at(now()->subDays(120))->create();
    Visit::factory()->for($this->site)->create(['entered_at' => now()->subDays(120)]);

    PrunePlateData::dispatchSync();

    expect(PlateEvent::query()->count())->toBe(0)
        ->and(Visit::query()->count())->toBe(0);
});

it('keeps plate data inside the retention window', function () {
    $this->site->update(['settings' => ['retention_days' => 90]]);

    PlateEvent::factory()->for($this->camera)->at(now()->subDays(30))->create();
    Visit::factory()->for($this->site)->create(['entered_at' => now()->subDays(30)]);

    PrunePlateData::dispatchSync();

    expect(PlateEvent::query()->count())->toBe(1)
        ->and(Visit::query()->count())->toBe(1);
});

it('honours a shorter retention window set by the site', function () {
    $this->site->update(['settings' => ['retention_days' => 30]]);

    PlateEvent::factory()->for($this->camera)->at(now()->subDays(45))->create();
    PlateEvent::factory()->for($this->camera)->at(now()->subDays(10))->create();

    PrunePlateData::dispatchSync();

    expect(PlateEvent::query()->count())->toBe(1);
});

it('will not let a site retain data past the platform ceiling', function () {
    $this->site->update(['settings' => ['retention_days' => 5000]]);

    PlateEvent::factory()->for($this->camera)->at(now()->subDays(1200))->create();

    PrunePlateData::dispatchSync();

    expect(PlateEvent::query()->count())->toBe(0);
});

it('will not let a site set a retention window below the floor', function () {
    $this->site->update(['settings' => ['retention_days' => 1]]);

    PlateEvent::factory()->for($this->camera)->at(now()->subDays(10))->create();

    PrunePlateData::dispatchSync();

    expect(PlateEvent::query()->count())->toBe(1);
});

it('prunes each site against its own window', function () {
    $otherSite = Site::factory()->create(['settings' => ['retention_days' => 365]]);
    $otherCamera = Camera::factory()->entrance()->create(['site_id' => $otherSite->id]);

    $this->site->update(['settings' => ['retention_days' => 30]]);

    PlateEvent::factory()->for($this->camera)->at(now()->subDays(60))->create();
    PlateEvent::factory()->for($otherCamera)->at(now()->subDays(60))->create();

    PrunePlateData::dispatchSync();

    expect(PlateEvent::query()->where('camera_id', $this->camera->id)->count())->toBe(0)
        ->and(PlateEvent::query()->where('camera_id', $otherCamera->id)->count())->toBe(1);
});

it('can be limited to one site', function () {
    $otherSite = Site::factory()->create(['settings' => ['retention_days' => 30]]);
    $otherCamera = Camera::factory()->entrance()->create(['site_id' => $otherSite->id]);

    $this->site->update(['settings' => ['retention_days' => 30]]);

    PlateEvent::factory()->for($this->camera)->at(now()->subDays(60))->create();
    PlateEvent::factory()->for($otherCamera)->at(now()->subDays(60))->create();

    PrunePlateData::dispatchSync($this->site->id);

    expect(PlateEvent::query()->where('camera_id', $this->camera->id)->count())->toBe(0)
        ->and(PlateEvent::query()->where('camera_id', $otherCamera->id)->count())->toBe(1);
});

it('deletes in batches without missing rows', function () {
    $this->site->update(['settings' => ['retention_days' => 30]]);

    Visit::factory()->count(60)->for($this->site)->create(['entered_at' => now()->subDays(60)]);

    PrunePlateData::dispatchSync();

    expect(Visit::query()->count())->toBe(0);
});
