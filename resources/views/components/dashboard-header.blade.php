@props([
    'title',
    'subtitle' => null,
    'alertCount' => 0,
    'showBell' => true,
])

<div {{ $attributes->class('mb-6 flex flex-wrap items-center justify-between gap-3') }}>
    <div>
        <h1 class="text-[26px] font-semibold tracking-tight text-ink">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-sm text-ink-2">{{ $subtitle }}</p>
        @endif
    </div>

    <div class="flex items-center gap-2">
        @isset($actions)
            {{ $actions }}
        @endisset

        {{-- Notification bell — surfaces open alerts from Security + Watchlist
             without forcing the user to switch tabs. Only rendered when the
             viewer actually has access to the alerts it would link to. --}}
        @if ($showBell)
            <a
                href="{{ route('security') }}"
                wire:navigate
                class="relative inline-flex size-10 items-center justify-center rounded-full border border-line bg-surface text-ink-2 shadow-tf-sm transition-colors hover:text-ink"
                aria-label="{{ __('Alerts') }}"
            >
                <flux:icon icon="bell" class="size-5" />
                @if ($alertCount > 0)
                    <span class="absolute -right-1 -top-1 flex min-w-[18px] items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-white">
                        {{ $alertCount > 99 ? '99+' : $alertCount }}
                    </span>
                @endif
            </a>
        @endif
    </div>
</div>
