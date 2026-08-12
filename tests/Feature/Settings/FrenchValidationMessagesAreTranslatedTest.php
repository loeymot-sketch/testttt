<?php

namespace Tests\Feature\Settings;

use Tests\TestCase;

/**
 * [GOAL-OPS-SWAP W1 2026-08-12 — constat I18N-VALIDATION-ANGLAISE]
 *
 * `config/app.php:165` fixe `'locale' => 'fr'`. Laravel charge donc
 * `lang/fr/validation.php` pour TOUTE erreur de formulaire du produit —
 * connexion, caisse, borne, admin.
 *
 * Or ce fichier contenait de l'anglais : 77 des 83 clés étaient identiques mot
 * pour mot à `lang/en/validation.php` (92 %). Constaté à l'écran sur /login :
 * « The email must be a valid email address. »
 *
 * `CONSTITUTION.md §3.4` (ADR-007, immuable) : « Locale FR. Pas de message
 * anglais user-facing. »
 *
 * Cette sentinelle échoue si une clé redevient identique à sa version anglaise.
 * Elle compare les VALEURS réellement rendues, pas la présence du fichier :
 * un fichier français qui recopie l'anglais est précisément le défaut corrigé.
 */
class FrenchValidationMessagesAreTranslatedTest extends TestCase
{
    /**
     * Clés dont l'identité FR/EN est légitime.
     *
     * `custom.attribute-name.rule-name => custom-message` est le GABARIT livré
     * par Laravel pour montrer la convention « attribut.règle ». Ce n'est pas
     * un message affiché : c'est un exemple, identique dans toutes les langues.
     *
     * Attrapé par cette sentinelle elle-même lors de sa première exécution —
     * mon test était faux, pas le fichier de traduction. Toute addition ici
     * doit être justifiée de la même façon : prouver que la chaîne n'est
     * JAMAIS rendue à un utilisateur.
     */
    private const IDENTITE_TOLEREE = [
        'custom.attribute-name.rule-name',
    ];

    private function aplatir(array $arbre, string $prefixe = ''): array
    {
        $plat = [];
        foreach ($arbre as $cle => $valeur) {
            $chemin = $prefixe === '' ? (string) $cle : $prefixe.'.'.$cle;
            if (is_array($valeur)) {
                $plat += $this->aplatir($valeur, $chemin);
            } elseif (is_string($valeur)) {
                $plat[$chemin] = $valeur;
            }
        }

        return $plat;
    }

    public function test_aucun_message_de_validation_francais_n_est_resté_en_anglais(): void
    {
        $fr = $this->aplatir(require base_path('lang/fr/validation.php'));
        $en = $this->aplatir(require base_path('lang/en/validation.php'));

        $anglaises = [];
        foreach ($fr as $cle => $texteFr) {
            if (in_array($cle, self::IDENTITE_TOLEREE, true)) {
                continue;
            }
            if (!isset($en[$cle])) {
                continue;
            }
            // Une valeur courte (< 4 car.) ou un gabarit sans mot ne prouve rien.
            if (mb_strlen($texteFr) < 4) {
                continue;
            }
            if ($texteFr === $en[$cle]) {
                $anglaises[] = $cle.' → '.$texteFr;
            }
        }

        $this->assertSame(
            [],
            $anglaises,
            "CONSTITUTION.md §3.4 : aucun message user-facing ne doit être en anglais.\n"
            .count($anglaises)." clé(s) de lang/fr/validation.php sont identiques à l'anglais :\n  - "
            .implode("\n  - ", array_slice($anglaises, 0, 20))
        );
    }

    public function test_le_message_vu_a_l_ecran_de_connexion_est_en_francais(): void
    {
        // Le message exact capturé sur /login avant correction.
        $email = (string) trans('validation.email', [], 'fr');

        $this->assertStringNotContainsString('must be a valid email address', $email);
        $this->assertStringContainsString('e-mail', mb_strtolower($email));
    }

    public function test_les_ajouts_propres_au_projet_sont_preserves(): void
    {
        // Ces clés étaient déjà françaises AVANT le correctif : une réécriture
        // du fichier ne doit pas les avoir perdues.
        $this->assertSame('Maximum 50 articles par commande', trans('validation.items_cap_exceeded'));
        $this->assertStringContainsString('Sélectionnez au moins', trans('validation.multi_variation.min'));
        $this->assertStringContainsString('ne correspond pas', trans('validation.confirmed'));
    }
}
