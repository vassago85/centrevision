@props([
    'pdf' => 'exportPdf',
    'csv' => 'exportCsv',
])

<flux:dropdown position="bottom" align="end" {{ $attributes }}>
    <flux:button icon="arrow-down-tray" icon:trailing="chevron-down" class="min-h-11" data-test="export-menu">Export</flux:button>

    <flux:menu>
        <flux:menu.item as="button" type="button" icon="document-text" wire:click="{{ $pdf }}" data-test="export-pdf">
            PDF report
        </flux:menu.item>
        <flux:menu.item as="button" type="button" icon="table-cells" wire:click="{{ $csv }}" data-test="export-csv">
            CSV data
        </flux:menu.item>
        <flux:menu.separator />
        <p class="max-w-64 px-2 py-1.5 text-[12px] leading-snug text-ink-2">
            Uses the current period, comparison and audience. Aggregates only — no registration numbers.
        </p>
    </flux:menu>
</flux:dropdown>
