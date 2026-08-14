<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * [FIDÉLITÉ COMPTOIR 2026-08-14 · propriétaire] « Le client va recevoir le mail et enregistrer
 * ses données » — envoyé quand un compte est créé AU COMPTOIR avec un e-mail. Confirme au client
 * que son compte existe et lui donne son code fidélité, pour qu'il puisse le retrouver sans
 * revenir au comptoir.
 *
 * Envoi SYNCHRONE (pas de ShouldQueue), même choix que `SignupOtpMail` : la création d'un compte
 * ne doit dépendre d'aucun worker de queue, et l'appelant (`CustomerAccountProvisioner`) encapsule
 * déjà l'envoi dans un `try/catch` — un échec d'e-mail ne doit jamais faire tomber une vente.
 */
class CustomerWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $name;
    public string $loyaltyCode;

    public function __construct(string $name, string $loyaltyCode)
    {
        $this->name = $name;
        $this->loyaltyCode = $loyaltyCode;
    }

    public function build(): self
    {
        $nom = e($this->name);
        $code = e($this->loyaltyCode);

        $html =
            '<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;color:#1A1A1A">'
            .'<div style="background:#1A1A1A;padding:20px;text-align:center;border-radius:12px 12px 0 0">'
            .'<span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff">LE </span>'
            .'<span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#F4501E">CAYENNE</span></div>'
            .'<div style="border:1px solid #eee;border-top:0;padding:28px 24px;border-radius:0 0 12px 12px">'
            .'<p style="font-size:15px;margin:0 0 8px">Bonjour '.$nom.',</p>'
            .'<p style="font-size:15px;margin:0 0 18px">Votre compte fidélité Le Cayenne est créé. '
            .'Présentez ce code au comptoir pour cumuler ou utiliser vos points :</p>'
            .'<div style="text-align:center;margin:0 0 18px">'
            .'<span style="display:inline-block;font-size:28px;font-weight:800;letter-spacing:6px;'
            .'color:#F4501E;background:#FFF3EE;padding:14px 22px;border-radius:10px">'.$code.'</span></div>'
            .'<p style="font-size:13px;color:#555;margin:0 0 18px">Conservez cet e-mail — votre code y reste accessible à tout moment.</p>'
            .'<hr style="border:0;border-top:1px solid #eee;margin:18px 0">'
            .'<p style="font-size:11px;color:#999;margin:0;line-height:1.5">Le Cayenne — 437 rue Élie Gruyelle, 62110 Hénin-Beaumont'
            .'<br>Email transactionnel automatique · merci de ne pas y répondre.</p>'
            .'</div></div>';

        $text = "LE CAYENNE\n\nBonjour {$this->name},\n\nVotre compte fidélité est créé.\n"
            ."Code fidélité : {$this->loyaltyCode}\n\nPrésentez-le au comptoir pour cumuler ou utiliser vos points."
            ."\n\nLe Cayenne — 437 rue Élie Gruyelle, 62110 Hénin-Beaumont\nEmail transactionnel automatique.";

        return $this->subject('Votre compte fidélité — Le Cayenne')
            ->html($html)
            ->text('emails.raw-text', ['content' => $text]);
    }
}
