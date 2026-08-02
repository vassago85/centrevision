@props(['number'])

{{-- Plates are stored normalised (JD45GP) and only re-spaced for reading. --}}
<span {{ $attributes->class('font-medium tracking-wide') }}>
    {{ App\Support\PlateNumber::forDisplay($number) }}
</span>
