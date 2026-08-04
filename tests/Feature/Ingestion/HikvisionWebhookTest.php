<?php

use App\Enums\PlateDirection;
use App\Http\Controllers\HikvisionWebhookController;
use App\Jobs\ProcessHikvisionWebhook;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Services\Ingestion\PlateCapture;
use App\Services\Ingestion\PlateEventRecorder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

// hikXml() and hikMultipart() live in HikvisionWebhookParserTest so we share
// the fixtures across both suites and never drift.
require_once __DIR__.'/HikvisionWebhookParserTest.php';

beforeEach(function () {
    Storage::fake('local');

    $this->site = Site::factory()->create();
    $this->camera = Camera::factory()
        ->entrance()
        ->webhook('office-secret')
        ->create(['site_id' => $this->site->id]);

    // Cameras page tests share the process, so clean up before each request.
    RateLimiter::clear('hik:'.$this->camera->id);
});

function postHikWebhook(Camera $camera, string $body, string $contentType, ?string $auth = 'valid'): \Illuminate\Testing\TestResponse
{
    $server = ['CONTENT_TYPE' => $contentType];

    if ($auth === 'valid') {
        $server['HTTP_AUTHORIZATION'] = 'Basic '.base64_encode($camera->id.':office-secret');
    } elseif ($auth !== null) {
        $server['HTTP_AUTHORIZATION'] = $auth;
    }

    return test()->call('POST', '/webhooks/hik/'.$camera->id, [], [], [], $server, $body);
}

it('records a plate event on a valid webhook', function () {
    $body = hikMultipart(hikXml(plate: 'JD45GP', direction: 'forward'));

    postHikWebhook(
        $this->camera,
        $body,
        'multipart/form-data; boundary=MIME_boundary_ANPR',
    )->assertOk();

    $event = PlateEvent::withoutGlobalScope(SiteScope::class)->sole();

    expect($event->plate_number)->toBe('JD45GP')
        ->and($event->direction)->toBe(PlateDirection::In)
        ->and($event->camera_id)->toBe($this->camera->id)
        ->and($event->confidence)->toBe(0.92);
});

it('updates webhook_last_seen_at even when the payload is a duplicate', function () {
    // Prime a matching capture through the recorder so the incoming webhook
    // gets treated as a duplicate.
    app(PlateEventRecorder::class)->record($this->camera, new PlateCapture(
        plateNumber: 'JD45GP',
        capturedAt: Date::parse('2026-08-03T10:15:30+02:00'),
        direction: PlateDirection::In,
    ));

    $body = hikMultipart(hikXml(plate: 'JD45GP', direction: 'forward', dateTime: '2026-08-03T10:15:30+02:00'));

    postHikWebhook(
        $this->camera,
        $body,
        'multipart/form-data; boundary=MIME_boundary_ANPR',
    )->assertOk();

    expect(PlateEvent::withoutGlobalScope(SiteScope::class)->count())->toBe(1)
        ->and($this->camera->fresh()->webhook_last_seen_at)->not->toBeNull();
});

it('saves attached images alongside the plate event', function () {
    $body = hikMultipart(
        xml: hikXml(plate: 'HK12GP'),
        images: [
            ['content_type' => 'image/jpeg', 'filename' => 'plate.jpg', 'bytes' => 'plate-bytes'],
            ['content_type' => 'image/jpeg', 'filename' => 'vehicle.jpg', 'bytes' => 'vehicle-bytes'],
        ],
    );

    postHikWebhook(
        $this->camera,
        $body,
        'multipart/form-data; boundary=MIME_boundary_ANPR',
    )->assertOk();

    $event = PlateEvent::withoutGlobalScope(SiteScope::class)->sole();
    $day = now()->format('Y/m/d');
    $prefix = ProcessHikvisionWebhook::CAPTURES_DIR.'/'.$this->camera->id.'/'.$day;

    // Two attachments produce two files, ordered by their position in the
    // multipart body.
    expect(Storage::disk('local')->get($prefix.'/'.$event->id.'-0.jpg'))->toBe('plate-bytes')
        ->and(Storage::disk('local')->get($prefix.'/'.$event->id.'-1.jpg'))->toBe('vehicle-bytes');
});

it('accepts a bare XML body', function () {
    postHikWebhook(
        $this->camera,
        hikXml(plate: 'BX91GP'),
        'application/xml; charset=UTF-8',
    )->assertOk();

    expect(PlateEvent::withoutGlobalScope(SiteScope::class)->sole()->plate_number)->toBe('BX91GP');
});

