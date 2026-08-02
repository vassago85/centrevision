<?php

use App\Models\Invoice;
use App\Support\Platform\PlatformMetrics;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Platform')] class extends Component {
    #[Computed]
    public function metrics(): PlatformMetrics
    {
        return app(PlatformMetrics::class);
    }

    #[Computed]
    public function counts(): array
    {
        return $this->metrics()->counts();
    }

    #[Computed]
    public function health(): array
    {
        return $this->metrics()->ingestionHealth();
    }

    /**
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function unpaidInvoices(): Collection
    {
        return Invoice::query()
            ->whereIn('status', [App\Enums\InvoiceStatus::Pending, App\Enums\InvoiceStatus::Failed])
            ->with('billable:id,name')
            ->orderBy('period_start')
            ->limit(15)
            ->get();
    }
}; ?>

<div>
    <x-page-header title="Platform" subtitle="Every tenant, at a glance" />

    <div class="mb-7 grid grid-cols-4 gap-3 max-lg:grid-cols-2 max-sm:grid-cols-1">
        <x-metric
            label="Monthly recurring revenue"
            :value="'R'.number_format($this->metrics->monthlyRecurringRevenue(), 2)"
            delta="Site fees plus platform shop share"
        />
        <x-metric
            label="Outstanding"
            :value="'R'.number_format($this->metrics->outstanding(), 2)"
            :variant="$this->metrics->outstanding() > 0 ? 'danger' : 'default'"
            delta="Invoiced but unpaid"
        />
        <x-metric
            label="Owners"
            :value="$this->counts['owners']"
            :delta="$this->counts['sites'].' sites · '.$this->counts['cameras'].' cameras'"
        />
        <x-metric
            label="Payouts due"
            :value="'R'.number_format($this->metrics->payoutsDue(), 2)"
            :delta="$this->counts['partners'].' partners'"
        />
    </div>

    <x-panel heading="Last 24 hours">
        <div class="grid grid-cols-3 gap-3 max-sm:grid-cols-1">
            <x-metric label="Plate events" :value="number_format($this->health['events'])" />
            <x-metric label="Visits opened" :value="number_format($this->health['visits'])" />
            <x-metric
                label="Silent cameras"
                :value="$this->health['silent_cameras']"
                :variant="$this->health['silent_cameras'] > 0 ? 'danger' : 'default'"
                delta="Active but not reporting"
            />
        </div>
    </x-panel>

    <x-panel heading="Awaiting payment">
        <x-slot:actions>
            <flux:button size="sm" variant="ghost" :href="route('platform.owners')" wire:navigate>All owners</flux:button>
        </x-slot:actions>

        <x-data-table
            :headers="['Invoice', 'Billed to', 'Period', 'Status', ['label' => 'Amount', 'align' => 'right']]"
            :is-empty="$this->unpaidInvoices->isEmpty()"
            empty="Everything is settled."
        >
            @foreach ($this->unpaidInvoices as $invoice)
                <tr wire:key="unpaid-{{ $invoice->id }}">
                    <td class="border-b border-line py-2 font-mono text-xs">{{ $invoice->number }}</td>
                    <td class="border-b border-line py-2 font-medium">{{ $invoice->billable?->name }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $invoice->period_start->format('M Y') }}</td>
                    <td class="border-b border-line py-2">
                        <x-badge :tone="$invoice->status === App\Enums\InvoiceStatus::Failed ? 'danger' : 'warning'">
                            {{ $invoice->status->label() }}
                        </x-badge>
                    </td>
                    <td class="border-b border-line py-2 text-right tabular-nums">
                        R{{ number_format((float) $invoice->amount, 2) }}
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>
</div>
