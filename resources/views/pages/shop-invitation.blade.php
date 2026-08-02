<?php

use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\ShopInvitation;
use App\Models\ShopSubscription;
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

    public ?ShopInvitation $invitation = null;

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        // Guests reach this page, so the tenant scope is dormant and the
        // lookup is by unguessable token rather than by id.
        $this->invitation = ShopInvitation::with('site:id,name')
            ->where('token', $token)
            ->first();
    }

    /**
     * Creates the shop organization, its subscription and its first admin in
     * one transaction: a half-built shop would leave the user able to log in
     * with nothing to see.
     */
    public function accept(): void
    {
        abort_if($this->invitation === null || ! $this->invitation->isPending(), 404);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $organization = Organization::create([
                'name' => $this->invitation->shop_name,
                'type' => OrganizationType::Shop,
                'parent_site_id' => $this->invitation->site_id,
            ]);

            // Shops start trialing so they can see the product before the
            // first invoice; billing moves them to active on payment.
            ShopSubscription::create([
                'organization_id' => $organization->getKey(),
                'monthly_amount' => $this->invitation->monthly_amount,
                'status' => SubscriptionStatus::Trialing,
                'current_period_ends_at' => now()->endOfMonth(),
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $this->invitation->email,
                'password' => $validated['password'],
                'organization_id' => $organization->getKey(),
                'role' => UserRole::ShopAdmin,
            ]);

            $this->invitation->update([
                'accepted_at' => now(),
                'organization_id' => $organization->getKey(),
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
            :description="__('This link is not valid. Ask the centre management to send a new invitation.')"
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
            :description="__('Ask the centre management to resend it.')"
        />
    @else
        <x-auth-header
            :title="$invitation->shop_name"
            :description="__(':site has invited you to :app at R:amount per month.', [
                'app' => config('app.name'),
                'site' => $invitation->site->name,
                'amount' => number_format((float) $invitation->monthly_amount, 2),
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
