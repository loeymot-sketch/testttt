<?php

namespace Tests\Feature\Wheel;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Item;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\StockOutflow;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelClaimService;
use App\Services\Wheel\WheelService;
use App\Services\Wheel\WheelUnlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ROUE — les trous trouvés par trois audits ADVERSAIRES indépendants, et refermés.
 *
 * Les trois agents ont convergé sur les mêmes défauts, ce qui est le signal le plus fiable qu'ils
 * étaient réels. Chacun est verrouillé ici par un test qui échoue si le correctif est retiré.
 *
 * 1. PLAFOND EN EUROS ABSENT. `-15 %` sans `maximum_discount` fait 37,50 € offerts sur une commande
 *    de groupe de 250 €, par un jeu censé donner « un petit lot ». Le moteur de coupons n'applique
 *    le plafond que s'il est > 0, et la colonne vaut 0 par défaut : ne pas le renseigner, c'est un
 *    pourcentage SANS LIMITE.
 *
 * 2. LA CHARGE S'ACCROCHAIT À UNE COMMANDE ANNULÉE. Le moteur de coupons exclut délibérément les
 *    commandes annulées du comptage d'usage — une annulation ne brûle pas le code — mais la ligne
 *    `order_coupons` reste. On inscrivait donc la charge d'un cadeau jamais donné, on marquait le
 *    tour comme réclamé, et le code redevenait dépensable : second cadeau, définitivement
 *    inchiffrable. Répétable.
 *
 * 3. CODE FRAPPÉ SANS CONTRÔLE D'UNICITÉ, alors que l'index UNIQUE de `coupons.code` a été retiré
 *    exprès et que la garantie repose désormais sur un générateur qui vérifie et reprend. En cas de
 *    doublon, la résolution renvoie le PLUS ANCIEN : le gagnant tombe sur un coupon déjà brûlé.
 *
 * 4. ORACLE ANONYME. `/wheel/config?phone=…` disait « ce numéro a-t-il joué, et qu'a-t-il gagné »
 *    pour n'importe quel numéro, sans jeton. De quoi énumérer les numéros n'ayant pas joué pour y
 *    brûler un jeton volé, et lire les lots des autres.
 */
class WheelAdversarialFixesTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        $this->branchId = Branch::factory()->create()->id;

        Config::set('wheel.enabled', true);
        // [ROUE × CAISSE 2026-08-10] La roue ne tire plus de lot en REMISE quand la caisse
        // refuse les codes — c'est une garde neuve, et elle est juste. Ce banc parle d'autre
        // chose : on accepte donc les codes ici, pour qu'un interrupteur de la caisse ne
        // décide pas de ce qu'il éprouve.
        Config::set('pos.coupon_codes_enabled', true);
        Config::set('wheel.campaign_key', 'adv');
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
    }

    private function cle(): array
    {
        return ['x-api-key' => (string) config('app.api_key')];
    }

    // ── 1. LE PLAFOND EN EUROS ────────────────────────────────────────────────────────────────

    public function test_un_lot_en_pourcentage_porte_un_PLAFOND_EN_EUROS(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'r15', 'label' => '-15%', 'type' => 'coupon_percent', 'value' => 15,
             'weight' => 1, 'daily_cap' => 0, 'max_discount' => 6.0],
        ]);

        $spin = app(WheelService::class)->spin($this->branchId, '0611111111', 'X', ['method' => 'staff']);
        $coupon = Coupon::withoutGlobalScopes()->findOrFail($spin->coupon_id);

        $this->assertSame(6.0, (float) $coupon->maximum_discount,
            'le coupon n\'a pas de plafond : « -15 % » sur une commande de groupe de 250 € offrirait '
            . '37,50 € par un jeu censé donner un petit lot');
    }

    /** Garde de configuration : un segment en % SANS plafond est une bombe à retardement. */
    public function test_la_configuration_livree_plafonne_TOUS_ses_lots_en_pourcentage(): void
    {
        // On relit la vraie configuration du dépôt, pas celle du test.
        $segments = require config_path('wheel.php');

        $sansPlafond = [];
        foreach ($segments['segments'] as $s) {
            if (($s['type'] ?? '') === 'coupon_percent' && (float) ($s['max_discount'] ?? 0) <= 0) {
                $sansPlafond[] = $s['key'];
            }
        }

        $this->assertSame([], $sansPlafond,
            'segment(s) en % sans plafond en euros : ' . implode(', ', $sansPlafond)
            . ' — le moteur de coupons n\'applique le plafond que s\'il est > 0');
    }

    // ── 2. LA COMMANDE ANNULÉE ────────────────────────────────────────────────────────────────

    /**
     * [MISE À JOUR 2026-08-09] Ces deux tests visaient la réconciliation PAR COUPON. Depuis que la
     * charge est inscrite AU MOMENT DE LA REMISE au comptoir, un produit offert n'a plus de coupon
     * du tout : la réconciliation ne peut donc plus le voir. Elle ne sert désormais qu'à RATTRAPER
     * les tours créés avant ce changement — ceux qui portent encore un coupon.
     *
     * On teste donc exactement cela, avec une ligne fabriquée comme le faisait l'ancien code : un
     * coupon de roue consommé sur une commande ANNULÉE ne doit produire AUCUNE charge. C'était le
     * défaut : le moteur de coupons exclut les commandes annulées du comptage d'usage (une
     * annulation ne brûle pas le code) mais la ligne `order_coupons` reste — on inscrivait donc la
     * charge d'un cadeau jamais donné, on marquait le tour comme réclamé, et le code redevenu
     * dépensable offrait un SECOND cadeau que le rescan excluait à jamais. Répétable.
     */
    public function test_un_coupon_consomme_sur_une_commande_ANNULEE_ne_declenche_AUCUNE_charge(): void
    {
        $item = Item::factory()->create();
        Config::set('wheel.segments', [
            ['key' => 'free', 'label' => 'Menu offert', 'type' => 'free_item', 'value' => 0,
             'weight' => 1, 'daily_cap' => 0, 'cost_item_id' => $item->id],
        ]);
        Config::set('wheel.record_cost_on_claim', true);

        [$spin, $coupon] = $this->tourAncienFormat('0622222222', 'Menu offert', $item->id);

        $client = User::factory()->create(['branch_id' => 0]);
        $order = Order::factory()->create([
            'branch_id' => $this->branchId, 'user_id' => $client->id,
            'status' => OrderStatus::CANCELED,
        ]);
        DB::table('order_coupons')->insert([
            'coupon_id' => $coupon->id, 'order_id' => $order->id, 'user_id' => $client->id,
            'discount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = app(WheelClaimService::class)->reconcile();

        $this->assertSame(0, $r['inscrits'],
            'une charge a été inscrite pour une commande ANNULÉE : le cadeau n\'a jamais été donné, '
            . 'et le tour serait marqué réclamé alors que le code redevient dépensable');
        $this->assertSame(0, StockOutflow::withoutGlobalScope(BranchScope::class)->count());
        $this->assertNull($spin->refresh()->cost_outflow_id,
            'le tour est marqué réclamé : il serait exclu à jamais du rescan, donc le VRAI cadeau '
            . 'ne serait jamais chiffré');
    }

    public function test_un_coupon_consomme_sur_une_commande_VIVANTE_declenche_bien_la_charge(): void
    {
        // Contre-preuve : sans elle, le test précédent passerait même si la réconciliation était
        // entièrement morte.
        $item = Item::factory()->create();
        Config::set('wheel.segments', [
            ['key' => 'free', 'label' => 'Menu offert', 'type' => 'free_item', 'value' => 0,
             'weight' => 1, 'daily_cap' => 0, 'cost_item_id' => $item->id],
        ]);

        [$spin, $coupon] = $this->tourAncienFormat('0633333333', 'Menu offert', $item->id);

        $client = User::factory()->create(['branch_id' => 0]);
        $order = Order::factory()->create([
            'branch_id' => $this->branchId, 'user_id' => $client->id,
            'status' => OrderStatus::PENDING,
        ]);
        DB::table('order_coupons')->insert([
            'coupon_id' => $coupon->id, 'order_id' => $order->id, 'user_id' => $client->id,
            'discount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = app(WheelClaimService::class)->reconcile();

        $this->assertSame(1, $r['inscrits'], 'le rattrapage des anciens tours doit rester fonctionnel');
        $this->assertNotNull($spin->refresh()->cost_outflow_id);
    }

    /**
     * Fabrique un tour AU FORMAT D'AVANT : produit offert AVEC un coupon. C'est exactement ce que la
     * roue créait jusqu'au 2026-08-09, et ce que le rattrapage doit encore savoir traiter.
     */
    private function tourAncienFormat(string $tel, string $label, int $itemId): array
    {
        $coupon = new Coupon();
        $coupon->forceFill([
            'name' => 'Roue', 'code' => 'ROUE-' . strtoupper(bin2hex(random_bytes(3))),
            'discount' => 0, 'discount_type' => 5,
            'start_date' => now()->subDay(), 'end_date' => now()->addDays(30),
            'status' => 5, 'max_uses_global' => 1, 'limit_per_user' => 1,
            'minimum_order' => 0, 'usage_count' => 0,
        ])->save();

        $spin = new WheelSpin();
        $spin->forceFill([
            'branch_id' => $this->branchId, 'campaign_key' => 'adv', 'phone' => $tel,
            'prize_key' => 'free', 'prize_label' => $label, 'prize_type' => 'free_item',
            'prize_value' => 0, 'coupon_id' => $coupon->id, 'unlock_method' => 'staff',
        ])->save();

        return [$spin, $coupon];
    }

    // ── 3. L'UNICITÉ DU CODE ──────────────────────────────────────────────────────────────────

    /**
     * `coupons.code` n'a PLUS d'index unique : la garantie est applicative. On force une collision
     * en pré-créant un coupon, puis on vérifie que la roue n'en fabrique pas un doublon.
     */
    public function test_le_code_du_lot_ne_double_JAMAIS_un_code_existant(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'r10', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 1, 'daily_cap' => 0, 'max_discount' => 4.0],
        ]);

        $codes = [];
        for ($i = 0; $i < 12; $i++) {
            $spin = app(WheelService::class)->spin(
                $this->branchId, '06' . str_pad((string) (40000000 + $i), 8, '0'), 'X', ['method' => 'staff']
            );
            $codes[] = Coupon::withoutGlobalScopes()->findOrFail($spin->coupon_id)->code;
        }

        $this->assertSame(count($codes), count(array_unique($codes)),
            'deux lots partagent le même code : la résolution renvoie le PLUS ANCIEN, donc le second '
            . 'gagnant tomberait sur un coupon déjà brûlé');

        // Et la vérification existe bien dans le code : sans elle, ce test passerait par chance.
        $src = file_get_contents(app_path('Services/Wheel/WheelService.php'));
        $this->assertStringContainsString("where('code', \$code)->exists()", $src,
            'aucun contrôle de collision : l\'index UNIQUE a été retiré exprès, la garantie est '
            . 'applicative — la retirer aussi ne laisse plus rien');
    }

    // ── 4. L'ORACLE ───────────────────────────────────────────────────────────────────────────

    public function test_sans_jeton_la_configuration_ne_dit_RIEN_sur_un_numero(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'r10', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 1, 'daily_cap' => 0, 'max_discount' => 4.0],
        ]);
        $spin = app(WheelService::class)->spin($this->branchId, '0655555555', 'X', ['method' => 'staff']);

        $r = $this->withHeaders($this->cle())
            ->getJson('/api/frontend/wheel/config?branch_id=' . $this->branchId . '&phone=0655555555')
            ->assertOk();

        $this->assertFalse($r->json('already_spun'),
            'l\'endpoint dit qui a joué sans exiger de jeton : on énumère les numéros n\'ayant pas '
            . 'joué pour y brûler un jeton volé');
        $this->assertNull($r->json('previous_prize'), 'le lot d\'un autre est lisible');
        $this->assertNull($r->json('previous_code'), 'le CODE d\'un autre est lisible — c\'est de l\'argent');
        $this->assertNotNull($spin->id, 'garde de cohérence : le tour existe bien');
    }

    /** AVEC son jeton, le client doit pouvoir RETROUVER son lot — c'est la sortie d'impasse. */
    public function test_avec_son_jeton_le_client_RETROUVE_son_lot(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'r10', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 1, 'daily_cap' => 0, 'max_discount' => 4.0],
        ]);
        $spin = app(WheelService::class)->spin($this->branchId, '0666666666', 'X', ['method' => 'staff']);
        $jeton = app(WheelUnlockService::class)->issue($this->branchId, 1)['token'];

        $r = $this->withHeaders($this->cle())->getJson(
            '/api/frontend/wheel/config?branch_id=' . $this->branchId
            . '&phone=0666666666&t=' . urlencode($jeton)
        )->assertOk();

        $this->assertTrue($r->json('already_spun'));
        $this->assertSame('-10%', $r->json('previous_prize'),
            'le client ne peut pas retrouver son lot : après une coupure réseau on lui a dit « ton '
            . 'tour n\'a pas été utilisé » puis « tu as déjà tourné » — deux fois faux, et une impasse');
        $this->assertNotNull($r->json('previous_code'), 'sans le code, retrouver son lot ne sert à rien');
    }

    public function test_un_jeton_INVALIDE_ne_fait_pas_parler_l_oracle(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'r10', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 1, 'daily_cap' => 0, 'max_discount' => 4.0],
        ]);
        app(WheelService::class)->spin($this->branchId, '0677777777', 'X', ['method' => 'staff']);

        $r = $this->withHeaders($this->cle())->getJson(
            '/api/frontend/wheel/config?branch_id=' . $this->branchId . '&phone=0677777777&t=faux.jeton'
        )->assertOk();

        $this->assertFalse($r->json('already_spun'), 'un jeton forgé fait parler l\'oracle');
        $this->assertNull($r->json('previous_code'));
    }
}