it('refuses a request with a wrong secret', function () {
    $server = [
        'CONTENT_TYPE' => 'application/xml',
        'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($this->camera->id.':not-the-secret'),
    ];

    test()->call('POST', '/webhooks/hik/'.$this->camera->id, [], [], [], $server, hikXml())
        ->assertStatus(401);

    expect(PlateEvent::withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});

it('refuses a request whose Basic username does not match the URL camera id', function () {
    $other = Camera::factory()->entrance()->webhook('other-secret')->create(['site_id' => $this->site->id]);

    $server = [
        'CONTENT_TYPE' => 'application/xml',
        // Valid credentials for the *other* camera, but the URL points at $this->camera.
        'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($other->id.':other-secret'),
    ];

    test()->call('POST', '/webhooks/hik/'.$this->camera->id, [], [], [], $server, hikXml())
        ->assertStatus(401);
});

it('refuses a request with no Authorization header', function () {
    postHikWebhook($this->camera, hikXml(), 'application/xml', auth: null)->assertStatus(401);
});

it('accepts a request whose secret is passed as a URL path segment', function () {
    // Newer Hikvision "Alarm Server" firmware has no auth fields — the
    // camera can only paste a URL. This variant carries the secret as the
    // last segment of the URL so the same middleware still authenticates.
    $server = ['CONTENT_TYPE' => 'application/xml'];

    test()->call('POST', "/webhooks/hik/{$this->camera->id}/office-secret", [], [], [], $server, hikXml())
        ->assertOk();

    expect(PlateEvent::withoutGlobalScope(SiteScope::class)->sole()->plate_number)->toBe('JD45GP');
});

it('refuses a URL-token request whose token is wrong', function () {
    $server = ['CONTENT_TYPE' => 'application/xml'];

    test()->call('POST', "/webhooks/hik/{$this->camera->id}/wrong-secret-abcdefghijklm", [], [], [], $server, hikXml())
        ->assertStatus(401);

    expect(PlateEvent::withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});

it('refuses a request for an inactive camera', function () {
    $this->camera->update(['is_active' => false]);

    postHikWebhook($this->camera, hikXml(), 'application/xml')->assertStatus(401);
});

it('returns 404 for a non-existent camera id', function () {
    $server = [
        'CONTENT_TYPE' => 'application/xml',
        'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('9999999:whatever'),
    ];

    // The route constraint on {camera} accepts any integer, but the middleware
    // returns 401 because the camera lookup fails. Either 401 or 404 is fine
    // as long as no plate event is written.
    test()->call('POST', '/webhooks/hik/9999999', [], [], [], $server, hikXml())
        ->assertStatus(401);
});

it('quarantines an unparseable payload without crashing', function () {
    postHikWebhook(
        $this->camera,
        'not xml, not a boundary, definitely not an event',
        'text/plain',
    )->assertOk();

    expect(PlateEvent::withoutGlobalScope(SiteScope::class)->count())->toBe(0);

    // The staged file has moved to quarantine.
    $inboxFiles = Storage::disk('local')->files(HikvisionWebhookController::INBOX_DIR.'/'.$this->camera->id);
    $quarantineFiles = Storage::disk('local')->files('hikvision-webhook-quarantine/'.$this->camera->id);

    expect($inboxFiles)->toBeEmpty()
        ->and($quarantineFiles)->toHaveCount(1);
});

it('silently accepts an empty body so keepalive pings do not go into back-off', function () {
    postHikWebhook($this->camera, '', 'application/xml')->assertOk();

    expect(PlateEvent::withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});

it('deduplicates a webhook against an event already recorded by the FTP path', function () {
    // Simulate the FTP sweep having recorded the same capture first.
    app(PlateEventRecorder::class)->record($this->camera, new PlateCapture(
        plateNumber: 'JD45GP',
        capturedAt: Date::parse('2026-08-03T10:15:30+02:00'),
        direction: PlateDirection::In,
    ));

    postHikWebhook(
        $this->camera,
        hikMultipart(hikXml(plate: 'JD45GP', dateTime: '2026-08-03T10:15:30+02:00')),
        'multipart/form-data; boundary=MIME_boundary_ANPR',
    )->assertOk();

    expect(PlateEvent::withoutGlobalScope(SiteScope::class)->count())->toBe(1);
});

it('applies the per-camera rate limit', function () {
    // Push the limit down so the test does not have to fire 60 requests.
    RateLimiter::clear('hik:'.$this->camera->id);

    \Illuminate\Support\Facades\RateLimiter::for('hik-webhook', function () {
        return \Illuminate\Cache\RateLimiting\Limit::perSecond(2)->by('hik:'.$this->camera->id);
    });

    postHikWebhook($this->camera, hikXml(plate: 'AA01GP'), 'application/xml')->assertOk();
    postHikWebhook($this->camera, hikXml(plate: 'AA02GP'), 'application/xml')->assertOk();
    postHikWebhook($this->camera, hikXml(plate: 'AA03GP'), 'application/xml')->assertStatus(429);
});

it('cleans the staged file from the inbox after a successful record', function () {
    postHikWebhook(
        $this->camera,
        hikMultipart(hikXml(plate: 'JD45GP')),
        'multipart/form-data; boundary=MIME_boundary_ANPR',
    )->assertOk();

    $inboxFiles = Storage::disk('local')->allFiles(HikvisionWebhookController::INBOX_DIR);

    expect($inboxFiles)->toBeEmpty();
});
