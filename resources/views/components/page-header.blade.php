@props([
    'title',
    'subtitle' => null,
])

<div {{ $attributes->class('mb-4 flex flex-wrap items-end justify-between gap-x-4 gap-y-3') }}>
    <div class="min-w-0">
        <h1 class="text-[24px] font-semibold leading-tight tracking-tight text-ink">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-[13px] text-ink-2">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
