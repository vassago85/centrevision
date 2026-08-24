@php
    /** @var \App\Support\Reporting\TrafficReport $report */
    $summary = $report->summary();
    $busiest = $report->daily()->sortByDesc('count')->first();
    $peakHourly = max(1, $report->hourly()->max('count'));
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title() }}</title>

    {{-- Dompdf has no access to the app stylesheet, so the print styles live here. --}}
    <style>
        @page { margin: 24mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1c1c1a; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 11px; margin: 22px 0 6px; }
        .muted { color: #6b6b66; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e2e1dd; text-align: left; }
        th { font-weight: 600; color: #4a4a45; }
        td.num, th.num { text-align: right; }
        .cards { width: 100%; margin-top: 12px; }
        .cards td { border: 0; background: #f0efec; padding: 8px 10px; width: 25%; }
        .cards .label { color: #6b6b66; font-size: 9px; }
        .cards .value { font-size: 15px; font-weight: 600; }
        .bar { background: #2a78d6; height: 7px; display: inline-block; }
    </style>
</head>
<body>
    <h1>{{ $report->scope }}</h1>
    <p class="muted">
        {{ $report->range->label }} ·
        {{ $report->range->from->format('j M Y') }} – {{ $report->range->to->format('j M Y') }}
        @if ($report->comparison)
            · vs {{ strtolower($report->comparison->label) }}
            ({{ $report->comparison->from->format('j M Y') }} – {{ $report->comparison->to->format('j M Y') }})
        @endif
        · generated {{ now()->format('j M Y H:i') }}
    </p>

    <table class="cards">
        <tr>
            <td>
                <div class="label">Visits</div>
                <div class="value">{{ number_format($summary['total']) }}</div>
            </td>
            <td>
                <div class="label">Unique visitors</div>
                <div class="value">{{ number_format($summary['unique']) }}</div>
            </td>
            <td>
                <div class="label">Return rate</div>
                <div class="value">{{ $summary['return_rate'] === null ? '—' : $summary['return_rate'].'%' }}</div>
            </td>
            <td>
                <div class="label">Daily average</div>
                <div class="value">{{ number_format($summary['daily_average']) }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Average dwell</div>
                <div class="value">{{ $summary['average_dwell'] === null ? '—' : $summary['average_dwell'].' min' }}</div>
            </td>
            <td>
                <div class="label">Median dwell</div>
                <div class="value">{{ $summary['median_dwell'] === null ? '—' : $summary['median_dwell'].' min' }}</div>
            </td>
            <td>
                <div class="label">Peak hour</div>
                <div class="value">{{ $summary['peak_hour'] ?? '—' }}</div>
            </td>
            <td>
                <div class="label">Staff visits excluded</div>
                <div class="value">{{ number_format($summary['excluded']) }}</div>
            </td>
        </tr>
    </table>

    <h2>Visits by hour</h2>
    <table>
        @foreach ($report->hourly() as $hour)
            <tr>
                <td style="width: 44px;">{{ $hour['label'] }}</td>
                <td><span class="bar" style="width: {{ round($hour['count'] / $peakHourly * 100) }}%;"></span></td>
                <td class="num" style="width: 52px;">{{ number_format($hour['count']) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>Dwell time distribution</h2>
    <table>
        <thead>
            <tr>
                <th>Duration</th>
                <th class="num">Visits</th>
                <th class="num">Share</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report->dwellDistribution() as $bucket)
                <tr>
                    <td>{{ $bucket['label'] }}</td>
                    <td class="num">{{ number_format($bucket['count']) }}</td>
                    <td class="num">{{ $bucket['percent'] }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Visitor behaviour</h2>
    <table>
        <thead>
            <tr>
                <th>Frequency</th>
                <th class="num">Vehicles</th>
                <th class="num">Share</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report->visitFrequency() as $bucket)
                <tr>
                    <td>{{ $bucket['label'] }}</td>
                    <td class="num">{{ number_format($bucket['count']) }}</td>
                    <td class="num">{{ $bucket['percent'] }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Visits by weekday</h2>
    <table>
        <thead>
            <tr>
                <th>Day</th>
                <th class="num">Average visits</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report->weekday() as $day)
                <tr>
                    <td>{{ $day['label'] }}</td>
                    <td class="num">{{ number_format($day['count']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($report->occupancy)
        <h2>Occupancy</h2>
        <table class="cards">
            <tr>
                <td>
                    <div class="label">Peak occupancy</div>
                    <div class="value">{{ number_format($report->occupancy['peak']) }}</div>
                </td>
                <td>
                    <div class="label">Average occupancy</div>
                    <div class="value">{{ number_format($report->occupancy['average'], 1) }}</div>
                </td>
                <td>
                    <div class="label">Parking pressure</div>
                    <div class="value">{{ $report->occupancy['parking_pressure'] }} above 80%</div>
                </td>
                <td>
                    <div class="label">Above 90%</div>
                    <div class="value">{{ \App\Support\Analytics\OccupancyAnalytics::formatDuration($report->occupancy['minutes_above_90']) }}</div>
                </td>
            </tr>
        </table>
    @endif

    <h2>Visits per day</h2>
    <table>
        <thead>
            <tr>
                <th>Day</th>
                <th class="num">Visits</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report->daily() as $day)
                <tr>
                    <td>{{ $day['label'] }}@if ($busiest && $day['date'] === $busiest['date']) <span class="muted">· busiest</span>@endif</td>
                    <td class="num">{{ number_format($day['count']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="muted" style="margin-top: 24px;">
        Aggregate figures only. Vehicle registration numbers are personal information and are never included in an export.
    </p>
</body>
</html>
