<?php

namespace Tests\Unit\Sentinels;

use PHPUnit\Framework\TestCase;

/**
 * [VOUVOIEMENT 2026-11-04 · décision propriétaire « Vouvoyer partout » (site + application)]
 *
 * Le site et l'application vouvoient désormais le client. Ces fichiers serveur fabriquent des messages que
 * le client LIT sur le site ou dans l'application (roue, paiement Apple Pay, fidélité, inscription et
 * connexion, profil, mise à jour de l'application, suppression de compte, recours « Si vous ne recevez
 * rien »). Un seul message tutoyé au milieu d'écrans vouvoyés se remarque : c'est ce que ce banc empêche.
 *
 * Il lit les CHAÎNES PHP (tokenizer : les commentaires, qui citent l'ancien texte, sont ignorés).
 * Hors champ, à dessein : la borne, la caisse (personnel) et le ticket promo imprimé (texte fixé par le
 * propriétaire).
 */
class VouvoiementMessagesClientSentinelTest extends TestCase
{
    private const FICHIERS = [
        'app/Http/Requests/GuestEmailLoginRequest.php',
        'app/Http/Controllers/Frontend/WheelController.php',
        'app/Http/Controllers/Frontend/MolliePaymentController.php',
        'app/Http/Controllers/Frontend/LoyaltyController.php',
        'app/Http/Controllers/Auth/GuestSignupController.php',
        'app/Services/Wheel/WheelStepService.php',
        'app/Services/Wheel/WheelService.php',
        'app/Services/ProfileService.php',
        'config/app_mobile.php',
        'lang/fr/all.php',
    ];

    /** 2ᵉ personne du singulier : pronoms, possessifs, « -toi », et les impératifs relevés dans ces fichiers. */
    private const TUTOIEMENT = '/\b(?:tu|ton|ta|tes|toi)\b|-toi\b|\bt[\'’](?=[aeiouyéh])|'
        . '\b(?:Réessaie|réessaie|Indique|indique|Montre|Laisse|abonne|Mets|Récupère|attends|pense|passe au|choisis|Choisis|demande en caisse)\b/u';

    public function test_aucun_message_client_de_ces_fichiers_ne_tutoie(): void
    {
        $racine = dirname(__DIR__, 3);
        $fautes = [];

        foreach (self::FICHIERS as $relatif) {
            $source = file_get_contents($racine . '/' . $relatif);
            $this->assertNotFalse($source, "fichier introuvable : {$relatif}");

            foreach (token_get_all($source) as $jeton) {
                if (! is_array($jeton) || ! in_array($jeton[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                if (preg_match(self::TUTOIEMENT, $jeton[1], $m)) {
                    $fautes[] = "{$relatif}:{$jeton[2]} « {$m[0]} » dans " . mb_substr($jeton[1], 0, 120);
                }
            }
        }

        $this->assertSame([], $fautes, "Message client encore tutoyé :\n" . implode("\n", $fautes));
    }

    public function test_le_banc_mord_sur_un_message_tutoye(): void
    {
        foreach (["'Réessaie dans un instant.'", "'Indique ton prénom.'", "'abonne-toi, et c\\'est à toi !'", '"Si tu ne reçois rien"'] as $ancien) {
            $this->assertSame(1, preg_match(self::TUTOIEMENT, $ancien), "le motif doit reconnaître {$ancien}");
        }
        foreach (["'Réessayez dans un instant.'", "'Indiquez votre prénom.'", "'abonnez-vous, et c\\'est à vous !'"] as $nouveau) {
            $this->assertSame(0, preg_match(self::TUTOIEMENT, $nouveau), "le motif ne doit pas reconnaître {$nouveau}");
        }
    }
}
