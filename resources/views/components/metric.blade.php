@props([
    'label',
    'value',
    'delta' => null,
    // 'up' renders green, 'down' renders red, otherwise muted.
    'deltaTone' => 'muted',
    // default | danger | warn | positive
    'variant' => 'default',
])

@php
    // Each variant paints its own quiet-background card. Danger stays the
    // loudest because that one is designed to yank attention.
    $bg = match ($variant) {
        'danger' => 'bg-danger-soft',
        'warn' => 'bg-warn-soft',
        'positive' => 'bg-positive-soft',
        default => 'bg-surface-2',
    };
    $labelColour = match ($variant) {
        'danger' => 'text-danger',
        'warn' => 'text-warn',
        'positive' => 'text-positive',
        default => 'text-ink-2',
    };
    $valueColour = match ($variant) {
        'danger' => 'text-danger',
        'warn' => 'text-warn',
        'positive' => 'text-positive',
        default => 'text-ink',
    };
@endphp

<div {{ $attributes->class(['rounded-tf p-4', $bg]) }}>
    <p class="text-[13px] mb-1 {{ $labelColour }}">{{ $label }}</p>

    <p class="text-[26px] font-semibold leading-tight {{ $valueColour }}">{{ $value }}</p>

    @if ($delta)
        <p @class([
            'text-xs mt-1.5',
            'text-positive' => $deltaTone === 'up',
            'text-danger' => $deltaTone === 'down',
            'text-ink-muted' => ! in_array($deltaTone, ['up', 'down']),
        ])>{{ $delta }}</p>
    @endif
</div>
