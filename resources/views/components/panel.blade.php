@props([
    'heading' => null,
    'description' => null,
])

<section {{ $attributes->class('mb-4') }}>
    @if ($heading || isset($actions))
        <div class="mb-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            @if ($heading)
                <div class="min-w-0">
                    <h2 class="text-[15px] font-semibold text-ink">{{ $heading }}</h2>
                    @if ($description)
                        <p class="mt-0.5 text-[13px] text-ink-muted">{{ $description }}</p>
                    @endif
                </div>
            @endif

            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    {{ $slot }}
</section>
