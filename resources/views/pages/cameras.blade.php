<?php

use App\Enums\CameraRole;
use App\Enums\IngestionMode;
use App\Models\Camera;
use App\Models\Site;
use App\Services\Isapi\AlertStreamListener;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cameras')] class extends Component {
    /** Null while adding, otherwise the camera being edited. */
    public ?int $editingId = null;

    public bool $showForm = false;

    public ?int $siteId = null;

    public string $name = '';

    public string $role = 'entrance';

    public string $ingestionMode = 'webhook';

    public string $ipAddress = '';

    public string $isapiUsername = '';

    /** Left blank on edit means "keep the stored password". */
    public string $isapiPassword = '';

    public int $channelId = 1;

    public bool $isActive = true;

    /** Camera the setup modal is open for; null when the modal is closed. */
    public ?int $setupCameraId = null;

    public bool $showSetup = false;

    /**
     * Plaintext webhook secret to render in the setup modal. Populated when
     * the modal opens and wiped when it closes so the value is not shipped
     * back on every subsequent Livewire request.
     */
    public string $revealedSecret = '';

    /**
     * True right after a create or regenerate; drives the "you will not see
     * this again" nudge so operators actually copy the secret.
     */
    public bool $secretJustGenerated = false;

    protected function rules(): array
    {
        return [
            // Restricting the site list to what the tenant can reach makes a
            // tampered site id a validation failure rather than a data leak.
            'siteId' => ['required', 'integer', Rule::in(app(Tenancy::class)->accessibleSiteIds())],
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::enum(CameraRole::class)],
            'ingestionMode' => ['required', Rule::enum(IngestionMode::class)],
            // Stream-mode cameras have to be addressable; webhook and FTP
            // cameras dial home, so a placeholder or empty value is fine.
            'ipAddress' => [
                Rule::requiredIf(fn () => $this->ingestionMode === IngestionMode::Stream->value),
                'nullable', 'string', 'max:120',
            ],
            'isapiUsername' => ['nullable', 'string', 'max:120'],
            'isapiPassword' => ['nullable', 'string', 'max:255'],
            'channelId' => ['required', 'integer', 'min:1', 'max:64'],
        ];
    }

    #[Computed]
    public function cameras(): Collection
    {
        return Camera::query()
            ->with('site:id,name')
            ->withCount(['plateEvents as events_today' => fn ($query) => $query->where('captured_at', '>=', now()->startOfDay())])
            ->orderBy('site_id')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function sites(): Collection
    {
        return app(Tenancy::class)->sites();
    }

    /**
     * Owners and platform admins configure cameras. Security operators land
     * on this page as well but only to check that the devices are alive; the
     * add / edit / delete / regenerate-secret controls are hidden for them.
     */
    #[Computed]
    public function canManageCameras(): bool
    {
        return auth()->user()?->can('manage cameras') ?? false;
    }

    public function add(): void
    {
        // Guard the server side too — hiding a button in the view isn't
        // enough on its own, because Livewire actions can still be invoked
        // by anyone who knows their names.
        abort_unless($this->canManageCameras, 403);

        $this->reset(['editingId', 'name', 'ipAddress', 'isapiUsername', 'isapiPassword']);
        $this->resetValidation();

        $this->siteId = app(Tenancy::class)->currentSiteId() ?? $this->sites()->first()?->getKey();
        $this->role = CameraRole::Entrance->value;
        $this->ingestionMode = IngestionMode::Webhook->value;
        $this->channelId = 1;
        $this->isActive = true;
        $this->showForm = true;
    }

    public function edit(int $cameraId): void
    {
        $camera = Camera::findOrFail($cameraId);

        $this->authorize('manageCameras', $camera->site);
        $this->resetValidation();

        $this->editingId = $camera->getKey();
        $this->siteId = $camera->site_id;
        $this->name = $camera->name;
        $this->role = $camera->role->value;
        $this->ingestionMode = $camera->ingestion_mode->value;
        $this->ipAddress = $camera->ip_address;
        $this->isapiUsername = $camera->isapi_username ?? '';
        $this->isapiPassword = '';
        $this->channelId = $camera->channel_id;
        $this->isActive = $camera->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        $site = Site::findOrFail($this->siteId);
        $this->authorize('manageCameras', $site);

        $attributes = [
            'site_id' => $site->getKey(),
            'name' => $this->name,
            'role' => $this->role,
            'ingestion_mode' => $this->ingestionMode,
            'ip_address' => $this->ipAddress ?: '0.0.0.0',
            'isapi_username' => $this->isapiUsername ?: null,
            'channel_id' => $this->channelId,
            'is_active' => $this->isActive,
        ];

        // An empty password field on edit means "leave the stored one alone",
        // since we never render the existing secret back to the browser.
        if ($this->isapiPassword !== '') {
            $attributes['isapi_password'] = $this->isapiPassword;
        }

        $isNew = $this->editingId === null;

        if ($isNew) {
            $camera = Camera::create($attributes);
        } else {
            $camera = Camera::findOrFail($this->editingId);
            $camera->update($attributes);
        }

        unset($this->cameras);
        $this->showForm = false;

        // Freshly-created webhook cameras jump straight into the setup panel:
        // the operator needs the URL and secret to configure the device, and
        // it saves them hunting for the button they have not seen yet.
        if ($isNew && $camera->ingestion_mode !== IngestionMode::Stream) {
            $this->openSetup($camera->id, justGenerated: true);
        } else {
            Flux::toast(
                variant: 'success',
                text: $this->name.' saved.',
            );
        }
    }

    /**
     * Open the "Set up in camera" modal, showing the webhook URL and current
     * secret so the operator can copy them into the Hikvision UI.
     */
    public function openSetup(int $cameraId, bool $justGenerated = false): void
    {
        $camera = Camera::findOrFail($cameraId);
        $this->authorize('manageCameras', $camera->site);

        $this->setupCameraId = $camera->getKey();
        $this->revealedSecret = (string) $camera->webhook_secret;
        $this->secretJustGenerated = $justGenerated;
        $this->showSetup = true;
    }

    public function closeSetup(): void
    {
        // Wipe the plaintext so it does not travel back on unrelated requests.
        $this->setupCameraId = null;
        $this->revealedSecret = '';
        $this->secretJustGenerated = false;
        $this->showSetup = false;
    }

    /**
     * Regenerate the shared secret. The plaintext is only ever readable once
     * (via the setup modal that opens immediately after), because subsequent
     * page loads re-read the encrypted-at-rest value and reveal it again on
     * demand — but the intent here is: "this is your fresh one, copy it now".
     */
    public function regenerateSecret(int $cameraId): void
    {
        $camera = Camera::findOrFail($cameraId);
        $this->authorize('manageCameras', $camera->site);

        $secret = $camera->regenerateWebhookSecret();

        $this->setupCameraId = $camera->getKey();
        $this->revealedSecret = $secret;
        $this->secretJustGenerated = true;
        $this->showSetup = true;

        Flux::toast(variant: 'success', text: 'New secret generated. Update the camera now.');
    }

    /**
     * The camera currently loaded in the setup modal. Null when the modal is
     * closed so the Blade side can early-return without extra null checks.
     */
    #[Computed]
    public function setupCamera(): ?Camera
    {
        return $this->setupCameraId === null
            ? null
            : Camera::find($this->setupCameraId);
    }

    public function delete(int $cameraId): void
    {
        $camera = Camera::findOrFail($cameraId);

        $this->authorize('manageCameras', $camera->site);

        // Plate events cascade with the camera, which is the POPIA-friendly
        // outcome: removing a device removes what it recorded.
        $camera->delete();

        unset($this->cameras);

        Flux::toast(variant: 'success', text: 'Camera removed.');
    }

    /**
     * Hit the camera's deviceInfo endpoint so the operator gets an answer now
     * rather than waiting for the next event to arrive.
     */
    public function probe(int $cameraId): void
    {
        $camera = Camera::findOrFail($cameraId);

        $this->authorize('manageCameras', $camera->site);

        $ok = app(AlertStreamListener::class)->probe($camera);

        unset($this->cameras);

        Flux::toast(
            variant: $ok ? 'success' : 'danger',
            text: $ok
                ? $camera->name.' answered.'
                : ($camera->fresh()->last_probe_error ?: $camera->name.' did not respond.'),
        );
    }

    /**
     * @return array{tone: string, label: string}
     */
    public function status(Camera $camera): array
    {
        if (! $camera->is_active) {
            return ['tone' => 'neutral', 'label' => 'Disabled'];
        }

        if ($camera->isReachable()) {
            return ['tone' => 'positive', 'label' => 'Online'];
        }

        return ['tone' => 'danger', 'label' => $camera->last_event_at === null ? 'Never seen' : 'Unreachable'];
    }

    /**
     * Health is the row-level rollup that answers "is this camera doing
     * its job?". Three states, with a matching badge tone:
     *
     * - Healthy: active and reachable.
     * - Stale: active, previously seen, but silent past the stale window.
     * - Offline: inactive, or never-seen / unreachable.
     *
     * Split out from {@see status()} because status() is fine-grained
     * (Online / Never seen / Unreachable / Disabled), while the monitoring
     * table only needs three buckets a security operator can act on.
     *
     * @return array{tone: string, label: string}
     */
    public function health(Camera $camera): array
    {
        if (! $camera->is_active) {
            return ['tone' => 'danger', 'label' => 'Offline'];
        }

        if ($camera->isReachable()) {
            return ['tone' => 'positive', 'label' => 'Healthy'];
        }

        if ($camera->last_event_at === null) {
            return ['tone' => 'danger', 'label' => 'Offline'];
        }

        return ['tone' => 'warn', 'label' => 'Stale'];
    }

    /**
     * Summary counts for the compact status strip at the top of the page.
     * "Offline" here folds in inactive + never-seen + unreachable so a
     * security operator only sees the three buckets that matter to them.
     *
     * @return array{cameras: int, online: int, offline: int, stale: int, reads_today: int}
     */
    #[Computed]
    public function fleetSummary(): array
    {
        $online = 0;
        $stale = 0;
        $offline = 0;

        foreach ($this->cameras as $camera) {
            $tone = $this->health($camera)['tone'];

            if ($tone === 'positive') {
                $online++;
            } elseif ($tone === 'warn') {
                $stale++;
            } else {
                $offline++;
            }
        }

        return [
            'cameras' => $this->cameras->count(),
            'online' => $online,
            'stale' => $stale,
            'offline' => $offline,
            'reads_today' => (int) $this->cameras->sum('events_today'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Cameras" subtitle="Devices feeding this site">
        <x-slot:actions>
            @if ($this->canManageCameras)
                <flux:button size="sm" variant="primary" wire:click="add">Add camera</flux:button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @unless ($this->canManageCameras)
        {{-- Security operators land here to check that cameras are alive.
             The banner tells them plainly that camera config is not theirs
             to change, so they know to escalate to the site owner. --}}
        <div class="mb-5 rounded-lg border border-line bg-surface-2 p-3 text-sm text-ink-2">
            You are viewing cameras in read-only mode. Ask the site owner to add, edit or remove a device.
        </div>
    @endunless

    {{-- Compact status strip — a single line the operator can read at a
         glance. The full-width metric cards used to eat half the screen
         above a mostly-empty table; on a monitoring page the table is
         the point, so we keep the summary small. --}}
    @php $summary = $this->fleetSummary; @endphp
    <div class="mb-5 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-tf border border-line bg-surface px-4 py-3 text-[13px] shadow-tf-sm">
        <span class="inline-flex items-center gap-1.5">
            <flux:icon icon="video-camera" class="size-4 text-ink-muted" />
            <span class="font-semibold text-ink tabular-nums">{{ $summary['cameras'] }}</span>
            <span class="text-ink-2">{{ \Illuminate\Support\Str::plural('Camera', $summary['cameras']) }}</span>
        </span>
        <span class="text-ink-muted">·</span>
        <span class="inline-flex items-center gap-1.5" title="Reachable within the last {{ config('trafficflow.camera_stale_after_minutes') }} minutes">
            <span class="size-2 rounded-full bg-positive"></span>
            <span class="font-semibold text-positive tabular-nums">{{ $summary['online'] }}</span>
            <span class="text-ink-2">Online</span>
        </span>
        @if ($summary['stale'] > 0)
            <span class="text-ink-muted">·</span>
            <span class="inline-flex items-center gap-1.5" title="Active but silent past the stale window">
                <span class="size-2 rounded-full bg-warn"></span>
                <span class="font-semibold text-warn tabular-nums">{{ $summary['stale'] }}</span>
                <span class="text-ink-2">Stale</span>
            </span>
        @endif
        <span class="text-ink-muted">·</span>
        <span class="inline-flex items-center gap-1.5">
            <span class="size-2 rounded-full {{ $summary['offline'] > 0 ? 'bg-danger' : 'bg-surface-2 border border-line' }}"></span>
            <span class="font-semibold {{ $summary['offline'] > 0 ? 'text-danger' : 'text-ink' }} tabular-nums">{{ $summary['offline'] }}</span>
            <span class="text-ink-2">Offline</span>
        </span>
        <span class="text-ink-muted">·</span>
        <span class="inline-flex items-center gap-1.5">
            <flux:icon icon="signal" class="size-4 text-ink-muted" />
            <span class="font-semibold text-ink tabular-nums">{{ number_format($summary['reads_today']) }}</span>
            <span class="text-ink-2">Reads today</span>
        </span>
    </div>

    <x-panel heading="Devices">
        <x-data-table
            :headers="['Camera', 'Site', 'Direction', 'Connection', 'Health', ['label' => 'Reads Today', 'align' => 'right'], 'Last Read', ['label' => '', 'align' => 'right']]"
            :is-empty="$this->cameras->isEmpty()"
            empty="No cameras yet. Add the first one to start ingesting plates."
        >
            @foreach ($this->cameras as $camera)
                @php
                    // Block form on purpose: the single-expression @php(...) form
                    // miscompiles when it sits immediately after Livewire's
                    // wire:key loop-iteration shim.
                    $health = $this->health($camera);
                @endphp

                <tr wire:key="camera-{{ $camera->id }}">
                    <td class="border-b border-line py-2 font-medium">
                        {{ $camera->name }}
                        {{-- The address only matters for stream-mode cameras;
                             hiding it for webhook devices avoids drawing eyes
                             to a value the app never actually uses. --}}
                        @if ($camera->ingestion_mode === App\Enums\IngestionMode::Stream && $camera->ip_address)
                            <div class="mt-0.5 font-mono text-[11px] font-normal text-ink-muted">{{ $camera->ip_address }}</div>
                        @endif
                    </td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $camera->site->name }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $camera->role->label() }}</td>
                    <td class="border-b border-line py-2">
                        <x-badge :tone="$camera->ingestion_mode === App\Enums\IngestionMode::Webhook ? 'accent' : 'neutral'">
                            {{ match ($camera->ingestion_mode) {
                                App\Enums\IngestionMode::Webhook => 'Webhook',
                                App\Enums\IngestionMode::Stream => 'ISAPI stream',
                                App\Enums\IngestionMode::Ftp => 'FTP',
                                App\Enums\IngestionMode::Auto => 'Any',
                            } }}
                        </x-badge>
                    </td>
                    <td class="border-b border-line py-2">
                        <x-badge :tone="$health['tone']">{{ $health['label'] }}</x-badge>
                    </td>
                    <td class="border-b border-line py-2 text-right tabular-nums {{ ($camera->events_today ?? 0) === 0 ? 'text-ink-muted' : 'text-ink-2' }}">
                        {{ number_format($camera->events_today ?? 0) }}
                    </td>
                    <td class="border-b border-line py-2 text-ink-2">
                        {{ $camera->last_event_at?->diffForHumans() ?? '—' }}
                    </td>
                    <td class="border-b border-line py-2 text-right">
                        <div class="flex justify-end gap-1">
                            @if ($this->canManageCameras)
                                @if ($camera->ingestion_mode !== App\Enums\IngestionMode::Stream)
                                    <flux:button size="xs" variant="ghost" wire:click="openSetup({{ $camera->id }})">Setup</flux:button>
                                @endif
                                @if ($camera->ingestion_mode->needsInboundReach())
                                    <flux:button size="xs" variant="ghost" wire:click="probe({{ $camera->id }})">Test</flux:button>
                                @endif
                                <flux:button size="xs" variant="ghost" wire:click="edit({{ $camera->id }})">Edit</flux:button>
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    wire:click="delete({{ $camera->id }})"
                                    wire:confirm="Remove {{ $camera->name }} and every plate event it recorded?"
                                >Remove</flux:button>
                            @else
                                <span class="text-xs text-ink-muted">—</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>

    <flux:modal wire:model.self="showForm" class="md:w-[32rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit camera' : 'Add camera' }}</flux:heading>

            <flux:select wire:model="siteId" label="Site">
                @foreach ($this->sites as $site)
                    <flux:select.option :value="$site->id">{{ $site->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="name" label="Name" placeholder="North entrance" />

            <flux:select wire:model="role" label="Role">
                @foreach (App\Enums\CameraRole::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select
                wire:model.live="ingestionMode"
                label="Ingestion mode"
                description="Webhook: camera POSTs events to us over HTTPS (no VPN needed). Stream: we hold an ISAPI connection to it. FTP: legacy fallback."
            >
                @foreach (App\Enums\IngestionMode::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            {{-- Stream-mode cameras must be addressable on the LAN, so they
                 need an IP + ISAPI credentials. Webhook/FTP dial home, so the
                 same fields are optional — hide them by default to keep the
                 add-camera flow short. --}}
            @if ($ingestionMode === App\Enums\IngestionMode::Stream->value)
                <div class="grid grid-cols-3 gap-3">
                    <flux:input wire:model="ipAddress" label="IP address" class="col-span-2" placeholder="10.0.1.21" />
                    <flux:input wire:model="channelId" type="number" label="Channel" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <flux:input wire:model="isapiUsername" label="ISAPI user" autocomplete="off" />
                    <flux:input
                        wire:model="isapiPassword"
                        type="password"
                        label="ISAPI password"
                        autocomplete="new-password"
                        :placeholder="$editingId ? 'Unchanged' : ''"
                    />
                </div>
            @else
                <flux:input wire:model="channelId" type="number" label="Channel" description="Rarely more than 1 on a bullet camera; keep as-is unless the model does multi-channel LPR." />
            @endif

            <flux:switch wire:model="isActive" label="Active" description="Inactive cameras stop accepting webhooks and are skipped by the listener and drop-folder sweep." />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="$set('showForm', false)">Cancel</flux:button>
                <flux:button variant="primary" type="submit">Save camera</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ── Camera setup modal ────────────────────────────────────────────
         Shows the webhook URL + secret and step-by-step setup instructions
         so the operator can copy them straight into the Hikvision UI. --}}
    <flux:modal wire:model.self="showSetup" @close="$wire.closeSetup()" class="md:w-[36rem]">
        @if ($this->setupCamera)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Set up "{{ $this->setupCamera->name }}"</flux:heading>
                    <p class="mt-1 text-sm text-ink-2">
                        Configure the camera to POST its plate events to CentreVision. It dials out
                        over HTTPS, so no VPN or port forwarding is needed on the site's network.
                    </p>
                </div>

                @if ($secretJustGenerated)
                    <div class="rounded-tf border border-warn/40 bg-warn-soft p-3 text-[13px] text-ink">
                        <p class="font-semibold">Copy the secret now.</p>
                        <p class="mt-0.5 text-ink-2">
                            It stays visible until you close this dialog. You can reveal it again later
                            from this page, or regenerate it if it is lost.
                        </p>
                    </div>
                @endif

                @php
                    $webhookHost   = parse_url($this->setupCamera->webhookUrlWithToken(), PHP_URL_HOST);
                    $webhookScheme = parse_url($this->setupCamera->webhookUrlWithToken(), PHP_URL_SCHEME);
                    $webhookPath   = parse_url($this->setupCamera->webhookUrlWithToken(), PHP_URL_PATH);
                    $webhookPort   = $webhookScheme === 'https' ? 443 : 80;
                    $webhookIsTls  = $webhookScheme === 'https';
                @endphp

                <div
                    x-data="{
                        show: false,
                        copy(text, label) {
                            navigator.clipboard.writeText(text);
                            this.$dispatch('toast', { variant: 'success', text: label + ' copied.' });
                        },
                    }"
                    class="space-y-4"
                >
                    {{-- Primary copy panel: the four values the Hikvision UI wants,
                         laid out in the same order the camera page presents them.
                         Colour-tinted so it clearly reads as "these are the fields
                         you paste into the camera". --}}
                    <div class="rounded-tf border border-accent/40 bg-accent-soft p-4">
                        <p class="mb-3 text-[12px] font-semibold uppercase tracking-[0.14em] text-accent">
                            Paste these into the camera's Alarm Server / HTTP Listening page
                        </p>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="col-span-2">
                                <label class="text-[12px] font-medium text-ink-2">1 · Destination IP or Host Name</label>
                                <div class="mt-1 flex gap-2">
                                    <input
                                        type="text"
                                        readonly
                                        value="{{ $webhookHost }}"
                                        class="flex-1 rounded-tf border border-line bg-surface px-3 py-2 font-mono text-[13px] font-semibold text-ink"
                                    />
                                    <flux:button size="sm" variant="ghost" @click="copy('{{ $webhookHost }}', 'Host')">Copy</flux:button>
                                </div>
                                <p class="mt-1 text-[11px] text-ink-muted">Hostname only — no <code>{{ $webhookScheme }}://</code>, no <code>/</code>.</p>
                            </div>

                            <div class="col-span-2">
                                <label class="text-[12px] font-medium text-ink-2">2 · URL (path)</label>
                                <div class="mt-1 flex gap-2">
                                    <input
                                        type="text"
                                        readonly
                                        value="{{ $webhookPath }}"
                                        class="flex-1 rounded-tf border border-line bg-surface px-3 py-2 font-mono text-[13px] text-ink"
                                    />
                                    <flux:button size="sm" variant="ghost" @click="copy('{{ $webhookPath }}', 'Path')">Copy</flux:button>
                                </div>
                                <p class="mt-1 text-[11px] text-ink-muted">Starts with <code>/</code>. The trailing segment is this camera's authentication secret.</p>
                            </div>

                            <div>
                                <label class="text-[12px] font-medium text-ink-2">3 · Protocol Type</label>
                                <div class="mt-1 rounded-tf border border-line bg-surface px-3 py-2 font-mono text-[13px] font-semibold text-ink">
                                    {{ strtoupper($webhookScheme) }}
                                </div>
                                @if ($webhookIsTls)
                                    <p class="mt-1 text-[11px] text-ink-muted">TLS is handled by our reverse proxy — pick <code>HTTPS</code>.</p>
                                @else
                                    <p class="mt-1 text-[11px] text-ink-muted">Plain HTTP — TLS is not configured for this install.</p>
                                @endif
                            </div>

                            <div>
                                <label class="text-[12px] font-medium text-ink-2">4 · Port</label>
                                <div class="mt-1 rounded-tf border border-line bg-surface px-3 py-2 font-mono text-[13px] font-semibold text-ink">
                                    {{ $webhookPort }}
                                </div>
                                <p class="mt-1 text-[11px] text-ink-muted">Standard {{ strtoupper($webhookScheme) }} port.</p>
                            </div>
                        </div>
                    </div>

                    {{-- Fallback: some newer firmwares only have one URL field. Give
                         the operator the fully-assembled URL for that case. --}}
                    <div>
                        <label class="text-[13px] font-medium text-ink-2">Full URL (for cameras with a single URL field)</label>
                        <div class="mt-1 flex gap-2">
                            <input
                                type="text"
                                readonly
                                value="{{ $this->setupCamera->webhookUrlWithToken() }}"
                                class="flex-1 rounded-tf border border-line bg-surface-2 px-3 py-2 font-mono text-[12.5px] text-ink"
                            />
                            <flux:button size="sm" variant="ghost" @click="copy('{{ $this->setupCamera->webhookUrlWithToken() }}', 'URL')">Copy</flux:button>
                        </div>
                        <p class="mt-1 text-[11px] text-ink-muted">
                            Paste this into a single <em class="not-italic font-medium">URL</em> field only when the camera's page has no separate host/path/port controls.
                        </p>
                    </div>

                    {{-- Older firmware: HTTP Listening with real Basic auth fields.
                         Kept as a plain bordered block rather than a <details>
                         element: Blade's compiler mis-parses directive-heavy
                         children of <details>. --}}
                    <div class="rounded-tf border border-line bg-surface p-3">
                        <p class="mb-3 text-[12px] font-semibold text-ink-2">
                            Only if the camera also asks for User Name / Password (older HTTP Listening firmware)
                        </p>
                        <div class="space-y-3">
                            <div>
                                <label class="text-[12px] font-medium text-ink-2">URL (no secret in path)</label>
                                <div class="mt-1 flex gap-2">
                                    <input
                                        type="text"
                                        readonly
                                        value="{{ $this->setupCamera->webhookUrl() }}"
                                        class="flex-1 rounded-tf border border-line bg-surface-2 px-3 py-2 font-mono text-[12px] text-ink"
                                    />
                                    <flux:button size="sm" variant="ghost" @click="copy('{{ $this->setupCamera->webhookUrl() }}', 'URL')">Copy</flux:button>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-[12px] font-medium text-ink-2">User Name</label>
                                    <div class="mt-1 flex gap-2">
                                        <input
                                            type="text"
                                            readonly
                                            value="{{ $this->setupCamera->id }}"
                                            class="flex-1 rounded-tf border border-line bg-surface-2 px-3 py-2 font-mono text-[12px] text-ink"
                                        />
                                        <flux:button size="sm" variant="ghost" @click="copy('{{ $this->setupCamera->id }}', 'Username')">Copy</flux:button>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-[12px] font-medium text-ink-2">Password</label>
                                    <div class="mt-1 flex gap-2">
                                        <input
                                            :type="show ? 'text' : 'password'"
                                            readonly
                                            value="{{ $revealedSecret }}"
                                            class="flex-1 rounded-tf border border-line bg-surface-2 px-3 py-2 font-mono text-[12px] text-ink"
                                        />
                                        <flux:button size="sm" variant="ghost" @click="show = ! show" x-text="show ? 'Hide' : 'Show'">Show</flux:button>
                                        <flux:button size="sm" variant="ghost" @click="copy('{{ $revealedSecret }}', 'Secret')">Copy</flux:button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-tf border border-line bg-surface-2 p-4 text-[13px]">
                    <p class="mb-2 font-semibold text-ink">Steps in the Hikvision UI</p>
                    <ol class="ml-4 list-decimal space-y-1.5 text-ink-2">
                        <li>Log into the camera as <code>admin</code>.</li>
                        <li>
                            Open the destination-server page. It has moved across firmwares:
                            <ul class="ml-4 mt-1 list-disc space-y-0.5">
                                <li><span class="font-medium text-ink">Event → Basic Event → Alarm Server</span> <em class="not-italic text-ink-muted">(most common on newer firmware)</em></li>
                                <li><span class="font-medium text-ink">Network → Network Service → HTTP Listening</span></li>
                                <li><span class="font-medium text-ink">Network → Advanced Settings → HTTP Listening</span> <em class="not-italic text-ink-muted">(older firmware, has User/Password fields)</em></li>
                            </ul>
                            <em class="not-italic block pt-1 text-ink-muted">
                                <strong>Do not</strong> use <span class="font-medium">Network → Platform Access → Hik-Connect</span> —
                                that's Hikvision's consumer cloud, unrelated to this webhook.
                            </em>
                        </li>
                        <li>
                            Click <span class="font-medium text-ink">Add</span> and copy the four values from the panel above:
                            <ul class="ml-4 mt-1 list-disc space-y-0.5">
                                <li><span class="font-medium text-ink">Destination IP or Host Name:</span> <code>{{ $webhookHost }}</code></li>
                                <li><span class="font-medium text-ink">URL:</span> <code>{{ $webhookPath }}</code></li>
                                <li><span class="font-medium text-ink">Protocol Type:</span> <code>{{ strtoupper($webhookScheme) }}</code></li>
                                <li><span class="font-medium text-ink">Port No.:</span> <code>{{ $webhookPort }}</code></li>
                            </ul>
                            <em class="not-italic block pt-1 text-ink-muted">
                                If (and only if) the page also shows <span class="font-medium">User Name / Password</span> fields, open the "older firmware" panel above for those values.
                            </em>
                        </li>
                        <li>
                            Turn <span class="font-medium text-ink">ANR</span> <strong>off</strong> if the camera has no SD card, otherwise events go into an offline buffer that never plays back.
                        </li>
                        <li>
                            <span class="font-medium text-ink">Event → Smart Event → Road Traffic → Vehicle Detection</span>:
                            open the <span class="font-medium text-ink">Linkage Method</span> tab and tick
                            <span class="font-medium text-ink">Notify Surveillance Center</span> (some firmware labels this row
                            <span class="font-medium text-ink">HTTP Listening</span>) for every detection rule.
                        </li>
                        <li>Save. Drive a plate past and watch the "Last Read" column on this page tick over.</li>
                    </ol>
                </div>

                <div class="flex items-center justify-between gap-2">
                    <flux:button
                        variant="ghost"
                        wire:click="regenerateSecret({{ $this->setupCamera->id }})"
                        wire:confirm="Generate a new secret? The camera will stop authenticating until you update its config."
                    >Regenerate secret</flux:button>
                    <flux:button variant="primary" wire:click="closeSetup">Done</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
