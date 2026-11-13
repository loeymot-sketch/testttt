<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SubscriberMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */

    public string $title;
    public mixed $message;
    // [S13-04] Lien de désinscription propre au destinataire (null = aperçu / ancien appel).
    public ?string $lienDesinscription;

    public function __construct($title, $message, ?string $lienDesinscription = null)
    {
        $this->title = $title;
        $this->message = $message;
        $this->lienDesinscription = $lienDesinscription;
    }

    public function build()
    {
        // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] NC-06: the real
        // Subject header must carry what the admin actually typed — it was
        // hardcoded to a generic English string (ADR-007 FR-lock violation)
        // while the entered title only ever appeared as body text.
        $mail = $this->subject($this->title)->markdown('emails.subscriber');
        // [S14-04] En-têtes standard de désinscription (RFC 2369 + RFC 8058 « un clic ») : les messageries affichent leur
        // propre bouton « Se désinscrire », qui appelle le même lien signé en POST.
        if ($this->lienDesinscription) {
            $lien = $this->lienDesinscription;
            $mail->withSymfonyMessage(function ($message) use ($lien) {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $lien . '>');
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        }

        return $mail;
    }
}
