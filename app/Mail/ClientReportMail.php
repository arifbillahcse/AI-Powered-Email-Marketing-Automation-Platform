<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The scheduled client report: headline numbers and a link to the page.
 */
class ClientReportMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $report  ClientReport::data()
     */
    public function __construct(
        public array $report,
        public string $url,
        public string $senderName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->senderName),
            subject: "{$this->report['workspace']}: outreach report ({$this->report['period']})",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.client-report');
    }
}
