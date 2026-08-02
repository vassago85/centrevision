<?php

namespace App\Mail;

use App\Models\ShopInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShopInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ShopInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->invitation->site->name.' has invited you to '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.shop-invitation',
            with: [
                'url' => route('shop-invitations.show', $this->invitation->token),
                'siteName' => $this->invitation->site->name,
                'amount' => number_format((float) $this->invitation->monthly_amount, 2),
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
