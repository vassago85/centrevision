<?php

use App\Jobs\ProcessHikvisionWebhook;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\User;
use App\Support\Ingestion\PlateCaptureStore;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create();
    $this->camera = Camera::factory()->for($this->site)->entrance()->create();
    $this->event = PlateEvent::factory()->for($this->camera)->create([
        'captured_at' => now()->subHour(),
    ]);
    $this->path = ProcessHikvisionWebhook::CAPTURES_DIR
        .'/'.$this->camera->id.'/'
        .$this->event->captured_at->format('Y/m/d').'/'
        .$this->event->id.'-0.jpg';

    Storage::disk('local')->put($this->path, 'jpeg-bytes');
});

it('finds the stored JPEG for an event', function () {
    expect(app(PlateCaptureStore::class)->pathsFor($this->event))->toBe([$this->path]);
});

it('streams a capture to an owner who can see the event', function () {
    $this->actingAs(User::factory()->ownerAdmin($this->owner)->create())
        ->get(route('activity.captures.show', [$this->event, 0]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertSee('jpeg-bytes', false);
});

it('returns 404 when the JPEG has already been pruned', function () {
    Storage::disk('local')->delete($this->path);

    $this->actingAs(User::factory()->ownerAdmin($this->owner)->create())
        ->get(route('activity.captures.show', [$this->event, 0]))
        ->assertNotFound();
});

it('refuses a shop account', function () {
    $shop = Organization::factory()->shop($this->site)->create();
    ShopSubscription::factory()->for($shop, 'organization')->create();

    $this->actingAs(User::factory()->shopAdmin($shop)->create())
        ->get(route('activity.captures.show', [$this->event, 0]))
        ->assertForbidden();
});

it('does not serve another tenant\'s capture', function () {
    $this->actingAs(User::factory()->ownerAdmin($this->owner)->create());

    $foreign = PlateEvent::factory()->create(['captured_at' => now()->subHour()]);

    $this->get(route('activity.captures.show', [$foreign, 0]))->assertForbidden();
});

it('shows a Photos action on Activity when a JPEG is still on disk', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::test('pages::activity')
        ->assertSee('data-test="view-captures-'.$this->event->id.'"', false)
        ->call('viewCaptures', $this->event->id)
        ->assertSet('viewingCaptureEventId', $this->event->id)
        ->assertSee(route('activity.captures.show', [$this->event, 0]), false);
});
