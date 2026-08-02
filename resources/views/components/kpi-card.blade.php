@props([
    'label',
    'value',
    'icon',
    'delta' => null,
    // 'up' | 'down' | 'muted'
    'deltaTone' => 'muted',
    'comparison' => null,
])

@php
    $deltaClass = match ($deltaTone) {
        'up' => 'text-positive bg-positive-soft',
        'down' => 'text-danger bg-danger-soft',
        default => 'text-ink-muted bg-surface-2',
    };
    $deltaGlyph = match ($deltaTone) {
        'up' => '↑',
        'down' => '↓',
        default => '·',
    };
@endphp

<div {{ $attributes->class('flex flex-col gap-4 rounded-tf border border-line bg-surface p-5 shadow-tf-sm') }}>
    <div class="flex items-start justify-between gap-3">
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">{{ $label }}</p>

        {{-- Circular blue icon top-right — matches the mockup. --}}
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent">
            <flux:icon :icon="$icon" class="size-4.5" />
        </span>
    </div>

    <p class="text-[30px] font-semibold leading-none tracking-tight text-ink">{{ $value }}</p>

    <div class="flex items-baseline justify-between gap-2 text-[12px]">
        @if ($delta !== null)
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold {{ $deltaClass }}">
                <span>{{ $deltaGlyph }}</span>
                <span>{{ $delta }}</span>
            </span>
        @else
            <span></span>
        @endif

        @if ($comparison)
            <span class="text-ink-muted">{{ $comparison }}</span>
        @endif
    </div>
</div>
