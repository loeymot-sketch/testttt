<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\StockOutflow;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LA DÉCHARGE — le coût réel des produits offerts, exigence explicite du propriétaire :
 * « le truc gratuit qu'on donne, ça va être calculé dans les charges ».
 *
 * Sans cette écriture, deux choses dérivent en silence : la marge affichée devient fausse (on croit
 * vendre à 70 % alors qu'on a offert quinze menus), et l'inventaire s'écarte du réel — écart qui,
 * à l'inventaire suivant, sera mis sur le compte du vol.
 *
 * Ce que cette suite verrouille, et chaque point est un piège dans lequel on tomberait sans lui :
 *   1. un produit offert CONSOMMÉ génère une charge, du bon type ;
 *   2. un lot gagné mais PAS ENCORE consommé n'en génère AUCUNE — provisionner des cadeaux que la
 *      moitié des clients ne viendra pas chercher gonflerait les charges pour rien ;
 *   3. une REMISE EN POURCENTAGE n'est pas une sortie de stock : la recette réduite le dit déjà,
 *      l'inscrire compterait le cadeau deux fois ;
 *   4. la réconciliation est IDEMPOTENTE : la relancer n'écrit rien de plus ;
 *   5. un cadeau n'est PAS étiqueté « Perte » — sinon on ne distingue plus ce qu'on gaspille de ce
 *      qu'on offre pour récupérer un client ;
 *   6. le type « cadeau roue » n'est PAS saisissable à la main : il n'existe que s'il y a un lot.
 */
class WheelClaimCostTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;
    private WheelClaimService $service;
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branchId = Branch::factory()->create()->id;
        $this->service = app(WheelClaimService::class);
        Config::set('wheel.record_cost_on_claim', true);

        // `stock_outflows.item_id` est NOT NULL : chaque segment offert doit désigner le produit qui
        // sert de RÉFÉRENCE DE COÛT. C'est une décision de gestion, pas une donnée à deviner — d'où
        // sa présence dans la configuration et non dans le code.
        $this->itemId = (int) \App\Models\Item::factory()->create()->id;
        Config::set('wheel.segments', [
            ['key' => 'k', 'label' => 'Menu offert', 'type' => 'free_item', 'value' => 0,
             'weight' => 1, 'daily_cap' => 0, 'cost_item_id' => $this->itemId],
            // Le segment en POURCENTAGE porte volontairement un `cost_item_id` VALIDE : ainsi, la
            // seule chose qui empêche une écriture de stock est le contrôle de TYPE. Sans ce
            // produit, le test passait parce que la référence manquait — pas parce que la règle
            // était respectée. Une mutation l'a montré.
            ['key' => 'pct', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 1, 'daily_cap' => 0, 'cost_item_id' => $this->itemId],
            ['key' => 'nonconf', 'label' => 'Frites offertes', 'type' => 'free_item', 'value' => 0,
             'weight' => 1, 'daily_cap' => 0, 'cost_item_id' => 0],
        ]);
    }

    private function spin(string $type, string $label, ?int $couponId, string $key = 'k'): WheelSpin
    {
        $s = new WheelSpin();
        $s->forceFill([
            'branch_id' => $this->branchId, 'campaign_key' => 'test',
            'phone' => '06' . random_int(10000000, 99999999),
            'prize_key' => $key, 'prize_label' => $label, 'prize_type' => $type, 'prize_value' => 0,
            'coupon_id' => $couponId, 'unlock_method' => 'staff',
        ])->save();

        return $s;
    }

    private function coupon(): Coupon
    {
        $c = new Coupon();
        $c->forceFill([
            'name' => 'Roue', 'code' => 'ROUE-' . strtoupper(bin2hex(random_bytes(3))),
            'discount' => 0, 'discount_type' => 5,
            'start_date' => now()->subDay(), 'end_date' => now()->addDay(),
            'status' => 5, 'max_uses_global' => 1, 'limit_per_user' => 1,
            'minimum_order' => 0, 'usage_count' => 0,
        ])->save();

        return $c;
    }

    /** Marque le coupon comme RÉELLEMENT consommé sur une commande. */
    private function consommer(Coupon $c): int
    {
        $client = User::factory()->create(['branch_id' => 0]);
        $order = Order::factory()->create(['branch_id' => $this->branchId, 'user_id' => $client->id]);

        DB::table('order_coupons')->insert([
            'coupon_id' => $c->id, 'order_id' => $order->id, 'user_id' => $client->id,
            'discount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) $order->id;
    }

    // ── 1. LE CAS CENTRAL ────────────────────────────────────────────────────────────────────

    public function test_un_produit_offert_CONSOMME_genere_une_charge(): void
    {
        $c = $this->coupon();
        $spin = $this->spin('free_item', 'Menu offert', $c->id);
        $orderId = $this->consommer($c);

        $r = $this->service->reconcile();

        $this->assertSame(1, $r['inscrits'], 'aucune charge inscrite : le cadeau serait invisible en compta');

        $spin->refresh();
        $this->assertNotNull($spin->cost_outflow_id);
        $this->assertSame($orderId, (int) $spin->claimed_order_id);
        $this->assertNotNull($spin->claimed_at);

        $sortie = StockOutflow::withoutGlobalScope(BranchScope::class)->findOrFail($spin->cost_outflow_id);
        $this->assertSame(StockOutflow::TYPE_PROMO_GIFT, $sortie->type);
        $this->assertSame('Menu offert', $sortie->item_name);
        $this->assertSame($this->itemId, (int) $sortie->item_id,
            'la charge doit pointer le produit de RÉFÉRENCE configuré, pas un produit devine');
        $this->assertSame(1, (int) $sortie->quantity);
        $this->assertSame($this->branchId, (int) $sortie->branch_id);
        $this->assertStringContainsString('#' . $orderId, (string) $sortie->note,
            'la charge doit pointer la commande : sans elle, impossible de retrouver à quoi elle correspond');
    }

    // ── 2. CE QU'ON N'INSCRIT PAS ────────────────────────────────────────────────────────────

    public function test_un_lot_gagne_mais_PAS_consomme_ne_genere_AUCUNE_charge(): void
    {
        $c = $this->coupon();
        $spin = $this->spin('free_item', 'Frites offertes', $c->id);
        // On NE consomme pas.

        $r = $this->service->reconcile();

        $this->assertSame(0, $r['inscrits'],
            'une charge a été inscrite pour un cadeau jamais réclamé : les charges seraient gonflées '
            . 'de tous les lots que personne ne vient chercher');
        $this->assertNull($spin->refresh()->cost_outflow_id);
        $this->assertSame(0, StockOutflow::withoutGlobalScope(BranchScope::class)->count());
    }

    public function test_une_remise_en_pourcentage_ne_genere_PAS_de_sortie_de_stock(): void
    {
        $c = $this->coupon();
        $spin = $this->spin('coupon_percent', '-10%', $c->id, 'pct');
        $this->consommer($c);

        $r = $this->service->reconcile();

        $this->assertSame(0, $r['inscrits'],
            'une remise en % a été inscrite comme sortie de stock : le cadeau serait compté DEUX '
            . 'fois, puisque la recette réduite le dit déjà');
        $this->assertSame(0, StockOutflow::withoutGlobalScope(BranchScope::class)->count());
        $this->assertNull($spin->refresh()->cost_outflow_id);
    }

    // ── 3. IDEMPOTENCE ───────────────────────────────────────────────────────────────────────

    public function test_relancer_la_reconciliation_n_ecrit_RIEN_de_plus(): void
    {
        $c = $this->coupon();
        $this->spin('free_item', 'Boisson offerte', $c->id);
        $this->consommer($c);

        $this->service->reconcile();
        $apres1 = StockOutflow::withoutGlobalScope(BranchScope::class)->count();

        $r2 = $this->service->reconcile();
        $r3 = $this->service->reconcile();

        $this->assertSame(1, $apres1);
        $this->assertSame(0, $r2['inscrits'], 'la 2e passe a réécrit une charge');
        $this->assertSame(0, $r3['inscrits'], 'la 3e passe a réécrit une charge');
        $this->assertSame(1, StockOutflow::withoutGlobalScope(BranchScope::class)->count(),
            'le cadeau est compté plusieurs fois dans les charges');
    }

    /**
     * LA GARDE DE CONCURRENCE. `reconcile()` filtre déjà sur `cost_outflow_id IS NULL`, donc une
     * seconde passe séquentielle ne trouve rien — et un test mono-processus ne prouve donc RIEN sur
     * la re-vérification INTERNE. Une mutation l'a montré : la retirer ne cassait aucun test.
     *
     * On simule donc le cas réel : le cron et un lancement à la main démarrent en même temps, tous
     * deux voient le lot comme non inscrit, et tous deux tentent d'écrire. Le second doit refuser.
     */
    public function test_deux_executions_SIMULTANEES_n_inscrivent_qu_une_seule_charge(): void
    {
        $c = $this->coupon();
        $spin = $this->spin('free_item', 'Menu offert', $c->id);
        $orderId = $this->consommer($c);
        $consommation = (object) ['order_id' => $orderId, 'created_at' => now()->toDateTimeString()];

        $m = new \ReflectionMethod($this->service, 'inscrire');
        $m->setAccessible(true);
        $item = $this->itemId;

        // Les DEUX appels reçoivent le MÊME objet en mémoire, non rafraîchi : c'est exactement
        // l'état de deux processus qui ont lu la ligne avant que l'autre n'écrive.
        $premier = $m->invoke($this->service, $spin, $consommation, $item);
        $second  = $m->invoke($this->service, $spin, $consommation, $item);

        $this->assertTrue($premier, 'la première écriture doit aboutir');
        $this->assertFalse($second,
            'la seconde écriture a abouti : deux processus simultanés compteraient le cadeau DEUX '
            . 'fois dans les charges');
        $this->assertSame(1, StockOutflow::withoutGlobalScope(BranchScope::class)->count());
    }

    public function test_la_commande_artisan_est_utilisable_et_rend_compte(): void
    {
        $c = $this->coupon();
        $this->spin('free_item', 'Menu offert', $c->id);
        $this->consommer($c);

        $this->artisan('wheel:reconcile-claims')
            ->expectsOutputToContain('charge(s) inscrite(s)')
            ->assertExitCode(0);

        $this->assertSame(1, StockOutflow::withoutGlobalScope(BranchScope::class)->count());
    }

    // ── 4. LES GARDES DE COHÉRENCE ───────────────────────────────────────────────────────────

    /** Un cadeau n'est pas une perte : les confondre rend le pilotage aveugle. */
    public function test_un_cadeau_n_est_PAS_etiquete_comme_une_perte(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Admin/PosStockOutflowController.php'));

        $this->assertStringContainsString('Cadeau roue', $src,
            'le cadeau roue n\'a pas d\'étiquette : il apparaîtrait comme « Perte »');
        $this->assertStringContainsString('TYPE_PROMO_GIFT', $src);
    }

    /**
     * Le type ne doit PAS être saisissable à la main : la validation du comptoir s'appuie sur
     * `TYPES`, et ouvrir cette liste créerait une porte pour sortir du stock sans qu'aucun lot ne
     * corresponde.
     */
    public function test_le_type_cadeau_roue_n_est_PAS_saisissable_a_la_main(): void
    {
        $this->assertNotContains(StockOutflow::TYPE_PROMO_GIFT, StockOutflow::TYPES,
            'le cadeau roue est devenu saisissable au comptoir : on pourrait sortir du stock sans '
            . 'qu\'aucun lot n\'ait été gagné');
        $this->assertContains(StockOutflow::TYPE_PROMO_GIFT, StockOutflow::TYPES_ALL,
            'le type doit rester connu pour l\'affichage et les totaux');
    }

    /**
     * UN TROU SIGNALÉ SE CORRIGE ; UN TROU SILENCIEUX SE DÉCOUVRE À L'INVENTAIRE. Si l'exploitant
     * n'a pas encore choisi le produit de référence d'un segment, on n'invente rien ET on le NOMME.
     */
    public function test_un_segment_SANS_produit_de_reference_est_signale_et_rien_n_est_invente(): void
    {
        $c = $this->coupon();
        $this->spin('free_item', 'Frites offertes', $c->id, 'nonconf');
        $this->consommer($c);

        $r = $this->service->reconcile();

        $this->assertSame(0, $r['inscrits'], 'un produit a été deviné pour chiffrer le cadeau');
        $this->assertSame(0, StockOutflow::withoutGlobalScope(BranchScope::class)->count());
        $this->assertContains('nonconf', $r['a_configurer'],
            'le segment non configuré doit être SIGNALÉ, sinon le trou reste invisible');

        $this->artisan('wheel:reconcile-claims')
            ->expectsOutputToContain('SANS produit de référence')
            ->assertExitCode(0);
    }

    public function test_le_reglage_permet_de_couper_l_inscription(): void
    {
        Config::set('wheel.record_cost_on_claim', false);
        $c = $this->coupon();
        $this->spin('free_item', 'Menu offert', $c->id);
        $this->consommer($c);

        $r = $this->service->reconcile();

        $this->assertSame(0, $r['examines'], 'le réglage n\'est pas honoré');
        $this->assertSame(0, StockOutflow::withoutGlobalScope(BranchScope::class)->count());
    }
}
