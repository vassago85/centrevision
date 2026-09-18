<?php

use App\Services\Ingestion\TesseractPlateImageReader;

beforeEach(function () {
    config()->set('trafficflow.plate_image_read_min_confidence', 0.7);
});

it('accepts a confident plate from the reader', function () {
    $read = (new TesseractPlateImageReader)->interpret("HW37HTGP 91\n");

    expect($read)->not->toBeNull()
        ->and($read->plate)->toBe('HW37HTGP')
        ->and($read->confidence)->toBe(0.91);
});

it('rejects a weak read', function () {
    expect((new TesseractPlateImageReader)->interpret('HW37HTGP 40'))->toBeNull();
});

it('rejects output that is not a plate', function (string $output) {
    expect((new TesseractPlateImageReader)->interpret($output))->toBeNull();
})->with([
    'NONE',
    '',
    'HELLO 99',
    '123456 99',
    'UNKNOWN 99',
    'not a plate line',
]);
