<?php

use App\Enums\WatchlistKind;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\Site;
use App\Models\User;
use App\Models\WatchlistPlate;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);
    $this->camera = Camera::factory()->for($this->site)->entrance()->create();

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
});

it('adds a plate to the watchlist with a kind and reason', function () {
    Livewire::test('pages::watchlist')
        ->set('siteId', $this->site->id)
        ->set('plateNumber', 'bx 91 gp')
        ->set('kind', WatchlistKind::Block->value)
        ->set('reason', 'Loitering after hours')
        ->call('save');

    $entry = WatchlistPlate::sole();

    // Plate stored normalised so the recorder can match on equality.
    expect($entry->plate_number)->toBe('BX91GP')
        ->and($entry->kind)->toBe(WatchlistKind::Block)
        ->and($entry->reason)->toBe('Loitering after hours');
});

it('will not add a plate to a site the tenant does not own', function () {
    $foreign = Site::factory()->create();

    Livewire::test('pages::watchlist')
        ->set('siteId', $foreign->id)
        ->set('plateNumber', 'BX91GP')
        ->call('save')
        ->assertHasErrors(['siteId']);
});

it('counts hits against retained plate events', function () {
    WatchlistPlate::factory()->watch()->for($this->site)->create([
        'plate_number' => 'BX91GP',
    ]);

    // Three events inside the 30-day window, one older that should not count.
    foreach ([1, 3, 7] as $daysAgo) {
        PlateEvent::factory()->for($this->camera)->create([
            'plate_number' => 'BX91GP',
            'captured_at' => Date::now()->subDays($daysAgo),
        ]);
    }

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'BX91GP',
        'captured_at' => Date::now()->subDays(45),
    ]);

    $entry = WatchlistPlate::sole();
    $hits = Livewire::test('pages::watchlist')->instance()->recentHits;

    expect((int) $hits[$entry->id]->hits_30d)->toBe(3);
});

it('lets the owner remove an entry', function () {
    $entry = WatchlistPlate::factory()->block()->for($this->site)->create([
        'plate_number' => 'STOLEN1',
    ]);

    Livewire::test('pages::watchlist')->call('remove', $entry->id);

    expect(WatchlistPlate::count())->toBe(0);
});
