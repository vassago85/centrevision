@props([
    'label',
    'value',
    'icon' => null,
    // Period-change caption, e.g. "▲ 12.5%". Rendered neutrally unless
    // `signed` is set, because "more" is not automatically "better".
    'delta' => null,
    // 'up' | 'down' | 'muted'
    'deltaTone' => 'muted',
    // Opt in to green/red only when the direction has a defined meaning.
    'signed' => false,
    // Supporting line under the value: definition, sample size, context.
    'comparison' => null,
    // Short data-quality caveat shown in amber under the supporting line.
    'warning' => null,
    // Optional drill-down. The whole card becomes the link target.
    'href' => null,
])

@php
    $deltaClass = match (true) {
        $signed && $deltaTone === 'up' => 'text-positive bg-positive-soft',
        $signed && $deltaTone === 'down' => 'text-danger bg-danger-soft',
        default => 'text-ink-2 bg-surface-2',
    };
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class([
        'flex min-h-[110px] flex-col gap-1.5 rounded-tf border border-line bg-surface p-4',
        'transition-colors hover:border-accent/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent' => $href,
    ]) }}
>
    <div class="flex items-start justify-between gap-2">
        <p class="text-[13px] font-medium text-ink-2">{{ $label }}</p>
        @if ($icon)
            <flux:icon :icon="$icon" class="size-4 shrink-0 text-ink-muted" aria-hidden="true" />
        @endif
    </div>

    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <p class="text-[28px] font-semibold leading-tight tracking-tight text-ink tabular-nums">{{ $value }}</p>
        @if ($delta !== null)
            <span class="rounded-full px-2 py-0.5 text-[12px] font-semibold tabular-nums {{ $deltaClass }}">{{ $delta }}</span>
        @endif
    </div>

    @if ($comparison)
        <p class="text-[12px] leading-snug text-ink-2">{{ $comparison }}</p>
    @endif

    @if ($warning)
        <p class="flex items-start gap-1 text-[12px] leading-snug text-ink">
            <flux:icon icon="exclamation-triangle" class="mt-px size-3.5 shrink-0 text-warning" aria-hidden="true" />
            <span>{{ $warning }}</span>
        </p>
    @endif

    {{ $slot }}
</{{ $tag }}>
