<?php

namespace Tests\Feature\Wheel;

use App\Enums\Ask;
use App\Models\Branch;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelDeliveryService;
use App\Services\Wheel\WheelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LES DEUX RÈGLES QUE LA REMISE AFFICHAIT SANS LES APPLIQUER.
 *
 * ── L'ÉCHÉANCE ───────────────────────────────────────────────────────────────────────────────
 * Le client la lit trois fois : sur l'écran de gain, dans son e-mail, et sur l'écran du comptoir.
 * Et un lot de six mois se remettait encore en un appui. Une échéance écrite trois fois et jamais
 * appliquée n'est pas une échéance : c'est une décoration, et c'est la maison qui paie l'écart.
 *
 * ── LE COMPTOIR ──────────────────────────────────────────────────────────────────────────────
 * L'identifiant du tour arrive d'un CHAMP CACHÉ du formulaire. Rien ne vérifiait que le lot
 * appartenait bien à la caisse qui le remet. En V1 LOCAL il n'y a qu'un comptoir, donc le risque est
 * théorique aujourd'hui — mais c'est précisément le genre de garde qu'on n'ajoute plus jamais après.
 */
class WheelDeliveryGuardsTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branchId = Branch::factory()->create()->id;

        Config::set('wheel.enabled', true);
        Config::set('wheel.campaign_key', 'test-gardes');
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.prize_validity_days', 30);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
        Config::set('wheel.segments', [
            ['key' => 'b', 'label' => 'Boisson offerte', 'type' => 'free_item', 'value' => 0,
             'weight' => 1, 'daily_cap' => 0],
        ]);
    }

    private function tour(int $branchId, string $tel, string $mail): WheelSpin
    {
        return app(WheelService::class)->spin(
            $branchId, $tel, 'Client', ['method' => 'staff'], null, null, $mail
        );
    }

    // ── L'ÉCHÉANCE ───────────────────────────────────────────────────────────────────────────

    public function test_un_lot_PERIME_n_est_plus_remis_et_le_refus_nomme_la_date(): void
    {
        $spin = $this->tour($this->branchId, '0611000701', 'perime@exemple.fr');

        $this->travel(31)->days();

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $this->assertFalse($r['ok'],
            'un lot de plus d\'un mois a été remis : l\'échéance annoncée trois fois au client ne '
            . 'sert à rien, et la maison paie l\'écart');
        $this->assertMatchesRegularExpression('/expir/iu', (string) $r['message']);
        $this->assertMatchesRegularExpression('/\d{2}\/\d{2}\/\d{4}/', (string) $r['message'],
            'le refus doit NOMMER la date : sinon l\'équipe a l\'air de subir une panne');
        $this->assertNull($spin->fresh()->delivered_at,
            'un lot périmé ne doit pas être marqué remis : il n\'a pas été donné');
    }

    /** La veille de l'échéance, le lot passe encore. Une garde trop stricte vole un client honnête. */
    public function test_la_veille_de_l_echeance_le_lot_passe_encore(): void
    {
        $spin = $this->tour($this->branchId, '0611000702', 'veille@exemple.fr');

        $this->travel(29)->days();

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertNotNull($spin->fresh()->delivered_at);
    }

    /** Le jour même de l'échéance compte jusqu'au soir : on ne coupe pas un client à midi. */
    public function test_le_jour_de_l_echeance_compte_jusqu_au_soir(): void
    {
        $spin = $this->tour($this->branchId, '0611000703', 'jourj@exemple.fr');

        $this->travel(30)->days();

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $this->assertTrue($r['ok'], $r['message']);
    }

    /** Zéro jour = pas d'échéance. Un réglage à zéro ne doit pas tout périmer instantanément. */
    public function test_une_echeance_a_zero_ne_perime_rien(): void
    {
        Config::set('wheel.prize_validity_days', 0);
        $spin = $this->tour($this->branchId, '0611000704', 'sansfin@exemple.fr');

        $this->travel(400)->days();

        $this->assertTrue(app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId)['ok']);
    }

    // ── LE COMPTOIR ──────────────────────────────────────────────────────────────────────────

    public function test_un_lot_gagne_ailleurs_n_est_pas_remis_ici(): void
    {
        $voisin = Branch::factory()->create();
        $spin = $this->tour($voisin->id, '0611000705', 'voisin@exemple.fr');

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $this->assertFalse($r['ok'],
            'une caisse remet le lot d\'un autre point de vente : le champ caché `spin_id` suffit');
        $this->assertMatchesRegularExpression('/autre point de vente/iu', (string) $r['message']);
        $this->assertNull($spin->fresh()->delivered_at);
    }

    /** Sans caisse précisée (appels internes, réconciliation), on ne bloque rien. */
    public function test_sans_caisse_precisee_la_remise_reste_possible(): void
    {
        $spin = $this->tour($this->branchId, '0611000706', 'interne@exemple.fr');

        $this->assertTrue(app(WheelDeliveryService::class)->deliver($spin->id, null, null)['ok']);
    }

    /** Et la garde de la caisse ne casse pas le crédit des points, qui vit sur un autre chemin. */
    public function test_la_garde_de_caisse_ne_casse_pas_le_credit_des_points(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'p', 'label' => '50 points', 'type' => 'points', 'value' => 50,
             'weight' => 1, 'daily_cap' => 0],
        ]);

        $spin = $this->tour($this->branchId, '0611000707', 'points-garde@exemple.fr');
        $u = User::factory()->create([
            'phone' => '0611000707', 'branch_id' => 0, 'is_guest' => Ask::YES, 'loyalty_points' => 5,
        ]);

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(55, (int) $u->fresh()->loyalty_points);
    }
}
