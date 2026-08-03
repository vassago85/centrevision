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

it('reports a camera as unreachable once it goes quiet', function () {
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
        ->assertSeeInOrder(['Silent camera', 'Unreachable'])
        ->assertSeeInOrder(['Live camera', 'Online']);
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
