<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** One generic, plain alert e-mail. Payload is already minimal and safe (no CV text, no secrets). */
class AlertMail extends Mailable
{
    /**
     * @param  list<string>  $lines
     * @param  list<array{title: string, meta?: string, url?: string}>  $items  optional linked list (e.g. digest jobs)
     */
    public function __construct(
        public string $heading,
        public array $lines,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
        public array $items = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[JobPilot] '.$this->heading);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.alert');
    }
}
