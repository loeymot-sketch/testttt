<?php

namespace Tests\Feature\Wheel;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Scopes\BranchScope;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockOutflow;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelAccountService;
use App\Services\Wheel\WheelDeliveryService;
use App\Services\Wheel\WheelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * BANC ADVERSAIRE TEMPORAIRE — VAGUE E ronde 2. À SUPPRIMER après le rapport.
 */
class AdvWaveEAttackTest extends TestCase
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
        Config::set('wheel.campaign_key', 'adv-e');
        Config::set('wheel.daily_total_cap', 5000);
        Config::set('wheel.record_cost_on_claim', true);
        Config::set('wheel.prize_validity_days', 30);
        Config::set('pos.coupon_codes_enabled', true);
    }

    private function spin(array $attrs = []): WheelSpin
    {
        $s = new WheelSpin();
        $s->forceFill(array_merge([
            'branch_id' => $this->branchId,
            'campaign_key' => 'adv-e',
            'phone' => '0612345678',
            'email' => 'adv' . uniqid('', true) . '@b.fr',
            'customer_name' => 'Client',
            'prize_key' => 'points_50',
            'prize_label' => '50 points',
            'prize_type' => 'points',
            'prize_value' => 50,
            'points_awarded' => 50,
            'unlock_method' => 'staff',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs))->save();

        return $s->refresh();
    }

    private function segmentsCadeau(string $nomOuId, bool $parId = false): void
    {
        Config::set('wheel.segments', [[
            'key' => 'boisson', 'label' => 'Boisson offerte', 'type' => 'free_item',
            'value' => 0, 'weight' => 1, 'daily_cap' => 0,
            'cost_item_id' => $parId ? (int) $nomOuId : 0,
            'cost_item_name' => $parId ? '' : $nomOuId,
        ]]);
    }

    // ═══════════════ A1 · DOUBLE DÉPENSE ═══════════════

    /** A1 — rejeu séquentiel de la même remise. */
    public function test_a1_double_remise_sequentielle_refusee(): void
    {
        $u = User::factory()->create(['phone' => '0612345678', 'loyalty_points' => 0, 'is_guest' => Ask::YES]);
        $s = $this->spin();
        $d = app(WheelDeliveryService::class);

        $r1 = $d->deliver($s->id, null, $this->branchId);
        $r2 = $d->deliver($s->id, null, $this->branchId);

        $this->assertTrue($r1['ok'], 'la 1re remise doit passer');
        $this->assertFalse($r2['ok'], 'la 2e remise DOIT être refusée');
        $this->assertSame(50, (int) $u->fresh()->loyalty_points, 'les points ne doivent être crédités QU\'UNE fois');
    }

    /** A1b — cadeau produit : rejeu → une seule sortie, un seul mouvement. */
    public function test_a1b_double_remise_cadeau_produit_refusee(): void
    {
        $item = Item::factory()->create(['name' => 'Boisson Seule', 'status' => Status::ACTIVE]);
        StockLevel::create(['branch_id' => $this->branchId, 'stockable_type' => Item::class,
            'stockable_id' => $item->id, 'on_hand' => 10]);
        $this->segmentsCadeau('Boisson Seule');

        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);
        $d = app(WheelDeliveryService::class);

        $this->assertTrue($d->deliver($s->id, null, $this->branchId)['ok']);
        $this->assertFalse($d->deliver($s->id, null, $this->branchId)['ok']);

        $this->assertSame(1, StockOutflow::withoutGlobalScope(BranchScope::class)
            ->where('type', StockOutflow::TYPE_PROMO_GIFT)->count(), 'une seule ligne de charge');
        $this->assertSame(1, StockMovement::withoutGlobalScope(BranchScope::class)
            ->where('idempotency_key', 'wheel-gift-' . $s->id)->count(), 'un seul mouvement');
        $this->assertSame(9, (int) StockLevel::where('stockable_id', $item->id)->first()->on_hand);
    }

    /** A1c — rejeu de la clé d'idempotence `wheel-gift-<id>` par le chemin de la caisse. */
    public function test_a1c_rejeu_cle_idempotence_wheel_gift_par_la_caisse(): void
    {
        $item = Item::factory()->create(['name' => 'Boisson Seule', 'status' => Status::ACTIVE]);
        StockLevel::create(['branch_id' => $this->branchId, 'stockable_type' => Item::class,
            'stockable_id' => $item->id, 'on_hand' => 10]);
        $this->segmentsCadeau('Boisson Seule');
        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);

        app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);
        $apres = (int) StockLevel::where('stockable_id', $item->id)->first()->on_hand;

        // La caisse rejoue LA MÊME clé : le stock ne doit pas rebouger.
        $ok = app(\App\Services\Stock\StockService::class)->recordManualOutflow(
            $item->id, $this->branchId, 1, 'manual_out', 1, 'wheel-gift-' . $s->id
        );

        $this->assertTrue($ok, 'le rejeu se déclare idempotent');
        $this->assertSame($apres, (int) StockLevel::where('stockable_id', $item->id)->first()->on_hand,
            'le rejeu ne doit PAS redécrémenter');
    }

    // ═══════════════ A2 · POINTS — QUI EST CRÉDITÉ ═══════════════

    /**
     * A2 — LE TÉLÉPHONE N'EST CHERCHÉ QUE SOUS SA FORME NORMALISÉE.
     * `WheelAccountService::variantes()` cherche 4 écritures ; `creditPoints()` une seule.
     */
    public function test_a2_points_jamais_credites_si_le_compte_porte_la_forme_plus33(): void
    {
        $u = User::factory()->create([
            'phone' => '+33612345678', 'loyalty_points' => 0, 'is_guest' => Ask::YES,
        ]);

        // Le service de COMPTE, lui, le trouve : preuve de l'asymétrie.
        $vu = app(WheelAccountService::class)->ensure('0612345678', 'a@b.fr', 'Client');
        $this->assertSame((int) $u->id, (int) $vu['user_id'], 'le service de compte TROUVE le compte');
        $this->assertSame('existing', $vu['reason']);

        $s = $this->spin(['phone' => '0612345678']);
        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $this->assertFalse($r['ok'], 'la remise échoue alors que le compte EXISTE');
        $this->assertStringContainsString('Aucun compte à ce numéro', $r['message']);
        $this->assertSame(0, (int) $u->fresh()->loyalty_points, 'les points ne seront JAMAIS crédités');
        // Et la boucle est infinie : le compte existe déjà, donc « crée ton compte » ne change rien.
        $this->assertNull($s->fresh()->delivered_at);
    }

    /** A2b — le compte SUPPRIMÉ est crédité, et le compte vivant du même numéro ne l'est pas. */
    public function test_a2b_points_credites_a_un_compte_supprime(): void
    {
        $mort = User::factory()->create(['phone' => '0612345678', 'loyalty_points' => 0, 'is_guest' => Ask::YES]);
        $mort->delete();
        $vivant = User::factory()->create(['phone' => '0612345678', 'loyalty_points' => 0, 'is_guest' => Ask::YES]);

        $this->assertTrue($mort->fresh()->trashed());

        $s = $this->spin();
        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $this->assertTrue($r['ok'], 'la remise se déclare RÉUSSIE');
        $this->assertStringContainsString('points crédités sur son compte', $r['message']);
        $this->assertSame(50, (int) User::withTrashed()->find($mort->id)->loyalty_points,
            'les points sont allés sur le compte SUPPRIMÉ');
        $this->assertSame(0, (int) $vivant->fresh()->loyalty_points,
            'le compte VIVANT du client n\'a rien reçu');
        $this->assertNotNull($s->fresh()->delivered_at, 'et le lot est définitivement clos');
    }

    /** A2c — le compte de l'ÉQUIPE est crédité, alors que le service de compte refuse d'y toucher. */
    public function test_a2c_points_credites_a_un_compte_de_l_equipe(): void
    {
        $staff = User::factory()->create(['phone' => '0612345678', 'loyalty_points' => 0,
            'is_guest' => Ask::NO, 'branch_id' => $this->branchId]);

        $vu = app(WheelAccountService::class)->ensure('0612345678', 'x@y.fr', 'X');
        $this->assertSame('staff_phone', $vu['reason'], 'le service de compte REFUSE de toucher l\'équipe');

        $s = $this->spin();
        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $this->assertTrue($r['ok']);
        $this->assertSame(50, (int) $staff->fresh()->loyalty_points,
            'la remise crédite quand même le compte de l\'équipe');
    }

    // ═══════════════ A3 · STOCK QUI DÉRIVE ═══════════════

    /**
     * A3 — LE PRODUIT DE RÉFÉRENCE N'A PAS DE StockLevel (cas RÉEL en base : « Boisson Seule »).
     * Le cadeau part, rien ne bouge, et le comptoir lit une raison FAUSSE.
     */
    public function test_a3_cadeau_sans_stocklevel_message_faux(): void
    {
        Item::factory()->create(['name' => 'Boisson Seule', 'status' => Status::ACTIVE]);
        $this->segmentsCadeau('Boisson Seule'); // aucun StockLevel créé — comme en base réelle

        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);
        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('produit sans stock direct', $r['message']);
        $this->assertSame(0, StockMovement::withoutGlobalScope(BranchScope::class)->count(),
            'aucun mouvement de stock');
        $this->assertFalse((bool) StockOutflow::withoutGlobalScope(BranchScope::class)->first()->stock_decremented);
    }

    /**
     * A3b — `on_hand = 0` : le cadeau part, le stock ne bouge pas, et le message accuse
     * « produit sans stock direct » alors que le produit EN A un, simplement vide.
     */
    public function test_a3b_on_hand_zero_message_accuse_le_mauvais_motif(): void
    {
        $item = Item::factory()->create(['name' => 'Boisson Seule', 'status' => Status::ACTIVE]);
        StockLevel::create(['branch_id' => $this->branchId, 'stockable_type' => Item::class,
            'stockable_id' => $item->id, 'on_hand' => 0]);
        $this->segmentsCadeau('Boisson Seule');

        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);
        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $this->assertTrue($r['ok'], 'la remise passe malgré le rayon vide');
        $this->assertStringContainsString('produit sans stock direct', $r['message'],
            'MESSAGE FAUX : le produit a un stock direct, il est à zéro');
        $this->assertSame(0, StockMovement::withoutGlobalScope(BranchScope::class)->count());
    }

    /**
     * A3c — RUPTURE POSÉE À LA CAISSE ENTRE LE TIRAGE ET LA REMISE.
     * Le tirage écarte les lots en rupture ; la remise ne regarde RIEN.
     */
    public function test_a3c_rupture_posee_apres_le_tirage_ne_bloque_pas_la_remise(): void
    {
        $item = Item::factory()->create(['name' => 'Boisson Seule', 'status' => Status::ACTIVE]);
        StockLevel::create(['branch_id' => $this->branchId, 'stockable_type' => Item::class,
            'stockable_id' => $item->id, 'on_hand' => 10]);
        $this->segmentsCadeau('Boisson Seule');

        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);

        // 86 MANUEL depuis la caisse, après le tirage.
        \App\Models\ItemBranchAvailability::withoutGlobalScope(BranchScope::class)->create([
            'item_id' => $item->id, 'branch_id' => $this->branchId, 'is_available' => 0,
            'daily_reset_at' => now(),
        ]);
        $this->assertFalse(app(\App\Services\Menu\AvailabilityService::class)
            ->isAvailable($item->id, $this->branchId));

        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $this->assertTrue($r['ok'], 'la remise passe sur un produit déclaré INDISPONIBLE à la caisse');
        $this->assertSame(9, (int) StockLevel::where('stockable_id', $item->id)->first()->on_hand,
            'et elle décrémente un produit que la caisse a retiré de la vente');
    }

    /**
     * A3d — PRODUIT SUPPRIMÉ (soft-deleted) encore accepté comme produit de coût.
     * Le chemin de la caisse exige un produit ACTIF non supprimé ; la roue non.
     */
    public function test_a3d_produit_supprime_accepte_comme_produit_de_cout(): void
    {
        $item = Item::factory()->create(['name' => 'Boisson Seule', 'status' => Status::ACTIVE]);
        StockLevel::create(['branch_id' => $this->branchId, 'stockable_type' => Item::class,
            'stockable_id' => $item->id, 'on_hand' => 10]);
        $item->delete();
        $this->assertTrue($item->fresh()->trashed());

        $this->segmentsCadeau('Boisson Seule');
        $this->assertSame((int) $item->id, app(WheelDeliveryService::class)->costItemId('boisson'),
            'costItemId() résout un produit RETIRÉ du catalogue');

        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);
        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $this->assertTrue($r['ok']);
        $this->assertSame(9, (int) StockLevel::where('stockable_id', $item->id)->first()->on_hand,
            'le stock d\'un produit supprimé est décrémenté');

        // Le chemin de référence de la caisse, lui, refuse (422).
        $pos = User::factory()->create(['branch_id' => $this->branchId]);
        $pos->assignRole(\App\Enums\Role::ADMIN);
        $this->actingAs($pos)
            ->postJson('/api/admin/pos/stock-outflow', ['item_id' => $item->id, 'quantity' => 1, 'type' => 'waste'])
            ->assertStatus(422);
    }

    /**
     * A3e — `cost_item_id` réglé sur un identifiant INEXISTANT : la charge est écrite sur un
     * produit fantôme, valorisée 0 €, et le comptoir lit « produit sans stock direct ».
     */
    public function test_a3e_cost_item_id_inexistant_charge_fantome(): void
    {
        $this->segmentsCadeau('999999', true);

        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);
        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $this->assertTrue($r['ok'], 'aucune garde : la remise passe');
        $o = StockOutflow::withoutGlobalScope(BranchScope::class)->first();
        $this->assertSame(999999, (int) $o->item_id, 'ligne de charge sur un produit qui n\'existe pas');

        $t = app(WheelReportService::class)->tableau($this->branchId);
        $this->assertSame(0.0, (float) $t['periodes']['aujourdhui']['valeur_offerte'],
            'valeur offerte = 0,00 € pour un cadeau réellement donné');
    }

    /** A3f — `cost_item_id` d'une AUTRE branche : charge écrite ici, stock décrémenté nulle part. */
    public function test_a3f_cost_item_d_une_autre_branche(): void
    {
        $autre = Branch::factory()->create();
        $item = Item::factory()->create(['name' => 'Boisson Seule', 'status' => Status::ACTIVE, 'price' => 1.90]);
        StockLevel::create(['branch_id' => $autre->id, 'stockable_type' => Item::class,
            'stockable_id' => $item->id, 'on_hand' => 10]);
        $this->segmentsCadeau((string) $item->id, true);

        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);
        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $this->assertTrue($r['ok']);
        $this->assertSame(10, (int) StockLevel::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $autre->id)->first()->on_hand, 'le stock de l\'autre branche est intact');
        $this->assertStringContainsString('produit sans stock direct', $r['message']);
    }

    /**
     * A3g — `record_cost_on_claim = false` : le cadeau part, AUCUN décrément, AUCUNE trace,
     * et le message ne dit RIEN. L'inventaire dérive en silence total.
     */
    public function test_a3g_interrupteur_de_charge_eteint_dérive_muette(): void
    {
        $item = Item::factory()->create(['name' => 'Boisson Seule', 'status' => Status::ACTIVE]);
        StockLevel::create(['branch_id' => $this->branchId, 'stockable_type' => Item::class,
            'stockable_id' => $item->id, 'on_hand' => 10]);
        $this->segmentsCadeau('Boisson Seule');
        Config::set('wheel.record_cost_on_claim', false);

        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);
        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $this->assertTrue($r['ok']);
        $this->assertSame('Remis : Boisson offerte. Bon service !', $r['message'],
            'AUCUN avertissement : le comptoir croit que l\'inventaire suit');
        $this->assertSame(10, (int) StockLevel::where('stockable_id', $item->id)->first()->on_hand,
            'stock INCHANGÉ');
        $this->assertSame(0, StockOutflow::withoutGlobalScope(BranchScope::class)->count(),
            'AUCUNE trace de sortie');
        $this->assertNotNull($s->fresh()->delivered_at, 'et le lot est bien marqué remis');

        $t = app(WheelReportService::class)->tableau($this->branchId);
        $this->assertSame(0, (int) $t['periodes']['aujourdhui']['cadeaux_remis']);
        $this->assertSame([], $t['avertissements'], 'et le tableau n\'avertit de RIEN');
    }

    // ═══════════════ A4 · L'ÉCHÉANCE ═══════════════

    /** A4 — un lot périmé est refusé. */
    public function test_a4_lot_perime_refuse(): void
    {
        $User = User::factory()->create(['phone' => '0612345678', 'loyalty_points' => 0]);
        $s = $this->spin(['created_at' => now()->subDays(31)]);
        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('expiré', $r['message']);
        $this->assertSame(0, (int) $User->fresh()->loyalty_points);
    }

    /** A4b — l'écran PROPOSE encore le bouton d'un lot périmé, et n'explique jamais l'échéance. */
    public function test_a4b_ecran_propose_un_bouton_qui_echoue_toujours(): void
    {
        $s = $this->spin(['created_at' => now()->subDays(400)]);
        $pending = app(WheelDeliveryService::class)->pending($this->branchId, '0612345678');

        $this->assertNotNull($pending, 'pending() rend un lot PÉRIMÉ');
        $this->assertSame((int) $s->id, (int) $pending->id);
        $this->assertFalse(app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId)['ok']);
    }

    /** A4c — `prize_validity_days` négatif ou nul DÉSACTIVE l'échéance en silence. */
    public function test_a4c_validite_negative_desactive_l_echeance(): void
    {
        User::factory()->create(['phone' => '0612345678', 'loyalty_points' => 0, 'is_guest' => Ask::YES]);
        Config::set('wheel.prize_validity_days', -1);

        $s = $this->spin(['created_at' => now()->subYears(3)]);
        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $this->assertTrue($r['ok'], 'un lot de TROIS ANS est remis');
    }

    /** A4d — les lots périmés restent comptés « dus » à jamais dans le tableau. */
    public function test_a4d_lots_dus_compte_les_perimes_a_jamais(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->spin(['phone' => '06000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'created_at' => now()->subDays(200 + $i)]);
        }

        $t = app(WheelReportService::class)->tableau($this->branchId);
        $this->assertSame(4, (int) $t['lots_dus'], 'des lots morts depuis 200 jours restent « dus »');
        $this->assertSame(0, (int) $t['periodes']['mois']['cadeaux_dus'],
            'la fenêtre 30 jours, elle, n\'en voit aucun — deux chiffres contradictoires côte à côte');
    }

    // ═══════════════ A5 · LA CAISSE ET LA BRANCHE ═══════════════

    /** A5 — la garde de branche tient quand elle est passée. */
    public function test_a5_lot_d_une_autre_caisse_refuse(): void
    {
        $autre = Branch::factory()->create();
        $s = $this->spin(['branch_id' => $autre->id]);

        $r = app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('autre point de vente', $r['message']);
    }

    /** A5b — un appelant qui ne transmet PAS la branche remet le lot de n'importe quelle caisse. */
    public function test_a5b_appelant_sans_branche_contourne_la_garde(): void
    {
        $autre = Branch::factory()->create();
        $u = User::factory()->create(['phone' => '0612345678', 'loyalty_points' => 0, 'is_guest' => Ask::YES]);
        $s = $this->spin(['branch_id' => $autre->id]);

        $r = app(WheelDeliveryService::class)->deliver($s->id, null, null); // 3e argument omis

        $this->assertTrue($r['ok'], 'la garde de branche est CONTOURNÉE quand la branche n\'est pas passée');
        $this->assertSame(50, (int) $u->fresh()->loyalty_points);
    }

    // ═══════════════ A6 · CE QUE LA CAISSE VOIT ═══════════════

    /** A6 — on ne peut PAS fabriquer un faux cadeau roue depuis la caisse. */
    public function test_a6_faux_cadeau_roue_depuis_la_caisse_refuse(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $pos = User::factory()->create(['branch_id' => $this->branchId]);
        $pos->assignRole(\App\Enums\Role::ADMIN);

        $this->actingAs($pos)->postJson('/api/admin/pos/stock-outflow', [
            'item_id' => $item->id, 'quantity' => 1, 'type' => 'promo_gift',
        ])->assertStatus(422);

        $this->assertSame(0, StockOutflow::withoutGlobalScope(BranchScope::class)->count());
    }

    /** A6b — la liste « sorties récentes » ne montre pas les sorties d'une autre caisse. */
    public function test_a6b_liste_recente_ne_fuit_pas_entre_caisses(): void
    {
        $autre = Branch::factory()->create();
        StockOutflow::withoutGlobalScope(BranchScope::class)->create([
            'branch_id' => $autre->id, 'item_id' => 1, 'item_name' => 'Boisson offerte',
            'quantity' => 1, 'type' => StockOutflow::TYPE_PROMO_GIFT, 'note' => 'x',
            'user_id' => null, 'stock_decremented' => true, 'created_at' => now(),
        ]);

        $pos = User::factory()->create(['branch_id' => $this->branchId]);
        $pos->assignRole(\App\Enums\Role::ADMIN);

        $rep = $this->actingAs($pos)->getJson('/api/admin/pos/stock-outflow/recent')->assertOk();
        $this->assertSame([], $rep->json('data'), 'aucune sortie de l\'autre caisse ne doit apparaître');
    }

    /** A6c — le cadeau est étiqueté « Cadeau roue », mais le PRODUIT réellement sorti est masqué. */
    public function test_a6c_liste_recente_masque_le_produit_reellement_sorti(): void
    {
        $item = Item::factory()->create(['name' => 'Coca 33cl', 'status' => Status::ACTIVE]);
        StockLevel::create(['branch_id' => $this->branchId, 'stockable_type' => Item::class,
            'stockable_id' => $item->id, 'on_hand' => 10]);
        $this->segmentsCadeau((string) $item->id, true);

        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);
        app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId);

        $pos = User::factory()->create(['branch_id' => $this->branchId]);
        $pos->assignRole(\App\Enums\Role::ADMIN);
        $ligne = $this->actingAs($pos)->getJson('/api/admin/pos/stock-outflow/recent')->json('data.0');

        $this->assertSame('Cadeau roue', $ligne['type_label']);
        $this->assertSame('Boisson offerte', $ligne['item_name'],
            'la liste nomme le LOT, pas le produit décrémenté (Coca 33cl)');
        $this->assertArrayNotHasKey('item_id', $ligne, 'et l\'identifiant du produit n\'est pas exposé');
    }

    // ═══════════════ A7 · LES CHIFFRES DU TABLEAU ═══════════════

    /** A7 — un lot sans produit de référence n'est ni compté ni valorisé, mais il est SIGNALÉ. */
    public function test_a7_cadeau_sans_produit_de_reference_invisible_mais_signale(): void
    {
        $this->segmentsCadeau('Produit Inexistant');

        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);
        $this->assertTrue(app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId)['ok']);

        $t = app(WheelReportService::class)->tableau($this->branchId);
        $this->assertSame(0, (int) $t['periodes']['aujourdhui']['cadeaux_remis']);
        $this->assertSame(0.0, (float) $t['periodes']['aujourdhui']['valeur_offerte']);
        $this->assertNotEmpty($t['avertissements'], 'au moins c\'est signalé');
    }

    /**
     * A7b — LOT HISTORIQUE dont le segment a été retiré de la configuration : plus de charge,
     * plus de valorisation, ET PLUS D'AVERTISSEMENT. Silence complet.
     */
    public function test_a7b_segment_retire_de_la_config_silence_complet(): void
    {
        // La config ne connaît plus « boisson » (campagne changée), mais le lot a été gagné avant.
        Config::set('wheel.segments', [[
            'key' => 'points_50', 'label' => '50 points', 'type' => 'points',
            'value' => 50, 'weight' => 1, 'daily_cap' => 0,
        ]]);

        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);
        $this->assertTrue(app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId)['ok']);

        $t = app(WheelReportService::class)->tableau($this->branchId);
        $this->assertSame(0, (int) $t['periodes']['aujourdhui']['cadeaux_remis']);
        $this->assertSame(0.0, (float) $t['periodes']['aujourdhui']['valeur_offerte']);
        $this->assertSame([], $t['avertissements'], 'AUCUN avertissement : le trou est muet');
    }

    /** A7c — les POINTS offerts ne sont valorisés nulle part dans le tableau. */
    public function test_a7c_les_points_offerts_ne_sont_jamais_valorises(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $tel = '06000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            User::factory()->create(['phone' => $tel, 'loyalty_points' => 0, 'is_guest' => Ask::YES]);
            $s = $this->spin(['phone' => $tel, 'points_awarded' => 500]);
            $this->assertTrue(app(WheelDeliveryService::class)->deliver($s->id, null, $this->branchId)['ok']);
        }

        $t = app(WheelReportService::class)->tableau($this->branchId);
        $this->assertSame(0.0, (float) $t['periodes']['aujourdhui']['valeur_offerte'],
            '10 000 points crédités = 0,00 € affiché');
        $this->assertSame(20, (int) $t['periodes']['aujourdhui']['par_type']['points']);
    }

    // ═══════════════ A8 · L'ÉCRAN DE REMISE, PAR HTTP ═══════════════

    private function posUser(): User
    {
        $u = User::factory()->create(['branch_id' => $this->branchId]);
        $u->assignRole(\App\Enums\Role::ADMIN);
        $this->app['auth']->guard('web')->login($u);

        return $u;
    }

    /**
     * A8 — `spin_id` EST UN CHAMP CACHÉ, ET RIEN NE LE RATTACHE AU NUMÉRO AFFICHÉ.
     * L'écran montre le client A ; le lot du client B est consommé.
     */
    public function test_a8_spin_id_trafique_consomme_le_lot_d_un_autre_client(): void
    {
        Config::set('wheel.access.pin', '');
        $item = Item::factory()->create(['name' => 'Boisson Seule', 'status' => Status::ACTIVE]);
        StockLevel::create(['branch_id' => $this->branchId, 'stockable_type' => Item::class,
            'stockable_id' => $item->id, 'on_hand' => 10]);
        $this->segmentsCadeau('Boisson Seule');

        $a = $this->spin(['phone' => '0611111111', 'prize_key' => 'boisson',
            'prize_label' => 'Boisson offerte', 'prize_type' => 'free_item', 'points_awarded' => 0]);
        $b = $this->spin(['phone' => '0622222222', 'prize_key' => 'boisson',
            'prize_label' => 'Boisson offerte', 'prize_type' => 'free_item', 'points_awarded' => 0]);

        $this->posUser();
        $rep = $this->post(route('admin.wheel.prize.deliver'), [
            'spin_id' => $b->id,        // le lot de B…
            'phone' => '0611111111',    // …pendant que l'écran parle de A
        ]);

        $rep->assertOk();
        $this->assertNotNull($b->fresh()->delivered_at, 'le lot de B a été consommé');
        $this->assertNull($a->fresh()->delivered_at, 'le lot de A est intact');
        $rep->assertSee('Remis', false);
    }

    /**
     * A8b — OUVERT PAR LE CODE DE LA MAISON : la sortie de stock n'a AUCUN auteur.
     * Le chemin de la caisse, lui, inscrit toujours `user_id`.
     */
    public function test_a8b_cadeau_remis_par_le_code_sortie_de_stock_anonyme(): void
    {
        Config::set('wheel.access.pin', '481526');
        Config::set('wheel.counter_branch_id', $this->branchId);
        $item = Item::factory()->create(['name' => 'Boisson Seule', 'status' => Status::ACTIVE]);
        StockLevel::create(['branch_id' => $this->branchId, 'stockable_type' => Item::class,
            'stockable_id' => $item->id, 'on_hand' => 10]);
        $this->segmentsCadeau('Boisson Seule');

        $s = $this->spin(['prize_key' => 'boisson', 'prize_label' => 'Boisson offerte',
            'prize_type' => 'free_item', 'points_awarded' => 0]);

        $this->withSession([\App\Http\Middleware\EnsureWheelAccess::SESSION_KEY => now()->getTimestamp()])
            ->post(route('admin.wheel.prize.deliver'), ['spin_id' => $s->id, 'phone' => '0612345678'])
            ->assertOk();

        $o = StockOutflow::withoutGlobalScope(BranchScope::class)->first();
        $this->assertNotNull($o, 'la sortie de stock existe');
        $this->assertNull($o->user_id, 'AUCUN auteur sur une sortie de stock réelle');
        $this->assertNull($s->fresh()->delivered_by_user_id);
        $this->assertSame(9, (int) StockLevel::where('stockable_id', $item->id)->first()->on_hand);
    }

    /**
     * A8c — CODES DE REMISE ÉTEINTS : l'écran ordonne à l'équipe d'envoyer le client saisir un
     * code que la prise de commande REFUSE.
     */
    public function test_a8c_ecran_envoie_le_client_avec_un_code_mort(): void
    {
        Config::set('wheel.access.pin', '');
        Config::set('pos.coupon_codes_enabled', false);
        Config::set('pos.manual_discount_enabled', false);
        $this->assertFalse(app(\App\Services\Wheel\WheelService::class)->remisesAcceptees());

        $coupon = \App\Models\Coupon::withoutGlobalScopes()->create([
            'name' => 'Roue — -15 %', 'code' => 'ROUE-MORTE1',
            'discount' => 15, 'discount_type' => \App\Enums\DiscountType::PERCENTAGE,
            'start_date' => now(), 'end_date' => now()->addDays(30),
            'status' => Status::ACTIVE, 'max_uses_global' => 1, 'limit_per_user' => 1,
            'minimum_order' => 0, 'maximum_discount' => 5, 'usage_count' => 0,
        ]);
        $this->spin(['prize_key' => 'p15', 'prize_label' => '-15 %',
            'prize_type' => 'coupon_percent', 'points_awarded' => 0, 'coupon_id' => $coupon->id]);

        $this->posUser();
        $this->get(route('admin.wheel.prize', ['phone' => '0612345678']))
            ->assertOk()
            ->assertSee('ROUE-MORTE1', false)
            ->assertSee('saisir sur le site', false);
    }

    /**
     * A8d — HISTORIQUE BORNÉ À 5 : au 6ᵉ tour, l'écran affirme « ses lots sont déjà remis »
     * alors que le client a un code jamais utilisé.
     */
    public function test_a8d_historique_borne_a_5_fait_mentir_l_ecran(): void
    {
        Config::set('wheel.access.pin', '');
        $coupon = \App\Models\Coupon::withoutGlobalScopes()->create([
            'name' => 'Roue — -15 %', 'code' => 'ROUE-CACHEE',
            'discount' => 15, 'discount_type' => \App\Enums\DiscountType::PERCENTAGE,
            'start_date' => now(), 'end_date' => now()->addDays(30),
            'status' => Status::ACTIVE, 'max_uses_global' => 1, 'limit_per_user' => 1,
            'minimum_order' => 0, 'maximum_discount' => 5, 'usage_count' => 0,
        ]);
        // Le lot en remise est le PLUS ANCIEN ; cinq tours plus récents le poussent hors fenêtre.
        // `wheel_spins` est UNIQUE (branche, campagne, téléphone) : il faut donc six CAMPAGNES —
        // ce qu'un an d'exploitation produit naturellement (`campaign_key` = '2026-rentree'…).
        $this->spin(['campaign_key' => 'c0', 'prize_key' => 'p15', 'prize_label' => '-15 %',
            'prize_type' => 'coupon_percent', 'points_awarded' => 0, 'coupon_id' => $coupon->id,
            'created_at' => now()->subDays(60)]);
        for ($i = 1; $i <= 5; $i++) {
            $this->spin(['campaign_key' => 'c' . $i, 'points_awarded' => 50,
                'delivered_at' => now(), 'created_at' => now()->subDays(50 - $i)]);
        }

        $this->posUser();
        $this->get(route('admin.wheel.prize', ['phone' => '0612345678']))
            ->assertOk()
            ->assertSee('déjà remis', false)
            ->assertDontSee('ROUE-CACHEE', false);
    }

    /** A7d — `exposition_max` d'un pourcentage SANS plafond vaut 0 : rassurant et faux. */
    public function test_a7d_exposition_max_nulle_pour_un_pourcentage_sans_plafond(): void
    {
        \App\Models\Coupon::withoutGlobalScopes()->create([
            'name' => 'Roue — -15 %', 'code' => 'ROUE-ABCDEF',
            'discount' => 15, 'discount_type' => \App\Enums\DiscountType::PERCENTAGE,
            'start_date' => now(), 'end_date' => now()->addDays(30),
            'status' => Status::ACTIVE, 'max_uses_global' => 1, 'limit_per_user' => 1,
            'minimum_order' => 0, 'maximum_discount' => 0, 'usage_count' => 0,
        ]);

        $t = app(WheelReportService::class)->tableau($this->branchId);
        $this->assertSame(1, (int) $t['periodes']['aujourdhui']['codes_emis']);
        $this->assertSame(0.0, (float) $t['periodes']['aujourdhui']['exposition_max'],
            'un -15 % SANS plafond est annoncé comme une exposition de 0,00 €');
    }

    /** A7e — un code SUPPRIMÉ par l'exploitant compte encore comme exposition vivante. */
    public function test_a7e_code_supprime_compte_encore_dans_l_exposition(): void
    {
        $c = \App\Models\Coupon::withoutGlobalScopes()->create([
            'name' => 'Roue — -10 %', 'code' => 'ROUE-EFFACE',
            'discount' => 10, 'discount_type' => \App\Enums\DiscountType::PERCENTAGE,
            'start_date' => now(), 'end_date' => now()->addDays(30),
            'status' => Status::ACTIVE, 'max_uses_global' => 1, 'limit_per_user' => 1,
            'minimum_order' => 0, 'maximum_discount' => 50, 'usage_count' => 0,
        ]);
        $c->delete();
        $this->assertNotNull(\App\Models\Coupon::withTrashed()->find($c->id)->deleted_at);

        $t = app(WheelReportService::class)->tableau($this->branchId);
        $this->assertSame(1, (int) $t['periodes']['aujourdhui']['codes_emis'],
            'un code effacé est toujours compté comme émis');
        $this->assertSame(50.0, (float) $t['periodes']['aujourdhui']['exposition_max'],
            '50 € d\'exposition annoncés pour un code qui n\'existe plus');
    }
}
