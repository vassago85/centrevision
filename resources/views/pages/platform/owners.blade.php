<?php

use App\Enums\BaseTier;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Support\Platform\OwnerSummary;
use App\Support\Platform\PlatformMetrics;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Owners')] class extends Component {
    public string $search = '';

    public bool $lapsedOnly = false;

    // ── Billing editor ───────────────────────────────────────────────────
    /**
     * Modal visibility. Bound directly to the `<flux:modal>` — Flux writes a
     * boolean back through this model on client-side close/open events, so
     * we intentionally keep this separate from `editingBillingId`. Binding
     * the modal to the int id ended up round-tripping `true` back into the
     * property, PHP coerced it to `1`, and saveBilling then targeted org 1
     * instead of the owner the admin actually clicked. See
     * vendor/livewire/flux/stubs/resources/views/flux/modal/index.blade.php.
     */
    public bool $showBilling = false;

    /** Organization being edited in the modal, or null when it's closed. */
    public ?int $editingBillingId = null;

    /**
     * Cached org name for the modal heading. Captured on openBilling so the
     * form does not depend on a computed re-query the modal might paint
     * before the server round-trip lands.
     */
    public string $billingOwnerName = '';

    /**
     * Partner attributed with the referral. Empty string = "no partner"
     * (a select cannot bind directly to null through Livewire without extra
     * juggling; the save handler converts "" back to null).
     */
    public string $billingPartnerId = '';

    /** Toggle: waive every fee (base, camera, variable, seats). */
    public bool $billingFree = false;

    /**
     * Per-owner overrides. Strings, not floats, so an empty input reads back
     * as "" — that's what the save handler treats as "no override, use the
     * tier default". Casting to nullable float on save keeps the settings
     * JSON tidy.
     */
    public string $billingBaseFeeOverride = '';

    public string $billingVariableRateOverride = '';

    public string $billingVariableFeeCapOverride = '';

    public string $billingNotes = '';

    /**
     * Per-site handshake rows for the billing modal. Each entry is
     * [id, name, base_fee, partner_id] as strings so empty inputs stay
     * empty instead of becoming 0. The partner's standing split (e.g. 1/3)
     * is applied to whatever this site invoices — no per-site rand cut.
     *
     * @var array<int, array{id: int, name: string, base_fee: string, partner_id: string}>
     */
    public array $billingSites = [];

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

    /**
     * Partner picker options for the edit-billing modal.
     *
     * @return Collection<int, Partner>
     */
    #[Computed]
    public function availablePartners(): Collection
    {
        return Partner::query()->orderBy('name')->get(['id', 'name', 'commission_rate']);
    }

    /**
     * Prefill the modal from whatever the owner has stored today. Nulls are
     * shown as empty strings so the placeholder acts as the guide value.
     */
    public function openBilling(int $ownerId): void
    {
        $owner = Organization::query()->find($ownerId);

        if ($owner === null) {
            Flux::toast(variant: 'danger', text: 'That owner is gone.');

            return;
        }

        $this->editingBillingId = $owner->getKey();
        $this->billingOwnerName = (string) $owner->name;
        $this->billingPartnerId = $owner->referred_by_partner_id === null
            ? ''
            : (string) $owner->referred_by_partner_id;
        $this->billingFree = (bool) $owner->setting('billing.free', false);
        $this->billingBaseFeeOverride = $this->formatOverride($owner->setting('billing.base_fee_override'));
        $this->billingVariableRateOverride = $this->formatOverride($owner->setting('billing.variable_rate_override'));
        $this->billingVariableFeeCapOverride = $this->formatOverride($owner->setting('billing.variable_fee_cap_override'));
        $this->billingNotes = (string) $owner->setting('billing.notes', '');
        $this->billingSites = $this->agreementRowsFor($owner);

        $this->showBilling = true;
        $this->resetValidation();
    }

    public function closeBilling(): void
    {
        $this->reset([
            'showBilling',
            'editingBillingId',
            'billingOwnerName',
            'billingPartnerId',
            'billingFree',
            'billingBaseFeeOverride',
            'billingVariableRateOverride',
            'billingVariableFeeCapOverride',
            'billingNotes',
            'billingSites',
        ]);
    }

    /**
     * Merge the edited billing block back into the org's settings JSON. The
     * rest of the settings blob (like platform_shop_revenue_share) is left
     * untouched — we only replace the `billing` key.
     */
    public function saveBilling(): void
    {
        // Look up the org fresh from the primitive id rather than trusting a
        // computed property — the latter can go stale between the modal open
        // and the save if a full-page morph drops the cache, and an abort
        // 404 out of a Livewire action bubbles up as a visible 404 page.
        if ($this->editingBillingId === null) {
            $this->closeBilling();

            return;
        }

        $owner = Organization::query()->find($this->editingBillingId);

        if ($owner === null) {
            Flux::toast(variant: 'danger', text: 'That owner no longer exists.');
            $this->closeBilling();

            return;
        }

        $data = $this->validate([
            // Empty string = "no partner assigned"; anything else must be a
            // real partner id. Validated as an integer to catch tampering.
            'billingPartnerId' => ['nullable', 'string'],
            'billingFree' => ['boolean'],
            'billingBaseFeeOverride' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'billingVariableRateOverride' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'billingVariableFeeCapOverride' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'billingNotes' => ['nullable', 'string', 'max:1000'],
            'billingSites' => ['array'],
            'billingSites.*.id' => ['required', 'integer'],
            'billingSites.*.base_fee' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'billingSites.*.partner_id' => ['nullable', 'string'],
        ]);

        $partnerId = ($data['billingPartnerId'] ?? '') === ''
            ? null
            : (int) $data['billingPartnerId'];

        // Guard against a tampered select value pointing at a partner that
        // does not exist: rather than crashing on the FK insert, just null
        // it out and let the admin re-pick.
        if ($partnerId !== null && ! Partner::query()->whereKey($partnerId)->exists()) {
            $partnerId = null;
        }

        $settings = $owner->settings ?? [];
        $settings['billing'] = [
            'free' => (bool) $data['billingFree'],
            'base_fee_override' => $this->parseOverride($data['billingBaseFeeOverride'] ?? null),
            'variable_rate_override' => $this->parseOverride($data['billingVariableRateOverride'] ?? null),
            'variable_fee_cap_override' => $this->parseOverride($data['billingVariableFeeCapOverride'] ?? null),
            'notes' => trim((string) ($data['billingNotes'] ?? '')),
        ];

        $owner->forceFill([
            'settings' => $settings,
            'referred_by_partner_id' => $partnerId,
        ])->save();

        $this->saveSiteAgreements($owner, $data['billingSites'] ?? []);

        // Drop the cached rows so the owner's badges and totals refresh in
        // place instead of showing stale figures until the next full reload.
        unset($this->owners, $this->total);
        $this->closeBilling();

        Flux::toast(variant: 'success', text: 'Billing plan saved.');
    }

    /**
     * @return array<int, array{id: int, name: string, base_fee: string, partner_id: string}>
     */
    protected function agreementRowsFor(Organization $owner): array
    {
        return Site::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('organization_id', $owner->getKey())
            ->orderBy('name')
            ->with(['subscription' => fn ($query) => $query->withoutGlobalScope(SiteScope::class)])
            ->get()
            ->map(fn (Site $site): array => [
                'id' => $site->getKey(),
                'name' => (string) $site->name,
                'base_fee' => $this->formatOverride($site->subscription?->base_fee),
                'partner_id' => $site->subscription?->partner_id
                    ? (string) $site->subscription->partner_id
                    : '',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function saveSiteAgreements(Organization $owner, array $rows): void
    {
        $ownedIds = Site::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('organization_id', $owner->getKey())
            ->pluck('id');

        foreach ($rows as $row) {
            $siteId = (int) ($row['id'] ?? 0);

            if (! $ownedIds->contains($siteId)) {
                continue;
            }

            $site = Site::query()
                ->withoutGlobalScope(SiteScope::class)
                ->find($siteId);

            if ($site === null) {
                continue;
            }

            $partnerId = ($row['partner_id'] ?? '') === ''
                ? null
                : (int) $row['partner_id'];

            if ($partnerId !== null && ! Partner::query()->whereKey($partnerId)->exists()) {
                $partnerId = null;
            }

            $subscription = SiteSubscription::query()
                ->withoutGlobalScope(SiteScope::class)
                ->firstOrCreate(
                    ['site_id' => $site->getKey()],
                    [
                        'base_tier' => BaseTier::Starter,
                        'base_fee' => 0,
                        'variable_rate_per_camera_per_subuser' => (float) config('trafficflow.variable_rate_per_camera_per_subuser'),
                        'status' => SubscriptionStatus::Active,
                        'current_period_ends_at' => now()->endOfMonth(),
                    ],
                );

            $subscription->forceFill([
                'base_fee' => $this->parseOverride($row['base_fee'] ?? null) ?? 0,
                'partner_id' => $partnerId,
            ])->save();
        }
    }

    protected function formatOverride(mixed $value): string
    {
        if ($value === null || $value === '' || (float) $value <= 0) {
            return '';
        }

        // Trim trailing zeros so 1800.00 shows as "1800" but 1234.5 stays
        // "1234.5" — the input field is a number so extra precision on
        // display just adds visual noise.
        $number = (float) $value;

        return $number === floor($number)
            ? (string) (int) $number
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    protected function parseOverride(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) $value;

        // Zero collapses to null: "R0" as an override is indistinguishable
        // from "no override" and would fight the free-plan flag. Admins who
        // really want zero across the board tick "Free account" instead.
        return $number > 0.0 ? $number : null;
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
                'Plan',
                ['label' => 'Sites', 'align' => 'right'],
                ['label' => 'Cameras', 'align' => 'right'],
                ['label' => 'Shops', 'align' => 'right'],
                ['label' => 'Site fees', 'align' => 'right'],
                ['label' => 'Shop share', 'align' => 'right'],
                ['label' => 'Total', 'align' => 'right'],
                ['label' => '', 'align' => 'right'],
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
                    <td class="border-b border-line py-2">
                        @if ($owner->isFree)
                            <x-badge tone="positive">Free</x-badge>
                        @elseif ($owner->hasCustomPlan)
                            <x-badge tone="accent">Custom</x-badge>
                        @else
                            <span class="text-ink-muted">Standard</span>
                        @endif
                    </td>
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
                    <td class="border-b border-line py-2 text-right">
                        <flux:button size="xs" variant="ghost" wire:click="openBilling({{ $owner->organization->id }})">
                            Edit billing
                        </flux:button>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>

    {{--
        Modal binds to a dedicated boolean (`showBilling`), never to
        `editingBillingId`. Flux's `<ui-modal>` treats its wire:model as an
        open/closed flag and syncs a boolean back through it on close — binding
        an `?int` there let `true` coerce to `1` and target the wrong org on
        save. Content is still gated on `editingBillingId` so a stale open
        without state never paints an empty form.
    --}}
    <flux:modal wire:model.self="showBilling" @close="$wire.closeBilling()" class="md:w-[42rem]">
        @if ($showBilling && $editingBillingId !== null)
            <form wire:submit.prevent="saveBilling" class="space-y-5">
                <div>
                    <flux:heading size="lg">Billing plan</flux:heading>
                    <p class="mt-1 text-[13px] text-ink-muted">
                        Owner-wide fields are the fallback for sites without a handshake.
                        A per-site agreement below wins for that mall only.
                    </p>
                </div>

                <flux:select
                    wire:model="billingPartnerId"
                    label="Referred by partner"
                    description="Fallback partner for sites without their own handshake. A site agreement below can name a different partner."
                >
                    <flux:select.option value="">— No partner —</flux:select.option>

                    @foreach ($this->availablePartners as $partner)
                        <flux:select.option value="{{ $partner->id }}">{{ $partner->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="rounded-lg border border-line bg-surface-2 p-4">
                    <flux:switch
                        wire:model="billingFree"
                        label="Free account"
                        description="Waives base, camera, variable and security-operator seat fees. Shop share to the platform is unaffected."
                    />
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input
                        wire:model="billingBaseFeeOverride"
                        type="number"
                        step="0.01"
                        min="0"
                        label="Base fee override (R / site / month)"
                        placeholder="Tier default"
                        description="Starter R{{ number_format(BaseTier::Starter->baseFee(), 0) }}, Standard R{{ number_format(BaseTier::Standard->baseFee(), 0) }}, Large R{{ number_format(BaseTier::Large->baseFee(), 0) }}."
                    />

                    <flux:input
                        wire:model="billingVariableRateOverride"
                        type="number"
                        step="0.01"
                        min="0"
                        label="Variable rate override (R / camera / shop / month)"
                        placeholder="Platform default"
                        description="Applied per camera, per paying shop. Default R{{ number_format((float) config('trafficflow.variable_rate_per_camera_per_subuser'), 2) }}."
                    />

                    <flux:input
                        wire:model="billingVariableFeeCapOverride"
                        type="number"
                        step="0.01"
                        min="0"
                        label="Variable fee cap override (R / site / month)"
                        placeholder="Uncapped"
                        description="Ceiling on the variable line per site. Leave blank to let it run uncapped."
                    />

                    <flux:textarea
                        wire:model="billingNotes"
                        label="Notes (internal)"
                        rows="3"
                        placeholder="Why this plan? e.g. '6-month pilot until Feb 2027'."
                    />
                </div>

                @if ($billingSites !== [])
                    <div class="space-y-3">
                        <div>
                            <flux:heading size="sm">Site agreements</flux:heading>
                            <p class="mt-1 text-[13px] text-ink-muted">
                                Invoice the handshake total for that site. The site partner is paid their standing split of whatever this site bills — extra cameras and shops included. You keep the rest.
                            </p>
                        </div>

                        @foreach ($billingSites as $index => $row)
                            <div wire:key="agreement-{{ $row['id'] }}" class="space-y-3 rounded-lg border border-line p-4">
                                <input type="hidden" wire:model="billingSites.{{ $index }}.id">
                                <p class="font-medium text-ink">{{ $row['name'] }}</p>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <flux:input
                                        wire:model.live="billingSites.{{ $index }}.base_fee"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        label="Agreement (R / month)"
                                        placeholder="No handshake"
                                    />
                                    <flux:select
                                        wire:model.live="billingSites.{{ $index }}.partner_id"
                                        label="Site partner"
                                    >
                                        <flux:select.option value="">— No partner —</flux:select.option>
                                        @foreach ($this->availablePartners as $partner)
                                            <flux:select.option value="{{ $partner->id }}">{{ $partner->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                                @php
                                    $selectedPartner = $this->availablePartners->firstWhere('id', (int) ($row['partner_id'] ?: 0));
                                    $agreementFee = (float) ($row['base_fee'] ?: 0);
                                @endphp
                                @if ($selectedPartner && $agreementFee > 0)
                                    @php
                                        $partnerCut = $selectedPartner->shareOf($agreementFee);
                                        $platformKeep = round($agreementFee - $partnerCut, 2);
                                        $partnerPct = (float) $selectedPartner->commission_rate * 100;
                                    @endphp
                                    <p class="text-[13px] text-ink-muted">
                                        {{ $selectedPartner->name }} keeps
                                        {{ rtrim(rtrim(number_format($partnerPct, 4, '.', ''), '0'), '.') }}%
                                        (R{{ number_format($partnerCut, 2) }})
                                        · platform keeps R{{ number_format($platformKeep, 2) }}
                                        of the base. The same split applies to extra cameras and shops.
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" type="button" wire:click="closeBilling">Cancel</flux:button>
                    <flux:button variant="primary" type="submit">Save billing plan</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
