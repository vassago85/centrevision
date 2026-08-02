<?php

use App\Support\Platform\OwnerSummary;
use App\Support\Platform\PlatformMetrics;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Owners')] class extends Component {
    public string $search = '';

    public bool $lapsedOnly = false;

    /**
     * @return Collection<int, OwnerSummary>
     */
    #[Computed]
    public function owners(): Collection
    {
        return app(PlatformMetrics::class)
            ->ownerSummaries()
            ->when($this->lapsedOnly, fn (Collection $owners) => $owners->filter(
                fn (OwnerSummary $owner) => $owner->lapsed,
            ))
            ->when($this->search !== '', fn (Collection $owners) => $owners->filter(
                fn (OwnerSummary $owner) => str_contains(
                    mb_strtolower($owner->organization->name),
                    mb_strtolower($this->search),
                ),
            ))
            ->values();
    }

    #[Computed]
    public function total(): float
    {
        return round($this->owners()->sum(fn (OwnerSummary $owner) => $owner->totalToPlatform()), 2);
    }
}; ?>

<div>
    <x-page-header title="Owners" subtitle="All owner organizations and their sites">
        <x-slot:actions>
            <flux:input size="sm" wire:model.live.debounce.300ms="search" placeholder="Search owners" class="w-56" />
            <flux:button
                size="sm"
                :variant="$lapsedOnly ? 'primary' : 'ghost'"
                wire:click="$toggle('lapsedOnly')"
            >Lapsed only</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-7 grid grid-cols-3 gap-3 max-sm:grid-cols-1">
        <x-metric label="Owners" :value="$this->owners->count()" />
        <x-metric
            label="Monthly value"
            :value="'R'.number_format($this->total, 2)"
            delta="Site fees plus platform shop share"
        />
        <x-metric
            label="Lapsed"
            :value="$this->owners->filter(fn ($owner) => $owner->lapsed)->count()"
            :variant="$this->owners->contains(fn ($owner) => $owner->lapsed) ? 'danger' : 'default'"
        />
    </div>

    <x-panel>
        <x-data-table
            :headers="[
                'Owner',
                'Partner',
                ['label' => 'Sites', 'align' => 'right'],
                ['label' => 'Cameras', 'align' => 'right'],
                ['label' => 'Shops', 'align' => 'right'],
                ['label' => 'Site fees', 'align' => 'right'],
                ['label' => 'Shop share', 'align' => 'right'],
                ['label' => 'Total', 'align' => 'right'],
            ]"
            :is-empty="$this->owners->isEmpty()"
            empty="No owners match that filter."
        >
            @foreach ($this->owners as $owner)
                <tr wire:key="owner-{{ $owner->organization->id }}">
                    <td class="border-b border-line py-2">
                        <span class="font-medium">{{ $owner->organization->name }}</span>

                        @if ($owner->lapsed)
                            <x-badge tone="danger" class="ml-2">Lapsed</x-badge>
                        @endif
                    </td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $owner->partner?->name ?? '—' }}</td>
                    <td class="border-b border-line py-2 text-right tabular-nums">{{ $owner->siteCount }}</td>
                    <td class="border-b border-line py-2 text-right tabular-nums">{{ $owner->cameraCount }}</td>
                    <td class="border-b border-line py-2 text-right tabular-nums">{{ $owner->payingShopCount }}</td>
                    <td class="border-b border-line py-2 text-right tabular-nums">
                        R{{ number_format($owner->monthlyCharge, 2) }}
                    </td>
                    <td class="border-b border-line py-2 text-right tabular-nums">
                        R{{ number_format($owner->platformShopShare, 2) }}
                    </td>
                    <td class="border-b border-line py-2 text-right font-semibold tabular-nums">
                        R{{ number_format($owner->totalToPlatform(), 2) }}
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>
</div>
