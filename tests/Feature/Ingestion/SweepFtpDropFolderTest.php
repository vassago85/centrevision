<?php

use App\Enums\PlateDirection;
use App\Jobs\SweepFtpDropFolder;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Site;
use App\Services\Ingestion\PlateCapture;
use App\Services\Ingestion\PlateEventRecorder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->root = storage_path('framework/testing/plate-drop-'.uniqid());
    config()->set('trafficflow.plate_drop_path', $this->root);

    $this->site = Site::factory()->create();
    $this->camera = Camera::factory()->entrance()->create(['site_id' => $this->site->id]);

    $this->cameraDir = $this->root.DIRECTORY_SEPARATOR.$this->camera->id;
    File::ensureDirectoryExists($this->cameraDir);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function dropFile(string $name, string $contents = 'binary'): string
{
    $path = test()->cameraDir.DIRECTORY_SEPARATOR.$name;
    File::put($path, $contents);

    return $path;
}

it('records a capture from an encoded filename', function () {
    dropFile('ANPR_JD45GP_20260802101530_forward_92.jpg');

    SweepFtpDropFolder::dispatchSync();

    $event = PlateEvent::query()->sole();

    expect($event->plate_number)->toBe('JD45GP')
        ->and($event->direction)->toBe(PlateDirection::In)
        ->and($event->confidence)->toBe(0.92)
        ->and($event->captured_at->format('Y-m-d H:i:s'))->toBe('2026-08-02 10:15:30')
        ->and($event->camera_id)->toBe($this->camera->id);
});

it('prefers the sidecar xml over the filename', function () {
    dropFile('capture-001.jpg');
    dropFile('capture-001.xml', alertXml('HK12GP', 'reverse'));

    SweepFtpDropFolder::dispatchSync();

    $event = PlateEvent::query()->sole();

    expect($event->plate_number)->toBe('HK12GP')
        ->and($event->direction)->toBe(PlateDirection::Out);
});

it('reads an xml dropped without an image', function () {
    dropFile('capture-002.xml', alertXml('BX91GP'));

    SweepFtpDropFolder::dispatchSync();

    expect(PlateEvent::query()->sole()->plate_number)->toBe('BX91GP');
});

it('archives the capture and its sidecar so the next sweep skips them', function () {
    dropFile('capture-003.jpg');
    dropFile('capture-003.xml', alertXml('JD45GP'));

    SweepFtpDropFolder::dispatchSync();

    $archive = $this->cameraDir.DIRECTORY_SEPARATOR.'processed';

    expect(File::exists($archive.DIRECTORY_SEPARATOR.'capture-003.jpg'))->toBeTrue()
        ->and(File::exists($archive.DIRECTORY_SEPARATOR.'capture-003.xml'))->toBeTrue()
        ->and(File::files($this->cameraDir))->toHaveCount(0);

    SweepFtpDropFolder::dispatchSync();

    expect(PlateEvent::query()->count())->toBe(1);
});

it('does not duplicate a capture the alert stream already recorded', function () {
    $capture = new PlateCapture(
        plateNumber: 'JD45GP',
        capturedAt: Date::parse('2026-08-02 10:15:30'),
        direction: PlateDirection::In,
    );

    app(PlateEventRecorder::class)->record($this->camera, $capture);

    dropFile('ANPR_JD45GP_20260802101530_forward_92.jpg');

    SweepFtpDropFolder::dispatchSync();

    expect(PlateEvent::query()->count())->toBe(1);
});

it('quarantines files it cannot parse instead of retrying them forever', function () {
    dropFile('not-a-capture.jpg');

    SweepFtpDropFolder::dispatchSync();

    expect(PlateEvent::query()->count())->toBe(0)
        ->and(File::exists($this->cameraDir.DIRECTORY_SEPARATOR.'not-a-capture.jpg'))->toBeFalse()
        ->and(File::exists($this->cameraDir.DIRECTORY_SEPARATOR.'failed'.DIRECTORY_SEPARATOR.'not-a-capture.jpg'))->toBeTrue();
});

it('ignores files that are not captures', function () {
    dropFile('readme.txt');

    SweepFtpDropFolder::dispatchSync();

    expect(PlateEvent::query()->count())->toBe(0);
});

it('skips an inactive camera', function () {
    $this->camera->update(['is_active' => false]);
    dropFile('ANPR_JD45GP_20260802101530_forward_92.jpg');

    SweepFtpDropFolder::dispatchSync();

    expect(PlateEvent::query()->count())->toBe(0);
});

it('can sweep a single camera', function () {
    $other = Camera::factory()->exit()->create(['site_id' => $this->site->id]);
    $otherDir = $this->root.DIRECTORY_SEPARATOR.$other->id;
    File::ensureDirectoryExists($otherDir);
    File::put($otherDir.DIRECTORY_SEPARATOR.'ANPR_HK12GP_20260802101530_reverse_88.jpg', 'binary');

    dropFile('ANPR_JD45GP_20260802101530_forward_92.jpg');

    SweepFtpDropFolder::dispatchSync($this->camera->id);

    expect(PlateEvent::query()->count())->toBe(1)
        ->and(PlateEvent::query()->sole()->plate_number)->toBe('JD45GP');
});

it('does nothing when the drop folder is absent', function () {
    config()->set('trafficflow.plate_drop_path', $this->root.'-missing');

    SweepFtpDropFolder::dispatchSync();

    expect(PlateEvent::query()->count())->toBe(0);
});
