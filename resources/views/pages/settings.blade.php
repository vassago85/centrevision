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
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Settings')] class extends Component {
    /** Field => section, for per-section "unsaved changes" feedback. */
    private const SECTION_FIELDS = [
        'site' => ['name', 'address', 'dwellAlertHours', 'orphanAfterHours', 'retentionDays', 'parkingCapacity'],
        'classification' => ['recurringWindowDays', 'recurringMinWeekdayRatio', 'recurringMaxArrivalStddevMinutes'],
        'alerts' => [
            'alertsEnabled', 'alertRecipients', 'alertQuietStart', 'alertQuietEnd',
            'alertWatchlistEnabled', 'alertWatchlistRespectQuiet', 'alertDwellEnabled', 'alertDwellRespectQuiet',
            'alertOddHourEnabled', 'alertOddHourRespectQuiet', 'alertMultiEntryEnabled', 'alertMultiEntryRespectQuiet',
        ],
        'reports' => ['reportSchedule', 'reportRecipients'],
        'commercial' => ['platformSharePercent', 'platformShopRevenueShare'],
    ];

    #[Url(as: 'tab', keep: true)]
    public string $tab = 'site';

    /** @var array<string, bool> sections with edits that have not been saved */
    public array $dirty = [];

    /** @var array<string, string> section => time of the last successful save */
    public array $savedAt = [];

    /** Platform share as a percentage for the form; the stored value stays a fraction. */
    public float $platformSharePercent = 30;

    public ?int $siteId = null;

    public string $name = '';

    public string $address = '';

    public int $dwellAlertHours = 4;

    public int $orphanAfterHours = 12;

    public int $retentionDays = 365;

    public ?int $parkingCapacity = null;

    public int $recurringWindowDays = 28;

    public float $recurringMinWeekdayRatio = 0.8;

    public float $recurringMaxArrivalStddevMinutes = 30;

    public float $platformShopRevenueShare = 0.3;

    public string $reportSchedule = 'off';

    public string $reportRecipients = '';

    public bool $alertsEnabled = false;

    public string $alertRecipients = '';

    public string $alertQuietStart = '';

    public string $alertQuietEnd = '';

    public bool $alertWatchlistEnabled = true;

    public bool $alertWatchlistRespectQuiet = false;

    public bool $alertDwellEnabled = true;

    public bool $alertDwellRespectQuiet = true;

    public bool $alertOddHourEnabled = true;

    public bool $alertOddHourRespectQuiet = true;

    public bool $alertMultiEntryEnabled = true;

    public bool $alertMultiEntryRespectQuiet = true;

    public function mount(): void
    {
        $this->siteId = app(Tenancy::class)->currentSiteId() ?? app(Tenancy::class)->sites()->first()?->getKey();

        $this->loadSite();

        $this->platformShopRevenueShare = (float) app(Tenancy::class)
            ->organization()
            ->setting('platform_shop_revenue_share');
        $this->platformSharePercent = round($this->platformShopRevenueShare * 100, 2);

        if (! array_key_exists($this->tab, $this->tabs)) {
            $this->tab = 'site';
        }
    }

    public function updatedSiteId(): void
    {
        $this->loadSite();

        // Everything except the organisation-wide commercial setting is
        // per site, so switching site starts those sections clean.
        $this->dirty = array_intersect_key($this->dirty, ['commercial' => true]);
        $this->savedAt = array_intersect_key($this->savedAt, ['commercial' => true]);
    }

    public function updatedPlatformSharePercent(): void
    {
        $this->platformShopRevenueShare = round((float) $this->platformSharePercent / 100, 4);
    }

    public function updated(string $property): void
    {
        foreach (self::SECTION_FIELDS as $section => $fields) {
            if (in_array($property, $fields, true)) {
                $this->dirty[$section] = true;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function tabs(): array
    {
        $tabs = [
            'site' => 'Site & parking',
            'classification' => 'Traffic classification',
            'alerts' => 'Alerts',
            'reports' => 'Scheduled reports',
            'team' => 'Team & access',
        ];

        if ($this->canManageCommercial) {
            $tabs['commercial'] = 'Commercial';
        }

        return $tabs;
    }

    #[Computed]
    public function canManageCommercial(): bool
    {
        return (bool) auth()->user()?->can('manage billing');
    }

    protected function markSaved(string ...$sections): void
    {
        foreach ($sections as $section) {
            unset($this->dirty[$section]);
            $this->savedAt[$section] = now()->format('H:i');
        }
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
        $this->parkingCapacity = $site->parkingCapacity();
        $this->recurringWindowDays = (int) $site->setting('recurring_window_days');
        $this->recurringMinWeekdayRatio = (float) $site->setting('recurring_min_weekday_ratio');
        $this->recurringMaxArrivalStddevMinutes = (float) $site->setting('recurring_max_arrival_stddev_minutes');
        $this->reportSchedule = $site->reportSchedule()->value;
        $this->reportRecipients = implode(', ', $site->reportRecipients());

        $alerts = is_array($site->setting('alerts', [])) ? $site->setting('alerts', []) : [];
        $rules = is_array($alerts['rules'] ?? null) ? $alerts['rules'] : [];

        $this->alertsEnabled = (bool) ($alerts['enabled'] ?? false);
        $this->alertRecipients = implode(', ', (array) ($alerts['recipients'] ?? []));
        $this->alertQuietStart = (string) ($alerts['quiet_start'] ?? '');
        $this->alertQuietEnd = (string) ($alerts['quiet_end'] ?? '');
        $this->alertWatchlistEnabled = (bool) ($rules['watchlist_hit']['enabled'] ?? true);
        $this->alertWatchlistRespectQuiet = (bool) ($rules['watchlist_hit']['respect_quiet'] ?? false);
        $this->alertDwellEnabled = (bool) ($rules['dwell']['enabled'] ?? true);
        $this->alertDwellRespectQuiet = (bool) ($rules['dwell']['respect_quiet'] ?? true);
        $this->alertOddHourEnabled = (bool) ($rules['odd_hour']['enabled'] ?? true);
        $this->alertOddHourRespectQuiet = (bool) ($rules['odd_hour']['respect_quiet'] ?? true);
        $this->alertMultiEntryEnabled = (bool) ($rules['multi_entry']['enabled'] ?? true);
        $this->alertMultiEntryRespectQuiet = (bool) ($rules['multi_entry']['respect_quiet'] ?? true);
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
            'parkingCapacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
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
                'parking_capacity' => $this->parkingCapacity,
                'recurring_window_days' => $this->recurringWindowDays,
                'recurring_min_weekday_ratio' => $this->recurringMinWeekdayRatio,
                'recurring_max_arrival_stddev_minutes' => $this->recurringMaxArrivalStddevMinutes,
            ],
        ]);

        $this->markSaved('site', 'classification');

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
        $this->markSaved('reports');

        Flux::toast(variant: 'success', text: 'Report schedule saved.');
    }

    public function saveAlertSettings(): void
    {
        $site = $this->site();

        abort_if($site === null, 404);
        $this->authorize('update', $site);

        $recipients = collect(preg_split('/[\s,;]+/', $this->alertRecipients, flags: PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->unique()
            ->values();

        Validator::make(
            [
                'recipients' => $recipients->all(),
                'quiet_start' => $this->alertQuietStart,
                'quiet_end' => $this->alertQuietEnd,
            ],
            [
                'recipients' => ['array', 'max:'.config('trafficflow.report_max_recipients')],
                'recipients.*' => ['email'],
                'quiet_start' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
                'quiet_end' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            ],
            [],
            ['recipients.*' => 'recipient'],
        )->validateWithBag('default');

        $quietStart = $this->alertQuietStart !== '' ? $this->alertQuietStart : null;
        $quietEnd = $this->alertQuietEnd !== '' ? $this->alertQuietEnd : null;

        if (($quietStart === null) xor ($quietEnd === null)) {
            $this->addError('alertQuietStart', 'Set both quiet start and end, or leave both blank.');

            return;
        }

        $site->update([
            'settings' => [
                ...($site->settings ?? []),
                'alerts' => [
                    'enabled' => $this->alertsEnabled,
                    'recipients' => $recipients->all(),
                    'quiet_start' => $quietStart,
                    'quiet_end' => $quietEnd,
                    'rules' => [
                        'watchlist_hit' => [
                            'enabled' => $this->alertWatchlistEnabled,
                            'respect_quiet' => $this->alertWatchlistRespectQuiet,
                        ],
                        'dwell' => [
                            'enabled' => $this->alertDwellEnabled,
                            'respect_quiet' => $this->alertDwellRespectQuiet,
                        ],
                        'odd_hour' => [
                            'enabled' => $this->alertOddHourEnabled,
                            'respect_quiet' => $this->alertOddHourRespectQuiet,
                        ],
                        'multi_entry' => [
                            'enabled' => $this->alertMultiEntryEnabled,
                            'respect_quiet' => $this->alertMultiEntryRespectQuiet,
                        ],
                    ],
                ],
            ],
        ]);

        $this->alertRecipients = $recipients->implode(', ');
        $this->markSaved('alerts');

        Flux::toast(variant: 'success', text: 'Alert settings saved.');
    }

    public function saveRevenueShare(): void
    {
        $organization = app(Tenancy::class)->organization();

        abort_if($organization === null, 404);
        $this->authorize('manage billing');

        $this->validate(
            [
                'platformSharePercent' => ['required', 'numeric', 'min:0', 'max:90'],
                'platformShopRevenueShare' => ['required', 'numeric', 'min:0', 'max:0.9'],
            ],
            attributes: ['platformSharePercent' => 'platform share'],
        );

        $organization->update([
            'settings' => [
                ...($organization->settings ?? []),
                'platform_shop_revenue_share' => $this->platformShopRevenueShare,
            ],
        ]);

        $this->platformSharePercent = round($this->platformShopRevenueShare * 100, 2);
        $this->markSaved('commercial');

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
    /**
     * Labels of other tabs holding unsaved edits. Site & parking and Traffic
     * classification share one save, so each already mentions the other.
     *
     * @return list<string>
     */
    #[Computed]
    public function unsavedElsewhere(): array
    {
        $shared = ['site' => 'classification', 'classification' => 'site'];

        return collect(array_keys(array_filter($this->dirty)))
            ->reject(fn (string $section) => $section === $this->tab || $section === ($shared[$this->tab] ?? null))
            ->map(fn (string $section) => $this->tabs[$section] ?? $section)
            ->values()
            ->all();
    }
}; ?>

<div>
    <x-page-header title="Settings" subtitle="Site, traffic rules, alerts and access">
        <x-slot:actions>
            @if ($this->sites->count() > 1 && $tab !== 'team' && $tab !== 'commercial')
                <flux:select wire:model.live="siteId" size="sm" class="min-w-44" label="Site" label:sr-only>
                    @foreach ($this->sites as $site)
                        <flux:select.option :value="$site->id">{{ $site->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($this->unsavedElsewhere !== [])
        <x-notice class="mb-4" data-test="unsaved-elsewhere">
            Unsaved changes in {{ implode(', ', $this->unsavedElsewhere) }}.
            They are kept while you move between tabs, but are lost if you leave the page or switch site.
        </x-notice>
    @endif

    <x-panel-card padding="p-0">
        <x-tabs :tabs="$this->tabs" :current="$tab" model="tab" label="Settings sections" class="px-2" />

        <div class="p-4 sm:p-5" role="tabpanel" aria-labelledby="tab-{{ $tab }}">
            @switch($tab)
                @case('classification')
                    <form wire:submit="save" class="space-y-4">
                        <div>
                            <h2 class="text-[15px] font-semibold text-ink">Staff-pattern detection</h2>
                            <p class="mt-1 max-w-prose text-[13px] text-ink-2">
                                Vehicles matching this pattern are treated as staff or tenants and left out of every
                                shopper-facing figure. The Security page still shows them.
                                {{ number_format($this->recurringPlateCount) }} {{ \Illuminate\Support\Str::plural('plate', $this->recurringPlateCount) }} currently tagged.
                            </p>
                        </div>

                        <div class="grid grid-cols-3 gap-4 max-md:grid-cols-1">
                            <flux:input wire:model.blur="recurringWindowDays" type="number" label="Window (days)" description="How far back the pattern looks. 7–90." />
                            <flux:input
                                wire:model.blur="recurringMinWeekdayRatio"
                                type="number"
                                step="0.05"
                                label="Min weekday presence"
                                description="0.8 means present on 80% of weekdays."
                            />
                            <flux:input
                                wire:model.blur="recurringMaxArrivalStddevMinutes"
                                type="number"
                                label="Arrival consistency (min)"
                                description="Lower is stricter."
                            />
                        </div>

                        <div>
                            <flux:button
                                size="sm"
                                variant="ghost"
                                type="button"
                                icon="arrow-path"
                                wire:click="clearRecurringTags"
                                wire:confirm="Clear every staff-pattern tag for this site? They rebuild on tonight's run."
                            >Clear tags and re-detect</flux:button>
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
                            @if ($dirty['site'] ?? false)
                                <span class="text-[13px] text-ink-muted">Also saves your Site &amp; parking changes.</span>
                            @endif
                            <x-save-status :dirty="$dirty['classification'] ?? false" :saved-at="$savedAt['classification'] ?? null" />
                            <flux:button variant="primary" type="submit">Save classification</flux:button>
                        </div>
                    </form>
                    @break

                @case('alerts')
                    <form wire:submit="saveAlertSettings" class="space-y-5">
                        <div>
                            <h2 class="text-[15px] font-semibold text-ink">Security alert emails</h2>
                            <p class="mt-1 max-w-prose text-[13px] text-ink-2">
                                Email the security desk when configured rules fire. Site recipients always get mail when
                                alerts are on; owner admins and security operators can also opt in under Account → Security.
                                Off by default.
                            </p>
                        </div>

                        <flux:checkbox wire:model.live="alertsEnabled" label="Enable security alert emails for this site" />

                        <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_8rem_8rem]">
                            <flux:input
                                wire:model.blur="alertRecipients"
                                label="Desk recipients"
                                description="Comma separated. Max {{ config('trafficflow.report_max_recipients') }}."
                                placeholder="security@example.com"
                            />
                            <flux:input wire:model.blur="alertQuietStart" label="Quiet start" placeholder="22:00" />
                            <flux:input wire:model.blur="alertQuietEnd" label="Quiet end" placeholder="06:00" />
                        </div>

                        <div class="relative -mx-1 overflow-x-auto px-1">
                            <table class="w-full text-left text-[13px]">
                                <thead>
                                    <tr class="border-b border-line text-ink-2">
                                        <th class="py-2 text-[12.5px] font-semibold">Rule</th>
                                        <th class="py-2 text-[12.5px] font-semibold">Enabled</th>
                                        <th class="py-2 text-[12.5px] font-semibold">Respect quiet hours</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="border-b border-line">
                                        <td class="py-2.5">Watchlist hit</td>
                                        <td class="py-2.5"><flux:checkbox wire:model.live="alertWatchlistEnabled" aria-label="Enable watchlist hit alerts" /></td>
                                        <td class="py-2.5"><flux:checkbox wire:model.live="alertWatchlistRespectQuiet" aria-label="Watchlist hit alerts respect quiet hours" /></td>
                                    </tr>
                                    <tr class="border-b border-line">
                                        <td class="py-2.5">Dwell over threshold</td>
                                        <td class="py-2.5"><flux:checkbox wire:model.live="alertDwellEnabled" aria-label="Enable dwell alerts" /></td>
                                        <td class="py-2.5"><flux:checkbox wire:model.live="alertDwellRespectQuiet" aria-label="Dwell alerts respect quiet hours" /></td>
                                    </tr>
                                    <tr class="border-b border-line">
                                        <td class="py-2.5">Odd-hour pattern</td>
                                        <td class="py-2.5"><flux:checkbox wire:model.live="alertOddHourEnabled" aria-label="Enable odd-hour alerts" /></td>
                                        <td class="py-2.5"><flux:checkbox wire:model.live="alertOddHourRespectQuiet" aria-label="Odd-hour alerts respect quiet hours" /></td>
                                    </tr>
                                    <tr>
                                        <td class="py-2.5">Multi-entry today</td>
                                        <td class="py-2.5"><flux:checkbox wire:model.live="alertMultiEntryEnabled" aria-label="Enable multi-entry alerts" /></td>
                                        <td class="py-2.5"><flux:checkbox wire:model.live="alertMultiEntryRespectQuiet" aria-label="Multi-entry alerts respect quiet hours" /></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <p class="text-[13px] text-ink-muted">
                            The dwell threshold itself is set under
                            <button type="button" wire:click="$set('tab', 'site')" class="font-medium text-accent hover:underline">Site &amp; parking</button>.
                        </p>

                        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
                            <x-save-status :dirty="$dirty['alerts'] ?? false" :saved-at="$savedAt['alerts'] ?? null" />
                            <flux:button variant="primary" type="submit">Save alert settings</flux:button>
                        </div>
                    </form>
                    @break

                @case('reports')
                    <form wire:submit="saveReportSchedule" class="space-y-5">
                        <div>
                            <h2 class="text-[15px] font-semibold text-ink">Scheduled reports</h2>
                            <p class="mt-1 max-w-prose text-[13px] text-ink-2">
                                Emails the traffic report as a PDF and a CSV. Aggregates only — vehicle registration
                                numbers are never included.
                            </p>
                        </div>

                        <div class="grid gap-4 md:grid-cols-[14rem_minmax(0,1fr)]">
                            <flux:select wire:model.live="reportSchedule" label="Frequency">
                                @foreach (ReportSchedule::cases() as $option)
                                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:input
                                wire:model.blur="reportRecipients"
                                label="Recipients"
                                description="Comma separated."
                                placeholder="centre.manager@example.com, ops@example.com"
                            />
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
                            <a href="{{ route('reports') }}" wire:navigate class="text-[13px] font-medium text-accent hover:underline">Open Reports</a>
                            <div class="flex flex-wrap items-center gap-3">
                                <x-save-status :dirty="$dirty['reports'] ?? false" :saved-at="$savedAt['reports'] ?? null" />
                                <flux:button variant="primary" type="submit">Save schedule</flux:button>
                            </div>
                        </div>
                    </form>
                    @break

                @case('team')
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-[15px] font-semibold text-ink">Team</h2>
                                <p class="mt-1 text-[13px] text-ink-2">People in your organisation who can sign in.</p>
                            </div>
                            <flux:button size="sm" variant="ghost" icon="users" :href="route('shops')" wire:navigate>Manage shops &amp; security operators</flux:button>
                        </div>
                        <x-data-table :headers="['Name', 'Email', 'Role']" :is-empty="$this->teamMembers->isEmpty()">
                            @foreach ($this->teamMembers as $member)
                                <tr wire:key="member-{{ $member->id }}">
                                    <td class="border-b border-line py-2 font-medium">{{ $member->name }}</td>
                                    <td class="border-b border-line py-2 text-ink-2">{{ $member->email }}</td>
                                    <td class="border-b border-line py-2"><x-badge>{{ $member->role->label() }}</x-badge></td>
                                </tr>
                            @endforeach
                        </x-data-table>
                    </div>
                    @break

                @case('commercial')
                    @if ($this->canManageCommercial)
                        <form wire:submit="saveRevenueShare" class="space-y-5">
                            <div>
                                <h2 class="text-[15px] font-semibold text-ink">Shop revenue share</h2>
                                <p class="mt-1 max-w-prose text-[13px] text-ink-2">
                                    The portion of each shop's monthly fee the platform keeps. Applies to every site in your organisation.
                                </p>
                            </div>

                            <div class="max-w-xs">
                                <flux:input
                                    wire:model.blur="platformSharePercent"
                                    type="number"
                                    step="0.5"
                                    min="0"
                                    max="90"
                                    label="Platform share (%)"
                                    :description="'You keep '.rtrim(rtrim(number_format(max(0, 100 - (float) $platformSharePercent), 2), '0'), '.').'% of each shop fee.'"
                                />
                                @error('platformShopRevenueShare')
                                    <p class="mt-1 text-[13px] text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
                                <x-save-status :dirty="$dirty['commercial'] ?? false" :saved-at="$savedAt['commercial'] ?? null" />
                                <flux:button variant="primary" type="submit">Save revenue share</flux:button>
                            </div>
                        </form>
                    @endif
                    @break

                @default
                    <form wire:submit="save" class="space-y-5">
                        <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                            <flux:input wire:model.blur="name" label="Site name" />
                            <flux:input wire:model.blur="address" label="Address" />
                        </div>

                        <div class="border-t border-line pt-4">
                            <h2 class="mb-3 text-[15px] font-semibold text-ink">Parking, matching and retention</h2>
                            <div class="grid grid-cols-2 gap-4 max-md:grid-cols-1">
                                <flux:input
                                    wire:model.blur="parkingCapacity"
                                    type="number"
                                    label="Parking capacity (bays)"
                                    description="Optional. When set, the dashboard shows occupancy % from vehicles currently on site."
                                />

                                <flux:select wire:model.live="dwellAlertHours" label="Dwell alert" description="Flags a vehicle on the Security page once it passes this.">
                                    @foreach (config('trafficflow.dwell_alert_options') as $hours)
                                        <flux:select.option :value="$hours">{{ $hours }} hours</flux:select.option>
                                    @endforeach
                                </flux:select>

                                <flux:input
                                    wire:model.blur="orphanAfterHours"
                                    type="number"
                                    label="Matching timeout (hours)"
                                    description="An open visit older than this is assumed to have a missed exit. 1–72."
                                />

                                <flux:input
                                    wire:model.blur="retentionDays"
                                    type="number"
                                    label="Retention (days)"
                                    :description="'Standard is 180 days (6 months). Longer periods need to be agreed with us. Allowed range: '.config('trafficflow.retention_min_days').'–'.config('trafficflow.retention_max_days').' days.'"
                                />
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
                            @if ($dirty['classification'] ?? false)
                                <span class="text-[13px] text-ink-muted">Also saves your Traffic classification changes.</span>
                            @endif
                            <x-save-status :dirty="$dirty['site'] ?? false" :saved-at="$savedAt['site'] ?? null" />
                            <flux:button variant="primary" type="submit">Save site settings</flux:button>
                        </div>
                    </form>
            @endswitch
        </div>
    </x-panel-card>
</div>
