<?php

use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Mail\ShopInvitationMail;
use App\Models\Organization;
use App\Models\Scopes\SiteScope;
use App\Models\ShopInvitation;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);

    $this->user = actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
});

it('lists shops on the owner sites and nobody elses', function () {
    $mine = Organization::factory()->shop($this->site)->create(['name' => 'Kloof Coffee']);
    ShopSubscription::factory()->for($mine, 'organization')->create();

    Organization::factory()->shop(Site::factory()->create())->create(['name' => 'Another Mall Shop']);

    Livewire::test('pages::shops')
        ->assertSee('Kloof Coffee')
        ->assertDontSee('Another Mall Shop');
});

it('sends an invitation with an unguessable token', function () {
    Livewire::test('pages::shops')
        ->call('openInvite')
        ->set('shopName', 'Corner Bakery')
        ->set('email', 'hello@cornerbakery.co.za')
        ->set('monthlyAmount', 420)
        ->call('invite')
        ->assertHasNoErrors()
        ->assertSet('showInvite', false);

    $invitation = ShopInvitation::sole();

    expect($invitation->shop_name)->toBe('Corner Bakery')
        ->and($invitation->site_id)->toBe($this->site->id)
        ->and(strlen($invitation->token))->toBe(64)
        ->and($invitation->isPending())->toBeTrue();

    Mail::assertSent(ShopInvitationMail::class, fn ($mail) => $mail->hasTo('hello@cornerbakery.co.za'));
});

it('holds the monthly fee inside the agreed range', function (float $amount, bool $valid) {
    $test = Livewire::test('pages::shops')
        ->call('openInvite')
        ->set('shopName', 'Corner Bakery')
        ->set('email', 'hello@cornerbakery.co.za')
        ->set('monthlyAmount', $amount)
        ->call('invite');

    $valid ? $test->assertHasNoErrors() : $test->assertHasErrors('monthlyAmount');
})->with([
    [300.00, false],
    [350.00, true],
    [500.00, true],
    [600.00, false],
]);

it('refuses a second pending invitation for the same address', function () {
    ShopInvitation::create([
        'site_id' => $this->site->id,
        'shop_name' => 'Corner Bakery',
        'email' => 'hello@cornerbakery.co.za',
        'token' => ShopInvitation::generateToken(),
        'monthly_amount' => 400,
        'expires_at' => now()->addDays(14),
    ]);

    Livewire::test('pages::shops')
        ->call('openInvite')
        ->set('shopName', 'Corner Bakery Again')
        ->set('email', 'hello@cornerbakery.co.za')
        ->call('invite')
        ->assertHasErrors('email');
});

it('will not invite a shop onto another owner site', function () {
    $foreign = Site::factory()->create();

    Livewire::test('pages::shops')
        ->call('openInvite')
        ->set('siteId', $foreign->id)
        ->set('shopName', 'Sneaky')
        ->set('email', 'sneaky@example.com')
        ->call('invite')
        ->assertHasErrors('siteId');

    expect(ShopInvitation::withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});

it('suspends and reactivates a shop subscription', function () {
    $shop = Organization::factory()->shop($this->site)->create();
    $subscription = ShopSubscription::factory()->for($shop, 'organization')->create([
        'status' => SubscriptionStatus::Active,
    ]);

    Livewire::test('pages::shops')->call('toggleSuspension', $shop->id);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Canceled);

    Livewire::test('pages::shops')->call('toggleSuspension', $shop->id);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('revokes a pending invitation', function () {
    $invitation = ShopInvitation::create([
        'site_id' => $this->site->id,
        'shop_name' => 'Corner Bakery',
        'email' => 'hello@cornerbakery.co.za',
        'token' => ShopInvitation::generateToken(),
        'monthly_amount' => 400,
        'expires_at' => now()->addDays(14),
    ]);

    Livewire::test('pages::shops')->call('revoke', $invitation->id);

    expect(ShopInvitation::withoutGlobalScope(SiteScope::class)->find($invitation->id))->toBeNull();
});

it('counts only paying shops toward the variable fee', function () {
    foreach ([SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue] as $status) {
        $shop = Organization::factory()->shop($this->site)->create();
        ShopSubscription::factory()->for($shop, 'organization')->create([
            'status' => $status,
            'monthly_amount' => 400,
        ]);
    }

    Livewire::test('pages::shops')->assertSee('R400.00');
});

describe('accepting an invitation', function () {
    it('creates the shop, its subscription and its first admin', function () {
        $invitation = ShopInvitation::create([
            'site_id' => $this->site->id,
            'shop_name' => 'Corner Bakery',
            'email' => 'hello@cornerbakery.co.za',
            'token' => ShopInvitation::generateToken(),
            'monthly_amount' => 400,
            'expires_at' => now()->addDays(14),
        ]);

        auth()->logout();
        app(Tenancy::class)->setUser(null);

        Livewire::test('pages::shop-invitation', ['token' => $invitation->token])
            ->set('name', 'Thandi Mokoena')
            ->set('password', 'a-long-enough-password')
            ->set('password_confirmation', 'a-long-enough-password')
            ->call('accept')
            ->assertHasNoErrors()
            ->assertRedirect(route('overview'));

        $shop = Organization::where('name', 'Corner Bakery')->sole();
        $user = User::where('email', 'hello@cornerbakery.co.za')->sole();

        expect($shop->type)->toBe(OrganizationType::Shop)
            ->and($shop->parent_site_id)->toBe($this->site->id)
            ->and($shop->shopSubscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and((float) $shop->shopSubscription->monthly_amount)->toBe(400.0)
            ->and($user->role)->toBe(UserRole::ShopAdmin)
            ->and($user->organization_id)->toBe($shop->id)
            ->and($invitation->fresh()->accepted_at)->not->toBeNull()
            ->and(auth()->id())->toBe($user->id);
    });

    it('turns away an expired invitation', function () {
        $invitation = ShopInvitation::create([
            'site_id' => $this->site->id,
            'shop_name' => 'Corner Bakery',
            'email' => 'hello@cornerbakery.co.za',
            'token' => ShopInvitation::generateToken(),
            'monthly_amount' => 400,
            'expires_at' => now()->subDay(),
        ]);

        auth()->logout();
        app(Tenancy::class)->setUser(null);

        Livewire::test('pages::shop-invitation', ['token' => $invitation->token])
            ->assertSee('Invitation expired');

        expect(Organization::where('name', 'Corner Bakery')->exists())->toBeFalse();
    });

    it('shows nothing useful for an unknown token', function () {
        auth()->logout();
        app(Tenancy::class)->setUser(null);

        Livewire::test('pages::shop-invitation', ['token' => 'not-a-real-token'])
            ->assertSee('Invitation not found')
            ->assertSet('invitation', null);
    });
});
