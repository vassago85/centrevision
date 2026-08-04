<?php

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Organization;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.auth')] #[Title('Waiting for approval')] class extends Component {
    #[Computed]
    public function organization(): ?Organization
    {
        return auth()->user()?->organization;
    }

    /**
     * The most recent approval attached to this org. Powers the copy —
     * "waiting", "you were rejected", or "already approved" — so a signed-in
     * owner never lands on an ambiguous holding screen.
     */
    #[Computed]
    public function latestApproval(): ?Approval
    {
        $org = $this->organization;

        if ($org === null) {
            return null;
        }

        return Approval::query()
            ->where('kind', ApprovalKind::OwnerRegistration)
            ->where('subject_type', Organization::class)
            ->where('subject_id', $org->getKey())
            ->orderByDesc('created_at')
            ->first();
    }

    public function logout(): void
    {
        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirect(route('login'));
    }
}; ?>

<div class="flex flex-col gap-6">
    @php $approval = $this->latestApproval; @endphp

    @if ($this->organization === null || $this->organization->isApproved())
        {{-- A user who has since been approved (or platform-linked) can bounce
             straight to the app; landing on this page is now stale. --}}
        <x-auth-header
            :title="__('You are all set')"
            :description="__('Your organization has been approved. Reload to continue.')"
        />
        <flux:button variant="primary" class="w-full" :href="route('home')" wire:navigate>{{ __('Enter the app') }}</flux:button>
    @elseif ($approval !== null && $approval->status === App\Enums\ApprovalStatus::Rejected)
        <x-auth-header
            :title="__('Sign-up not approved')"
            :description="__('A platform administrator reviewed your registration and could not approve it. See below for the reason we were given.')"
        />

        @if (! empty($approval->review_note))
            <div class="rounded-lg border border-danger/30 bg-danger-soft p-4 text-[13.5px] text-ink-2">
                {{ $approval->review_note }}
            </div>
        @endif

        <p class="text-[13px] text-ink-2">
            If you think this is a mistake, email <a href="mailto:{{ config('trafficflow.support_email') }}" class="font-semibold text-accent">{{ config('trafficflow.support_email') }}</a>.
        </p>

        <flux:button variant="ghost" wire:click="logout" class="w-full">{{ __('Sign out') }}</flux:button>
    @else
        <x-auth-header
            :title="__('Thanks — a platform admin is reviewing your sign-up.')"
            :description="__('This usually takes less than a working day. You will get an email as soon as your account is unlocked.')"
        />

        <div class="rounded-lg border border-line bg-surface-2 p-4 text-[13.5px] text-ink-2">
            <p class="font-semibold text-ink">{{ $this->organization->name }}</p>
            <p class="mt-0.5">Registered {{ $this->organization->created_at?->diffForHumans() ?? 'just now' }}.</p>
        </div>

        <p class="text-[13px] text-ink-2">
            Questions? Email <a href="mailto:{{ config('trafficflow.support_email') }}" class="font-semibold text-accent">{{ config('trafficflow.support_email') }}</a>.
        </p>

        <flux:button variant="ghost" wire:click="logout" class="w-full">{{ __('Sign out for now') }}</flux:button>
    @endif
</div>
