@props([
    'labels' => [],
    'values' => [],
    // For grouped bars: list of ['label' => ..., 'values' => [...], 'color' => ...].
    'series' => null,
    // Key into the theme palette: accent | positive | danger | warning.
    'color' => 'accent',
    'maxBarThickness' => 20,
    'height' => 220,
    'ariaLabel' => null,
    'showLegend' => false,
])

<div
    wire:ignore
    x-data="tfBarChart(@js([
        'labels' => $labels,
        'values' => $values,
        'series' => $series,
        'color' => $color,
        'maxBarThickness' => $maxBarThickness,
        'showLegend' => $showLegend,
    ]))"
    {{ $attributes->class('relative w-full') }}
    style="height: {{ $height }}px"
>
    <canvas x-ref="canvas" role="img" @if ($ariaLabel) aria-label="{{ $ariaLabel }}" @endif></canvas>
</div>
