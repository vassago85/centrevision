@props([
    // key => label
    'tabs' => [],
    'current' => null,
    // Livewire property the tab key is written to.
    'model',
    'label' => 'Sections',
])

{{-- Server-rendered tab strip: each tab swaps the Livewire property, so the
     choice lands in the URL and survives reloads and shared links. Arrow keys
     move between tabs as the WAI-ARIA tabs pattern expects. --}}
<div
    role="tablist"
    aria-label="{{ $label }}"
    x-data
    x-on:keydown.right.prevent="($event.target.nextElementSibling || $el.firstElementChild).focus()"
    x-on:keydown.left.prevent="($event.target.previousElementSibling || $el.lastElementChild).focus()"
    {{ $attributes->class('flex gap-1 overflow-x-auto overflow-y-hidden shadow-[inset_0_-1px_0_var(--color-line)]') }}
>
    @foreach ($tabs as $key => $tabLabel)
        @php $selected = $current === $key; @endphp
        <button
            type="button"
            role="tab"
            id="tab-{{ $key }}"
            aria-selected="{{ $selected ? 'true' : 'false' }}"
            tabindex="{{ $selected ? '0' : '-1' }}"
            wire:click="$set('{{ $model }}', '{{ $key }}')"
            @class([
                'min-h-11 shrink-0 whitespace-nowrap border-b-2 px-3.5 text-[14px] transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent',
                'border-accent font-semibold text-accent' => $selected,
                'border-transparent font-medium text-ink-2 hover:text-ink' => ! $selected,
            ])
        >{{ $tabLabel }}</button>
    @endforeach
</div>
