@props([
    // danger | warning | accent | positive | neutral
    'tone' => 'neutral',
])

<span {{ $attributes->class([
    'inline-block rounded-tf px-2.5 py-[3px] text-[11px] font-semibold',
    'bg-danger-soft text-danger' => $tone === 'danger',
    'bg-warning-soft text-warning' => $tone === 'warning',
    'bg-accent-soft text-accent' => $tone === 'accent',
    'bg-surface-2 text-positive' => $tone === 'positive',
    'bg-surface-2 text-ink-2' => $tone === 'neutral',
]) }}>{{ $slot }}</span>
