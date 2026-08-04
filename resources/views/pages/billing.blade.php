<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Support\Billing\BillingCalculator;
use App\Support\Billing\PaymentProcessor;
use App\Support\Billing\SiteCharge;
use App\Support\Tenancy;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Billing')] class extends Component {
    #[Computed]
    public function organization(): ?Organization
    {
        return app(Tenancy::class)->organization();
    }

    /**
     * Billing spans every site the owner runs, not just the one selected in
     * the switcher, so this deliberately ignores the current site.
     *
     * @return Collection<int, SiteCharge>
     */
    #[Computed]
    public function charges(): Collection
    {
        $organization = $this->organization();

        return $organization === null
            ? collect()
            : app(BillingCalculator::class)->chargesForOwner($organization);
    }

    #[Computed]
    public function total(): float
    {
        return round($this->charges()->sum(fn (SiteCharge $charge) => $charge->total()), 2);
    }

    #[Computed]
    public function shopRevenue(): array
    {
        $organization = $this->organization();

        return $organization === null
            ? ['gross' => 0.0, 'platform_share' => 0.0, 'owner_share' => 0.0]
            : app(BillingCalculator::class)->shopRevenueSplit($organization);
    }

    #[Computed]
    public function invoices(): Collection
    {
        $organization = $this->organization();

        return $organization === null
            ? collect()
            : Invoice::query()
                ->where('billable_type', $organization->getMorphClass())
                ->where('billable_id', $organization->getKey())
                ->with('lines.site:id,name')
                ->orderByDesc('period_start')
                ->limit(24)
                ->get();
    }

    /**
     * Hand the payer to the gateway's hosted page. Nothing here decides the
     * invoice is paid; only the gateway's own confirmation does that.
     */
    public function pay(int $invoiceId): void
    {
        $invoice = $this->invoices()->firstWhere('id', $invoiceId);

        abort_if($invoice === null, 404);

        if ($invoice->status === InvoiceStatus::Paid) {
            Flux::toast(text: 'That invoice is already paid.');

            return;
        }

        $checkout = app(PaymentProcessor::class)->startCheckout(
            $invoice,
            auth()->user()->email,
            route('billing.callback'),
        );

        $this->redirect($checkout->url);
    }

    /**
     * @return array{tone: string, label: string}
     */
    public function invoiceBadge(Invoice $invoice): array
    {
        return match ($invoice->status) {
            InvoiceStatus::Paid => ['tone' => 'positive', 'label' => 'Paid'],
            InvoiceStatus::Pending => ['tone' => 'warning', 'label' => 'Awaiting payment'],
            InvoiceStatus::Failed => ['tone' => 'danger', 'label' => 'Payment failed'],
            default => ['tone' => 'neutral', 'label' => $invoice->status->label()],
        };
    }
}; ?>

