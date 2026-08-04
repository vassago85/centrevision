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

    public function add(): void
    {
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
}; ?>

<div>
    <x-page-header title="Cameras" subtitle="Devices feeding this site">
        <x-slot:actions>
            <flux:button size="sm" variant="primary" wire:click="add">Add camera</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-7 grid grid-cols-3 gap-3 max-sm:grid-cols-1">
        <x-metric label="Cameras" :value="$this->cameras->count()" />
        <x-metric
            label="Online"
            :value="$this->cameras->filter(fn ($camera) => $camera->is_active && $camera->isReachable())->count()"
            :delta="'Stale after '.config('trafficflow.camera_stale_after_minutes').' minutes'"
        />
        <x-metric label="Events today" :value="number_format($this->cameras->sum('events_today'))" />
    </div>

    <x-panel heading="Devices">
        <x-data-table
            :headers="['Camera', 'Site', 'Role', 'Mode', 'Status', 'Last event', ['label' => '', 'align' => 'right']]"
            :is-empty="$this->cameras->isEmpty()"
            empty="No cameras yet. Add the first one to start ingesting plates."
        >
            @foreach ($this->cameras as $camera)
                @php($status = $this->status($camera))

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
                        <x-badge :tone="$status['tone']">{{ $status['label'] }}</x-badge>
                    </td>
                    <td class="border-b border-line py-2 text-ink-2">
                        {{ $camera->last_event_at?->diffForHumans() ?? '—' }}
                    </td>
                    <td class="border-b border-line py-2 text-right">
                        <div class="flex justify-end gap-1">
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

                <div
                    x-data="{
                        show: false,
                        copy(text, label) {
                            navigator.clipboard.writeText(text);
                            this.$dispatch('toast', { variant: 'success', text: label + ' copied.' });
                        },
                    }"
                    class="space-y-3"
                >
                    <div>
                        <label class="text-[13px] font-medium text-ink-2">HTTP Listening URL (with secret in path — for Alarm Server without auth fields)</label>
                        <div class="mt-1 flex gap-2">
                            <input
                                type="text"
                                readonly
                                value="{{ $this->setupCamera->webhookUrlWithToken() }}"
                                class="flex-1 rounded-tf border border-line bg-surface-2 px-3 py-2 font-mono text-[12.5px] text-ink"
                            />
                            <flux:button size="sm" variant="ghost" @click="copy('{{ $this->setupCamera->webhookUrlWithToken() }}', 'URL')">Copy</flux:button>
                        </div>
                    </div>

                    <div>
                        <label class="text-[13px] font-medium text-ink-2">HTTP Listening URL (auth-header variant)</label>
                        <div class="mt-1 flex gap-2">
                            <input
                                type="text"
                                readonly
                                value="{{ $this->setupCamera->webhookUrl() }}"
                                class="flex-1 rounded-tf border border-line bg-surface-2 px-3 py-2 font-mono text-[12.5px] text-ink"
                            />
                            <flux:button size="sm" variant="ghost" @click="copy('{{ $this->setupCamera->webhookUrl() }}', 'URL')">Copy</flux:button>
                        </div>
                    </div>

                    <div>
                        <label class="text-[13px] font-medium text-ink-2">HTTP Basic username</label>
                        <div class="mt-1 flex gap-2">
                            <input
                                type="text"
                                readonly
                                value="{{ $this->setupCamera->id }}"
                                class="flex-1 rounded-tf border border-line bg-surface-2 px-3 py-2 font-mono text-[12.5px] text-ink"
                            />
                            <flux:button size="sm" variant="ghost" @click="copy('{{ $this->setupCamera->id }}', 'Username')">Copy</flux:button>
                        </div>
                    </div>

                    <div>
                        <label class="text-[13px] font-medium text-ink-2">HTTP Basic password (secret)</label>
                        <div class="mt-1 flex gap-2">
                            <input
                                :type="show ? 'text' : 'password'"
                                readonly
                                value="{{ $revealedSecret }}"
                                class="flex-1 rounded-tf border border-line bg-surface-2 px-3 py-2 font-mono text-[12.5px] text-ink"
                            />
                            <flux:button size="sm" variant="ghost" @click="show = ! show" x-text="show ? 'Hide' : 'Show'">Show</flux:button>
                            <flux:button size="sm" variant="ghost" @click="copy('{{ $revealedSecret }}', 'Secret')">Copy</flux:button>
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
                                <li><span class="font-medium text-ink">Event → Basic Event → Alarm Server</span> <em class="not-italic text-ink-muted">(newer firmware, no auth fields — use the first URL above)</em></li>
                                <li><span class="font-medium text-ink">Network → Network Service → HTTP Listening</span></li>
                                <li><span class="font-medium text-ink">Network → Advanced Settings → HTTP Listening</span> <em class="not-italic text-ink-muted">(older firmware, has auth fields — use the second URL + username/password)</em></li>
                            </ul>
                            <em class="not-italic block pt-1 text-ink-muted">
                                <strong>Do not</strong> use <span class="font-medium">Network → Platform Access → Hik-Connect</span> —
                                that's Hikvision's consumer cloud, nothing to do with this webhook.
                            </em>
                        </li>
                        <li>
                            Click <span class="font-medium text-ink">Add</span> and fill it in:
                            <ul class="ml-4 mt-1 list-disc space-y-0.5">
                                <li><span class="font-medium text-ink">Destination IP or Host Name:</span> <code>centrevision.co.za</code></li>
                                <li><span class="font-medium text-ink">URL:</span> the path portion of the URL you copied above.</li>
                                <li><span class="font-medium text-ink">Protocol Type:</span> <code>HTTP</code> — the reverse proxy handles TLS.</li>
                                <li><span class="font-medium text-ink">Port No.:</span> <code>443</code>.</li>
                                <li>Leave ANR on — the camera will buffer and retry events on network drops.</li>
                                <li>If the page has User Name / Password fields, paste the camera id and secret from above.</li>
                            </ul>
                        </li>
                        <li>
                            <span class="font-medium text-ink">Event → Smart Event → Road Traffic → Vehicle Detection</span>:
                            open the <span class="font-medium text-ink">Linkage Method</span> tab and tick
                            <span class="font-medium text-ink">Notify Surveillance Center</span> (some firmware calls
                            this row <span class="font-medium text-ink">HTTP Listening</span>) for every detection rule.
                        </li>
                        <li>Save. Drive a plate past and watch the "Last event" column on this page tick over.</li>
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
