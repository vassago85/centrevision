<?php

use App\Enums\PayoutStatus;
use App\Jobs\GeneratePartnerPayouts;
use App\Models\Partner;
use App\Models\PartnerPayout;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Partners')] class extends Component {
    /**
     * @return Collection<int, Partner>
     */
    #[Computed]
    public function partners(): Collection
    {
        return Partner::query()
            ->withCount('organizations')
            ->withSum(['payouts as pending_commission' => fn ($query) => $query->where('status', PayoutStatus::Pending)], 'commission_amount')
            ->withSum(['payouts as paid_commission' => fn ($query) => $query->where('status', PayoutStatus::Paid)], 'commission_amount')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, PartnerPayout>
     */
    #[Computed]
    public function payouts(): Collection
    {
        return PartnerPayout::query()
            ->with('partner:id,name')
            ->orderByDesc('period_start')
            ->orderBy('partner_id')
            ->limit(50)
            ->get();
    }

    /**
     * Recalculate last month's commission on demand, for when an invoice
     * settles after the scheduled run.
     */
    public function recalculate(): void
    {
        GeneratePartnerPayouts::dispatchSync();

        unset($this->partners, $this->payouts);

        Flux::toast(variant: 'success', text: 'Payouts recalculated for last month.');
    }

    public function markPaid(int $payoutId): void
    {
        $payout = PartnerPayout::findOrFail($payoutId);

        $payout->update(['status' => PayoutStatus::Paid, 'paid_at' => now()]);

        unset($this->partners, $this->payouts);

        Flux::toast(variant: 'success', text: 'Payout marked as paid.');
    }
}; ?>

<div>
    <x-page-header title="Partners" subtitle="Referrals and commission payouts">
        <x-slot:actions>
            <flux:button size="sm" variant="ghost" wire:click="recalculate">Recalculate last month</flux:button>
        </x-slot:actions>
    </x-page-header>

    <x-panel heading="Partners">
        <x-data-table
            :headers="[
                'Partner',
                'Email',
                ['label' => 'Referrals', 'align' => 'right'],
                ['label' => 'Rate', 'align' => 'right'],
                ['label' => 'Pending', 'align' => 'right'],
                ['label' => 'Paid to date', 'align' => 'right'],
            ]"
            :is-empty="$this->partners->isEmpty()"
            empty="No partners have been registered yet."
        >
            @foreach ($this->partners as $partner)
                <tr wire:key="partner-{{ $partner->id }}">
                    <td class="border-b border-line py-2 font-medium">{{ $partner->name }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $partner->email }}</td>
                    <td class="border-b border-line py-2 text-right tabular-nums">{{ $partner->organizations_count }}</td>
                    <td class="border-b border-line py-2 text-right tabular-nums">
                        {{ number_format((float) $partner->commission_rate * 100, 1) }}%
                    </td>
                    <td class="border-b border-line py-2 text-right tabular-nums">
                        R{{ number_format((float) $partner->pending_commission, 2) }}
                    </td>
                    <td class="border-b border-line py-2 text-right tabular-nums text-ink-2">
                        R{{ number_format((float) $partner->paid_commission, 2) }}
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>

    <x-panel heading="Payout history">
        <x-data-table
            :headers="[
                'Period',
                'Partner',
                ['label' => 'Revenue base', 'align' => 'right'],
                ['label' => 'Commission', 'align' => 'right'],
                'Status',
                ['label' => '', 'align' => 'right'],
            ]"
            :is-empty="$this->payouts->isEmpty()"
            empty="No payouts have been generated yet."
        >
            @foreach ($this->payouts as $payout)
                <tr wire:key="payout-{{ $payout->id }}">
                    <td class="border-b border-line py-2">{{ $payout->period_start->format('M Y') }}</td>
                    <td class="border-b border-line py-2 font-medium">{{ $payout->partner->name }}</td>
                    <td class="border-b border-line py-2 text-right tabular-nums text-ink-2">
                        R{{ number_format((float) $payout->revenue_base, 2) }}
                    </td>
                    <td class="border-b border-line py-2 text-right font-semibold tabular-nums">
                        R{{ number_format((float) $payout->commission_amount, 2) }}
                    </td>
                    <td class="border-b border-line py-2">
                        <x-badge :tone="$payout->status === PayoutStatus::Paid ? 'positive' : 'warning'">
                            {{ $payout->status->label() }}
                        </x-badge>
                    </td>
                    <td class="border-b border-line py-2 text-right">
                        @if ($payout->status !== PayoutStatus::Paid)
                            <flux:button size="xs" variant="ghost" wire:click="markPaid({{ $payout->id }})">
                                Mark paid
                            </flux:button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>
</div>
