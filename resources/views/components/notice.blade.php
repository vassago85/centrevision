@props([
    // warning | info
    'tone' => 'warning',
    'title' => null,
])

<div
    role="note"
    {{ $attributes->class([
        'flex items-start gap-2.5 rounded-lg border px-3.5 py-2.5 text-[13px] leading-snug text-ink',
        'border-warning/40 bg-warning-soft' => $tone === 'warning',
        'border-accent/25 bg-accent-soft' => $tone === 'info',
    ]) }}
>
    <flux:icon
        :icon="$tone === 'warning' ? 'exclamation-triangle' : 'information-circle'"
        @class(['mt-px size-4 shrink-0', 'text-warning' => $tone === 'warning', 'text-accent' => $tone === 'info'])
        aria-hidden="true"
    />
    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div class="text-ink-2">{{ $slot }}</div>
    </div>
    @isset($action)
        <div class="shrink-0">{{ $action }}</div>
    @endisset
</div>
