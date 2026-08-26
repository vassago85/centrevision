@props([
    'title',
    'subtitle' => null,
    'alertCount' => 0,
    'showBell' => true,
    // When true, render a small pulsing dot + last-render timestamp so the
    // viewer can tell at a glance that the page is refreshing itself. The
    // stamp is server-rendered, so it ticks forward on every successful poll
    // and quietly stops if the connection drops — which is the honest signal
    // that something is wrong.
    'live' => false,
    // Optional current-conditions pill for the tenant's site(s). Null (or
    // any shape with both temp_c and weather_label null) hides the pill
    // silently — a header that spits out an error card because Open-Meteo
    // burped would be worse than no weather at all.
    'weather' => null,
])

@php
    $hasWeather = is_array($weather)
        && (($weather['weather_label'] ?? null) !== null || ($weather['temp_c'] ?? null) !== null);

    // "Clear" and "Mainly clear" get a sun; thunderstorms get a bolt. Everything
    // else falls back to a generic cloud — same convention the notable-days
    // chips already use, keeping the weather visual language consistent.
    $weatherIcon = match ($weather['weather_label'] ?? null) {
        'Clear', 'Mainly clear' => 'sun',
        'Thunderstorm' => 'bolt',
        default => 'cloud',
    };
@endphp

<div {{ $attributes->class('mb-6 flex flex-wrap items-center justify-between gap-3') }}>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <div>
            <h1 class="text-[26px] font-semibold tracking-tight text-ink">{{ $title }}</h1>
            @if ($subtitle)
                <p class="mt-1 text-sm text-ink-2">{{ $subtitle }}</p>
            @endif
        </div>

        @if ($hasWeather)
            {{-- Live "now" conditions for the site(s) in scope. Suppressed
                 entirely when we have no coordinates or upstream is down —
                 a pill that flickers between values and blanks would be
                 more distracting than useful. --}}
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-2.5 py-1 text-[11px] font-medium text-ink-2"
                title="{{ __('Current conditions') }}"
            >
                <flux:icon :icon="$weatherIcon" class="size-3.5" />
                @if (($weather['weather_label'] ?? null) !== null)
                    <span>{{ $weather['weather_label'] }}</span>
                @endif
                @if (($weather['temp_c'] ?? null) !== null)
                    <span class="tabular-nums">
                        @if (($weather['weather_label'] ?? null) !== null) · @endif
                        {{ round((float) $weather['temp_c']) }}°C
                    </span>
                @endif
            </span>
        @endif
    </div>

    <div class="flex items-center gap-2">
        @if ($live)
            {{-- Live indicator. The timestamp is rendered server-side on every
                 poll, so a stale value means the poll has stopped landing. --}}
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-2.5 py-1 text-[11px] font-medium text-ink-2"
                title="{{ __('Updated at :time', ['time' => now()->format('H:i:s')]) }}"
            >
                <span class="relative flex size-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-accent opacity-75"></span>
                    <span class="relative inline-flex size-2 rounded-full bg-accent"></span>
                </span>
                <span class="tabular-nums">{{ __('Live') }} · {{ now()->format('H:i:s') }}</span>
            </span>
        @endif

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
