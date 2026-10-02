@props([
    'label' => 'View',
])

{{-- One distribution, two presentations. The chart and the table are both
     rendered so the switch is instant and screen-reader users can always
     choose the table. --}}
<div x-data="{ asTable: false }" {{ $attributes }}>
    <div class="mb-3 inline-flex rounded-lg border border-line p-0.5" role="group" aria-label="{{ $label }}">
        <button
            type="button"
            x-on:click="asTable = false"
            x-bind:aria-pressed="(! asTable).toString()"
            x-bind:class="! asTable ? 'bg-accent text-white' : 'text-ink-2 hover:text-ink'"
            class="min-h-10 rounded-md px-3 text-[13px] font-medium focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent"
        >Chart</button>
        <button
            type="button"
            x-on:click="asTable = true"
            x-bind:aria-pressed="asTable.toString()"
            x-bind:class="asTable ? 'bg-accent text-white' : 'text-ink-2 hover:text-ink'"
            class="min-h-10 rounded-md px-3 text-[13px] font-medium focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent"
        >Table</button>
    </div>

    <div x-show="! asTable">{{ $chart }}</div>
    <div x-show="asTable" x-cloak>{{ $table }}</div>
</div>
