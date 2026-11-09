<?php

namespace App\Mail;

use App\Models\StoreVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso al comercio: LaU aprobó su verificación y ya puede conectar PayPal. */
class MerchantApprovedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly StoreVerification $verification) {}

    public function envelope(): Envelope
    {
        $replyTo = config('services.lau.reply_to');

        return new Envelope(
            subject: '¡'.$this->verification->store->name.' ya es un comercio verificado en LaU!',
            replyTo: $replyTo ? [new Address($replyTo, 'Equipo LaU')] : [],
        );
    }

    public function content(): Content
    {
        $frontend = config('services.frontend_url');

        return new Content(
            view: 'emails.comercio-aprobado',
            with: [
                'storeName' => $this->verification->store->name,
                'legalRepresentative' => $this->verification->legal_representative,
                'connectUrl' => $frontend.'/tabs/marketplace/empresa',
                'storeUrl' => $frontend.'/tabs/marketplace/store/'.$this->verification->store->slug,
                'logoUrl' => $frontend.'/LaU.png',
                'replyTo' => config('services.lau.reply_to'),
                'recipient' => $this->verification->contact_email,
            ],
        );
    }
}
