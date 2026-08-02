<?php

use App\Support\Billing\SubscriptionStatusResolver;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Subscription')] class extends Component {
    /**
     * Someone who is paid up has no business here, and would otherwise see a
     * dead-end page with no way back.
     */
    public function mount(SubscriptionStatusResolver $resolver): void
    {
        if ($resolver->hasAccess(auth()->user())) {
            $this->redirectRoute('overview', navigate: true);
        }
    }

    #[Computed]
    public function reason(): string
    {
        return app(SubscriptionStatusResolver::class)->reason(auth()->user());
    }

    #[Computed]
    public function lapsedSites(): Illuminate\Support\Collection
    {
        return app(SubscriptionStatusResolver::class)->lapsedSubscriptions(auth()->user());
    }
}; ?>

<div>
    <x-page-header
        title="Subscription inactive"
        subtitle="Access is paused until payment is up to date"
    />

    <div class="rounded-tf border border-line bg-surface p-6">
        <x-badge tone="danger">Payment required</x-badge>

        <p class="mt-4 max-w-prose text-[13.5px] text-ink-2">{{ $this->reason }}</p>

        @if ($this->lapsedSites->isNotEmpty())
            <div class="mt-5">
                <x-data-table :headers="['Site', 'Status', ['label' => 'Monthly', 'align' => 'right']]">
                    @foreach ($this->lapsedSites as $subscription)
                        <tr>
                            <td class="border-b border-line py-2.5">{{ $subscription->site->name }}</td>
                            <td class="border-b border-line py-2.5">
                                <x-badge tone="danger">{{ $subscription->status->label() }}</x-badge>
                            </td>
                            <td class="border-b border-line py-2.5 text-right tabular-nums">
                                R{{ number_format((float) $subscription->base_fee, 2) }}
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </div>
        @endif

        <div class="mt-6 flex flex-wrap items-center gap-3">
            @can('manage billing')
                <flux:button variant="primary" :href="route('billing')" wire:navigate>Go to billing</flux:button>
            @endcan

            <flux:text class="text-[13px] text-ink-muted">
                @php($billingEmail = config('trafficflow.billing_email'))
                Need help? Email <a class="text-accent" href="mailto:{{ $billingEmail }}">{{ $billingEmail }}</a>.
            </flux:text>
        </div>
    </div>
</div>
