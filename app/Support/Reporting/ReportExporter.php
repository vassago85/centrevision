<?php

namespace App\Support\Reporting;

use App\Support\Analytics\DataQualityAnalytics;
use App\Support\Analytics\SecurityAnalytics;
use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Renders a TrafficReport for download or attachment.
 *
 * Note what is absent: plate numbers. Exports leave the application and end up
 * in inboxes and shared drives, so they carry aggregates only.
 */
class ReportExporter
{
    public function csvDownload(TrafficReport $report): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print ($this->csv($report)),
            $report->filename('csv'),
            ['Content-Type' => 'text/csv'],
        );
    }

    public function csv(TrafficReport $report): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a buffer to build the CSV.');
        }

        $summary = $report->summary();

        fputcsv($handle, [$report->title()]);
        fputcsv($handle, ['Generated', now()->format('j M Y H:i')]);
        fputcsv($handle, []);

        fputcsv($handle, ['Summary']);
        fputcsv($handle, ['Total visits', $summary['total']]);
        fputcsv($handle, ['Unique vehicles', $summary['unique']]);
        fputcsv($handle, ['Returning vehicles', $summary['returning']]);
        fputcsv($handle, ['Return rate (%)', $summary['return_rate'] ?? '']);
        fputcsv($handle, ['30-day return rate (%)', $summary['return_rate_30d'] ?? '']);
        fputcsv($handle, ['Daily average', $summary['daily_average']]);
        fputcsv($handle, ['Average dwell (minutes)', $summary['average_dwell'] ?? '']);
        fputcsv($handle, ['Median dwell (minutes)', $summary['median_dwell'] ?? '']);
        fputcsv($handle, ['Repeat visitors (%)', $summary['repeat_percentage'] ?? '']);
        fputcsv($handle, ['Peak hour', $summary['peak_hour'] ?? '']);
        fputcsv($handle, ['Staff / regular visits excluded', $summary['excluded']]);

        if ($report->comparison !== null) {
            fputcsv($handle, ['Comparison period', $report->comparison->label]);
            fputcsv($handle, ['Comparison from', $report->comparison->from->toDateString()]);
            fputcsv($handle, ['Comparison to', $report->comparison->to->toDateString()]);
        }

        fputcsv($handle, []);

        fputcsv($handle, ['Visits per day']);
        fputcsv($handle, ['Date', 'Visits']);

        foreach ($report->daily() as $day) {
            fputcsv($handle, [$day['date'], $day['count']]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Visits by hour']);
        fputcsv($handle, ['Hour', 'Visits']);

        foreach ($report->hourly() as $hour) {
            fputcsv($handle, [$hour['label'], $hour['count']]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Dwell distribution']);
        fputcsv($handle, ['Duration', 'Visits', 'Share (%)']);

        foreach ($report->dwellDistribution() as $bucket) {
            fputcsv($handle, [$bucket['label'], $bucket['count'], $bucket['percent']]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Visits by weekday']);
        fputcsv($handle, ['Weekday', 'Average visits']);

        foreach ($report->weekday() as $day) {
            fputcsv($handle, [$day['label'], $day['count']]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Visit frequency']);
        fputcsv($handle, ['Bucket', 'Vehicles', 'Share (%)']);

        foreach ($report->visitFrequency() as $bucket) {
            fputcsv($handle, [$bucket['label'], $bucket['count'], $bucket['percent']]);
        }

        if ($report->occupancy !== null) {
            fputcsv($handle, []);
            fputcsv($handle, ['Occupancy']);
            fputcsv($handle, ['Peak occupancy', $report->occupancy['peak']]);
            fputcsv($handle, ['Average occupancy', $report->occupancy['average']]);
            fputcsv($handle, ['Parking pressure', $report->occupancy['parking_pressure']]);
            fputcsv($handle, ['Minutes above 80%', $report->occupancy['minutes_above_80']]);
            fputcsv($handle, ['Minutes above 90%', $report->occupancy['minutes_above_90']]);
        }

        if ($report->includeOps) {
            $quality = app(DataQualityAnalytics::class)->summary($report->range);
            $security = app(SecurityAnalytics::class)->reportSummary($report->range);

            fputcsv($handle, []);
            fputcsv($handle, ['Security summary']);
            fputcsv($handle, ['Watchlist hits', $security['watchlist_hits']]);
            fputcsv($handle, ['Long-dwell alerts', $security['long_dwell']]);
            fputcsv($handle, ['Odd-hour activity', $security['odd_hour']]);
            fputcsv($handle, ['Multiple-entry vehicles', $security['multi_entry']]);
            fputcsv($handle, ['Missed exits', $security['orphaned']]);

            fputcsv($handle, []);
            fputcsv($handle, ['Camera quality']);
            fputcsv($handle, ['Plate reads', $quality['reads']]);
            fputcsv($handle, ['Paired visits', $quality['paired_visits']]);
            fputcsv($handle, ['Pairing quality (%)', $quality['pairing_quality'] ?? '']);
            fputcsv($handle, ['Unmatched reads', $quality['unmatched_reads']]);
            fputcsv($handle, ['Cameras offline', $quality['cameras_offline']]);
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    public function pdf(TrafficReport $report): string
    {
        return Pdf::loadView('reports.traffic', ['report' => $report])
            ->setPaper('a4')
            ->output();
    }

    public function pdfDownload(TrafficReport $report): StreamedResponse
    {
        // streamDownload matches csvDownload. Returning the raw PDF bytes as
        // a regular Response makes Livewire JSON-encode the binary and 500
        // with "Malformed UTF-8 characters".
        return response()->streamDownload(
            fn () => print ($this->pdf($report)),
            $report->filename('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }
}
