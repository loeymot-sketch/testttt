<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * [CAYENNE HYGIÈNE 2026-10-18] Récapitulatif des rappels d'hygiène « à traiter maintenant ». Envoi SYNCHRONE volontaire (pas de file) :
 * une alerte sanitaire ne doit dépendre d'aucun worker. Texte et HTML : ce qui est dit sur l'accueil, mot pour mot.
 */
class HygieneAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param list<array{key:string,domain:string,level:string,text:string,route:string}> $items */
    public function __construct(public array $items, public CarbonInterface $at, public bool $isTest = false) {}

    public function build(): self
    {
        $n = count($this->items);
        $hm = $this->at->format('H:i');
        $base = rtrim((string) config('app.url'), '/');
        $subject = $this->isTest ? 'Test — alertes Cayenne Hygiène' : "Cayenne Hygiène — {$n} à traiter ({$hm})";

        $rows = '';
        $lines = [];
        foreach ($this->items as $i) {
            $link = e($base.$i['route']);
            $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #eee;font-size:15px">'.e($i['text'])
                .'<br><a href="'.$link.'" style="font-size:13px;color:#D63D0B">Ouvrir l\'écran</a></td></tr>';
            $lines[] = '- '.$i['text'].' → '.$base.$i['route'];
        }
        $title = $this->isTest ? 'Message de test' : ($n > 1 ? "{$n} choses à traiter maintenant" : '1 chose à traiter maintenant');

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;color:#1A1A1A">'
            .'<div style="background:#1A1A1A;padding:18px;text-align:center;border-radius:12px 12px 0 0">'
            .'<span style="font-size:20px;font-weight:800;color:#fff">LE </span><span style="font-size:20px;font-weight:800;color:#F4501E">CAYENNE</span>'
            .'<span style="font-size:14px;color:#ccc"> · Hygiène</span></div>'
            .'<div style="border:1px solid #eee;border-top:0;padding:22px 20px;border-radius:0 0 12px 12px">'
            .'<p style="font-size:17px;font-weight:700;margin:0 0 12px">'.e($title).'</p>'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0">'.$rows.'</table>'
            .'<p style="font-size:12px;color:#777;margin:16px 0 0;line-height:1.5">Message automatique de Cayenne Hygiène, envoyé à '.e($hm).'. '
            .'Vous recevez ces alertes parce que le responsable les a activées ; il peut les arrêter ou changer les destinataires dans Hygiène › Alertes.</p>'
            .'</div></div>';

        $text = "LE CAYENNE · Hygiène\n\n{$title}\n\n".implode("\n", $lines)
            ."\n\nMessage automatique envoyé à {$hm}. Le responsable peut arrêter ces alertes dans Hygiène › Alertes.";

        return $this->subject($subject)->html($html)->text('emails.raw-text', ['content' => $text]);
    }
}
