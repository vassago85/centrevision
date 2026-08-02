<?php

use App\Enums\CameraRole;
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

    public string $ipAddress = '';

    public string $isapiUsername = '';

    /** Left blank on edit means "keep the stored password". */
    public string $isapiPassword = '';

    public int $channelId = 1;

    public bool $isActive = true;

    protected function rules(): array
    {
        return [
            // Restricting the site list to what the tenant can reach makes a
            // tampered site id a validation failure rather than a data leak.
            'siteId' => ['required', 'integer', Rule::in(app(Tenancy::class)->accessibleSiteIds())],
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::enum(CameraRole::class)],
            'ipAddress' => ['required', 'string', 'max:120'],
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
            'ip_address' => $this->ipAddress,
            'isapi_username' => $this->isapiUsername ?: null,
            'channel_id' => $this->channelId,
            'is_active' => $this->isActive,
        ];

        // An empty password field on edit means "leave the stored one alone",
        // since we never render the existing secret back to the browser.
        if ($this->isapiPassword !== '') {
            $attributes['isapi_password'] = $this->isapiPassword;
        }

        if ($this->editingId === null) {
            Camera::create($attributes);
        } else {
            Camera::findOrFail($this->editingId)->update($attributes);
        }

        unset($this->cameras);
        $this->showForm = false;

        Flux::toast(
            variant: 'success',
            text: $this->name.' saved. It will start feeding events on the next sweep.',
        );
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
            :headers="['Camera', 'Site', 'Role', 'Address', 'Status', 'Last event', ['label' => '', 'align' => 'right']]"
            :is-empty="$this->cameras->isEmpty()"
            empty="No cameras yet. Add the first one to start ingesting plates."
        >
            @foreach ($this->cameras as $camera)
                @php($status = $this->status($camera))

                <tr wire:key="camera-{{ $camera->id }}">
                    <td class="border-b border-line py-2 font-medium">{{ $camera->name }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $camera->site->name }}</td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $camera->role->label() }}</td>
                    <td class="border-b border-line py-2 font-mono text-xs text-ink-2">{{ $camera->ip_address }}</td>
                    <td class="border-b border-line py-2">
                        <x-badge :tone="$status['tone']">{{ $status['label'] }}</x-badge>
                    </td>
                    <td class="border-b border-line py-2 text-ink-2">
                        {{ $camera->last_event_at?->diffForHumans() ?? '—' }}
                    </td>
                    <td class="border-b border-line py-2 text-right">
                        <div class="flex justify-end gap-1">
                            <flux:button size="xs" variant="ghost" wire:click="probe({{ $camera->id }})">Test</flux:button>
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

            <flux:switch wire:model="isActive" label="Active" description="Inactive cameras are skipped by the listener and the drop-folder sweep." />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="$set('showForm', false)">Cancel</flux:button>
                <flux:button variant="primary" type="submit">Save camera</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
