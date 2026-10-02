@props([
    'title',
    'icon' => null,
])

<div {{ $attributes->class('flex items-start gap-3 py-3') }}>
    @if ($icon)
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface-2 text-ink-muted">
            <flux:icon :icon="$icon" class="size-4" aria-hidden="true" />
        </span>
    @endif
    <div class="min-w-0">
        <p class="text-[14px] font-semibold text-ink">{{ $title }}</p>
        @if (trim($slot) !== '')
            <p class="mt-0.5 text-[13px] leading-snug text-ink-2">{{ $slot }}</p>
        @endif
        @isset($action)
            <div class="mt-2">{{ $action }}</div>
        @endisset
    </div>
</div>
