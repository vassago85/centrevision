<?php

use App\Enums\PlateTagType;
use App\Enums\ReportSchedule;
use App\Enums\UserRole;
use App\Models\PlateTag;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Settings')] class extends Component {
    public ?int $siteId = null;

    public string $name = '';

    public string $address = '';

    public int $dwellAlertHours = 4;

    public int $orphanAfterHours = 12;

    public int $retentionDays = 365;

    public int $recurringWindowDays = 28;

    public float $recurringMinWeekdayRatio = 0.8;

    public float $recurringMaxArrivalStddevMinutes = 30;

    public float $platformShopRevenueShare = 0.3;

    public string $reportSchedule = 'off';

    public string $reportRecipients = '';

    public function mount(): void
    {
        $this->siteId = app(Tenancy::class)->currentSiteId() ?? app(Tenancy::class)->sites()->first()?->getKey();

        $this->loadSite();

        $this->platformShopRevenueShare = (float) app(Tenancy::class)
            ->organization()
            ->setting('platform_shop_revenue_share');
    }

    public function updatedSiteId(): void
    {
        $this->loadSite();
    }

    protected function loadSite(): void
    {
        $site = $this->site();

        if ($site === null) {
            return;
        }

        $this->name = $site->name;
        $this->address = $site->address ?? '';
        $this->dwellAlertHours = $site->dwellAlertHours();
        $this->orphanAfterHours = $site->orphanAfterHours();
        $this->retentionDays = $site->retentionDays();
        $this->recurringWindowDays = (int) $site->setting('recurring_window_days');
        $this->recurringMinWeekdayRatio = (float) $site->setting('recurring_min_weekday_ratio');
        $this->recurringMaxArrivalStddevMinutes = (float) $site->setting('recurring_max_arrival_stddev_minutes');
        $this->reportSchedule = $site->reportSchedule()->value;
        $this->reportRecipients = implode(', ', $site->reportRecipients());
    }

    #[Computed]
    public function sites(): Collection
    {
        return app(Tenancy::class)->sites();
    }

    public function site(): ?Site
    {
        return $this->siteId === null ? null : Site::find($this->siteId);
    }

    #[Computed]
    public function teamMembers(): Collection
    {
        return User::query()
            ->where('organization_id', app(Tenancy::class)->organization()?->getKey())
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function recurringPlateCount(): int
    {
        return PlateTag::query()->where('tag', PlateTagType::RecurringPattern)->count();
    }

    public function save(): void
    {
        $site = $this->site();

        abort_if($site === null, 404);
        $this->authorize('update', $site);

        $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'dwellAlertHours' => ['required', 'integer', Rule::in(config('trafficflow.dwell_alert_options'))],
            'orphanAfterHours' => ['required', 'integer', 'min:1', 'max:72'],
            'retentionDays' => [
                'required', 'integer',
                'min:'.config('trafficflow.retention_min_days'),
                'max:'.config('trafficflow.retention_max_days'),
            ],
            'recurringWindowDays' => ['required', 'integer', 'min:7', 'max:90'],
            'recurringMinWeekdayRatio' => ['required', 'numeric', 'min:0.5', 'max:1'],
            'recurringMaxArrivalStddevMinutes' => ['required', 'numeric', 'min:5', 'max:180'],
        ]);

        $site->update([
            'name' => $this->name,
            'address' => $this->address ?: null,
            // Merged, not replaced: the report settings live in the same JSON
            // column and are saved by their own form.
            'settings' => [
                ...($site->settings ?? []),
                'dwell_alert_hours' => $this->dwellAlertHours,
                'orphan_after_hours' => $this->orphanAfterHours,
                'retention_days' => $this->retentionDays,
                'recurring_window_days' => $this->recurringWindowDays,
                'recurring_min_weekday_ratio' => $this->recurringMinWeekdayRatio,
                'recurring_max_arrival_stddev_minutes' => $this->recurringMaxArrivalStddevMinutes,
            ],
        ]);

        Flux::toast(variant: 'success', text: 'Site settings saved.');
    }

    /**
     * Recipients are typed as a free-text list, which is far less friction
     * than a repeater for what is usually two addresses.
     */
    public function saveReportSchedule(): void
    {
        $site = $this->site();

        abort_if($site === null, 404);
        $this->authorize('update', $site);

        $recipients = collect(preg_split('/[\s,;]+/', $this->reportRecipients, flags: PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->unique()
            ->values();

        Validator::make(
            ['schedule' => $this->reportSchedule, 'recipients' => $recipients->all()],
            [
                'schedule' => ['required', Rule::enum(ReportSchedule::class)],
                'recipients' => ['array', 'max:'.config('trafficflow.report_max_recipients')],
                'recipients.*' => ['email'],
            ],
            [],
            ['recipients.*' => 'recipient'],
        )->validateWithBag('default');

        $site->update([
            'settings' => [
                ...($site->settings ?? []),
                'report_schedule' => $this->reportSchedule,
                'report_recipients' => $recipients->all(),
            ],
        ]);

        $this->reportRecipients = $recipients->implode(', ');

        Flux::toast(variant: 'success', text: 'Report schedule saved.');
    }

    public function saveRevenueShare(): void
    {
        $organization = app(Tenancy::class)->organization();

        abort_if($organization === null, 404);
        $this->authorize('manage billing');

        $this->validate(['platformShopRevenueShare' => ['required', 'numeric', 'min:0', 'max:0.9']]);

        $organization->update([
            'settings' => [
                ...($organization->settings ?? []),
                'platform_shop_revenue_share' => $this->platformShopRevenueShare,
            ],
        ]);

        Flux::toast(variant: 'success', text: 'Revenue share saved.');
    }

    /**
     * Clearing the staff tags makes TagRecurringPlates rebuild them on its
     * next nightly run, which is the way to recover from a bad threshold.
     */
    public function clearRecurringTags(): void
    {
        $site = $this->site();

        abort_if($site === null, 404);
        $this->authorize('update', $site);

        PlateTag::query()
            ->where('site_id', $site->getKey())
            ->where('tag', PlateTagType::RecurringPattern)
            ->delete();

        unset($this->recurringPlateCount);

        Flux::toast(variant: 'success', text: 'Staff-pattern tags cleared. They will be rebuilt tonight.');
    }
}; ?>

<div>
    <x-page-header title="Settings" subtitle="Site, thresholds, and access">
        <x-slot:actions>
            @if ($this->sites->count() > 1)
                <flux:select wire:model.live="siteId" size="sm" class="min-w-44" label="Site" label:sr-only>
                    @foreach ($this->sites as $site)
                        <flux:select.option :value="$site->id">{{ $site->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </x-slot:actions>
    </x-page-header>

    <form wire:submit="save">
        <x-panel heading="Site">
            <div class="grid grid-cols-2 gap-4 rounded-tf border border-line bg-surface p-5 max-sm:grid-cols-1">
                <flux:input wire:model="name" label="Name" />
                <flux:input wire:model="address" label="Address" />
            </div>
        </x-panel>

        <x-panel heading="Thresholds">
            <div class="grid grid-cols-3 gap-4 rounded-tf border border-line bg-surface p-5 max-md:grid-cols-1">
                <flux:select wire:model="dwellAlertHours" label="Dwell alert" description="Flags a vehicle on the Security page once it passes this.">
                    @foreach (config('trafficflow.dwell_alert_options') as $hours)
                        <flux:select.option :value="$hours">{{ $hours }} hours</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model="orphanAfterHours"
                    type="number"
                    label="Orphan after (hours)"
                    description="An open visit older than this is assumed to have a missed exit."
                />

                <flux:input
                    wire:model="retentionDays"
                    type="number"
                    label="Retention (days)"
                    :description="'Plate data is deleted after this. Between '.config('trafficflow.retention_min_days').' and '.config('trafficflow.retention_max_days').' days.'"
                />
            </div>
        </x-panel>

        <x-panel heading="Staff-pattern detection">
            <div class="rounded-tf border border-line bg-surface p-5">
                <p class="mb-4 max-w-prose text-[13px] text-ink-2">
                    Vehicles matching this pattern are treated as staff or tenants and left out of every
                    shopper-facing figure. The Security page still shows them.
                    {{ number_format($this->recurringPlateCount) }} plates are currently tagged.
                </p>

                <div class="grid grid-cols-3 gap-4 max-md:grid-cols-1">
                    <flux:input wire:model="recurringWindowDays" type="number" label="Window (days)" />
                    <flux:input
                        wire:model="recurringMinWeekdayRatio"
                        type="number"
                        step="0.05"
                        label="Min weekday presence"
                        description="0.8 means present on 80% of weekdays."
                    />
                    <flux:input
                        wire:model="recurringMaxArrivalStddevMinutes"
                        type="number"
                        label="Arrival consistency (min)"
                        description="Lower is stricter."
                    />
                </div>

                <div class="mt-4">
                    <flux:button
                        size="sm"
                        variant="ghost"
                        type="button"
                        wire:click="clearRecurringTags"
                        wire:confirm="Clear every staff-pattern tag for this site? They rebuild on tonight's run."
                    >Clear tags and re-detect</flux:button>
                </div>
            </div>
        </x-panel>

        <div class="mb-8 flex justify-end">
            <flux:button variant="primary" type="submit">Save site settings</flux:button>
        </div>
    </form>

    <x-panel heading="Scheduled reports">
        <form wire:submit="saveReportSchedule" class="rounded-tf border border-line bg-surface p-5">
            <p class="mb-4 max-w-prose text-[13px] text-ink-2">
                Emails the traffic report as a PDF and a CSV. Aggregates only — vehicle registration
                numbers are never included.
            </p>

            <div class="flex flex-wrap items-end gap-4">
                <flux:select wire:model="reportSchedule" label="Frequency" class="max-w-56">
                    @foreach (ReportSchedule::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model="reportRecipients"
                    label="Recipients"
                    description="Comma separated."
                    placeholder="centre.manager@example.com, ops@example.com"
                    class="min-w-80 flex-1"
                />

                <flux:button variant="primary" type="submit">Save</flux:button>
            </div>
        </form>
    </x-panel>

    <x-panel heading="Shop revenue share">
        <form wire:submit="saveRevenueShare" class="flex flex-wrap items-end gap-4 rounded-tf border border-line bg-surface p-5">
            <flux:input
                wire:model="platformShopRevenueShare"
                type="number"
                step="0.01"
                label="Platform share"
                description="Portion of each shop's monthly fee the platform keeps. 0.3 means you keep 70%."
                class="max-w-xs"
            />

            <flux:button variant="primary" type="submit">Save</flux:button>
        </form>
    </x-panel>

    <x-panel heading="Team">
        <x-data-table :headers="['Name', 'Email', 'Role']" :is-empty="$this->teamMembers->isEmpty()">
            @foreach ($this->teamMembers as $member)
                <tr wire:key="member-{{ $member->id }}">
                    <td class="border-b border-line py-2 font-medium">{{ $member->name }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $member->email }}</td>
                    <td class="border-b border-line py-2"><x-badge>{{ $member->role->label() }}</x-badge></td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>
</div>
