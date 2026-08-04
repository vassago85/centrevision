<?php

use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use App\Mail\ShopInvitationMail;
use App\Models\Organization;
use App\Models\ShopInvitation;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sub-accounts')] class extends Component {
    public bool $showInvite = false;

    public ?int $siteId = null;

    public string $shopName = '';

    public string $email = '';

    public float $monthlyAmount = 0;

    /** Set when editing an existing shop's monthly fee. */
    public ?int $editingSubscriptionId = null;

    public function mount(): void
    {
        $this->monthlyAmount = (float) config('trafficflow.shop_monthly_amount_default');
    }

    protected function rules(): array
    {
        return [
            'siteId' => ['required', 'integer', Rule::in(app(Tenancy::class)->accessibleSiteIds())],
            'shopName' => ['required', 'string', 'max:160'],
            'email' => [
                'required', 'email', 'max:255',
                // One pending invitation per address per site keeps the shop
                // from receiving two links that create two organizations.
                Rule::unique('shop_invitations', 'email')
                    ->where('site_id', $this->siteId)
                    ->whereNull('accepted_at'),
            ],
            'monthlyAmount' => [
                'required', 'numeric',
                'min:'.config('trafficflow.shop_monthly_amount_min'),
                'max:'.config('trafficflow.shop_monthly_amount_max'),
            ],
        ];
    }

    #[Computed]
    public function shops(): Collection
    {
        return Organization::query()
            ->where('type', OrganizationType::Shop)
            ->whereIn('parent_site_id', app(Tenancy::class)->scopeSiteIds())
            ->with(['parentSite:id,name', 'shopSubscription'])
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function invitations(): Collection
    {
        return ShopInvitation::query()
            ->whereNull('accepted_at')
            ->with('site:id,name')
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function sites(): Collection
    {
        return app(Tenancy::class)->sites();
    }

    /**
     * Monthly revenue from shops that are actually paying, which is also what
     * drives the variable fee on the owner's own invoice.
     */
    #[Computed]
    public function payingRevenue(): float
    {
        return (float) $this->shops()
            ->filter(fn (Organization $shop) => $shop->shopSubscription?->status === SubscriptionStatus::Active)
            ->sum(fn (Organization $shop) => (float) $shop->shopSubscription->monthly_amount);
    }

    /**
     * Gross tenant billing split into what the platform keeps and what the
     * owner walks away with. Lets the "you charge tenants" metric show both
     * the top-line number and the net so the owner is not left doing mental
     * arithmetic against their platform_shop_revenue_share.
     *
     * @return array{gross: float, platform_share: float, owner_share: float}
     */
    #[Computed]
    public function tenantIncomeSplit(): array
    {
        $owner = app(Tenancy::class)->organization();

        if ($owner === null) {
            return ['gross' => 0.0, 'platform_share' => 0.0, 'owner_share' => 0.0];
        }

        return app(\App\Support\Billing\BillingCalculator::class)->shopRevenueSplit($owner);
    }

    /**
     * The rate the platform charges the owner per active camera per paying
     * shop, per month. Exposed to the view so the pricing panel stays in
     * sync when the platform changes it — no need to edit copy in Blade.
     */
    #[Computed]
    public function variableRate(): float
    {
        return (float) config('trafficflow.variable_rate_per_camera_per_subuser');
    }

    /**
     * The owner's current variable-fee footprint across every site they run,
     * summed. This is what today's shops-and-cameras mix would cost on their
     * next invoice, so the "how the variable fee works" panel can show a
     * real number instead of only a hypothetical example.
     *
     * @return array{sites: int, cameras: int, paying_shops: int, monthly: float}
     */
    #[Computed]
    public function variableFeeSummary(): array
    {
        $siteIds = $this->sites()->pluck('id');

        $cameras = (int) \App\Models\Camera::query()
            ->withoutGlobalScope(\App\Models\Scopes\SiteScope::class)
            ->whereIn('site_id', $siteIds)
            ->where('is_active', true)
            ->count();

        $payingShops = $this->shops()
            ->filter(fn (Organization $shop) => $shop->shopSubscription?->status === SubscriptionStatus::Active)
            ->count();

        return [
            'sites' => $siteIds->count(),
            'cameras' => $cameras,
            'paying_shops' => $payingShops,
            'monthly' => round($cameras * $payingShops * $this->variableRate(), 2),
        ];
    }

    public function invite(): void
    {
        $this->siteId ??= app(Tenancy::class)->currentSiteId() ?? $this->sites()->first()?->getKey();
        $this->validate();

        $site = Site::findOrFail($this->siteId);
        $this->authorize('manageShops', $site);

        $invitation = ShopInvitation::create([
            'site_id' => $site->getKey(),
            'shop_name' => $this->shopName,
            'email' => $this->email,
            'token' => ShopInvitation::generateToken(),
            'monthly_amount' => $this->monthlyAmount,
            'expires_at' => now()->addDays((int) config('trafficflow.shop_invitation_expires_days')),
        ]);

        Mail::to($invitation->email)->send(new ShopInvitationMail($invitation));

        $this->reset(['shopName', 'email', 'showInvite']);
        $this->monthlyAmount = (float) config('trafficflow.shop_monthly_amount_default');
        unset($this->invitations);

        Flux::toast(variant: 'success', text: 'Invitation sent. They have '.config('trafficflow.shop_invitation_expires_days').' days to accept.');
    }

    public function openInvite(): void
    {
        $this->resetValidation();
        $this->siteId = app(Tenancy::class)->currentSiteId() ?? $this->sites()->first()?->getKey();
        $this->showInvite = true;
    }

    public function revoke(int $invitationId): void
    {
        $invitation = ShopInvitation::findOrFail($invitationId);

        $this->authorize('manageShops', $invitation->site);

        $invitation->delete();
        unset($this->invitations);

        Flux::toast(variant: 'success', text: 'Invitation revoked.');
    }

    public function resend(int $invitationId): void
    {
        $invitation = ShopInvitation::findOrFail($invitationId);

        $this->authorize('manageShops', $invitation->site);

        $invitation->update([
            'expires_at' => now()->addDays((int) config('trafficflow.shop_invitation_expires_days')),
        ]);

        Mail::to($invitation->email)->send(new ShopInvitationMail($invitation));
        unset($this->invitations);

        Flux::toast(variant: 'success', text: 'Invitation resent.');
    }

    /**
     * Suspending a shop stops its access immediately and drops it out of the
     * owner's variable fee at the next invoice run.
     */
    public function toggleSuspension(int $shopId): void
    {
        $shop = $this->shops()->firstWhere('id', $shopId);

        abort_if($shop === null, 404);
        $this->authorize('manageShops', $shop->parentSite);

        $subscription = $shop->shopSubscription;

        abort_if($subscription === null, 404);

        $subscription->update([
            'status' => $subscription->grantsAccess() ? SubscriptionStatus::Canceled : SubscriptionStatus::Active,
        ]);

        unset($this->shops);

        Flux::toast(
            variant: 'success',
            text: $shop->name.($subscription->grantsAccess() ? ' reactivated.' : ' suspended.'),
        );
    }

    /**
     * @return array{tone: string, label: string}
     */
    public function statusBadge(?ShopSubscription $subscription): array
    {
        return match ($subscription?->status) {
            SubscriptionStatus::Active => ['tone' => 'positive', 'label' => 'Active'],
            SubscriptionStatus::Trialing => ['tone' => 'accent', 'label' => 'Trialing'],
            SubscriptionStatus::PastDue => ['tone' => 'warning', 'label' => 'Past due'],
            SubscriptionStatus::Canceled => ['tone' => 'danger', 'label' => 'Suspended'],
            default => ['tone' => 'neutral', 'label' => 'No subscription'],
        };
    }
}; ?>

<div>
    <x-page-header title="Sub-accounts" subtitle="Tenants you resell centre-wide analytics to">
        <x-slot:actions>
            <flux:button size="sm" variant="primary" wire:click="openInvite">Invite shop</flux:button>
        </x-slot:actions>
    </x-page-header>

    @php $income = $this->tenantIncomeSplit; @endphp
    <div class="mb-6 grid grid-cols-3 gap-3 max-sm:grid-cols-1">
        <x-metric label="Shops" :value="$this->shops->count()" delta="Total tenants" />
        <x-metric
            label="Paying"
            :value="$this->shops->filter(fn ($shop) => $shop->shopSubscription?->status === App\Enums\SubscriptionStatus::Active)->count()"
            delta="Adds to your platform bill"
        />
        <x-metric
            label="You charge tenants"
            :value="'R'.number_format($income['gross'], 2)"
            :delta="'per month · you keep R'.number_format($income['owner_share'], 2).' after platform share'"
            variant="positive"
        />
    </div>

    {{-- ── How the variable fee works ─────────────────────────────────────
         Explains where the "Drives your variable fee" number on the metric
         above actually goes, and shows the owner's real footprint so they
         can eyeball what they'd pay next month. --}}
    @php $vf = $this->variableFeeSummary; @endphp
    <div class="mb-7 flex items-start gap-4 rounded-tf border border-accent/30 bg-accent-soft p-5 dark:bg-accent-soft/40">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-white shadow-tf-sm">
            <flux:icon icon="information-circle" class="size-5" />
        </span>

        <div class="flex-1 space-y-3 text-[13.5px] leading-relaxed">
            <div>
                <p class="text-[15px] font-semibold text-ink">What the platform charges you for tenants</p>
                <p class="mt-1 text-ink-2">
                    The "You charge tenants" figure above is <em class="not-italic font-semibold text-ink">money coming
                    in</em> — what your tenants pay you each month. Separately, the platform adds a small
                    variable fee to <em class="not-italic font-semibold text-ink">your</em> monthly bill for
                    every paying shop, at a per-camera rate:
                </p>
            </div>

            <div class="rounded-md border border-line bg-surface px-3 py-2.5 font-mono text-[13px] text-ink">
                cameras × paying shops × R{{ number_format($this->variableRate, 2) }} = added to your platform bill
            </div>

            {{-- Live worked example using the owner's own totals so the
                 number is theirs, not a hypothetical. --}}
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <p class="text-ink-2">
                    <span class="font-semibold text-ink">Your footprint:</span>
                    {{ $vf['cameras'] }} active camera{{ $vf['cameras'] === 1 ? '' : 's' }}
                    across {{ $vf['sites'] }} site{{ $vf['sites'] === 1 ? '' : 's' }} ·
                    {{ $vf['paying_shops'] }} paying shop{{ $vf['paying_shops'] === 1 ? '' : 's' }}
                    → {{ $vf['cameras'] }} × {{ $vf['paying_shops'] }} × R{{ number_format($this->variableRate, 2) }} =
                    <span class="font-semibold text-ink">R{{ number_format($vf['monthly'], 2) }}/month</span>
                    added to your platform bill.
                </p>
                <a
                    href="{{ route('billing') }}"
                    wire:navigate
                    class="inline-flex items-center gap-1 whitespace-nowrap text-[12.5px] font-semibold text-accent hover:underline"
                >
                    See full invoice
                    <flux:icon icon="arrow-right" class="size-3.5" />
                </a>
            </div>

            <p class="text-[12.5px] text-ink-muted">
                Only paying shops count — trialing and suspended tenants are free. The fee scales by shop
                count, not by how much you charge them. The platform's share of what you charge tenants
                is set separately in Settings.
            </p>
        </div>
    </div>

    <x-panel heading="Shops">
        <x-data-table
            :headers="['Shop', 'Site', 'Users', 'Status', ['label' => 'Monthly', 'align' => 'right'], ['label' => '', 'align' => 'right']]"
            :is-empty="$this->shops->isEmpty()"
            empty="No shops yet. Invite your first tenant to start reselling."
        >
            @foreach ($this->shops as $shop)
                @php
                    $badge = $this->statusBadge($shop->shopSubscription);
                @endphp

                <tr wire:key="shop-{{ $shop->id }}">
                    <td class="border-b border-line py-2 font-medium">{{ $shop->name }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $shop->parentSite?->name }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $shop->users_count }}</td>
                    <td class="border-b border-line py-2"><x-badge :tone="$badge['tone']">{{ $badge['label'] }}</x-badge></td>
                    <td class="border-b border-line py-2 text-right tabular-nums">
                        {{ $shop->shopSubscription ? 'R'.number_format((float) $shop->shopSubscription->monthly_amount, 2) : '—' }}
                    </td>
                    <td class="border-b border-line py-2 text-right">
                        @if ($shop->shopSubscription)
                            <flux:button size="xs" variant="ghost" wire:click="toggleSuspension({{ $shop->id }})">
                                {{ $shop->shopSubscription->grantsAccess() ? 'Suspend' : 'Reactivate' }}
                            </flux:button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>

    <x-panel heading="Pending invitations">
        <x-data-table
            :headers="['Shop', 'Email', 'Site', 'Expires', ['label' => 'Monthly', 'align' => 'right'], ['label' => '', 'align' => 'right']]"
            :is-empty="$this->invitations->isEmpty()"
            empty="No invitations outstanding."
        >
            @foreach ($this->invitations as $invitation)
                <tr wire:key="invite-{{ $invitation->id }}">
                    <td class="border-b border-line py-2 font-medium">{{ $invitation->shop_name }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $invitation->email }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $invitation->site->name }}</td>
                    <td class="border-b border-line py-2">
                        @if ($invitation->hasExpired())
                            <x-badge tone="danger">Expired</x-badge>
                        @else
                            <span class="text-ink-2">{{ $invitation->expires_at->diffForHumans() }}</span>
                        @endif
                    </td>
                    <td class="border-b border-line py-2 text-right tabular-nums">
                        R{{ number_format((float) $invitation->monthly_amount, 2) }}
                    </td>
                    <td class="border-b border-line py-2 text-right">
                        <div class="flex justify-end gap-1">
                            <flux:button size="xs" variant="ghost" wire:click="resend({{ $invitation->id }})">Resend</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="revoke({{ $invitation->id }})">Revoke</flux:button>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>

    <flux:modal wire:model.self="showInvite" class="md:w-[28rem]">
        <form wire:submit="invite" class="space-y-5">
            <flux:heading size="lg">Invite a shop</flux:heading>

            <flux:select wire:model="siteId" label="Site">
                @foreach ($this->sites as $site)
                    <flux:select.option :value="$site->id">{{ $site->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="shopName" label="Shop name" placeholder="Woolworths Food" />
            <flux:input wire:model="email" type="email" label="Contact email" />

            <flux:input
                wire:model="monthlyAmount"
                type="number"
                step="0.01"
                label="Monthly fee (ZAR)"
                :description="'Between R'.number_format(config('trafficflow.shop_monthly_amount_min'), 2).' and R'.number_format(config('trafficflow.shop_monthly_amount_max'), 2).'.'"
            />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="$set('showInvite', false)">Cancel</flux:button>
                <flux:button variant="primary" type="submit">Send invitation</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
