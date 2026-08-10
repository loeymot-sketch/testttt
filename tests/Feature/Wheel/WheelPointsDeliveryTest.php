<?php

namespace Tests\Feature\Wheel;

use App\Enums\Ask;
use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelDeliveryService;
use App\Services\Wheel\WheelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LA REMISE D'UN LOT EN POINTS — et le P0 qu'elle cachait.
 *
 * ── CE QUI SE PASSAIT ────────────────────────────────────────────────────────────────────────
 * `delivered_at` était posé quel que soit le résultat du crédit. Quand aucun compte ne portait le
 * numéro du gagnant, l'écran du comptoir affichait un bandeau VERT : « points en attente : dis-lui
 * de créer son compte avec CE numéro, les points y seront ajoutés » — et, soixante pixels plus bas,
 * « remis le 10/08/2026 ».
 *
 * La promesse était donc impossible à tenir : le client revenait avec son compte créé, l'équipe
 * cherchait son numéro, et lisait « rien à remettre : ses lots sont déjà remis ». Les points
 * mouraient là, sans trace, avec l'air d'avoir été donnés.
 *
 * ── LA RÈGLE ─────────────────────────────────────────────────────────────────────────────────
 * Un lot n'est marqué REMIS que si quelque chose a réellement été remis. Rien d'autre ne peut le
 * marquer — ni une bonne intention, ni un message d'attente.
 */
class WheelPointsDeliveryTest extends TestCase
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
        // [ROUE × CAISSE 2026-08-10] La roue ne tire plus de lot en REMISE quand la caisse
        // refuse les codes — c'est une garde neuve, et elle est juste. Ce banc parle d'autre
        // chose : on accepte donc les codes ici, pour qu'un interrupteur de la caisse ne
        // décide pas de ce qu'il éprouve.
        Config::set('pos.coupon_codes_enabled', true);
        Config::set('wheel.campaign_key', 'test-points');
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
        Config::set('wheel.segments', [
            ['key' => 'p', 'label' => '50 points', 'type' => 'points', 'value' => 50,
             'weight' => 1, 'daily_cap' => 0],
        ]);
    }

    private function tour(string $tel, string $mail): WheelSpin
    {
        return app(WheelService::class)->spin(
            $this->branchId, $tel, 'Client', ['method' => 'staff'], null, null, $mail
        );
    }

    /**
     * AUCUN COMPTE À CE NUMÉRO → le lot est CONSERVÉ, pas marqué remis.
     *
     * C'est le cœur du défaut. Le message d'attente et la marque de remise ne peuvent pas coexister.
     */
    public function test_sans_compte_les_points_sont_CONSERVES_et_le_lot_n_est_PAS_marque_remis(): void
    {
        $spin = $this->tour('0611000901', 'sanscompte@exemple.fr');

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null);

        $this->assertFalse($r['ok'], 'la remise a été acceptée alors que rien n\'a été remis');
        $this->assertFalse($r['points_credited']);
        $this->assertMatchesRegularExpression('/CONSERV/iu', (string) $r['message'],
            'le message doit dire que le lot est gardé — sinon l\'équipe croit l\'avoir donné');
        $this->assertMatchesRegularExpression('/compte/iu', (string) $r['message'],
            'le message doit dire QUOI FAIRE : créer un compte avec ce numéro');

        $this->assertNull($spin->fresh()->delivered_at,
            'LES POINTS SONT MORTS : le lot est marqué remis alors qu\'aucun point n\'a été crédité, '
            . 'donc toute nouvelle tentative répondra « déjà remis »');
    }

    /**
     * LA SUITE DE L'HISTOIRE, et la preuve que la promesse tient : le client crée son compte, revient,
     * et l'équipe peut enfin lui créditer ses points. C'est exactement ce que le message annonce.
     */
    public function test_le_client_revient_avec_son_compte_et_les_points_sont_ENFIN_credites(): void
    {
        $spin = $this->tour('0611000902', 'revient@exemple.fr');

        app(WheelDeliveryService::class)->deliver($spin->id, null);
        $this->assertNull($spin->fresh()->delivered_at);

        // Il crée son compte avec CE numéro, comme on lui a dit.
        $u = User::factory()->create([
            'phone' => '0611000902', 'branch_id' => 0, 'is_guest' => Ask::YES, 'loyalty_points' => 12,
        ]);

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null);

        $this->assertTrue($r['ok'], 'le lot conservé n\'a pas pu être remis au retour du client');
        $this->assertTrue($r['points_credited']);
        $this->assertSame(62, (int) $u->fresh()->loyalty_points);
        $this->assertNotNull($spin->fresh()->delivered_at, 'là, il est bien remis');
    }

    /** Et une fois vraiment remis, la double remise reste refusée en nommant la date. */
    public function test_une_fois_credites_la_double_remise_reste_refusee(): void
    {
        $spin = $this->tour('0611000903', 'double@exemple.fr');
        User::factory()->create([
            'phone' => '0611000903', 'branch_id' => 0, 'is_guest' => Ask::YES, 'loyalty_points' => 0,
        ]);

        app(WheelDeliveryService::class)->deliver($spin->id, null);
        $r = app(WheelDeliveryService::class)->deliver($spin->id, null);

        $this->assertFalse($r['ok']);
        $this->assertMatchesRegularExpression('/d[ée]j[àa] .*remis/iu', (string) $r['message']);
        $this->assertSame(50, (int) User::withoutGlobalScopes()
            ->where('phone', '0611000903')->value('loyalty_points'),
            'les points ont été crédités DEUX FOIS : la maison paie deux fois le même lot');
    }

    /** Le lot reste visible comme EN ATTENTE tant qu'il n'est pas remis — sinon l'équipe l'oublie. */
    public function test_le_lot_conserve_reste_visible_dans_les_lots_en_attente(): void
    {
        $spin = $this->tour('0611000904', 'attente@exemple.fr');
        app(WheelDeliveryService::class)->deliver($spin->id, null);

        $enAttente = app(WheelDeliveryService::class)->pending($this->branchId, '0611000904');

        $this->assertNotNull($enAttente,
            'le lot conservé a disparu des lots en attente : personne ne saura qu\'il est dû');
        $this->assertSame($spin->id, $enAttente->id);
        $this->assertSame(1, WheelSpin::withoutGlobalScope(BranchScope::class)
            ->whereNull('delivered_at')->count());
    }
}
