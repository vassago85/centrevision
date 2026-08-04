<?php

use App\Enums\UserRole;
use App\Models\SecurityInvitation;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.auth')] #[Title('Accept invitation')] class extends Component {
    #[Locked]
    public string $token = '';

    public ?SecurityInvitation $invitation = null;

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        // Guests reach this page, so the tenant scope is dormant and the
        // lookup is by unguessable token rather than by id.
        $this->invitation = SecurityInvitation::with('organization:id,name')
            ->where('token', $token)
            ->first();

        // Pre-fill the display name from what the owner typed on the invite,
        // so the operator only has to confirm it or edit.
        if ($this->invitation !== null && $this->name === '') {
            $this->name = $this->invitation->name;
        }
    }

    /**
     * Creates the User under the owner's organization and marks the
     * invitation accepted, in a single transaction so a half-completed
     * acceptance cannot lock the invitation without producing a login.
     */
    public function accept(): void
    {
        abort_if($this->invitation === null || ! $this->invitation->isPending(), 404);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $this->invitation->email,
                'password' => $validated['password'],
                'organization_id' => $this->invitation->organization_id,
                'role' => UserRole::SecurityOperator,
            ]);

            $this->invitation->update([
                'accepted_at' => now(),
                'user_id' => $user->getKey(),
            ]);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('overview');
    }
}; ?>

<div class="flex flex-col gap-6">
    @if ($invitation === null)
        <x-auth-header
            :title="__('Invitation not found')"
            :description="__('This link is not valid. Ask the site owner to send a new invitation.')"
        />
    @elseif ($invitation->accepted_at !== null)
        <x-auth-header
            :title="__('Already accepted')"
            :description="__('This invitation has been used. Log in with the account you created.')"
        />

        <flux:button variant="primary" class="w-full" :href="route('login')">{{ __('Log in') }}</flux:button>
    @elseif ($invitation->hasExpired())
        <x-auth-header
            :title="__('Invitation expired')"
            :description="__('Ask the site owner to resend it.')"
        />
    @else
        <x-auth-header
            :title="__('Join as a security operator')"
            :description="__(':org has invited you to help watch their sites on :app.', [
                'app' => config('app.name'),
                'org' => $invitation->organization->name,
            ])"
        />

        <form wire:submit="accept" class="flex flex-col gap-6">
            <flux:input wire:model="name" :label="__('Your name')" type="text" required autofocus autocomplete="name" />

            <flux:input :label="__('Email address')" type="email" :value="$invitation->email" disabled />

            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Create account') }}</flux:button>
        </form>
    @endif
</div>
