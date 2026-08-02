@props([
    'padding' => 'p-5',
])

{{--
    The mockup's dashboard cards: white, rounded, softly shadowed, with a
    header row that hosts the title on the left and any actions on the right.

    The `header` slot renders inside the card, spans the full width, and sits
    above the main content. Callers can drop any Blade in there.
--}}
<div {{ $attributes->class(['rounded-tf border border-line bg-surface shadow-tf-sm', $padding]) }}>
    @isset($header)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            {{ $header }}
        </div>
    @endisset

    {{ $slot }}
</div>
