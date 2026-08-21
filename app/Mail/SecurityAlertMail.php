<?php

namespace App\Mail;

use App\Models\AlertEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SecurityAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AlertEvent $event,
        public string $siteName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[%s] %s — %s',
                $this->siteName,
                $this->event->rule->label(),
                $this->event->plate_number,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.security-alert',
            with: [
                'siteName' => $this->siteName,
                'ruleLabel' => $this->event->rule->label(),
                'plate' => $this->event->plate_number,
                'detectedAt' => $this->event->detected_at,
                'payload' => $this->event->payload ?? [],
                'securityUrl' => url('/security'),
            ],
        );
    }
}
