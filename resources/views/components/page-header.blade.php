@props([
    'title',
    'subtitle' => null,
])

<div {{ $attributes->class('mb-5 flex flex-wrap items-center justify-between gap-3') }}>
    <div>
        <h1 class="text-[17px] font-semibold text-ink">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-0.5 text-[13px] text-ink-2">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
