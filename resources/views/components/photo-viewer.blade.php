@props([
    // The detection being viewed, or null when it can't be shown.
    'event' => null,
    // Signed capture URLs, in camera order.
    'urls' => [],
    // Human label for how long photos stay on disk, e.g. "5 days".
    'retention',
])

{{-- One large photo with thumbnails underneath. Arrow keys and the side
     buttons step through the set; Escape is handled by the modal. --}}
<div
    wire:key="photo-viewer-{{ $event?->getKey() ?? 'none' }}"
    class="space-y-3"
    x-data="{
        index: 0,
        count: {{ count($urls) }},
        prev() { if (this.count > 1) this.index = (this.index - 1 + this.count) % this.count },
        next() { if (this.count > 1) this.index = (this.index + 1) % this.count },
        step(event, direction) {
            if (! this.$root.getClientRects().length || event.target.closest('input, textarea, select')) return;
            event.preventDefault();
            direction < 0 ? this.prev() : this.next();
        },
    }"
    x-on:keydown.arrow-left.window="step($event, -1)"
    x-on:keydown.arrow-right.window="step($event, 1)"
    data-test="photo-viewer"
>
    <div class="pr-8">
        <flux:heading size="lg">
            @if ($event)
                <span class="font-mono">{{ App\Support\PlateNumber::forDisplay($event->plate_number) }}</span>
            @else
                {{ __('Camera photos') }}
            @endif
        </flux:heading>
        @if ($event)
            <flux:text class="text-ink-2">
                {{ $event->captured_at->format('D d M Y · H:i:s') }} · {{ $event->camera?->name ?? __('Unknown camera') }}
            </flux:text>
        @endif
    </div>

    @if ($urls === [])
        <x-empty-state :title="__('Photos no longer available')" icon="photo">
            {{ __('Camera photos are removed after :period. The detection record is kept.', ['period' => $retention]) }}
        </x-empty-state>
    @else
        <div class="relative overflow-hidden rounded-tf border border-line bg-surface-2">
            @foreach ($urls as $i => $url)
                <img
                    src="{{ $url }}"
                    alt="{{ __('Photo :n of :total', ['n' => $i + 1, 'total' => count($urls)]) }}"
                    x-show="index === {{ $i }}"
                    @if ($i > 0) x-cloak @endif
                    class="max-h-[60vh] w-full object-contain"
                />
            @endforeach

            @if (count($urls) > 1)
                <button
                    type="button"
                    x-on:click="prev()"
                    class="absolute top-1/2 left-2 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink shadow focus-visible:outline-2 focus-visible:outline-accent"
                    aria-label="{{ __('Previous photo') }}"
                ><flux:icon icon="chevron-left" class="size-5" /></button>
                <button
                    type="button"
                    x-on:click="next()"
                    class="absolute top-1/2 right-2 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink shadow focus-visible:outline-2 focus-visible:outline-accent"
                    aria-label="{{ __('Next photo') }}"
                ><flux:icon icon="chevron-right" class="size-5" /></button>
            @endif
        </div>

        @if (count($urls) > 1)
            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('Photos') }}">
                @foreach ($urls as $i => $url)
                    <button
                        type="button"
                        x-on:click="index = {{ $i }}"
                        x-bind:aria-current="index === {{ $i }} ? 'true' : null"
                        x-bind:class="index === {{ $i }} ? 'ring-2 ring-accent' : 'opacity-70 hover:opacity-100'"
                        class="size-16 overflow-hidden rounded-lg border border-line bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                        aria-label="{{ __('Show photo :n of :total', ['n' => $i + 1, 'total' => count($urls)]) }}"
                    ><img src="{{ $url }}" alt="" class="size-full object-cover" loading="lazy" /></button>
                @endforeach
                <span class="ml-auto text-[12.5px] text-ink-muted" aria-live="polite">
                    <span x-text="index + 1">1</span> / {{ count($urls) }} · {{ __('use ← → to browse') }}
                </span>
            </div>
        @endif

        <p class="text-[12.5px] text-ink-muted">
            {{ __('Photos are kept for :period, then deleted. The detection record stays for your data-retention period.', ['period' => $retention]) }}
        </p>
    @endif
</div>
