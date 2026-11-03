<?php

namespace App\Mail;

use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MaintenanceReport extends Mailable
{
    /**
     * @param array<string, mixed> $design
     */
    public function __construct(
        public Customer $customer,
        public array $design,
        public CarbonImmutable $periodFrom,
        public CarbonImmutable $periodTo,
        private string $pdf,
        private string $filename,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->design['title'] . ' ' . $this->customer->name . ' – ' . $this->periodTo->locale('nl')->translatedFormat('F Y'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.maintenance-report');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
