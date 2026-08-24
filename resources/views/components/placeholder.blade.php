@props([
    // 'default' keeps the historical py-12 spacing for full-page empty
    // states. 'compact' halves the vertical padding for empty tables and
    // filtered lists — those show up next to real data and should not
    // dominate the page just because a filter returned zero rows.
    'size' => 'default',
])

<div {{ $attributes->class([
    'rounded-tf border border-dashed border-line px-6 text-center text-sm text-ink-muted',
    'py-12' => $size === 'default',
    'py-6' => $size === 'compact',
]) }}>
    {{ $slot }}
</div>
