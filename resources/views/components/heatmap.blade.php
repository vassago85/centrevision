@props([
    'rows' => [],
    'max' => 0,
])

@php
    $max = $max > 0 ? $max : 1;
@endphp

<div {{ $attributes->class('overflow-x-auto') }}>
    <table class="w-full border-collapse text-[11px]">
        <thead>
            <tr>
                <th class="sticky left-0 z-10 bg-surface py-1 pr-2 text-left font-semibold text-ink-2">Day</th>
                @foreach (range(0, 23) as $hour)
                    <th class="px-0.5 py-1 text-center font-medium text-ink-muted">{{ sprintf('%02d', $hour) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <th class="sticky left-0 z-10 bg-surface py-0.5 pr-2 text-left font-medium text-ink-2">{{ $row['label'] }}</th>
                    @foreach ($row['hours'] as $cell)
                        @php
                            $intensity = $cell['average'] / $max;
                            $alpha = $cell['average'] > 0 ? max(0.12, $intensity) : 0;
                        @endphp
                        <td
                            class="h-7 min-w-6 rounded-[4px] text-center tabular-nums {{ $alpha === 0 ? 'bg-surface-2' : '' }}"
                            style="{{ $alpha > 0 ? 'background: color-mix(in srgb, var(--tf-accent) '.round($alpha * 100).'%, transparent)' : '' }}"
                            title="{{ $row['label'] }} {{ sprintf('%02d:00', $cell['hour']) }} · {{ $cell['average'] }} avg"
                        >
                            <span class="sr-only">{{ $cell['average'] }}</span>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
