{{--
    The CentreVision mark: a lens ring split navy over blue, broken at 2 o'clock
    by an arrow that pierces outward — matched to the supplied wordmark.

    Colours are hard-coded because the mark is a brand asset, not a theme swatch.
--}}
<svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 200 200"
    fill="none"
    aria-hidden="true"
    {{ $attributes->merge(['class' => 'size-8']) }}
>
    {{-- Top-left arc, dark navy: from 7:30 up over 12 to 1:00 --}}
    <path
        d="M 42.84 133 A 66 66 0 0 0 133 42.84"
        stroke="#0e2340"
        stroke-width="14"
        stroke-linecap="butt"
    />

    {{-- Bottom-right arc, blue: from 2:00 down through 6 and back around to 7:30 --}}
    <path
        d="M 157.16 67 A 66 66 0 1 1 42.84 133"
        stroke="#1a76f0"
        stroke-width="14"
        stroke-linecap="butt"
    />

    {{-- Arrow bridging the gap at ~1:30, pointing outward --}}
    <path
        d="M 129 39 L 174 25 L 161 71 Z"
        fill="#1a76f0"
    />
</svg>
