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
    // Default cards are white on the neutral page; the tinted variants are
    // reserved for figures that genuinely need attention.
    $card = match ($variant) {
        'danger' => 'border-danger/25 bg-danger-soft',
        'warn' => 'border-warn/25 bg-warn-soft',
        'positive' => 'border-positive/25 bg-positive-soft',
        default => 'border-line bg-surface',
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

<div {{ $attributes->class(['rounded-tf border p-4', $card]) }}>
    <p class="mb-1 text-[13px] font-medium {{ $labelColour }}">{{ $label }}</p>

    <p class="text-[24px] font-semibold leading-tight tabular-nums {{ $valueColour }}">{{ $value }}</p>

    @if ($delta)
        <p @class([
            'mt-1.5 text-[12.5px]',
            'text-positive' => $deltaTone === 'up',
            'text-danger' => $deltaTone === 'down',
            'text-ink-muted' => ! in_array($deltaTone, ['up', 'down']),
        ])>{{ $delta }}</p>
    @endif
</div>
