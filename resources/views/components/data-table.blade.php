@props([
    // Column headers. A string, or ['label' => 'Dwell', 'align' => 'right'].
    'headers' => [],
    'empty' => 'Nothing to show yet.',
    'isEmpty' => false,
])

@if ($isEmpty)
    {{-- Compact empty state — an empty table next to other content should
         not eat half the page just to say "no rows". The full-size dashed
         box is still available via the standalone <x-placeholder> outside
         a table. --}}
    <x-placeholder size="compact">{{ $empty }}</x-placeholder>
@else
    <table data-tf-table {{ $attributes->class('w-full border-collapse text-[13px]') }}>
        @if ($headers)
            <thead>
                <tr>
                    @foreach ($headers as $header)
                        @php
                            $label = is_array($header) ? ($header['label'] ?? '') : $header;
                            $align = is_array($header) ? ($header['align'] ?? 'left') : 'left';
                            // A blank label means "actions column" — swap in a
                            // visually-hidden fallback so screen readers hear
                            // "Actions" and axe's empty-table-header rule passes.
                            $srOnly = $label === '' ? ($header['aria-label'] ?? 'Actions') : null;
                        @endphp
                        <th @class([
                            'border-b border-line py-2 font-semibold text-ink-2',
                            'text-left' => $align === 'left',
                            'text-right' => $align === 'right',
                        ])>
                            @if ($srOnly !== null)
                                <span class="sr-only">{{ $srOnly }}</span>
                            @else
                                {{ $label }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
        @endif

        <tbody>
            {{ $slot }}
        </tbody>
    </table>
@endif
