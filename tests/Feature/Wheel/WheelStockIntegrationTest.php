<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Scopes\BranchScope;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockOutflow;
use App\Models\WheelSpin;
use App\Services\Menu\AvailabilityService;
use App\Services\Wheel\WheelDeliveryService;
use App\Services\Wheel\WheelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LA ROUE FACE AU SYSTÈME DE GESTION ET DE STOCK PILOTÉ DEPUIS LA CAISSE.
 *
 * ── LES DEUX TROUS, PROUVÉS EN BASE AVANT CORRECTION ─────────────────────────────────────────
 *
 * 1. **LE STOCK NE BOUGEAIT PAS.** `recordCost()` écrivait `stock_decremented => false` en dur et
 *    n'appelait jamais le service de stock. Mesuré : cadeau remis, ligne de charge écrite,
 *    `stock_levels.on_hand` INCHANGÉ, zéro mouvement. Chaque boisson offerte laissait donc le stock
 *    théorique croire qu'elle était encore sur l'étagère — et sur une semaine, c'est la rupture (86),
 *    la borne, le site et l'inventaire qui dérivent. Le chemin « repas / pertes » de la caisse, lui,
 *    appelle bien le service. Il n'y avait aucune raison que le cadeau en soit dispensé.
 *
 *    Le `false` en dur venait d'un raisonnement valable AILLEURS (sur le chemin historique, l'article
 *    servi n'est pas identifié). Ici il l'est, et un humain vient de confirmer la remise.
 *
 * 2. **LA ROUE OFFRAIT DES PRODUITS EN RUPTURE.** Aucun service de la roue ne consultait la
 *    disponibilité. Mesuré : produit passé en rupture depuis la caisse, la roue l'a offert quand
 *    même, et le comptoir a pu le remettre. Le client gagne, on lui dit non — la pire séquence
 *    possible, et elle vient du logiciel.
 *
 * ── CE QUE CE BANC PROTÈGE ───────────────────────────────────────────────────────────────────
 * Que la roue passe par le MÊME chemin de stock que la caisse, que la ligne dise la VÉRITÉ sur ce
 * qui a bougé, et qu'on n'offre jamais ce qu'on n'a pas.
 */
class WheelStockIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;
    private int $itemId;
    private StockLevel $niveau;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branchId = Branch::factory()->create()->id;
        $this->itemId = (int) Item::factory()->create()->id;

        $this->niveau = StockLevel::create([
            'branch_id' => $this->branchId,
            'stockable_type' => Item::class,
            'stockable_id' => $this->itemId,
            'on_hand' => 10,
            'is_tracked' => 1,
        ]);

        Config::set('wheel.enabled', true);
        Config::set('wheel.campaign_key', 'test-stock');
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.record_cost_on_claim', true);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
        // Les remises sont ACCEPTÉES par défaut dans ce banc : les cas qui parlent d'autre chose ne
        // doivent pas dépendre d'un interrupteur de la caisse. La section 3 les éteint exprès.
        Config::set('pos.coupon_codes_enabled', true);
        $this->cadeau($this->itemId);
    }

    /** Une roue à un seul lot : un produit offert, rattaché à un produit de référence. */
    private function cadeau(?int $itemId): void
    {
        Config::set('wheel.segments', [[
            'key' => 'g', 'label' => 'Boisson offerte', 'type' => 'free_item', 'value' => 0,
            'weight' => 1, 'daily_cap' => 0,
            'cost_item_id' => $itemId ?? 0,
        ]]);
    }

    private function tour(string $tel): WheelSpin
    {
        return app(WheelService::class)->spin(
            $this->branchId, $tel, 'Client', ['method' => 'staff'], null, null, $tel . '@exemple.fr'
        );
    }

    private function onHand(): int
    {
        return (int) StockLevel::withoutGlobalScope(BranchScope::class)->find($this->niveau->id)->on_hand;
    }

    // ── 1. LE STOCK SUIT LE CADEAU ───────────────────────────────────────────────────────────

    public function test_un_cadeau_remis_DECREMENTE_le_stock_comme_le_fait_la_caisse(): void
    {
        $spin = $this->tour('0611000801');

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(9, $this->onHand(),
            'LE STOCK N\'A PAS BOUGÉ : la boisson est partie et l\'inventaire croit encore l\'avoir. '
            . 'Sur une semaine, la rupture, la borne et le site dérivent tous.');

        $mouvement = StockMovement::withoutGlobalScope(BranchScope::class)
            ->where('reason', 'manual_out')->latest('id')->first();
        $this->assertNotNull($mouvement,
            'aucun mouvement de stock : la sortie n\'est pas traçable dans l\'historique');
        $this->assertSame(-1, (int) $mouvement->delta);
    }

    /** La ligne de charge doit porter la VÉRITÉ, pas une constante. */
    public function test_la_ligne_de_charge_dit_si_le_stock_a_REELLEMENT_bouge(): void
    {
        $spin = $this->tour('0611000802');
        app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $sortie = StockOutflow::withoutGlobalScope(BranchScope::class)->latest('id')->firstOrFail();

        $this->assertTrue((bool) $sortie->stock_decremented,
            '`stock_decremented` reste faux alors que le stock a bougé : la ligne est inexploitable '
            . 'pour l\'inventaire');
        $this->assertSame(StockOutflow::TYPE_PROMO_GIFT, (string) $sortie->type,
            'le type doit rester « cadeau » : la caisse le distingue d\'une PERTE dans sa liste');
        $this->assertSame($this->itemId, (int) $sortie->item_id);
    }

    /**
     * PRODUIT COMPOSITE (un menu) : aucun stock direct, donc rien à décrémenter. La charge est tracée,
     * le cadeau est remis — et l'équipe est PRÉVENUE, sinon elle croit que l'inventaire suit.
     */
    public function test_un_produit_sans_stock_direct_est_remis_mais_le_dit(): void
    {
        $composite = (int) Item::factory()->create()->id;   // aucun StockLevel
        $this->cadeau($composite);

        $spin = $this->tour('0611000803');
        $r = app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $this->assertTrue($r['ok'], 'le cadeau doit être remis : le client n\'y est pour rien');
        $this->assertMatchesRegularExpression('/stock non d[ée]cr[ée]ment/iu', (string) $r['message'],
            'l\'équipe croit que l\'inventaire est à jour alors qu\'il ne l\'est pas');

        $sortie = StockOutflow::withoutGlobalScope(BranchScope::class)->latest('id')->firstOrFail();
        $this->assertFalse((bool) $sortie->stock_decremented);
    }

    /**
     * PLANCHER À ZÉRO. Le service de stock refuse de rendre un `on_hand` négatif — un compte négatif
     * masque un vol et bloque le produit en rupture jusqu'à un réappro qui dépasse le négatif. Le
     * cadeau part quand même : le client a gagné avant que le rayon soit vide.
     */
    public function test_a_stock_zero_le_cadeau_part_mais_le_stock_ne_devient_pas_negatif(): void
    {
        StockLevel::withoutGlobalScope(BranchScope::class)
            ->whereKey($this->niveau->id)->update(['on_hand' => 0]);

        $spin = $this->tour('0611000804');
        $r = app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $this->assertTrue($r['ok']);
        $this->assertSame(0, $this->onHand(), 'le stock est devenu négatif');
        $this->assertMatchesRegularExpression('/stock non d[ée]cr[ée]ment/iu', (string) $r['message']);
    }

    /** Une seconde remise ne peut pas décrémenter deux fois — la maison paierait deux fois. */
    public function test_une_seconde_remise_ne_decremente_pas_une_seconde_fois(): void
    {
        $spin = $this->tour('0611000805');
        app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);
        $apresUn = $this->onHand();

        app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $this->assertSame($apresUn, $this->onHand(),
            'le stock a été décrémenté DEUX FOIS pour un seul cadeau');
        $this->assertSame(1, StockMovement::withoutGlobalScope(BranchScope::class)
            ->where('reason', 'manual_out')->count());

        /*
         * La clé d'idempotence doit être DÉRIVÉE DU TOUR, pas tirée au hasard. Aujourd'hui la seconde
         * remise est déjà refusée par `delivered_at` — c'est de la défense en profondeur, donc une
         * clé aléatoire passerait inaperçue. On l'assertit donc directement : le jour où un autre
         * chemin (réconciliation, reprise) rejoue une remise, c'est CETTE clé qui empêche de payer
         * deux fois le même cadeau.
         */
        $mouvement = StockMovement::withoutGlobalScope(BranchScope::class)
            ->where('reason', 'manual_out')->firstOrFail();
        $this->assertSame('wheel-gift-' . $spin->id, (string) $mouvement->idempotency_key,
            'la clé d\'idempotence n\'est pas liée au tour : un rejeu décrémenterait une seconde fois');
    }

    // ── 2. ON N'OFFRE PAS CE QU'ON N'A PAS ───────────────────────────────────────────────────

    /**
     * LE CŒUR DU SECOND TROU. Le produit est mis en rupture DEPUIS LA CAISSE, et la roue doit cesser
     * de le promettre — sans que personne n'ait rien d'autre à faire.
     *
     * On ajoute un second lot sans stock pour que la roue ait toujours quelque chose à donner : le
     * comportement voulu est « elle continue de tourner, elle cesse de promettre CE lot-là ».
     */
    public function test_un_lot_dont_le_produit_est_en_RUPTURE_n_est_plus_tire(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'g', 'label' => 'Boisson offerte', 'type' => 'free_item', 'value' => 0,
             'weight' => 50, 'daily_cap' => 0, 'cost_item_id' => $this->itemId],
            ['key' => 'p', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 1, 'daily_cap' => 0, 'max_discount' => 5],
        ]);

        // Le poids du cadeau est 50 contre 1 : sans la garde, il sortirait presque à chaque fois.
        app(AvailabilityService::class)->toggle($this->itemId, $this->branchId, false, 'rupture');

        $types = [];
        for ($i = 0; $i < 25; $i++) {
            $types[] = $this->tour('06120008' . str_pad((string) $i, 2, '0', STR_PAD_LEFT))->prize_type;
        }

        $this->assertNotContains('free_item', $types,
            'la roue offre un produit EN RUPTURE : le client gagne et le comptoir doit lui dire non');
        $this->assertContains('coupon_percent', $types,
            'la roue ne tourne plus du tout : elle devait continuer, en cessant seulement de '
            . 'promettre le lot indisponible');
    }

    /** Et dès que la caisse remet le produit en vente, le lot revient. Aucun geste de plus. */
    public function test_des_que_la_caisse_remet_en_vente_le_lot_revient(): void
    {
        app(AvailabilityService::class)->toggle($this->itemId, $this->branchId, false, 'rupture');
        app(AvailabilityService::class)->toggle($this->itemId, $this->branchId, true, 'réappro');

        $this->assertSame('free_item', $this->tour('0611000806')->prize_type);
    }

    /**
     * SANS PRODUIT DE RÉFÉRENCE CONFIGURÉ, on ne peut rien vérifier — et on ne bloque pas. Mieux vaut
     * un lot non chiffré (que la commande de réconciliation signale) qu'une roue qui refuse de
     * tourner parce qu'un réglage manque. C'est la même règle que « on ne peut pas exiger ce qu'on ne
     * fournit pas ».
     */
    public function test_un_lot_sans_produit_de_reference_reste_tirable(): void
    {
        $this->cadeau(null);

        $this->assertSame('free_item', $this->tour('0611000807')->prize_type);
    }

    // ── 3. ON NE PROMET PAS CE QUE LA CAISSE REFUSE ──────────────────────────────────────────

    /**
     * TROISIÈME TROU, ET LE PLUS GROS. Les remises sont derrière deux interrupteurs de la caisse
     * (`pos.coupon_codes_enabled` et l'ancien `pos.manual_discount_enabled`) qui valent TOUS DEUX
     * faux par défaut. Dans cet état, la commande refuse la remise et l'entrée du code est masquée
     * sur le site : un code de la roue est refusé PARTOUT.
     *
     * Mesuré sur la configuration réelle : 40 % du poids de la roue sur des lots en remise. Deux
     * clients sur cinq repartaient donc avec un code inutilisable, pendant que la page leur disait
     * « saisis-le dans ton panier, c'est valable dès maintenant » et que l'e-mail le répétait.
     *
     * Le poids est volontairement écrasant (50 contre 1) : sans la garde, la remise sortirait presque
     * à chaque tour.
     */
    public function test_aucun_lot_en_remise_n_est_tire_quand_la_caisse_refuse_les_codes(): void
    {
        Config::set('pos.coupon_codes_enabled', false);
        Config::set('pos.manual_discount_enabled', false);
        Config::set('wheel.segments', [
            ['key' => 'g', 'label' => 'Boisson offerte', 'type' => 'free_item', 'value' => 0,
             'weight' => 1, 'daily_cap' => 0, 'cost_item_id' => $this->itemId],
            ['key' => 'p', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 50, 'daily_cap' => 0, 'max_discount' => 4],
        ]);

        $types = [];
        for ($i = 0; $i < 20; $i++) {
            $types[] = $this->tour('06130008' . str_pad((string) $i, 2, '0', STR_PAD_LEFT))->prize_type;
        }

        $this->assertNotContains('coupon_percent', $types,
            'la roue donne un code que la commande REFUSERA au dernier clic — et la page promet au '
            . 'client qu\'il est « valable dès maintenant »');
        $this->assertContains('free_item', $types,
            'la roue ne tourne plus : elle devait continuer avec les lots qui, eux, fonctionnent');
    }

    /** Chacun des deux interrupteurs suffit à rouvrir les remises — c'est le même couple que la commande. */
    public function test_chacun_des_deux_interrupteurs_ramene_les_lots_en_remise(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'p', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 1, 'daily_cap' => 0, 'max_discount' => 4],
        ]);

        Config::set('pos.coupon_codes_enabled', true);
        Config::set('pos.manual_discount_enabled', false);
        $this->assertSame('coupon_percent', $this->tour('0613000901')->prize_type,
            'l\'interrupteur dédié aux codes promo devrait suffire');

        Config::set('pos.coupon_codes_enabled', false);
        Config::set('pos.manual_discount_enabled', true);
        $this->assertSame('coupon_percent', $this->tour('0613000902')->prize_type,
            'l\'ancien interrupteur reste la porte de secours des installations qui ne connaissent '
            . 'pas la nouvelle variable');
    }

    /**
     * ET SI LA ROUE N'A QUE DES REMISES, codes éteints ? Elle ne peut rien donner, et elle le DIT
     * plutôt que de tirer un lot mort en silence.
     */
    public function test_une_roue_faite_QUE_de_remises_codes_eteints_refuse_le_tour_et_le_dit(): void
    {
        Config::set('pos.coupon_codes_enabled', false);
        Config::set('pos.manual_discount_enabled', false);
        Config::set('wheel.segments', [
            ['key' => 'p', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 1, 'daily_cap' => 0, 'max_discount' => 4],
        ]);

        $this->expectException(\App\Services\Wheel\WheelException::class);
        $this->tour('0613000903');
    }

    // ── 4. LA CAISSE VOIT LE CADEAU DANS SES SORTIES ─────────────────────────────────────────

    /**
     * Le cadeau doit apparaître dans la liste des sorties de la caisse, et étiqueté « Cadeau roue » —
     * pas « Perte ». On ne pilote pas un restaurant en confondant ce qu'on GASPILLE et ce qu'on OFFRE
     * pour récupérer un client.
     */
    public function test_le_cadeau_apparait_dans_les_sorties_de_la_caisse_etiquete_cadeau_roue(): void
    {
        $caissier = \App\Models\User::factory()->create(['branch_id' => $this->branchId]);
        $caissier->givePermissionTo('pos');

        $spin = $this->tour('0611000808');
        app(WheelDeliveryService::class)->deliver($spin->id, $caissier->id, $this->branchId);

        $r = $this->actingAs($caissier)
            ->getJson('/api/admin/pos/stock-outflow/recent')
            ->assertOk();

        $lignes = collect($r->json('data'));
        $cadeau = $lignes->firstWhere('type', StockOutflow::TYPE_PROMO_GIFT);

        $this->assertNotNull($cadeau,
            'le cadeau de la roue est invisible dans les sorties de la caisse : personne ne peut le piloter');
        $this->assertSame('Cadeau roue', $cadeau['type_label'],
            'un cadeau étiqueté autrement (« Perte », « Autre ») fausse le pilotage');
        $this->assertTrue((bool) $cadeau['stock_decremented']);
    }
}
