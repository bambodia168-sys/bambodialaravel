<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

final class PlatformEmail extends Mailable
{
    public function __construct(
        public readonly array $copy,
        public readonly ?string $actionUrl,
        public readonly ?string $unsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->copy['subject']);
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->unsubscribeUrl ? ['List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>'] : []);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.platform-email', text: 'mail.platform-email-text');
    }
}
