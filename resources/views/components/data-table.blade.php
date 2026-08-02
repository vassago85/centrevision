@props([
    // Column headers. A string, or ['label' => 'Dwell', 'align' => 'right'].
    'headers' => [],
    'empty' => 'Nothing to show yet.',
    'isEmpty' => false,
])

@if ($isEmpty)
    <x-placeholder>{{ $empty }}</x-placeholder>
@else
    <table data-tf-table {{ $attributes->class('w-full border-collapse text-[13px]') }}>
        @if ($headers)
            <thead>
                <tr>
                    @foreach ($headers as $header)
                        @php
                            $label = is_array($header) ? ($header['label'] ?? '') : $header;
                            $align = is_array($header) ? ($header['align'] ?? 'left') : 'left';
                        @endphp
                        <th @class([
                            'border-b border-line py-2 font-semibold text-ink-2',
                            'text-left' => $align === 'left',
                            'text-right' => $align === 'right',
                        ])>{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif

        <tbody>
            {{ $slot }}
        </tbody>
    </table>
@endif
