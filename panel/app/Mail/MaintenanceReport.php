<?php

namespace App\Mail;

use App\Models\Customer;
use App\Ocm\Reports\ReportDesign;
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
        return new Envelope(subject: ReportDesign::fill($this->design['mail_subject'], $this->customer, $this->periodFrom, $this->periodTo, $this->design));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.maintenance-report', with: ['body' => ReportDesign::fill($this->design['mail_body'], $this->customer, $this->periodFrom, $this->periodTo, $this->design)]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
