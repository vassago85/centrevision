@props([
    'title',
    'subtitle' => null,
    'alertCount' => 0,
    'showBell' => true,
    // Live status: a dot plus the server-rendered time of the last refresh.
    // The stamp moves forward on every successful poll and stops if polling
    // stops, which is the honest signal that something is wrong.
    'live' => false,
    // Current conditions for the site(s) in scope. Null, or both fields
    // null, hides the pill rather than showing an error.
    'weather' => null,
])

@php
    $hasWeather = is_array($weather)
        && (($weather['weather_label'] ?? null) !== null || ($weather['temp_c'] ?? null) !== null);

    $weatherIcon = match ($weather['weather_label'] ?? null) {
        'Clear', 'Mainly clear' => 'sun',
        'Thunderstorm' => 'bolt',
        default => 'cloud',
    };
@endphp

<div {{ $attributes->class('mb-4 flex flex-wrap items-end justify-between gap-x-4 gap-y-3') }}>
    <div class="min-w-0">
        <h1 class="text-[24px] font-semibold leading-tight tracking-tight text-ink">{{ $title }}</h1>
        @if ($subtitle || $live || $hasWeather)
            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-ink-2">
                @if ($subtitle)
                    <span>{{ $subtitle }}</span>
                @endif
                @if ($live)
                    <span class="inline-flex items-center gap-1.5" data-test="live-status">
                        <span class="relative flex size-2" aria-hidden="true">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-positive opacity-60 motion-reduce:hidden"></span>
                            <span class="relative inline-flex size-2 rounded-full bg-positive"></span>
                        </span>
                        <span class="tabular-nums">{{ __('Live · updated :time', ['time' => now()->format('H:i:s')]) }}</span>
                    </span>
                @endif
                @if ($hasWeather)
                    <span class="inline-flex items-center gap-1" title="{{ __('Current conditions') }}">
                        <flux:icon :icon="$weatherIcon" class="size-4 text-ink-muted" aria-hidden="true" />
                        @if (($weather['weather_label'] ?? null) !== null)
                            <span>{{ $weather['weather_label'] }}</span>
                        @endif
                        @if (($weather['temp_c'] ?? null) !== null)
                            <span class="tabular-nums">@if (($weather['weather_label'] ?? null) !== null)· @endif{{ round((float) $weather['temp_c']) }}°C</span>
                        @endif
                    </span>
                @endif
            </p>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @isset($actions)
            {{ $actions }}
        @endisset

        @if ($showBell)
            <a
                href="{{ route('security') }}"
                wire:navigate
                class="relative inline-flex size-11 items-center justify-center rounded-lg border border-line bg-surface text-ink-2 transition-colors hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                aria-label="{{ $alertCount > 0 ? trans_choice('{1}:count new alert|[2,*]:count new alerts', $alertCount, ['count' => $alertCount]) : __('Alerts') }}"
            >
                <flux:icon icon="bell" class="size-5" />
                @if ($alertCount > 0)
                    <span class="absolute -right-1 -top-1 flex min-w-[20px] items-center justify-center rounded-full bg-danger px-1 text-[11px] font-semibold text-white" aria-hidden="true">
                        {{ $alertCount > 99 ? '99+' : $alertCount }}
                    </span>
                @endif
            </a>
        @endif
    </div>
</div>
