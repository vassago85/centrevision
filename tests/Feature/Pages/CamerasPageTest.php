<?php

use App\Enums\CameraRole;
use App\Enums\IngestionMode;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);
    $this->user = actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
});

it('lists only the tenant cameras', function () {
    Camera::factory()->for($this->site)->create(['name' => 'North entrance']);
    Camera::factory()->create(['name' => 'Someone elses camera']);

    Livewire::test('pages::cameras')
        ->assertSee('North entrance')
        ->assertDontSee('Someone elses camera');
});

it('creates a camera on a site the owner runs', function () {
    Livewire::test('pages::cameras')
        ->call('add')
        ->set('name', 'South entrance')
        ->set('role', CameraRole::Exit->value)
        ->set('ipAddress', '10.0.1.22')
        ->set('isapiUsername', 'admin')
        ->set('isapiPassword', 'secret')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $camera = Camera::where('name', 'South entrance')->sole();

    expect($camera->site_id)->toBe($this->site->id)
        ->and($camera->role)->toBe(CameraRole::Exit)
        ->and($camera->isapi_password)->toBe('secret');
});

it('refuses to attach a camera to a site the owner does not run', function () {
    $foreign = Site::factory()->create();

    Livewire::test('pages::cameras')
        ->call('add')
        ->set('siteId', $foreign->id)
        ->set('name', 'Sneaky')
        ->set('ipAddress', '10.0.9.9')
        ->call('save')
        ->assertHasErrors('siteId');

    expect(Camera::withoutGlobalScope(SiteScope::class)->where('name', 'Sneaky')->exists())->toBeFalse();
});

it('keeps the stored ISAPI password when the field is left blank on edit', function () {
    $camera = Camera::factory()->for($this->site)->create(['isapi_password' => 'original']);

    Livewire::test('pages::cameras')
        ->call('edit', $camera->id)
        ->assertSet('isapiPassword', '')
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($camera->fresh()->isapi_password)->toBe('original')
        ->and($camera->fresh()->name)->toBe('Renamed');
});

it('will not let one owner edit another owner camera', function () {
    $foreign = Camera::factory()->create();

    // The site scope hides the row entirely, so the lookup misses rather than
    // reaching the authorization check.
    Livewire::test('pages::cameras')->call('edit', $foreign->id);
})->throws(ModelNotFoundException::class);

it('removes a camera and the events it recorded', function () {
    $camera = Camera::factory()->for($this->site)->create();
    PlateEvent::factory()->for($camera)->count(3)->create();

    Livewire::test('pages::cameras')->call('delete', $camera->id);

    expect(Camera::withoutGlobalScope(SiteScope::class)->find($camera->id))->toBeNull()
        ->and(PlateEvent::withoutGlobalScope(SiteScope::class)->where('camera_id', $camera->id)->count())->toBe(0);
});

it('reports a camera as stale once it goes quiet, and healthy while it is talking', function () {
    // Monitoring health has three buckets: Healthy (reachable), Stale
    // (active but silent past the window), Offline (inactive or never
    // seen). Anything more granular belongs in the setup / diagnostics
    // modals, not the ops table.
    Camera::factory()->for($this->site)->create([
        'name' => 'Silent camera',
        'last_event_at' => now()->subHours(4),
        'last_probe_ok_at' => now()->subHours(4),
    ]);

    Camera::factory()->for($this->site)->create([
        'name' => 'Live camera',
        'last_event_at' => now()->subMinute(),
        'last_probe_ok_at' => now()->subMinute(),
    ]);

    Livewire::test('pages::cameras')
        ->assertSeeInOrder(['Silent camera', 'Stale'])
        ->assertSeeInOrder(['Live camera', 'Healthy']);
});

it('surfaces the compact fleet summary with online / stale / offline / reads today', function () {
    Camera::factory()->for($this->site)->create([
        'name' => 'North',
        'last_event_at' => now()->subMinute(),
        'last_probe_ok_at' => now()->subMinute(),
    ]);
    Camera::factory()->for($this->site)->create([
        'name' => 'South',
        'is_active' => false,
    ]);

    Livewire::test('pages::cameras')
        ->assertSee('Online')
        ->assertSee('Offline')
        // The old "Stale after N minutes" copy is not a page-level metric
        // any more — it moved to a tooltip so it stops shouting when no
        // camera is actually stale.
        ->assertDontSee('Stale after')
        ->assertSee('Reads today');
});

it('renames the operational columns to Reads Today and Last Seen', function () {
    Camera::factory()->for($this->site)->create([
        'name' => 'North',
        'last_event_at' => now()->subMinute(),
    ]);

    Livewire::test('pages::cameras')
        // Commercial ANPR product wording — reads, not events — everywhere
        // this page describes ingestion activity. "Last Seen" (any signal
        // from the camera) rather than "Last Read" (only plate reads),
        // because a quiet-but-alive camera should not look alarming.
        ->assertSee('Reads Today')
        ->assertSee('Last Seen')
        ->assertDontSee('Events today')
        ->assertDontSee('Last event')
        ->assertDontSee('Last Read');
});

