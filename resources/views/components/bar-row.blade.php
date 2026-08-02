@props([
    'label',
    'value',
    // Percentage of the widest bar in the group, 0-100.
    'percent' => 0,
])

<div class="mb-2 flex items-center gap-2 text-[13px]">
    <span class="w-9 shrink-0 text-ink-2">{{ $label }}</span>
    <div class="h-2.5 flex-1 overflow-hidden rounded bg-surface-2">
        <div class="h-2.5 rounded bg-accent" style="width: {{ max(0, min(100, $percent)) }}%"></div>
    </div>
    <span class="w-11 shrink-0 text-right tabular-nums">{{ $value }}</span>
</div>
