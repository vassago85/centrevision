<?php

use App\Support\Tenancy;
use Livewire\Component;

new class extends Component {
    public ?int $siteId = null;

    public function mount(): void
    {
        $this->siteId = app(Tenancy::class)->currentSiteId();
    }

    /**
     * Persist the selection so it survives navigation, then reload the page so
     * every component re-queries against the new scope.
     */
    public function updatedSiteId(): void
    {
        session()->put('tenancy.site_id', $this->siteId ?: null);

        $this->redirect(request()->header('Referer') ?? route('overview'), navigate: true);
    }

    public function sites(): array
    {
        return app(Tenancy::class)->sites()
            ->mapWithKeys(fn ($site) => [$site->id => $site->name])
            ->all();
    }
}; ?>

<div>
    <flux:select wire:model.live="siteId" size="sm" class="min-w-44" :label="__('Site')" label:sr-only>
        <flux:select.option value="">{{ __('All sites') }}</flux:select.option>
        @foreach ($this->sites() as $id => $name)
            <flux:select.option :value="$id">{{ $name }}</flux:select.option>
        @endforeach
    </flux:select>
</div>
