<x-mail::message>
# {{ $siteName }} traffic report

{{ $range->label }} · {{ $range->from->format('j M Y') }} – {{ $range->to->format('j M Y') }}

<x-mail::table>
| Metric | Value |
| :----- | ----: |
| Total visits | {{ number_format($summary['total']) }} |
| Daily average | {{ number_format($summary['daily_average']) }} |
| Average dwell | {{ $summary['average_dwell'] === null ? '—' : $summary['average_dwell'].' min' }} |
| Peak hour | {{ $summary['peak_hour'] ?? '—' }} |
| Repeat visitors | {{ $summary['repeat_percentage'] === null ? '—' : $summary['repeat_percentage'].'%' }} |
@if ($busiest)
| Busiest day | {{ $busiest['label'] }} ({{ number_format($busiest['count']) }}) |
@endif
</x-mail::table>

The full breakdown is attached as a PDF and a CSV.

Staff and tenant vehicles are excluded from these figures. Aggregates only:
vehicle registration numbers are never included in a report.

<x-mail::button :url="route('reports')">
Open the dashboard
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
