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
    // Optional identifier used to build a wire:key that changes only when the
    // chart's data actually changes. Without a name we fall back to the old
    // wire:ignore behaviour so unnamed charts on non-polled pages stay put.
    'name' => null,
    // Per-label context appended to the tooltip. Shape:
    //   ['Aug 8' => ['Public holiday: Women's Day', 'Rain'], ...]
    // Keys match the entries in `labels`. Empty by default, so existing
    // callers get no new tooltip lines and no behaviour change.
    'annotations' => [],
])

@php
    // Snapshot of everything Chart.js will draw. Hashing this lets Livewire
    // leave the canvas alone when a poll brings identical data (no flicker,
    // no wasted redraw) and remount it only when the numbers actually move.
    $chartPayload = [
        'labels' => $labels,
        'values' => $values,
        'series' => $series,
        'color' => $color,
        'maxBarThickness' => $maxBarThickness,
        'showLegend' => $showLegend,
        'annotations' => (object) $annotations,
    ];
@endphp

<div
    @if ($name)
        wire:key="chart-{{ $name }}-{{ substr(md5(json_encode($chartPayload)), 0, 8) }}"
    @else
        wire:ignore
    @endif
    x-data="tfBarChart(@js($chartPayload))"
    {{ $attributes->class('relative w-full') }}
    style="height: {{ $height }}px"
>
    <canvas x-ref="canvas" role="img" @if ($ariaLabel) aria-label="{{ $ariaLabel }}" @endif></canvas>
</div>
