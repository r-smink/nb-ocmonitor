<?php

namespace App\Mail;

use App\Models\User;
use App\Ocm\Alerts\Message;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AlertDigest extends Mailable
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(public User $user, public array $items)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[OC Monitor] ' . mb_strimwidth(Message::title($this->items), 0, 150, '…'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.alert-digest');
    }
}
