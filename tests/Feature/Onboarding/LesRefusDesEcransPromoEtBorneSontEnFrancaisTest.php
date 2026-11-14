<?php

namespace Tests\Feature\Onboarding;

use Tests\TestCase;

/**
 * [ONB-09/10 2026-08-28] Les refus des écrans promo et bornes sont en français.
 *
 * ═══ LE DÉFAUT ═══
 *
 * `CouponRequest` poussait **huit** chaînes en anglais brut, affichées telles
 * quelles par `CouponCreateComponent` (`errors.discount[0]`,
 * `errors.minimum_order[0]`, `errors.end_date[0]`) :
 *
 *     « Percentage amount can't be greater than 100. »
 *     « End date can't be older than Start date. »
 *
 * `KioskMachineRequest` en poussait **trois** de plus sur l'écran des bornes.
 *
 * ═══ POURQUOI LE MÉCANISME i18n NE LES ATTRAPE PAS ═══
 *
 * Ces chaînes sont écrites **en dur** dans les règles : elles ne passent par aucune
 * clé de traduction. Le produit étant FR-locked en administration (ADR-007), il
 * n'existe aucun chemin par lequel elles pourraient devenir françaises — il faut
 * les écrire en français.
 *
 * C'est pour ça qu'un balayage de clés manquantes, aussi complet soit-il, ne
 * pouvait pas les voir : **il n'y avait pas de clé**.
 */
class LesRefusDesEcransPromoEtBorneSontEnFrancaisTest extends TestCase
{
    /**
     * Tournures anglaises qui trahissent un message non traduit.
     *
     * Choisies pour ne pas produire de faux positif sur du français : « can't »,
     * « field is required » et « must be » n'apparaissent dans aucune phrase
     * française légitime.
     */
    private const TOURNURES_ANGLAISES = [
        "can't",
        'cannot',
        'field is required',
        'is required when',
        'must be greater',
        'must be less',
    ];

    private const REGLES_SURVEILLEES = [
        'app/Http/Requests/CouponRequest.php',
        'app/Http/Requests/KioskMachineRequest.php',
    ];

    public function test_aucun_refus_ecrit_en_dur_n_est_en_anglais(): void
    {
        foreach (self::REGLES_SURVEILLEES as $chemin) {
            $source = file_get_contents(base_path($chemin));

            // On ne regarde que les chaînes POUSSÉES à l'écran : `errors()->add(…)`
            // et le tableau `messages()`. Les commentaires du fichier peuvent
            // parfaitement citer l'ancien texte anglais — c'est même souhaitable,
            // puisqu'ils expliquent ce qui a été corrigé.
            $sansCommentaires = preg_replace('#/\*[\s\S]*?\*/#', '', $source);
            $sansCommentaires = preg_replace('#^\s*//.*$#m', '', $sansCommentaires);

            $trouvees = [];
            foreach (self::TOURNURES_ANGLAISES as $tournure) {
                if (stripos($sansCommentaires, $tournure) !== false) {
                    $trouvees[] = $tournure;
                }
            }

            $this->assertSame(
                [],
                $trouvees,
                "`{$chemin}` pousse un refus en ANGLAIS vers l'écran : "
                . implode(', ', $trouvees) . "\n\n"
                . "Ces chaînes sont écrites en dur, sans clé de traduction : aucun\n"
                . "mécanisme i18n ne peut les rattraper, et le produit est FR-locked\n"
                . 'en administration (ADR-007). Il faut les écrire en français.'
            );
        }
    }

    public function test_les_messages_francais_disent_ce_qu_il_faut_faire(): void
    {
        // Un refus traduit mais vague — « valeur invalide » — ne vaut guère mieux
        // qu'un refus en anglais. On vérifie que les deux messages les plus coûteux
        // NOMMENT la correction attendue.
        $coupon = file_get_contents(base_path('app/Http/Requests/CouponRequest.php'));

        $this->assertStringContainsString(
            '100 %',
            $coupon,
            "Le refus sur le pourcentage ne dit pas où est la limite."
        );

        $this->assertStringContainsString(
            'gratuite',
            $coupon,
            "Le refus sur le minimum de commande ne dit pas CE QUI arriverait :\n"
            . 'une commande offerte. Sans ça, le commerçant ne comprend pas la règle.'
        );
    }
}
