<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\User;
use App\Services\Wheel\WheelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * L'HISTORIQUE — la lecture qui répond à « ce code-là, il a été validé ? ».
 *
 * [2026-08-13 · propriétaire : « toutes les fonctionnalités d'historique, de la gestion, de la
 * validation, de l'utilisation — par exemple quel code promo a été validé »]
 *
 * L'accueil de la roue donnait des AGRÉGATS. Ils servent à régler des plafonds et à rien d'autre :
 * devant un client qui affirme n'avoir jamais reçu son lot, un total ne tranche rien.
 *
 * ── CE QUE CE BANC PROTÈGE ───────────────────────────────────────────────────────────────────
 *   · les QUATRE ÉTATS, qui doivent être exclusifs et justes — c'est sur eux que l'équipe décide
 *     de tendre un produit ou de dire non ;
 *   · la CAISSE — l'historique d'une autre caisse n'a rien à faire ici ;
 *   · le NUMÉRO, qui ne doit JAMAIS sortir en entier d'un écran de comptoir que d'autres
 *     regardent par-dessus l'épaule.
 */
class WheelHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branche = Branch::factory()->create();
        Config::set('wheel.counter_branch_id', $this->branche->id);
        Config::set('wheel.prize_validity_days', 30);
    }

    private function tour(array $extra = []): int
    {
        return (int) DB::table('wheel_spins')->insertGetId(array_merge([
            'branch_id' => $this->branche->id,
            'phone' => '0612345678',
            'customer_name' => 'Dorian Martin',
            'prize_key' => 'boisson',
            'prize_label' => 'Boisson',
            'prize_type' => 'free_item',
            'prize_value' => 0,
            'campaign_key' => 'test',
            'unlock_method' => 'review',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    private function lignes(int $jours = 90): array
    {
        return app(WheelReportService::class)->historique($this->branche->id, $jours);
    }

    public function test_un_lot_non_remis_et_encore_valide_est_du(): void
    {
        $this->tour();
        $this->assertSame('du', $this->lignes()[0]['etat']);
    }

    public function test_un_lot_remis_le_dit_avec_qui_et_quand(): void
    {
        $caissier = User::factory()->create(['name' => 'Sarah', 'branch_id' => $this->branche->id]);
        $this->tour(['delivered_at' => now(), 'delivered_by_user_id' => $caissier->id]);

        $l = $this->lignes()[0];

        $this->assertSame('remis', $l['etat']);
        $this->assertNotNull($l['remis_le']);
        $this->assertSame('Sarah', $l['remis_par'],
            "l'écran doit nommer qui a remis : c'est la moitié de l'intérêt d'un historique");
    }

    /**
     * L'EXPIRATION EST UNE RÉPONSE, pas un oubli. Sans cet état, l'équipe lirait « à remettre »
     * pour un lot hors délai et le tendrait — ou refuserait sans savoir pourquoi.
     */
    public function test_un_lot_jamais_remis_hors_delai_est_expire(): void
    {
        $this->tour(['created_at' => now()->subDays(45), 'updated_at' => now()->subDays(45)]);
        $this->assertSame('expire', $this->lignes()[0]['etat']);
    }

    /** Un lot en remise n'a rien à tendre : le code fait le travail sur le site. */
    public function test_un_lot_en_remise_est_marque_comme_un_code(): void
    {
        $this->tour(['prize_type' => 'coupon_percent', 'prize_label' => '-10%']);
        $this->assertSame('code', $this->lignes()[0]['etat']);
    }

    /** Les quatre derniers chiffres, et RIEN de plus. */
    public function test_le_numero_ne_sort_jamais_en_entier(): void
    {
        $this->tour(['phone' => '0612345678']);
        $l = $this->lignes()[0];

        $this->assertSame('5678', $l['tel_fin']);
        $this->assertStringNotContainsString('0612345678', json_encode($l),
            'le numéro complet sort de l\'historique : il est lisible par-dessus l\'épaule');
    }

    /** Le prénom SEUL — « Dorian Martin » affiché en salle est une donnée exposée sans raison. */
    public function test_seul_le_prenom_est_rendu(): void
    {
        $this->tour(['customer_name' => 'Dorian Martin']);
        $l = $this->lignes()[0];

        $this->assertSame('Dorian', $l['prenom']);
        $this->assertStringNotContainsString('Martin', json_encode($l));
    }

    /** L'historique d'une autre caisse n'a rien à faire ici. */
    public function test_l_historique_est_borne_a_la_caisse(): void
    {
        $autre = Branch::factory()->create();
        $this->tour(['branch_id' => $autre->id, 'prize_label' => 'Ailleurs']);

        $this->assertCount(0, $this->lignes(),
            "un tour d'une autre caisse apparaît dans cet historique");
    }

    /**
     * LE DÉFAUT DU 10 AOÛT DOIT RESTER VISIBLE LIGNE PAR LIGNE. « Cadeau remis, stock inchangé »
     * ne se voit dans aucun total — il ne se voit que sur SA ligne.
     */
    public function test_un_cadeau_remis_sans_mouvement_de_stock_est_signale(): void
    {
        $this->tour(['delivered_at' => now(), 'cost_outflow_id' => null]);
        $this->assertFalse($this->lignes()[0]['stock_bouge']);
    }

    /** La période n'est pas librement saisissable : un écran de comptoir ne sort pas toute la table. */
    public function test_une_periode_hors_liste_retombe_sur_sept_jours(): void
    {
        Config::set('wheel.access.pin', '481526');
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $nav = $this->withHeaders(['Accept' => 'text/html,application/xhtml+xml']);
        $nav->post('/admin/roue/ouvrir', ['pin' => '481526']);

        $nav->get('/admin/roue-historique?jours=100000')
            ->assertOk()
            ->assertSee('aria-current="page"', false);
    }
}
