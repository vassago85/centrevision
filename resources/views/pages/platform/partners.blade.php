<?php

use App\Enums\PayoutStatus;
use App\Jobs\GeneratePartnerPayouts;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\PartnerPayout;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Partners')] class extends Component {
    // ── Add / edit modal ─────────────────────────────────────────────────
    /**
     * Modal visibility flag — bound directly to `<flux:modal>`. Kept
     * separate from `editingPartnerId` because Flux's modal treats its
     * wire:model as boolean open/closed state and will coerce an `?int`
     * property to `1` on close, silently retargeting the save. See the
     * matching comment on owners.blade.php for the full story.
     */
    public bool $showPartner = false;

    /**
     * Partner being edited, or null when adding a brand-new partner.
     * Independent from modal visibility now — the modal open state is
     * driven by `$showPartner`, so this stays purely about which record.
     */
    public ?int $editingPartnerId = null;

    public string $partnerName = '';

    public string $partnerEmail = '';

    /**
     * Held as a string percentage (0–100) so the input reads naturally to
     * a human ("20" for 20%). Converted to a 0.0–1.0 decimal on save.
     */
    public string $partnerCommissionPercent = '20';

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

    /**
     * Open the modal. Pass a partner id to edit, or null (default) to add
     * a brand-new partner. `editingPartnerId === null` is the "adding" state
     * now that modal visibility is tracked separately on `$showPartner`.
     */
    public function openPartner(?int $partnerId = null): void
    {
        if ($partnerId === null) {
            $this->editingPartnerId = null;
            $this->partnerName = '';
            $this->partnerEmail = '';
            $this->partnerCommissionPercent = '20';
        } else {
            $partner = Partner::query()->find($partnerId);

            if ($partner === null) {
                Flux::toast(variant: 'danger', text: 'That partner is gone.');

                return;
            }

            $this->editingPartnerId = $partner->getKey();
            $this->partnerName = (string) $partner->name;
            $this->partnerEmail = (string) $partner->email;
            $this->partnerCommissionPercent = $this->formatPercent((float) $partner->commission_rate);
        }

        $this->showPartner = true;
        $this->resetValidation();
    }

    public function closePartner(): void
    {
        $this->reset([
            'showPartner',
            'editingPartnerId',
            'partnerName',
            'partnerEmail',
            'partnerCommissionPercent',
        ]);
    }

    public function savePartner(): void
    {
        // "Creating" = editingPartnerId is null; "editing" = a real id we can
        // resolve. A non-null id that no longer resolves is a stale editor
        // (deleted between opens) — close it quietly rather than saving into
        // whatever record now sits at that key.
        $existing = $this->editingPartnerId === null
            ? null
            : Partner::query()->find($this->editingPartnerId);

        if ($this->editingPartnerId !== null && $existing === null) {
            Flux::toast(variant: 'danger', text: 'That partner no longer exists.');
            $this->closePartner();

            return;
        }

        $data = $this->validate([
            'partnerName' => ['required', 'string', 'max:120'],
            'partnerEmail' => [
                'required',
                'email',
                'max:255',
                Rule::unique('partners', 'email')->ignore($existing?->getKey()),
            ],
            // Percent as displayed (0–100). Converted below.
            'partnerCommissionPercent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $attributes = [
            'name' => trim($data['partnerName']),
            'email' => mb_strtolower(trim($data['partnerEmail'])),
            'commission_rate' => round(((float) $data['partnerCommissionPercent']) / 100, 6),
        ];

        if ($existing !== null) {
            $existing->update($attributes);
            $message = 'Partner updated.';
        } else {
            Partner::create($attributes);
            $message = 'Partner added.';
        }

        unset($this->partners);
        $this->closePartner();

        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Blast radius of a delete on the partner currently in the editor.
     * Returned as a plain array so it composes cleanly into the wire:confirm
     * string in the Blade template. The three numbers cover everything the
     * FKs will touch: attributed owners get their pointer nulled, and every
     * payout row is dropped via cascadeOnDelete.
     *
     * @return array{owners: int, payouts: int, paid_total: float, pending_total: float}
     */
    #[Computed]
    public function deleteImpact(): array
    {
        $empty = ['owners' => 0, 'payouts' => 0, 'paid_total' => 0.0, 'pending_total' => 0.0];

        if ($this->editingPartnerId === null) {
            return $empty;
        }

        $partner = Partner::query()->find($this->editingPartnerId);

        if ($partner === null) {
            return $empty;
        }

        return [
            'owners' => $partner->organizations()->count(),
            'payouts' => $partner->payouts()->count(),
            'paid_total' => (float) $partner->payouts()->where('status', PayoutStatus::Paid)->sum('commission_amount'),
            'pending_total' => (float) $partner->payouts()->where('status', PayoutStatus::Pending)->sum('commission_amount'),
        ];
    }

    /**
     * Remove the partner and everything the FKs let go of:
     *  - `organizations.referred_by_partner_id` → nulled by nullOnDelete, so
     *    attributed owners stay put and just lose their referrer badge.
     *  - `partner_payouts` → cascaded by the migration, so historical rows
     *    disappear. The confirmation upstream in the template spells this out
     *    with concrete counts pulled from `deleteImpact`.
     *
     * Wrapped in a transaction so a partial failure (e.g. an unrelated FK
     * added later) rolls both operations back rather than leaving orphaned
     * attributions.
     */
    public function deletePartner(): void
    {
        if ($this->editingPartnerId === null) {
            $this->closePartner();

            return;
        }

        $partner = Partner::query()->find($this->editingPartnerId);

        if ($partner === null) {
            Flux::toast(variant: 'danger', text: 'That partner no longer exists.');
            $this->closePartner();

            return;
        }

        $name = $partner->name;

        DB::transaction(function () use ($partner): void {
            // Explicit null-out on the owner side even though the FK already
            // handles it — keeps the intent obvious in a code search and
            // makes the operation portable if the migration ever changes.
            Organization::query()
                ->where('referred_by_partner_id', $partner->getKey())
                ->update(['referred_by_partner_id' => null]);

            $partner->delete();
        });

        unset($this->partners, $this->payouts, $this->deleteImpact);
        $this->closePartner();

        Flux::toast(variant: 'success', text: "Partner {$name} deleted.");
    }

    protected function formatPercent(float $rate): string
    {
        $percent = $rate * 100;

        // 20.0 → "20", 12.5 → "12.5" — same trailing-zero trim as the money
        // fields on the owners page so the input isn't visually noisy.
        return $percent === floor($percent)
            ? (string) (int) $percent
            : rtrim(rtrim(number_format($percent, 4, '.', ''), '0'), '.');
    }
}; ?>

<div>
    <x-page-header title="Partners" subtitle="Referrals and commission payouts">
        <x-slot:actions>
            <flux:button size="sm" variant="ghost" wire:click="recalculate">Recalculate last month</flux:button>
            <flux:button size="sm" variant="primary" wire:click="openPartner">Add partner</flux:button>
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
                ['label' => '', 'align' => 'right'],
            ]"
            :is-empty="$this->partners->isEmpty()"
            empty="No partners have been registered yet. Click 'Add partner' to get started."
        >
            @foreach ($this->partners as $partner)
                <tr wire:key="partner-{{ $partner->id }}">
                    <td class="border-b border-line py-2 font-medium">{{ $partner->name }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $partner->email }}</td>
                    <td class="border-b border-line py-2 text-right tabular-nums">{{ $partner->organizations_count }}</td>
                    <td class="border-b border-line py-2 text-right tabular-nums">
                        {{ rtrim(rtrim(number_format((float) $partner->commission_rate * 100, 4, '.', ''), '0'), '.') }}%
                    </td>
                    <td class="border-b border-line py-2 text-right tabular-nums">
                        R{{ number_format((float) $partner->pending_commission, 2) }}
                    </td>
                    <td class="border-b border-line py-2 text-right tabular-nums text-ink-2">
                        R{{ number_format((float) $partner->paid_commission, 2) }}
                    </td>
                    <td class="border-b border-line py-2 text-right">
                        <flux:button size="xs" variant="ghost" wire:click="openPartner({{ $partner->id }})">
                            Edit
                        </flux:button>
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

    {{--
        Same shape as the Owners edit-billing modal: the modal binds to a
        dedicated `showPartner` boolean, and content is gated on that flag.
        `editingPartnerId` stays a pure state field so Flux can't coerce a
        boolean into it during a close round-trip.
    --}}
    <flux:modal wire:model.self="showPartner" @close="$wire.closePartner()" class="md:w-[32rem]">
        @if ($showPartner)
            <form wire:submit.prevent="savePartner" class="space-y-5">
                <div>
                    <flux:heading size="lg">
                        {{ $editingPartnerId !== null ? 'Edit partner' : 'Add partner' }}
                    </flux:heading>
                    <p class="mt-1 text-[13px] text-ink-muted">
                        Partners earn a share of every owner they refer, calculated monthly and shown in the Payout history below.
                    </p>
                </div>

                <div class="grid gap-4">
                    <flux:input
                        wire:model="partnerName"
                        label="Name"
                        placeholder="e.g. Northgate Installs"
                        required
                    />

                    <flux:input
                        wire:model="partnerEmail"
                        type="email"
                        label="Email"
                        placeholder="paul@northgate.co.za"
                        description="Used to identify the partner and, later, to email them their payout advice."
                        required
                    />

                    <flux:input
                        wire:model="partnerCommissionPercent"
                        type="number"
                        step="0.0001"
                        min="0"
                        max="100"
                        label="Partner share (%)"
                        placeholder="20"
                        description="Their cut of invoiced business they bring — site fees, extra cameras, shops. You keep the rest. A 1/3 deal is 33.3333."
                        required
                    />
                </div>

                <div class="flex items-center justify-between gap-2">
                    <div>
                        @if ($editingPartnerId !== null)
                            @php
                                $impact = $this->deleteImpact;
                                // Build a plain-text confirm the browser will
                                // render in a native alert. Only mention the
                                // pieces that actually have something at stake
                                // so short-attention-span reads still land on
                                // the destructive bit.
                                $lines = ["Delete partner \"{$partnerName}\"?"];
                                if ($impact['owners'] > 0) {
                                    $lines[] = '';
                                    $lines[] = $impact['owners'] === 1
                                        ? '1 owner is currently attributed to this partner — their referrer will be cleared.'
                                        : "{$impact['owners']} owners are currently attributed — their referrer will be cleared.";
                                }
                                if ($impact['payouts'] > 0) {
                                    $lines[] = '';
                                    $lines[] = $impact['payouts'] === 1
                                        ? '1 payout record will be permanently deleted:'
                                        : "{$impact['payouts']} payout records will be permanently deleted:";
                                    $lines[] = '  Paid to date:  R'.number_format($impact['paid_total'], 2);
                                    $lines[] = '  Pending:       R'.number_format($impact['pending_total'], 2);
                                }
                                $lines[] = '';
                                $lines[] = 'This cannot be undone.';
                                $confirmMessage = implode("\n", $lines);
                            @endphp
                            <flux:button
                                variant="danger"
                                type="button"
                                wire:click="deletePartner"
                                wire:confirm="{{ $confirmMessage }}"
                            >Delete partner</flux:button>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <flux:button variant="ghost" type="button" wire:click="closePartner">Cancel</flux:button>
                        <flux:button variant="primary" type="submit">
                            {{ $editingPartnerId !== null ? 'Save changes' : 'Add partner' }}
                        </flux:button>
                    </div>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
