@props([
    'padding' => 'p-4 sm:p-5',
    'title' => null,
    'description' => null,
])

{{--
    White card with an optional header row: title + description on the left,
    `actions` on the right. The free-form `header` slot is still honoured for
    callers that need a custom layout.
--}}
<div {{ $attributes->class(['min-w-0 rounded-tf border border-line bg-surface', $padding]) }}>
    @if ($title || isset($actions))
        <div class="mb-3 flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="text-[15px] font-semibold text-ink">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-[13px] leading-snug text-ink-2">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @elseif (isset($header))
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            {{ $header }}
        </div>
    @endif

    {{ $slot }}
</div>
