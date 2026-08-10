<?php

namespace App\Mail;

use App\Models\WheelSpin;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * LE LOT, ÉCRIT NOIR SUR BLANC.
 *
 * ── POURQUOI CET E-MAIL EXISTE ───────────────────────────────────────────────────────────────
 * La page dit au client « dis-nous où l'envoyer et il s'affiche », et lui demande son adresse pour
 * ça. Sans envoi réel, cette phrase est un mensonge — le genre qui se découvre le lendemain, quand
 * le client cherche son code dans sa boîte et ne le trouve pas. Une promesse que le système ne
 * tient pas coûte plus cher que pas de promesse du tout.
 *
 * C'est aussi la seule trace que le client CONSERVE. Le code affiché à l'écran disparaît dès qu'il
 * ferme l'onglet ; un lot qu'on ne retrouve pas est un lot mort, et un client déçu.
 *
 * ── LES CONDITIONS SONT DEDANS ───────────────────────────────────────────────────────────────
 * Minimum d'achat, date limite, façon de l'utiliser. Écrites ici parce qu'une condition découverte
 * en caisse fait un client qui se sent piégé — et il a raison. Elles sont dites trois fois : avant
 * le tour, à l'écran de gain, et ici.
 *
 * ── ENVOI SYNCHRONE, VOLONTAIREMENT ──────────────────────────────────────────────────────────
 * Pas de `ShouldQueue` : le client vient de donner son adresse et regarde son écran. Un e-mail qui
 * part dans une file dépend d'un worker vivant ; l'e-mail du lot, lui, doit partir maintenant. Et
 * son échec ne doit JAMAIS faire échouer la réclamation (voir l'appelant).
 */
class WheelPrizeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly WheelSpin $spin,
        private readonly ?string $code,
        private readonly float $minOrder,
        private readonly ?string $validUntil,
        private readonly bool $compteCree,
    ) {}

    public function build(): self
    {
        $lot = e((string) $this->spin->prize_label);
        $type = (string) $this->spin->prize_type;
        $prenom = trim((string) $this->spin->customer_name);
        $bonjour = $prenom !== '' ? 'Bonjour ' . e($prenom) . ',' : 'Bonjour,';

        // Chaque type de lot dit ce qui est VRAI POUR LUI. Un message unique était faux pour deux
        // d'entre eux : un produit offert n'a pas de code, des points ne se saisissent pas.
        if ($type === 'free_item') {
            $commentFaire = 'Donnez votre numéro de téléphone au comptoir lors de votre prochaine '
                . 'commande : on vous le remet en main propre.';
        } elseif ($type === 'points') {
            $pts = (int) ($this->spin->points_awarded ?? 0);
            // « ONT ÉTÉ AJOUTÉS » ÉTAIT FAUX : les points arrivent sur le compte quand l'équipe
            // REMET le lot au comptoir (WheelDeliveryService), pas à la réclamation. Un e-mail qui
            // annonce un solde déjà crédité envoie le client vérifier son compte et y trouver zéro
            // — et cet e-mail-là, il le GARDE, donc le mensonge dure.
            $commentFaire = $pts > 0
                ? 'Donnez votre numéro de téléphone au comptoir lors de votre prochaine commande : '
                    . 'nous ajouterons vos ' . $pts . ' points sur votre compte.'
                : 'Donnez votre numéro au comptoir pour récupérer vos points.';
        } else {
            $commentFaire = 'Saisissez ce code dans votre panier sur le site, à votre prochaine commande.';
        }

        $conditions = [];
        if ($this->minOrder > 0) {
            $conditions[] = 'Valable dès ' . number_format($this->minOrder, 2, ',', ' ') . ' € de commande.';
        }
        if ($this->validUntil) {
            $conditions[] = 'À utiliser avant le ' . e($this->validUntil) . '.';
        }
        $conditions[] = 'Un seul tour par personne.';

        $blocCode = '';
        if ($this->code) {
            $blocCode = '<div style="text-align:center;margin:0 0 18px">'
                . '<span style="display:inline-block;font-family:monospace;font-size:26px;font-weight:800;'
                . 'letter-spacing:4px;color:#F4501E;background:#FFF3EE;padding:14px 22px;border-radius:10px;'
                . 'border:1px dashed #F4501E">' . e($this->code) . '</span></div>';
        }

        $blocCompte = '';
        if ($this->compteCree) {
            $blocCompte = '<div style="background:#F3FBF5;border:1px solid #CDEBD6;border-radius:10px;'
                . 'padding:14px 16px;margin:0 0 18px">'
                . '<p style="font-size:13px;color:#20603A;margin:0;line-height:1.55">'
                . '<b>Votre compte Le Cayenne est créé.</b><br>Pour vous connecter : votre numéro de '
                . 'téléphone, puis le code que nous vous enverrons ici. Aucun mot de passe à retenir.'
                . '</p></div>';
        }

        $html =
            '<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;color:#1A1A1A">'
            . '<div style="background:#1A1A1A;padding:20px;text-align:center;border-radius:12px 12px 0 0">'
            . '<span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#fff">LE </span>'
            . '<span style="font-size:22px;font-weight:800;letter-spacing:1px;color:#F4501E">CAYENNE</span></div>'
            . '<div style="border:1px solid #eee;border-top:0;padding:28px 24px;border-radius:0 0 12px 12px">'
            . '<p style="font-size:15px;margin:0 0 8px">' . $bonjour . '</p>'
            . '<p style="font-size:15px;margin:0 0 16px">Vous avez gagné à la roue :</p>'
            . '<div style="text-align:center;margin:0 0 18px">'
            . '<span style="display:inline-block;font-size:30px;font-weight:800;color:#1A1A1A">' . $lot . '</span></div>'
            . $blocCode
            . '<p style="font-size:14px;margin:0 0 16px;line-height:1.55">' . e($commentFaire) . '</p>'
            . $blocCompte
            . '<p style="font-size:13px;color:#555;margin:0 0 18px;line-height:1.6">'
            . implode('<br>', array_map('e', $conditions)) . '</p>'
            . '<hr style="border:0;border-top:1px solid #eee;margin:18px 0">'
            . '<p style="font-size:11px;color:#999;margin:0;line-height:1.5">Le Cayenne — 437 rue Élie Gruyelle, 62110 Hénin-Beaumont'
            . '<br>Email transactionnel automatique · merci de ne pas y répondre.</p>'
            . '</div></div>';

        $texte = "LE CAYENNE\n\n" . ($prenom !== '' ? 'Bonjour ' . $prenom . ',' : 'Bonjour,')
            . "\n\nVous avez gagné à la roue : " . $this->spin->prize_label . "\n"
            . ($this->code ? "\nVotre code : " . $this->code . "\n" : '')
            . "\n" . $commentFaire . "\n"
            . ($this->compteCree
                ? "\nVotre compte Le Cayenne est créé. Pour vous connecter : votre numéro de téléphone, "
                    . "puis le code que nous vous enverrons ici.\n"
                : '')
            . "\n" . implode("\n", $conditions)
            . "\n\nLe Cayenne — 437 rue Élie Gruyelle, 62110 Hénin-Beaumont\nEmail transactionnel automatique.";

        // Le lot ne va PAS dans le sujet : un sujet qui annonce un gain est le premier signal de
        // filtrage anti-spam, et l'e-mail arriverait là où personne ne le lit.
        return $this->subject('Votre lot Le Cayenne')
            ->html($html)
            ->text('emails.raw-text', ['content' => $texte]);
    }
}
