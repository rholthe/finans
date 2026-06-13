<?php

namespace App\Mail;

use App\Models\SyncEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SyncReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SyncEvent $syncEvent) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->syncEvent->status) {
            SyncEvent::STATUS_NEW => __('Banksynk: nye transaksjoner importert'),
            SyncEvent::STATUS_NO_NEW => __('Banksynk: fullført, ingen nye transaksjoner'),
            SyncEvent::STATUS_WITH_ERRORS => __('Banksynk: fullført med feil'),
            SyncEvent::STATUS_FAILED => __('Banksynk: synk mislyktes'),
            default => __('Banksynk: synkrapport'),
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.sync-report');
    }
}
