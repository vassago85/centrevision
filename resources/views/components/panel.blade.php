@props([
    'heading' => null,
])

<div {{ $attributes->class('mb-7') }}>
    @if ($heading || isset($actions))
        <div class="mb-2.5 flex items-center justify-between gap-3">
            @if ($heading)
                <h2 class="text-sm font-semibold text-ink">{{ $heading }}</h2>
            @endif

            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    {{ $slot }}
</div>
