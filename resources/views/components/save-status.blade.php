@props([
    'dirty' => false,
    'savedAt' => null,
])

<span {{ $attributes->class('inline-flex items-center gap-1.5 text-[13px]') }} role="status" aria-live="polite">
    @if ($dirty)
        <span class="size-2 rounded-full bg-warning" aria-hidden="true"></span>
        <span class="font-medium text-warning">Unsaved changes</span>
    @elseif ($savedAt)
        <flux:icon icon="check-circle" class="size-4 text-positive" aria-hidden="true" />
        <span class="text-ink-2">Saved at {{ $savedAt }}</span>
    @endif
</span>
