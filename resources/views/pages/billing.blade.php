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
    /**
     * A pending invoice for R0.00 should never need paying. It is shown as
     * flagged so someone looks into it; its status is left exactly as is.
     */
    public function needsReview(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Pending && round((float) $invoice->amount, 2) === 0.0;
    }
}; ?>

<div>
    <x-page-header title="Billing" :subtitle="$this->organization?->name">
        <x-slot:actions>
            <flux:button size="sm" variant="ghost" :href="route('shops')" wire:navigate>Manage shops</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <x-notice tone="info" class="mb-4">{{ session('status') }}</x-notice>
    @endif

    @php $flagged = $this->invoices->filter(fn ($invoice) => $this->needsReview($invoice)); @endphp
    @if ($flagged->isNotEmpty())
        <x-notice class="mb-4" :title="$flagged->count() === 1 ? 'One invoice needs checking' : $flagged->count().' invoices need checking'" data-test="zero-invoice-notice">
            {{ $flagged->pluck('number')->implode(', ') }} {{ $flagged->count() === 1 ? 'is' : 'are' }} marked "Awaiting payment" with a total of R0.00.
            Nothing is due on {{ $flagged->count() === 1 ? 'it' : 'them' }}; please contact
            <a href="mailto:{{ config('trafficflow.support_email') }}" class="font-medium text-accent underline">{{ config('trafficflow.support_email') }}</a>
            so the status can be corrected. We have not changed it automatically.
        </x-notice>
    @endif

    <div class="mb-4 grid grid-cols-4 gap-4 max-lg:grid-cols-2 max-sm:grid-cols-1">
        <x-metric label="Estimated this month" :value="'R'.number_format($this->total, 2)" delta="Across all sites · not yet invoiced" />
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

    <x-panel-card class="mb-4" title="Current period estimate" description="What this month will cost if nothing changes. It becomes an invoice at the end of the period.">
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
                            <span class="ml-1 text-[12px] text-ink-muted">capped</span>
                        @endif
                    </td>
                    <td class="border-b border-line py-2 text-right font-semibold tabular-nums">
                        R{{ number_format($charge->total(), 2) }}
                    </td>
                </tr>
            @endforeach

            @if ($this->charges->isNotEmpty())
                <tr>
                    <td colspan="6" class="py-2.5 text-right text-ink-2">Estimated total</td>
                    <td class="py-2.5 text-right text-[15px] font-semibold tabular-nums">R{{ number_format($this->total, 2) }}</td>
                </tr>
            @endif
        </x-data-table>
    </x-panel-card>

    <x-panel-card title="Issued invoices" description="Invoices that have been raised. Open one to see its lines.">
        @if ($this->invoices->isEmpty())
            <x-empty-state title="No invoices issued yet" icon="document-text">
                Your first invoice appears here at the end of the billing period.
            </x-empty-state>
        @else
            <div class="relative -mx-1 overflow-x-auto px-1">
                <table data-tf-table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th class="whitespace-nowrap border-b border-line py-2 text-left text-[12.5px] font-semibold text-ink-2">Invoice</th>
                            <th class="whitespace-nowrap border-b border-line py-2 text-left text-[12.5px] font-semibold text-ink-2">Period</th>
                            <th class="whitespace-nowrap border-b border-line py-2 text-left text-[12.5px] font-semibold text-ink-2">Status</th>
                            <th class="whitespace-nowrap border-b border-line py-2 text-right text-[12.5px] font-semibold text-ink-2">Amount</th>
                            <th class="border-b border-line py-2 text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    @foreach ($this->invoices as $invoice)
                        @php
                            $badge = $this->invoiceBadge($invoice);
                            $review = $this->needsReview($invoice);
                        @endphp
                        <tbody x-data="{ open: false }" wire:key="invoice-{{ $invoice->id }}">
                            <tr>
                                <td class="border-b border-line py-2">
                                    <button
                                        type="button"
                                        x-on:click="open = ! open"
                                        x-bind:aria-expanded="open.toString()"
                                        aria-controls="invoice-lines-{{ $invoice->id }}"
                                        class="inline-flex min-h-9 items-center gap-1.5 rounded font-mono text-[12.5px] text-ink hover:text-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                    >
                                        <flux:icon icon="chevron-right" class="size-3.5 transition-transform" x-bind:class="open && 'rotate-90'" aria-hidden="true" />
                                        {{ $invoice->number }}
                                        <span class="sr-only">— show {{ $invoice->lines->count() }} {{ \Illuminate\Support\Str::plural('line', $invoice->lines->count()) }}</span>
                                    </button>
                                </td>
                                <td class="whitespace-nowrap border-b border-line py-2 text-ink-2">
                                    {{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end->format('j M Y') }}
                                </td>
                                <td class="border-b border-line py-2">
                                    <span class="inline-flex flex-wrap items-center gap-1.5">
                                        <x-badge :tone="$badge['tone']">{{ $badge['label'] }}</x-badge>
                                        @if ($review)
                                            <x-badge tone="danger" data-test="needs-review-{{ $invoice->id }}">R0 · needs review</x-badge>
                                        @endif
                                    </span>
                                </td>
                                <td class="border-b border-line py-2 text-right font-semibold tabular-nums">R{{ number_format((float) $invoice->amount, 2) }}</td>
                                <td class="border-b border-line py-2 text-right">
                                    @if ($review)
                                        <span class="text-[12.5px] text-ink-muted">Nothing to pay</span>
                                    @elseif (! $invoice->status->isSettled())
                                        <flux:button size="sm" variant="primary" wire:click="pay({{ $invoice->id }})">
                                            Pay R{{ number_format((float) $invoice->amount, 2) }}
                                        </flux:button>
                                    @endif
                                </td>
                            </tr>

                            <tr id="invoice-lines-{{ $invoice->id }}" x-show="open" x-cloak>
                                <td colspan="5" class="border-b border-line bg-surface-2/60 px-3 py-2">
                                    @if ($invoice->lines->isEmpty())
                                        <p class="text-[12.5px] text-ink-muted">This invoice has no line items.</p>
                                    @else
                                        <ul class="divide-y divide-line">
                                            @foreach ($invoice->lines as $line)
                                                <li wire:key="line-{{ $line->id }}" class="flex items-baseline justify-between gap-4 py-1.5 text-[12.5px]">
                                                    <span class="text-ink-2">{{ $line->site?->name ? $line->site->name.' · ' : '' }}{{ $line->label }}</span>
                                                    <span class="tabular-nums text-ink">R{{ number_format((float) $line->amount, 2) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        @endif
    </x-panel-card>
</div>
