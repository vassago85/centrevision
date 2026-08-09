<?php

use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Mail\SecurityInvitationMail;
use App\Mail\ShopInvitationMail;
use App\Models\Organization;
use App\Models\SecurityInvitation;
use App\Models\ShopInvitation;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\User;
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

    // ── Security operator invitation state ──────────────────────────────
    // Distinct property names keep the shop and operator invite forms from
    // fighting over the same fields when both modals are on the page.
    public bool $showInviteOperator = false;

    public string $operatorName = '';

    public string $operatorEmail = '';

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

    // ── Security operators ──────────────────────────────────────────────

    /**
     * Guards and other security-desk staff hired by this owner.
     */
    #[Computed]
    public function operators(): Collection
    {
        $orgId = app(Tenancy::class)->organization()?->getKey();

        if ($orgId === null) {
            return collect();
        }

        return User::query()
            ->where('organization_id', $orgId)
            ->where('role', UserRole::SecurityOperator)
            ->orderBy('name')
            ->get();
    }

    /**
     * Invitations that have gone out but not yet been accepted or expired.
     */
    #[Computed]
    public function operatorInvitations(): Collection
    {
        $orgId = app(Tenancy::class)->organization()?->getKey();

        if ($orgId === null) {
            return collect();
        }

        return SecurityInvitation::query()
            ->where('organization_id', $orgId)
            ->whereNull('accepted_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Monthly cost the owner will see on their next invoice for the
     * operator seats they've already onboarded. Purely informational —
     * BillingCalculator is still the source of truth at invoice time.
     */
    #[Computed]
    public function operatorSeatCost(): float
    {
        return $this->operators->count()
            * (float) config('trafficflow.security_operator_monthly_amount');
    }

    public function openInviteOperator(): void
    {
        $this->resetValidation();
        $this->reset(['operatorName', 'operatorEmail']);
        $this->showInviteOperator = true;
    }

    public function inviteOperator(): void
    {
        $orgId = app(Tenancy::class)->organization()?->getKey();
        abort_if($orgId === null, 403);

        $validated = $this->validate([
            'operatorName' => ['required', 'string', 'max:160'],
            'operatorEmail' => [
                'required', 'email', 'max:255',
                // A live user with this address would collide on acceptance.
                // Owners re-inviting a former operator have to delete the
                // old user first, which is intentional: a stale login is a
                // security hole, not a convenience.
                Rule::unique('users', 'email'),
                Rule::unique('security_invitations', 'email')
                    ->where('organization_id', $orgId)
                    ->whereNull('accepted_at'),
            ],
        ]);

        $invitation = SecurityInvitation::create([
            'organization_id' => $orgId,
            'name' => $validated['operatorName'],
            'email' => $validated['operatorEmail'],
            'token' => SecurityInvitation::generateToken(),
            'expires_at' => now()->addDays((int) config('trafficflow.security_operator_invite_expires_days')),
        ]);

        Mail::to($invitation->email)->send(new SecurityInvitationMail($invitation));

        $this->reset(['operatorName', 'operatorEmail', 'showInviteOperator']);
        unset($this->operatorInvitations);

        Flux::toast(
            variant: 'success',
            text: 'Invitation sent. They have '.config('trafficflow.security_operator_invite_expires_days').' days to accept.',
        );
    }

    public function revokeOperatorInvitation(int $invitationId): void
    {
        $orgId = app(Tenancy::class)->organization()?->getKey();

        $invitation = SecurityInvitation::query()
            ->where('organization_id', $orgId)
            ->findOrFail($invitationId);

        $invitation->delete();

        unset($this->operatorInvitations);

        Flux::toast(variant: 'success', text: 'Invitation revoked.');
    }

    public function resendOperatorInvitation(int $invitationId): void
    {
        $orgId = app(Tenancy::class)->organization()?->getKey();

        $invitation = SecurityInvitation::query()
            ->where('organization_id', $orgId)
            ->findOrFail($invitationId);

        $invitation->update([
            'expires_at' => now()->addDays((int) config('trafficflow.security_operator_invite_expires_days')),
        ]);

        Mail::to($invitation->email)->send(new SecurityInvitationMail($invitation));

        unset($this->operatorInvitations);

        Flux::toast(variant: 'success', text: 'Invitation resent.');
    }

    /**
     * Remove a security operator seat. The user is deleted outright so their
     * login stops working and the seat drops off the next invoice.
     */
    public function removeOperator(int $userId): void
    {
        $orgId = app(Tenancy::class)->organization()?->getKey();

        $user = User::query()
            ->where('organization_id', $orgId)
            ->where('role', UserRole::SecurityOperator)
            ->findOrFail($userId);

        $user->delete();

        unset($this->operators);

        Flux::toast(variant: 'success', text: 'Operator removed.');
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
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent dark:bg-accent-2 text-white shadow-tf-sm">
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

    {{-- ── Security operators ────────────────────────────────────────────
         Guards the owner hires to watch plates day-to-day. Sits inside the
         same organization (so it inherits the site list) but has a slim
         permission set that keeps it away from billing and site config. --}}
    <div class="mt-8 mb-3 flex items-end justify-between gap-3">
        <div>
            <h2 class="text-[15px] font-semibold text-ink">Security operators</h2>
            <p class="mt-0.5 text-[13px] text-ink-2">
                Guards or on-site staff who watch every site you run.
                @php $operatorRate = number_format((float) config('trafficflow.security_operator_monthly_amount'), 2); @endphp
                R{{ $operatorRate }} per seat per month, added to your platform bill.
            </p>
        </div>
        <flux:button size="sm" variant="primary" wire:click="openInviteOperator">Invite operator</flux:button>
    </div>

    <x-panel heading="Active operators">
        <x-data-table
            :headers="['Name', 'Email', 'Joined', ['label' => '', 'align' => 'right']]"
            :is-empty="$this->operators->isEmpty()"
            empty="No operators yet. Invite your first to have someone watching plates in real time."
        >
            @foreach ($this->operators as $operator)
                <tr wire:key="operator-{{ $operator->id }}">
                    <td class="border-b border-line py-2 font-medium">{{ $operator->name }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $operator->email }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $operator->created_at->format('j M Y') }}</td>
                    <td class="border-b border-line py-2 text-right">
                        <flux:button
                            size="xs"
                            variant="ghost"
                            wire:click="removeOperator({{ $operator->id }})"
                            wire:confirm="Remove {{ $operator->name }}? Their login will stop working immediately."
                        >Remove</flux:button>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
        @if ($this->operators->isNotEmpty())
            {{-- Rough forecast so the owner isn't surprised when the seats
                 show up on the next invoice. --}}
            <p class="mt-3 text-right text-[12.5px] text-ink-muted">
                {{ $this->operators->count() }} seat{{ $this->operators->count() === 1 ? '' : 's' }}
                × R{{ number_format((float) config('trafficflow.security_operator_monthly_amount'), 2) }}
                = <span class="font-semibold text-ink">R{{ number_format($this->operatorSeatCost, 2) }}/month</span>
            </p>
        @endif
    </x-panel>

    <x-panel heading="Pending operator invitations">
        <x-data-table
            :headers="['Name', 'Email', 'Expires', ['label' => '', 'align' => 'right']]"
            :is-empty="$this->operatorInvitations->isEmpty()"
            empty="No operator invitations outstanding."
        >
            @foreach ($this->operatorInvitations as $invitation)
                <tr wire:key="operator-invite-{{ $invitation->id }}">
                    <td class="border-b border-line py-2 font-medium">{{ $invitation->name }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $invitation->email }}</td>
                    <td class="border-b border-line py-2">
                        @if ($invitation->hasExpired())
                            <x-badge tone="danger">Expired</x-badge>
                        @else
                            <span class="text-ink-2">{{ $invitation->expires_at->diffForHumans() }}</span>
                        @endif
                    </td>
                    <td class="border-b border-line py-2 text-right">
                        <div class="flex justify-end gap-1">
                            <flux:button size="xs" variant="ghost" wire:click="resendOperatorInvitation({{ $invitation->id }})">Resend</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="revokeOperatorInvitation({{ $invitation->id }})">Revoke</flux:button>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>

    <flux:modal wire:model.self="showInviteOperator" class="md:w-[28rem]">
        <form wire:submit="inviteOperator" class="space-y-5">
            <flux:heading size="lg">Invite a security operator</flux:heading>

            <flux:input wire:model="operatorName" label="Full name" placeholder="Jane Radebe" />
            <flux:input wire:model="operatorEmail" type="email" label="Email address" />

            <p class="text-[13px] text-ink-2">
                They will get an email with a link that lets them set their own password. They will see
                every site you run, plus cameras, security and the watchlist — no billing or shop tools.
            </p>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="$set('showInviteOperator', false)">Cancel</flux:button>
                <flux:button variant="primary" type="submit">Send invitation</flux:button>
            </div>
        </form>
    </flux:modal>

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
