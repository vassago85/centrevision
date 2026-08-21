@props(['number' => '', 'link' => true])

@php
    $display = \App\Support\PlateNumber::forDisplay($number);
    $normalised = \App\Support\PlateNumber::normalise($number);
    $site = app(\App\Support\Tenancy::class)->currentSite();
    $canLink = $link
        && $normalised !== ''
        && $site !== null
        && auth()->check()
        && auth()->user()->can('viewSecurity', $site);
@endphp

@if ($canLink)
    <a
        href="{{ route('vehicle', ['plate' => $normalised]) }}"
        wire:navigate
        {{ $attributes->class('font-mono text-[13px] font-semibold tracking-wide text-accent underline-offset-2 hover:underline') }}
    >{{ $display }}</a>
@else
    <span {{ $attributes->class('font-medium tracking-wide') }}>{{ $display }}</span>
@endif
