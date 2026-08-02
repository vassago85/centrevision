<?php

use App\Logging\RedactPlateNumbers;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\PlateTag;
use App\Models\Site;
use App\Models\Visit;
use App\Support\PlateNumber;
use Monolog\Level;
use Monolog\LogRecord;

it('masks a plate down to something unidentifying', function (string $plate, string $masked) {
    expect(PlateNumber::mask($plate))->toBe($masked);
})->with([
    ['JD 45 GP', 'J****P'],
    ['CA123456', 'C******6'],
    ['AB', '**'],
]);

it('keeps plate numbers out of a log message', function () {
    $record = (new RedactPlateNumbers)(new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Warning,
        message: 'Could not match JD 45 GP on camera 3',
    ));

    expect($record->message)->not->toContain('JD 45 GP')
        ->and($record->message)->toContain('J****P');
});

it('keeps plate numbers out of log context, however they are nested', function () {
    $record = (new RedactPlateNumbers)(new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Warning,
        message: 'Ingestion failed',
        context: [
            'plate_number' => 'JD45GP',
            'camera_id' => 3,
            'capture' => ['plate' => 'CA 123 456', 'confidence' => 0.9],
        ],
    ));

    expect($record->context['plate_number'])->toBe('J****P')
        ->and($record->context['capture']['plate'])->toBe('C******6')
        ->and($record->context['camera_id'])->toBe(3)
        ->and($record->context['capture']['confidence'])->toBe(0.9);
});

it('leaves anything that is not a plate alone', function () {
    $record = (new RedactPlateNumbers)(new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Info,
        message: 'Pruned 4210 visits for site 7',
        context: ['site_id' => 7, 'cutoff' => '2026-07-01'],
    ));

    expect($record->message)->toBe('Pruned 4210 visits for site 7')
        ->and($record->context['cutoff'])->toBe('2026-07-01');
});

it('never serialises a plate number', function () {
    $site = Site::factory()->for_(Organization::factory()->owner()->create())->create();
    $camera = Camera::factory()->for($site)->create();

    $event = PlateEvent::factory()->for($camera)->create(['plate_number' => 'JD45GP']);
    $visit = Visit::factory()->for($site)->create(['plate_number' => 'JD45GP']);
    $tag = PlateTag::factory()->for($site)->create(['plate_number' => 'JD45GP']);

    expect($event->toArray())->not->toHaveKey('plate_number')
        ->and($visit->toArray())->not->toHaveKey('plate_number')
        ->and($tag->toArray())->not->toHaveKey('plate_number')
        // The attribute is still readable in code; it is only export that is closed.
        ->and($visit->plate_number)->toBe('JD45GP');
});
