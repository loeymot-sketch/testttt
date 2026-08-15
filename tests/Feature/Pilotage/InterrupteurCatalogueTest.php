<?php

namespace Tests\Feature\Pilotage;

use App\Models\User;
use App\Services\Pilotage\InterrupteurService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [T-5.1 CATALOGUE-ETROIT 2026-08-15 · GOAL_CONFORT_MAX] Élargissement du
 * catalogue InterrupteurService — audit initial (`InterrupteurTest.php`)
 * ne couvrait que les 2 bascules d'origine (split_payment, wheel).
 *
 * 4 ajouts, tous VRAIMENT booléens (déjà des `config('...')` lus directement
 * dans le code métier — même famille que les 2 d'origine) :
 *   remise_manuelle, fidelite, kiosk_promo, impression_ticket_client_auto.
 *
 * Explicitement PAS ajoutés (valeurs numériques/texte/horaires, pas des
 * bascules on/off — forcer un seuil ou une mention légale dans un système
 * booléen aurait été une fausse case à cocher) : tolérance d'écart caisse
 * (config/cash.php:31), barème frais livraison, mention légale ticket
 * (SIRET/TVA), seuil alerte stock bas, heures de service (config/kds.php).
 * Et bien sûr, toujours PAS `idempotency.enabled` (garde NF525 — verrouillé
 * par InterrupteurTest::test_l_idempotence_n_est_PAS_devenue_basculable).
 */
class InterrupteurCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    public static function nouvellesBasculesProvider(): array
    {
        return [
            'remise_manuelle' => ['remise_manuelle', 'pos.manual_discount_enabled'],
            'fidelite' => ['fidelite', 'pos.loyalty_enabled'],
            'kiosk_promo' => ['kiosk_promo', 'kiosk.promo_enabled'],
            'impression_ticket_client_auto' => ['impression_ticket_client_auto', 'printing.auto_print_client_receipt'],
        ];
    }

    /** @dataProvider nouvellesBasculesProvider */
    public function test_la_bascule_existe_avec_la_bonne_cle(string $nom, string $cleAttendue): void
    {
        $this->assertArrayHasKey($nom, InterrupteurService::CATALOGUE);
        $this->assertSame($cleAttendue, InterrupteurService::CATALOGUE[$nom]['cle']);
        $this->assertNotEmpty(InterrupteurService::CATALOGUE[$nom]['libelle']);
        $this->assertNotEmpty(InterrupteurService::CATALOGUE[$nom]['consequence']);
    }

    /** @dataProvider nouvellesBasculesProvider */
    public function test_basculer_change_reellement_la_valeur_lue_par_le_code_metier(string $nom, string $cle): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/observability/interrupteurs/{$nom}", ['actif' => true])
            ->assertOk();
        $this->assertTrue((bool) Config::get($cle), "{$cle} doit refléter actif=true après bascule");

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/observability/interrupteurs/{$nom}", ['actif' => false])
            ->assertOk();
        $this->assertFalse((bool) Config::get($cle), "{$cle} doit refléter actif=false après bascule");
    }

    public function test_l_ecran_liste_desormais_6_bascules_toutes_documentees(): void
    {
        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/interrupteurs')->assertOk()->json('data');

        $this->assertCount(6, $r, '2 historiques + 4 nouvelles');
        $noms = array_column($r, 'nom');
        foreach (['split_payment', 'wheel', 'remise_manuelle', 'fidelite', 'kiosk_promo', 'impression_ticket_client_auto'] as $attendu) {
            $this->assertContains($attendu, $noms);
        }
    }

    public function test_les_reglages_numeriques_texte_et_horaires_restent_HORS_catalogue(): void
    {
        // Preuve négative : ces clés ne doivent PAS exister dans le catalogue —
        // ce ne sont pas des bascules on/off, les y forcer serait une fausse
        // case à cocher (cf. docblock CATALOGUE).
        $clesExclues = [
            'cash.variance_threshold_eur',
            'kds.scheduled_window_open',
            'kds.scheduled_window_close',
        ];
        $toutesLesCles = array_column(InterrupteurService::CATALOGUE, 'cle');
        foreach ($clesExclues as $exclue) {
            $this->assertNotContains($exclue, $toutesLesCles, "{$exclue} n'est pas une bascule booléenne — ne doit jamais entrer dans ce catalogue tel quel");
        }
    }

    public function test_l_idempotence_et_le_fiscal_restent_absents_apres_l_elargissement(): void
    {
        // Non-régression du garde-fou existant (InterrupteurTest) après ajout
        // de 4 entrées — personne n'a élargi la liste blanche au fiscal par erreur.
        foreach (InterrupteurService::CATALOGUE as $def) {
            $this->assertStringNotContainsString('idempotency', $def['cle']);
            $this->assertStringNotContainsString('fiscal', $def['cle']);
        }
    }
}
