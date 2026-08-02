@props([
    'variant' => 'compact', // compact = mark only, wordmark = full raster lockup
    'tagline' => false,
])

@if ($variant === 'wordmark')
    <span {{ $attributes->merge(['class' => 'inline-flex flex-col items-center gap-2']) }}>
        <img
            src="{{ asset('img/centrevision-wordmark.png') }}"
            alt="{{ config('app.name') }}"
            class="h-14 w-auto select-none sm:h-16"
            width="475"
            height="146"
        />

        @if ($tagline)
            <span class="text-[11px] font-medium uppercase tracking-[0.24em] text-ink-muted">
                {{ __('See every visit.') }} <span class="text-accent">{{ __('Know every customer.') }}</span>
            </span>
        @endif
    </span>
@else
    <span {{ $attributes->merge(['class' => 'flex items-center gap-2.5']) }}>
        <x-brand-mark class="!size-[30px]" />
        <span class="flex flex-col leading-none">
            <span class="text-[15px] font-semibold tracking-tight">
                <span class="text-ink">centre</span><span class="text-accent">vision</span>
            </span>
            <span class="mt-0.5 text-[10.5px] uppercase tracking-[0.18em] text-ink-muted">.co.za</span>
        </span>
    </span>
@endif