<div>
    <x-page-header title="Billing" :subtitle="$this->organization?->name">
        <x-slot:actions>
            <flux:button size="sm" variant="ghost" :href="route('shops')" wire:navigate>Manage shops</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="mb-5 rounded-tf border border-line bg-surface px-4 py-3 text-[13px] text-ink-2">
            {{ session('status') }}
        </div>
    @endif

    <div class="mb-7 grid grid-cols-4 gap-3 max-lg:grid-cols-2 max-sm:grid-cols-1">
        <x-metric label="This month" :value="'R'.number_format($this->total, 2)" delta="Across all sites" />
        <x-metric label="Sites" :value="$this->charges->count()" />
        <x-metric
            label="Paying shops"
            :value="$this->charges->sum(fn ($charge) => $charge->payingShopCount)"
            delta="Drives the variable fee"
        />
        <x-metric
            label="Shop revenue kept"
            :value="'R'.number_format($this->shopRevenue['owner_share'], 2)"
            :delta="'R'.number_format($this->shopRevenue['platform_share'], 2).' platform share'"
        />
    </div>

    <x-panel heading="Current period estimate">
        <x-data-table
            :headers="[
                'Site',
                'Tier',
                ['label' => 'Cameras', 'align' => 'right'],
                ['label' => 'Shops', 'align' => 'right'],
                ['label' => 'Base', 'align' => 'right'],
                ['label' => 'Variable', 'align' => 'right'],
                ['label' => 'Total', 'align' => 'right'],
            ]"
            :is-empty="$this->charges->isEmpty()"
            empty="No sites to bill yet."
        >
            @foreach ($this->charges as $charge)
                <tr wire:key="charge-{{ $charge->site->id }}">
                    <td class="border-b border-line py-2 font-medium">{{ $charge->site->name }}</td>
                    <td class="border-b border-line py-2">
                        <x-badge tone="accent">{{ $charge->tier->label() }}</x-badge>
                    </td>
                    <td class="border-b border-line py-2 text-right tabular-nums">{{ $charge->cameraCount }}</td>
                    <td class="border-b border-line py-2 text-right tabular-nums">{{ $charge->payingShopCount }}</td>
                    <td class="border-b border-line py-2 text-right tabular-nums">
                        R{{ number_format($charge->baseFee + $charge->cameraSurcharge, 2) }}
                    </td>
                    <td class="border-b border-line py-2 text-right tabular-nums">
                        R{{ number_format($charge->variableFee, 2) }}
                        @if ($charge->wasCapped())
                            <span class="ml-1 text-[11px] text-ink-muted">capped</span>
                        @endif
                    </td>
                    <td class="border-b border-line py-2 text-right font-semibold tabular-nums">
                        R{{ number_format($charge->total(), 2) }}
                    </td>
                </tr>
            @endforeach

            @if ($this->charges->isNotEmpty())
                <tr>
                    <td colspan="6" class="py-2.5 text-right text-ink-2">Total</td>
                    <td class="py-2.5 text-right text-[15px] font-semibold tabular-nums">R{{ number_format($this->total, 2) }}</td>
                </tr>
            @endif
        </x-data-table>
    </x-panel>

    <x-panel heading="Invoices">
        <x-data-table
            :headers="[
                'Number',
                'Period',
                'Status',
                ['label' => 'Amount', 'align' => 'right'],
                ['label' => '', 'align' => 'right'],
            ]"
            :is-empty="$this->invoices->isEmpty()"
            empty="No invoices have been issued yet."
        >
            @foreach ($this->invoices as $invoice)
                @php
                    $badge = $this->invoiceBadge($invoice);
                @endphp

                <tr wire:key="invoice-{{ $invoice->id }}">
                    <td class="border-b border-line py-2 font-mono text-xs">{{ $invoice->number }}</td>
                    <td class="border-b border-line py-2 text-ink-2">
                        {{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end->format('j M Y') }}
                    </td>
                    <td class="border-b border-line py-2"><x-badge :tone="$badge['tone']">{{ $badge['label'] }}</x-badge></td>
                    <td class="border-b border-line py-2 text-right tabular-nums">R{{ number_format((float) $invoice->amount, 2) }}</td>
                    <td class="border-b border-line py-2 text-right">
                        @unless ($invoice->status->isSettled())
                            <flux:button size="xs" variant="primary" wire:click="pay({{ $invoice->id }})">
                                Pay now
                            </flux:button>
                        @endunless
                    </td>
                </tr>

                @foreach ($invoice->lines as $line)
                    <tr wire:key="line-{{ $line->id }}">
                        <td class="border-b border-line py-1.5 pl-4 text-[12px] text-ink-muted" colspan="4">
                            {{ $line->site?->name ? $line->site->name.' · ' : '' }}{{ $line->label }}
                        </td>
                        <td class="border-b border-line py-1.5 text-right text-[12px] tabular-nums text-ink-muted">
                            R{{ number_format((float) $line->amount, 2) }}
                        </td>
                    </tr>
                @endforeach
            @endforeach
        </x-data-table>
    </x-panel>
</div>
