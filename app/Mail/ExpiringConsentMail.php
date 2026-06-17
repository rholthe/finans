<?php

namespace App\Mail;

use App\Models\BankConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ExpiringConsentMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, BankConnection>  $connections  Tilkoblinger med forestående utløp
     */
    public function __construct(public Collection $connections) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Banktilkobling: godkjenning utløper snart'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.expiring-consent');
    }
}
