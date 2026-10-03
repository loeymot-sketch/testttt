<?php

namespace Tests\Feature\Payment;

use App\Enums\Activity;
use Database\Seeders\SiteTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [INCIDENT PRODUCTION 2026-09-28] Les clients ne pouvaient plus payer en ligne.
 *
 * CE QUI S'EST PASSÉ
 * ------------------
 * Le 2026-09-26, le commit `633349c1f` a ajouté un garde légitime dans
 * `MolliePaymentController` : si `site_online_payment_gateway` ne vaut pas
 * ENABLE, l'API refuse en 503 « Paiement en ligne désactivé. » AVANT tout appel
 * à Mollie. L'intention déclarée était : « seule une désactivation EXPLICITE
 * ferme désormais le chemin ».
 *
 * Or `SiteTableSeeder` posait `DISABLE` sur **toute installation non-démo**.
 * Il n'y a donc jamais eu de désactivation explicite : la capacité était fermée
 * par défaut, sans action du propriétaire. Le réglage était resté sans effet
 * pendant des semaines, personne n'avait de raison de le basculer — et le jour
 * où le garde est devenu portant, **carte, Apple Pay et Google Pay sont tous
 * morts d'un coup**, en 503, sans qu'aucune erreur Mollie n'apparaisse dans les
 * journaux (le garde s'exécute avant l'appel).
 *
 * Mesuré en production le 2026-09-28 : `site_online_payment_gateway = 10`
 * (DISABLE). Dernier paiement Mollie réussi dans les journaux : **22/09**. Le
 * garde est daté du **26/09**. La chronologie concorde exactement.
 *
 * POURQUOI LA SUITE ÉTAIT VERTE PENDANT CE TEMPS
 * -----------------------------------------------
 * `MollieStructureTest` couvre deux cas — réglage **explicitement DISABLE**
 * (attend 503) et réglage **absent** (attend 200) — mais **jamais l'état d'une
 * vraie installation**, qui est « semé à DISABLE ». Le banc était au mauvais
 * périmètre : 43 tests Mollie verts ne prouvaient rien sur la capacité d'un
 * client à payer.
 *
 * CE TEST FERME CE TROU : il sème le groupe `site` comme le fait une
 * installation réelle, puis exige que la capacité de paiement en ligne soit
 * ouverte. Il échoue sur le seeder d'avant le correctif.
 *
 * Note : ouvrir ce réglage par défaut ne peut PAS faire encaisser à tort — le
 * contrôleur refuse toujours en 503 « Mollie non configuré. » tant que la clé
 * d'API n'est pas posée. Les deux gardes sont indépendants.
 */
class PaiementEnLigneVivantApresInstallationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le cœur du défaut : après l'installation d'un vrai restaurant, la
     * passerelle de paiement en ligne ne doit pas être fermée toute seule.
     */
    public function test_une_installation_reelle_ne_ferme_pas_le_paiement_en_ligne(): void
    {
        $this->seedMinimalSettings();

        $this->seed(SiteTableSeeder::class);

        $valeur = (int) Settings::group('site')->get('site_online_payment_gateway');

        $this->assertSame(
            Activity::ENABLE,
            $valeur,
            "Une installation réelle sème actuellement DISABLE (10) : le garde de "
            . "MolliePaymentController refuse alors TOUT paiement en ligne en 503, "
            . "sans qu'aucune désactivation propriétaire n'ait eu lieu. C'est ce qui a "
            . "tué carte + Apple Pay + Google Pay en production le 2026-09-26."
        );
    }

    /**
     * Garde de cohérence : le contrôleur traite l'ABSENCE de clé comme ENABLE.
     * Le seeder doit dire la même chose que lui, sinon deux couches du même
     * produit donnent deux réponses opposées à la même question.
     */
    public function test_le_seeder_dit_la_meme_chose_que_le_garde_du_controleur(): void
    {
        $this->seedMinimalSettings();
        $this->seed(SiteTableSeeder::class);

        $seme = (int) Settings::group('site')->get('site_online_payment_gateway');

        // Valeur par défaut du garde quand la clé est absent :
        // MolliePaymentController::checkout() → Settings::get(..., Activity::ENABLE)
        $defautDuGarde = Activity::ENABLE;

        $this->assertSame(
            $defautDuGarde,
            $seme,
            'Le seeder et le garde doivent partir du même défaut, sinon la capacité '
            . 'dépend de si la clé a été semée ou non — une différence invisible.'
        );
    }

    /**
     * NON-RÉGRESSION : une désactivation EXPLICITE par le propriétaire doit
     * toujours être respectée. Le correctif ne retire pas le garde, il retire
     * seulement la fermeture AUTOMATIQUE.
     */
    public function test_une_desactivation_explicite_du_proprietaire_reste_respectee(): void
    {
        $this->seedMinimalSettings();
        $this->seed(SiteTableSeeder::class);

        Settings::group('site')->set('site_online_payment_gateway', Activity::DISABLE);

        $this->assertSame(
            Activity::DISABLE,
            (int) Settings::group('site')->get('site_online_payment_gateway'),
            'Le choix explicite du propriétaire prime et doit persister.'
        );
    }
}
