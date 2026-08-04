<?php

namespace App\Mail;

use App\Models\SecurityInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SecurityInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SecurityInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->invitation->organization->name.' has invited you to '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.security-invitation',
            with: [
                'url' => route('security-invitations.show', $this->invitation->token),
                'organizationName' => $this->invitation->organization->name,
                'name' => $this->invitation->name,
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
