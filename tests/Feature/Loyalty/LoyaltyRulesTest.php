<?php

namespace Tests\Feature\Loyalty;

use App\Services\Loyalty\LoyaltyRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * LE BARÈME, UNE SEULE FOIS — le banc qui empêche le prochain jumeau.
 *
 * Ce que ce banc verrouille, ce n'est pas « le calcul est juste » (il est trivial), c'est que
 * TOUTES les surfaces lisent la même règle. Le 10 août, la caisse ignorait le plancher que le site
 * et la borne respectaient : trois définitions concordantes et une quatrième oubliée.
 */
class LoyaltyRulesTest extends TestCase
{
    use RefreshDatabase;

    private LoyaltyRules $regles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->regles = app(LoyaltyRules::class);
    }

    private function reglage(string $cle, $valeur): void
    {
        Settings::group('loyalty_setup')->set([$cle => $valeur]);
    }

    private function oublier(string $cle): void
    {
        Settings::group('loyalty_setup')->forget($cle);
        DB::table('settings')->where('group', 'loyalty_setup')->where('key', $cle)->delete();
    }

    // ── LES DÉFAUTS DU LOGICIEL ──────────────────────────────────────────────────────────────

    /**
     * Sans aucun réglage — installation neuve — les défauts sont ceux que le reste du logiciel
     * applique déjà. Un défaut différent ici, et les surfaces divergeraient dès le premier jour.
     */
    public function test_sans_reglage_les_defauts_sont_ceux_du_reste_du_logiciel(): void
    {
        foreach (['loyalty_points_per_euro', 'loyalty_points_for_1_euro_discount', 'loyalty_min_redeem_points'] as $cle) {
            $this->oublier($cle);
        }

        $this->assertSame(10, $this->regles->pointsPerEuro(), 'LoyaltySetupResource:20 dit 10');
        $this->assertSame(100, $this->regles->rate(), 'PosRedemptionService dit 100');
        $this->assertSame(50, $this->regles->floorSetting(), 'LoyaltyController:402 dit 50');
    }

    /**
     * Un taux nul ou négatif ne doit JAMAIS traverser : il vaudrait une division par zéro, donc une
     * remise infinie sur une commande réelle.
     */
    public function test_un_taux_absurde_retombe_sur_le_defaut_au_lieu_de_diviser_par_zero(): void
    {
        foreach ([0, -100] as $absurde) {
            $this->reglage('loyalty_points_for_1_euro_discount', $absurde);
            $this->assertSame(100, $this->regles->rate(), "taux {$absurde} accepté");
            $this->assertSame(1.0, $this->regles->euroValue(100));
        }
    }

    // ── LE PLANCHER EFFECTIF ─────────────────────────────────────────────────────────────────

    /**
     * LE CŒUR. Le seuil annoncé doit être le seuil opposable : premier multiple du taux ≥ réglage.
     * Avec le réglage de production (50 / 100), on annonçait « dès 50 » et on refusait sous 100.
     */
    public function test_le_plancher_annonce_est_le_plancher_opposable(): void
    {
        $cas = [
            // [réglage, taux, plancher effectif attendu, pourquoi]
            [50,   100, 100,  'réglage de production : on refusait sous 100 en annonçant 50'],
            [1000, 100, 1000, 'la valeur que le propriétaire veut : déjà un multiple, inchangée'],
            [101,  100, 200,  'un point au-dessus du multiple fait basculer au suivant'],
            [0,    100, 100,  '« dès 0 point » n\'a aucun sens : rien n\'est débitable sous le taux'],
            [50,   10,  50,   'un taux plus fin rend le réglage atteignable tel quel'],
            [55,   10,  60,   'arrondi au multiple supérieur, jamais vers le bas'],
        ];

        foreach ($cas as [$reglage, $taux, $attendu, $pourquoi]) {
            $this->reglage('loyalty_min_redeem_points', $reglage);
            $this->reglage('loyalty_points_for_1_euro_discount', $taux);

            $this->assertSame($attendu, $this->regles->effectiveFloor(),
                "plancher {$reglage} au taux {$taux} : {$pourquoi}");
        }
    }

    // ── CE QUE LE COMPTOIR MONTRE AU CLIENT ──────────────────────────────────────────────────

    /**
     * Ce qui est utilisable est arrondi vers le BAS. Arrondir vers le haut offrirait des points que
     * le client n'a pas — et le débit échouerait après avoir annoncé la remise.
     */
    public function test_utilisable_arrondi_vers_le_bas_jamais_au_dela_du_solde(): void
    {
        $this->reglage('loyalty_min_redeem_points', 1000);
        $this->reglage('loyalty_points_for_1_euro_discount', 100);

        $this->assertSame(0,    $this->regles->usablePoints(999),  'sous le seuil : rien, pas un reste');
        $this->assertSame(1000, $this->regles->usablePoints(1000), 'pile sur le seuil : tout');
        $this->assertSame(1000, $this->regles->usablePoints(1099), 'le reste de 99 points n\'est pas offert');
        $this->assertSame(1500, $this->regles->usablePoints(1550));
        $this->assertSame(0,    $this->regles->usablePoints(0));
        $this->assertSame(0,    $this->regles->usablePoints(-50),  'un solde négatif ne donne rien');
    }

    /**
     * Ce qui manque doit être DIT. Un client qui sait qu'il lui reste 300 points à gagner revient ;
     * un client qui reçoit un refus sec s'en va.
     */
    public function test_ce_qui_manque_est_chiffre_pour_pouvoir_l_expliquer(): void
    {
        $this->reglage('loyalty_min_redeem_points', 1000);
        $this->reglage('loyalty_points_for_1_euro_discount', 100);

        $this->assertSame(1000, $this->regles->pointsMissingBeforeUse(0));
        $this->assertSame(300,  $this->regles->pointsMissingBeforeUse(700));
        $this->assertSame(0,    $this->regles->pointsMissingBeforeUse(1000), 'il y est : rien ne manque');
        $this->assertSame(0,    $this->regles->pointsMissingBeforeUse(5000));
    }

    /** La conversion en euros est celle du propriétaire : 1000 points = 10 €. */
    public function test_la_conversion_en_euros_est_celle_du_proprietaire(): void
    {
        $this->reglage('loyalty_points_for_1_euro_discount', 100);

        $this->assertSame(10.0, $this->regles->euroValue(1000), '« 1000 points, l\'équivalent de 10 € »');
        $this->assertSame(1.0,  $this->regles->euroValue(100));
        $this->assertSame(0.5,  $this->regles->euroValue(50), 'les centimes ne sont pas perdus au passage');
    }

    // ── L'ÉTAT QUE PERSONNE N'A PRÉVU ────────────────────────────────────────────────────────

    /**
     * Une ligne de réglage présente mais VIDE (migration, main sur la base) ne doit pas désactiver
     * la règle en silence : `(int) null` vaut 0, et un plancher à 0 c'est un plancher absent.
     */
    public function test_un_reglage_vide_en_base_ne_desactive_pas_la_regle(): void
    {
        foreach ([null, ''] as $vide) {
            Settings::group('loyalty_setup')->set(['loyalty_min_redeem_points' => $vide]);

            $this->assertSame(50, $this->regles->floorSetting(),
                'un réglage vide retombe sur le défaut du logiciel, pas sur 0');
            $this->assertSame(100, $this->regles->effectiveFloor());
        }
    }
}
