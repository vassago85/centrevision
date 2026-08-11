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
    // Optional identifier. Kept for backwards compatibility with call sites
    // that already pass one — the new in-place update path no longer needs
    // it, but leaving it accepted avoids touching every dashboard page.
    'name' => null,
    // Per-label context appended to the tooltip. Shape:
    //   ['Aug 8' => ['Public holiday: Women's Day', 'Rain'], ...]
    // Keys match the entries in `labels`. Empty by default, so existing
    // callers get no new tooltip lines and no behaviour change.
    'annotations' => [],
])

@php
    // Snapshot of everything Chart.js needs to draw. This whole payload
    // is written into a data attribute the Alpine component watches — when
    // Livewire polls and morphs a new value in, the chart updates in place
    // (chart.update()) instead of the canvas being torn out and remounted.
    $chartPayload = [
        'labels' => $labels,
        'values' => $values,
        'series' => $series,
        'color' => $color,
        'maxBarThickness' => $maxBarThickness,
        'showLegend' => $showLegend,
        'annotations' => (object) $annotations,
    ];
    $payloadJson = json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp

<div
    x-data="tfBarChart"
    x-init="mount()"
    {{ $attributes->class('relative w-full') }}
    style="height: {{ $height }}px"
    data-chart-payload="{{ $payloadJson }}"
>
    {{-- Canvas lives under wire:ignore so Livewire's morphdom pass never
         tears the Chart.js instance out from under Alpine. Updates flow via
         the data-chart-payload attribute on the parent. --}}
    <div wire:ignore class="absolute inset-0">
        <canvas x-ref="canvas" role="img" @if ($ariaLabel) aria-label="{{ $ariaLabel }}" @endif></canvas>
    </div>
</div>
