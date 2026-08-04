<?php

use App\Enums\UserRole;
use App\Mail\SecurityInvitationMail;
use App\Models\Organization;
use App\Models\SecurityInvitation;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    $this->owner = Organization::factory()->owner()->create();
    Site::factory()->for_($this->owner)->create();

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
});

it('sends an invitation with a long random token', function () {
    Livewire::test('pages::shops')
        ->call('openInviteOperator')
        ->set('operatorName', 'Jane Radebe')
        ->set('operatorEmail', 'jane@guards.co.za')
        ->call('inviteOperator')
        ->assertHasNoErrors()
        ->assertSet('showInviteOperator', false);

    $invitation = SecurityInvitation::sole();

    expect($invitation->name)->toBe('Jane Radebe')
        ->and($invitation->organization_id)->toBe($this->owner->id)
        ->and(strlen($invitation->token))->toBe(64)
        ->and($invitation->isPending())->toBeTrue();

    Mail::assertSent(SecurityInvitationMail::class, fn ($mail) => $mail->hasTo('jane@guards.co.za'));
});

it('refuses two pending invitations for the same address within one organization', function () {
    SecurityInvitation::factory()->for($this->owner, 'organization')->create([
        'email' => 'dup@guards.co.za',
    ]);

    Livewire::test('pages::shops')
        ->call('openInviteOperator')
        ->set('operatorName', 'Second attempt')
        ->set('operatorEmail', 'dup@guards.co.za')
        ->call('inviteOperator')
        ->assertHasErrors('operatorEmail');
});

it('refuses an invitation for an address that is already a user', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    Livewire::test('pages::shops')
        ->call('openInviteOperator')
        ->set('operatorName', 'Somebody')
        ->set('operatorEmail', 'existing@example.com')
        ->call('inviteOperator')
        ->assertHasErrors('operatorEmail');
});

it('lists existing operators and forecasts their monthly seat cost', function () {
    User::factory()->securityOperator($this->owner)->count(3)->create();

    // A user in a different organization must not leak into the list.
    User::factory()->securityOperator()->create();

    $component = Livewire::test('pages::shops');

    expect($component->instance()->operators)->toHaveCount(3)
        ->and($component->instance()->operatorSeatCost)
        ->toBe(3 * (float) config('trafficflow.security_operator_monthly_amount'));
});

it('resends an invitation and pushes the expiry out again', function () {
    $invitation = SecurityInvitation::factory()->for($this->owner, 'organization')->create([
        'expires_at' => now()->addDays(1),
    ]);

    Livewire::test('pages::shops')->call('resendOperatorInvitation', $invitation->id);

    expect($invitation->fresh()->expires_at)->toBeGreaterThan(now()->addDays(5));

    Mail::assertSent(SecurityInvitationMail::class);
});

it('revokes a pending invitation', function () {
    $invitation = SecurityInvitation::factory()->for($this->owner, 'organization')->create();

    Livewire::test('pages::shops')->call('revokeOperatorInvitation', $invitation->id);

    expect(SecurityInvitation::find($invitation->id))->toBeNull();
});

it('removes an operator and stops their login', function () {
    $operator = User::factory()->securityOperator($this->owner)->create();

    Livewire::test('pages::shops')->call('removeOperator', $operator->id);

    expect(User::find($operator->id))->toBeNull();
});

it('will not remove an operator that belongs to another owner', function () {
    $foreign = User::factory()->securityOperator()->create();

    Livewire::test('pages::shops')
        ->call('removeOperator', $foreign->id);
})->throws(Illuminate\Database\Eloquent\ModelNotFoundException::class);

describe('accepting an operator invitation', function () {
    it('creates a User with the security_operator role in the owner organization', function () {
        $invitation = SecurityInvitation::factory()->for($this->owner, 'organization')->create([
            'name' => 'Jane Radebe',
            'email' => 'jane@guards.co.za',
        ]);

        auth()->logout();
        app(Tenancy::class)->setUser(null);

        Livewire::test('pages::security-invitation', ['token' => $invitation->token])
            ->assertSet('name', 'Jane Radebe')
            ->set('password', 'a-long-enough-password')
            ->set('password_confirmation', 'a-long-enough-password')
            ->call('accept')
            ->assertHasNoErrors()
            ->assertRedirect(route('overview'));

        $user = User::where('email', 'jane@guards.co.za')->sole();

        expect($user->role)->toBe(UserRole::SecurityOperator)
            ->and($user->organization_id)->toBe($this->owner->id)
            ->and($invitation->fresh()->accepted_at)->not->toBeNull()
            ->and($invitation->fresh()->user_id)->toBe($user->id)
            ->and(auth()->id())->toBe($user->id);
    });

    it('turns away an expired invitation', function () {
        $invitation = SecurityInvitation::factory()
            ->for($this->owner, 'organization')
            ->expired()
            ->create();

        auth()->logout();
        app(Tenancy::class)->setUser(null);

        Livewire::test('pages::security-invitation', ['token' => $invitation->token])
            ->assertSee('Invitation expired');

        expect(User::where('email', $invitation->email)->exists())->toBeFalse();
    });

    it('shows nothing useful for an unknown token', function () {
        auth()->logout();
        app(Tenancy::class)->setUser(null);

        Livewire::test('pages::security-invitation', ['token' => 'not-a-real-token'])
            ->assertSee('Invitation not found')
            ->assertSet('invitation', null);
    });

    it('will not let a used invitation be accepted twice', function () {
        $invitation = SecurityInvitation::factory()
            ->for($this->owner, 'organization')
            ->accepted()
            ->create();

        auth()->logout();
        app(Tenancy::class)->setUser(null);

        Livewire::test('pages::security-invitation', ['token' => $invitation->token])
            ->assertSee('Already accepted');
    });
});
