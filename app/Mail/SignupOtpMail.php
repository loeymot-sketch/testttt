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
        return $this->subject('Votre code Le Cayenne : '.$this->otpCode)
            ->html(
                '<p>Votre code Le Cayenne : <strong>'.e($this->otpCode).'</strong></p>'
                .'<p>Ce code est valable '.(int) $this->validityMinutes.' minutes.</p>'
                .'<p>Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.</p>'
            );
    }
}
