<?php

namespace App\Mail;

use App\Support\Reporting\ReportExporter;
use App\Support\Reporting\TrafficReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The recurring traffic report, with the PDF and CSV attached.
 *
 * The body carries the headline numbers so a recipient reading on a phone gets
 * the answer without opening anything.
 */
class ScheduledReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public TrafficReport $report,
        public string $siteName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->siteName.' traffic report — '.$this->report->range->label,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.scheduled-report',
            with: [
                'siteName' => $this->siteName,
                'summary' => $this->report->summary(),
                'range' => $this->report->range,
                'busiest' => $this->report->daily()->sortByDesc('count')->first(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $exporter = app(ReportExporter::class);

        return [
            Attachment::fromData(fn () => $exporter->pdf($this->report), $this->report->filename('pdf'))
                ->withMime('application/pdf'),
            Attachment::fromData(fn () => $exporter->csv($this->report), $this->report->filename('csv'))
                ->withMime('text/csv'),
        ];
    }
}
