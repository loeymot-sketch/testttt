<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * [WAVE C EMAIL-OTP 2026-07-28] Code de vérification signup web envoyé par
 * EMAIL (mandat owner : pas de SMS). Envoi SYNCHRONE volontaire (pas de
 * ShouldQueue) : l'auth ne doit dépendre d'aucun worker de queue.
 */
class SignupOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otpCode;
    public int $validityMinutes;

    public function __construct(string $otpCode, int $validityMinutes = 5)
    {
        $this->otpCode = $otpCode;
        $this->validityMinutes = $validityMinutes;
    }

    public function build(): self
    {
        $code = e($this->otpCode);
        $min = (int) $this->validityMinutes;

        // [HEAL DÉLIVRABILITÉ 2026-07-30] Email transactionnel brandé (avant : 3 <p> nus +
        // code dans le SUJET = signaux spam). Le code sort du sujet (sécurité + délivrabilité).
        // Identité complète du resto (adresse, mention transactionnelle) = légitimité anti-spam.
        // HTML simple inline-styled (tables) + partie TEXTE (multipart = moins spam).
        $html =
            '<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;color:#1A1A1A">'
            .'<div style="background:#1A1A1A;padding:20px;text-align:center;border-radius:12px 12px 0 0">'
            .'<span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff">LE </span>'
            .'<span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#F4501E">CAYENNE</span></div>'
            .'<div style="border:1px solid #eee;border-top:0;padding:28px 24px;border-radius:0 0 12px 12px">'
            .'<p style="font-size:15px;margin:0 0 8px">Bonjour,</p>'
            .'<p style="font-size:15px;margin:0 0 18px">Voici votre code de connexion pour Le Cayenne :</p>'
            .'<div style="text-align:center;margin:0 0 18px">'
            .'<span style="display:inline-block;font-size:34px;font-weight:800;letter-spacing:10px;'
            .'color:#F4501E;background:#FFF3EE;padding:14px 22px;border-radius:10px">'.$code.'</span></div>'
            .'<p style="font-size:13px;color:#555;margin:0 0 6px">Ce code est valable '.$min.' minutes.</p>'
            .'<p style="font-size:13px;color:#555;margin:0 0 18px">Si vous n\'êtes pas à l\'origine de cette demande, ignorez simplement cet email.</p>'
            .'<hr style="border:0;border-top:1px solid #eee;margin:18px 0">'
            .'<p style="font-size:11px;color:#999;margin:0;line-height:1.5">Le Cayenne — 437 rue Élie Gruyelle, 62110 Hénin-Beaumont'
            .'<br>Email transactionnel automatique · merci de ne pas y répondre.</p>'
            .'</div></div>';

        $text = "LE CAYENNE\n\nBonjour,\n\nVotre code de connexion : ".$this->otpCode
            ."\nValable ".$min." minutes.\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez cet email."
            ."\n\nLe Cayenne — 437 rue Élie Gruyelle, 62110 Hénin-Beaumont\nEmail transactionnel automatique.";

        return $this->subject('Votre code de connexion — Le Cayenne')
            ->html($html)
            ->text('emails.raw-text', ['content' => $text]);
    }
}
