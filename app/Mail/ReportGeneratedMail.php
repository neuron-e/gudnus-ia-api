<?php

namespace App\Mail;

use App\Models\ReportGeneration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReportGeneratedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ReportGeneration $reportGeneration)
    {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Tu informe de electroluminiscencia está listo - {$this->reportGeneration->project->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report-generated',
            with: [
                'projectName' => $this->reportGeneration->project->name,
                'totalImages' => $this->reportGeneration->total_images,
                'downloadUrls' => $this->reportGeneration->getDownloadUrls(),
                'expiresAt' => $this->reportGeneration->expires_at,
                'generationId' => $this->reportGeneration->id,
            ]
        );
    }
}
