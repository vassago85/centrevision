<?php

use App\Enums\PlateDirection;
use App\Enums\VisitStatus;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Site;
use App\Models\Visit;
use App\Services\Ingestion\PlateCapture;
use App\Services\Ingestion\PlateEventRecorder;
use Carbon\CarbonInterface;

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->camera = Camera::factory()->entrance()->create(['site_id' => $this->site->id]);
    $this->recorder = app(PlateEventRecorder::class);
});

function capture(string $plate, ?CarbonInterface $at = null, ?PlateDirection $direction = null): PlateCapture
{
    return new PlateCapture(
        plateNumber: $plate,
        capturedAt: $at ?? now(),
        direction: $direction,
        confidence: 0.91,
    );
}

it('normalises the plate before storing it', function () {
    $event = $this->recorder->record($this->camera, capture('jd 45-gp'));

    expect($event->plate_number)->toBe('JD45GP');
});

it('infers direction from the camera role when the payload omits it', function () {
    $event = $this->recorder->record($this->camera, capture('JD45GP'));

    expect($event->direction)->toBe(PlateDirection::In);
});

it('prefers the direction the payload carries', function () {
    $event = $this->recorder->record($this->camera, capture('JD45GP', direction: PlateDirection::Out));

    expect($event->direction)->toBe(PlateDirection::Out);
});

it('leaves direction unset for a camera covering both lanes', function () {
    $camera = Camera::factory()->create(['site_id' => $this->site->id]);

    expect($this->recorder->record($camera, capture('JD45GP'))->direction)->toBeNull();
});

it('drops a repeat capture inside the dedupe window', function () {
    $at = now();

    expect($this->recorder->record($this->camera, capture('JD45GP', $at)))->not->toBeNull()
        ->and($this->recorder->record($this->camera, capture('JD45GP', $at->copy()->addSeconds(5))))->toBeNull()
        ->and(PlateEvent::query()->count())->toBe(1);
});

it('keeps a repeat capture outside the dedupe window', function () {
    $at = now();

    $this->recorder->record($this->camera, capture('JD45GP', $at));
    $this->recorder->record($this->camera, capture('JD45GP', $at->copy()->addSeconds(120)));

    expect(PlateEvent::query()->count())->toBe(2);
});

it('does not dedupe the same plate seen on a different camera', function () {
    $other = Camera::factory()->exit()->create(['site_id' => $this->site->id]);
    $at = now();

    $this->recorder->record($this->camera, capture('JD45GP', $at));
    $this->recorder->record($other, capture('JD45GP', $at));

    expect(PlateEvent::query()->count())->toBe(2);
});

it('attributes a one-character misread to the vehicle already on site', function () {
    Visit::factory()->for($this->site)->plateNumber('JD45GP')->open(now()->subHour())->create();

    $event = $this->recorder->record($this->camera, capture('JD46GP'));

    expect($event->plate_number)->toBe('JD45GP')
        ->and($event->original_plate_number)->toBe('JD46GP')
        ->and($event->wasFuzzyCorrected())->toBeTrue();
});

it('leaves a plate alone when two open visits are equally plausible corrections', function () {
    Visit::factory()->for($this->site)->plateNumber('JD45GP')->open(now()->subHour())->create();
    Visit::factory()->for($this->site)->plateNumber('JD47GP')->open(now()->subHour())->create();

    $event = $this->recorder->record($this->camera, capture('JD46GP'));

    expect($event->plate_number)->toBe('JD46GP')
        ->and($event->original_plate_number)->toBeNull();
});

it('does not correct against a visit that has already closed', function () {
    Visit::factory()->for($this->site)->plateNumber('JD45GP')->create(['status' => VisitStatus::Closed]);

    expect($this->recorder->record($this->camera, capture('JD46GP'))->plate_number)->toBe('JD46GP');
});

it('does not correct against an open visit at another site', function () {
    $otherSite = Site::factory()->create();
    Visit::factory()->for($otherSite)->plateNumber('JD45GP')->open(now()->subHour())->create();

    expect($this->recorder->record($this->camera, capture('JD46GP'))->plate_number)->toBe('JD46GP');
});

it('will not correct plates shorter than the minimum length', function () {
    config()->set('trafficflow.fuzzy_match_min_length', 8);

    Visit::factory()->for($this->site)->plateNumber('JD45GP')->open(now()->subHour())->create();

    expect($this->recorder->record($this->camera, capture('JD46GP'))->plate_number)->toBe('JD46GP');
});

it('will not correct when two characters differ', function () {
    Visit::factory()->for($this->site)->plateNumber('JD45GP')->open(now()->subHour())->create();

    expect($this->recorder->record($this->camera, capture('JD46NP'))->plate_number)->toBe('JD46NP');
});

it('respects the fuzzy matching switch', function () {
    config()->set('trafficflow.fuzzy_match_enabled', false);

    Visit::factory()->for($this->site)->plateNumber('JD45GP')->open(now()->subHour())->create();

    expect($this->recorder->record($this->camera, capture('JD46GP'))->plate_number)->toBe('JD46GP');
});

it('ignores a capture with no readable plate', function () {
    expect($this->recorder->record($this->camera, capture('---')))->toBeNull()
        ->and(PlateEvent::query()->count())->toBe(0);
});

it('keeps the camera health columns current', function () {
    $at = now()->subMinutes(2);

    $this->recorder->record($this->camera, capture('JD45GP', $at));

    $this->camera->refresh();

    expect($this->camera->last_event_at->toDateTimeString())->toBe($at->toDateTimeString())
        ->and($this->camera->last_probe_ok_at)->not->toBeNull()
        ->and($this->camera->isReachable())->toBeTrue();
});

it('does not rewind camera health with an older capture', function () {
    $this->recorder->record($this->camera, capture('JD45GP', now()));
    $latest = $this->camera->fresh()->last_event_at;

    $this->recorder->record($this->camera, capture('HK12GP', now()->subHours(3)));

    expect($this->camera->fresh()->last_event_at->toDateTimeString())->toBe($latest->toDateTimeString());
});

it('reports how many of a batch were stored', function () {
    $at = now();

    $stored = $this->recorder->recordMany($this->camera, [
        capture('JD45GP', $at),
        // Duplicate of the first.
        capture('JD45GP', $at->copy()->addSecond()),
        capture('HK12GP', $at),
    ]);

    expect($stored)->toBe(2);
});
