@props([
    // compact  = SVG mark + inline "centrevision" text (crispest at tiny sizes)
    // wordmark = raster wordmark PNG, mark + CENTREVISION baked in, no tagline
    // full     = raster full lockup with tagline baked in
    'variant' => 'compact',
    // Only honoured for the `compact` variant — the raster variants already
    // include or exclude the tagline based on which file they point at.
    'tagline' => false,
])

@if ($variant === 'full')
    <span {{ $attributes->merge(['class' => 'inline-flex flex-col items-center']) }}>
        <img
            src="{{ asset('img/centrevision-logo-full.png') }}"
            alt="{{ config('app.name') }} — see every visit, know every customer"
            class="h-24 w-auto select-none sm:h-28"
            width="1024"
            height="682"
        />
    </span>
@elseif ($variant === 'wordmark')
    <span {{ $attributes->merge(['class' => 'inline-flex items-center']) }}>
        <img
            src="{{ asset('img/centrevision-wordmark.png') }}"
            alt="{{ config('app.name') }}"
            class="h-9 w-auto select-none sm:h-10"
            width="1024"
            height="307"
        />
    </span>
@else
    <span {{ $attributes->merge(['class' => 'flex items-center gap-2.5']) }}>
        <x-brand-mark class="!size-[30px]" />
        <span class="flex flex-col leading-none">
            <span class="text-[15px] font-semibold tracking-tight">
                <span class="text-ink">centre</span><span class="text-accent">vision</span>
            </span>
            @if ($tagline)
                <span class="mt-1 text-[10px] uppercase tracking-[0.18em] text-ink-muted">
                    {{ __('See every visit. Know every customer.') }}
                </span>
            @else
                <span class="mt-0.5 text-[10.5px] uppercase tracking-[0.18em] text-ink-muted">.co.za</span>
            @endif
        </span>
    </span>
@endif