it('shows the most recent liveness signal in the Last Seen column, not only plate reads', function () {
    // Freeze time so diffForHumans() renders a deterministic string; a
    // relative-time assertion is otherwise flaky under a slow CI worker.
    Illuminate\Support\Facades\Date::setTestNow('2026-08-25 12:00:00');

    // Camera has no plate read all day but is pinging us every minute
    // via the Alarm Server keepalive. The Last Seen column should reflect
    // the ping, not the two-hour-old plate read.
    Camera::factory()->for($this->site)->create([
        'name' => 'Quiet camera',
        'last_event_at' => now()->subHours(2),
        'webhook_last_seen_at' => now()->subMinute(),
    ]);

    Livewire::test('pages::cameras')
        // Presence of the ping's relative time and absence of the plate
        // read's proves we picked the newer of the two signals.
        ->assertSee('1 minute ago')
        ->assertDontSee('2 hours ago');
});

it('creates a webhook camera without requiring an IP address', function () {
    Livewire::test('pages::cameras')
        ->call('add')
        ->set('name', 'Office ANPR')
        ->set('role', CameraRole::Entrance->value)
        ->set('ingestionMode', IngestionMode::Webhook->value)
        // Deliberately leave ipAddress blank.
        ->call('save')
        ->assertHasNoErrors()
        // Fresh webhook cameras land in the setup modal so the operator
        // sees the URL and secret without having to hunt for them.
        ->assertSet('showSetup', true);

    $camera = Camera::where('name', 'Office ANPR')->sole();

    expect($camera->ingestion_mode)->toBe(IngestionMode::Webhook)
        ->and($camera->webhook_secret)->not->toBeEmpty();
});

it('still requires an IP address for stream cameras', function () {
    Livewire::test('pages::cameras')
        ->call('add')
        ->set('name', 'North stream')
        ->set('ingestionMode', IngestionMode::Stream->value)
        ->set('ipAddress', '')
        ->call('save')
        ->assertHasErrors('ipAddress');
});

it('opens the setup modal for an existing webhook camera', function () {
    $camera = Camera::factory()
        ->for($this->site)
        ->webhook('office-secret')
        ->create(['name' => 'Front gate']);

    Livewire::test('pages::cameras')
        ->call('openSetup', $camera->id)
        ->assertSet('showSetup', true)
        ->assertSet('setupCameraId', $camera->id)
        ->assertSet('revealedSecret', 'office-secret');
});

it('regenerates the webhook secret and shows the new value', function () {
    $camera = Camera::factory()
        ->for($this->site)
        ->webhook('office-secret')
        ->create();

    Livewire::test('pages::cameras')
        ->call('regenerateSecret', $camera->id)
        ->assertSet('showSetup', true)
        ->assertSet('secretJustGenerated', true);

    // Round-trip through the DB so the encrypted cast decrypts on read.
    expect($camera->fresh()->webhook_secret)->not->toBe('office-secret');
});

it('wipes the revealed secret from state when the modal closes', function () {
    $camera = Camera::factory()->for($this->site)->webhook('office-secret')->create();

    Livewire::test('pages::cameras')
        ->call('openSetup', $camera->id)
        ->assertSet('revealedSecret', 'office-secret')
        ->call('closeSetup')
        ->assertSet('revealedSecret', '')
        ->assertSet('showSetup', false)
        ->assertSet('setupCameraId', null);
});

it('will not let one owner regenerate another owner camera secret', function () {
    $foreign = Camera::factory()->webhook('other-secret')->create();

    Livewire::test('pages::cameras')->call('regenerateSecret', $foreign->id);
})->throws(ModelNotFoundException::class);

it('shows the cameras page in read-only mode for a security operator', function () {
    Camera::factory()->for($this->site)->create(['name' => 'North entrance']);

    $operator = User::factory()->securityOperator($this->owner)->create();
    actingAsTenant($operator);

    Livewire::test('pages::cameras')
        ->assertSee('North entrance')
        // The banner is the visual cue that the tab is not a config screen.
        ->assertSee('read-only mode')
        // The button that would let them add a camera is intentionally gone.
        // The hidden "Add camera" modal heading is still in the DOM so we
        // match on the button's wire:click handler for an unambiguous check.
        ->assertDontSeeHtml('wire:click="add"')
        ->assertDontSeeHtml('wire:click="edit')
        ->assertDontSeeHtml('wire:click="delete')
        ->assertSet('canManageCameras', false);
});

it('rejects a security operator that tries to call the add action directly', function () {
    $operator = User::factory()->securityOperator($this->owner)->create();
    actingAsTenant($operator);

    // Even if a hostile client bypasses the UI, the server refuses the
    // mutation. The bell-icon in the browser is defence in depth, not the
    // security boundary itself.
    Livewire::test('pages::cameras')->call('add')->assertStatus(403);
});

it('rejects a security operator that tries to edit or delete a camera directly', function () {
    $camera = Camera::factory()->for($this->site)->create();

    $operator = User::factory()->securityOperator($this->owner)->create();
    actingAsTenant($operator);

    Livewire::test('pages::cameras')->call('edit', $camera->id)->assertStatus(403);
    Livewire::test('pages::cameras')->call('delete', $camera->id)->assertStatus(403);
});
