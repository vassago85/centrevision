<?php

namespace App\Support\Reporting;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
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
        fputcsv($handle, ['Daily average', $summary['daily_average']]);
        fputcsv($handle, ['Average dwell (minutes)', $summary['average_dwell'] ?? '']);
        fputcsv($handle, ['Median dwell (minutes)', $summary['median_dwell'] ?? '']);
        fputcsv($handle, ['Repeat visitors (%)', $summary['repeat_percentage'] ?? '']);
        fputcsv($handle, ['Peak hour', $summary['peak_hour'] ?? '']);
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

    public function pdfDownload(TrafficReport $report): Response
    {
        return response($this->pdf($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$report->filename('pdf').'"',
        ]);
    }
}
