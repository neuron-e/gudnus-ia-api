<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ImagesProcessedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public int $total) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Procesamiento de imágenes completado',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.images_processed',
            with: ['total' => $this->total],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
