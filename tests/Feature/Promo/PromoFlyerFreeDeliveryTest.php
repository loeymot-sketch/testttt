<?php

namespace Tests\Feature\Promo;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderCoupon;
use App\Models\PromoFlyer;
use App\Models\Tax;
use App\Models\User;
use App\Services\Promo\PromoFlyerEscPosRenderer;
use App\Services\Promo\PromoFlyerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [TICKET PROMO 2026-11-03 · propriétaire] Deuxième offre du ticket promo client Uber :
 * « LIVRAISON OFFERTE », dès 10 € de commande, jusqu'à 7 km.
 *
 * Bout en bout, sur le VRAI chemin de commande du site : le code créé par le ticket doit annuler
 * les frais de livraison — et seulement eux — dans les conditions du propriétaire, et être REFUSÉ
 * (sans être consommé) hors de ces conditions.
 *
 * @group promo
 */
class PromoFlyerFreeDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $customer;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config([
            'app.api_key'              => 'test-api-key',
            'pos.coupon_codes_enabled' => true,
            // Distance à vol d'oiseau : le banc ne dépend pas du service d'itinéraire public.
            'delivery.routing.enabled' => false,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-03 19:02:00'));
        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30, 'order_setup_delivery' => 5, 'order_setup_takeaway' => 5,
        ]);
        Settings::group('delivery')->set(['free_delivery_above' => 0]);

        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        $this->customer = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);

        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $this->item = Item::factory()->create([
            'item_category_id' => $category->id, 'tax_id' => $tax->id, 'price' => 10.00, 'status' => Status::ACTIVE,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function flyer(): PromoFlyer
    {
        return app(PromoFlyerService::class)->create(
            'Christophe', (int) $this->branch->id, null, 'test', 'M.', PromoFlyer::OFFER_FREE_DELIVERY
        );
    }

    private function address(string $lat, string $lng, string $city, string $postal): Address
    {
        return Address::query()->create([
            'user_id' => $this->customer->id, 'label' => 'Domicile', 'address' => "1 Rue Test, {$city}",
            'latitude' => $lat, 'longitude' => $lng, 'city' => $city, 'postal_code' => $postal, 'geocode_status' => 'OK',
        ]);
    }

    private function homeAddress(): Address
    {
        return $this->address('50.4300', '2.9600', 'Hénin-Beaumont', '62110');
    }

    /** Lens : ~8,7 km à vol d'oiseau — livrable par nous (≤ 10 km) mais AU-DELÀ des 7 km du code. */
    private function farAddress(): Address
    {
        return $this->address('50.4320', '2.8320', 'Lens', '62300');
    }

    private function placeWith(int $couponId, array $extra)
    {
        return $this->place($couponId, 1, null, OrderType::DELIVERY, $extra);
    }

    private function place(int $couponId, int $quantity = 1, ?Address $address = null, int $orderType = OrderType::DELIVERY, array $extra = [])
    {
        $payload = [
            'branch_id' => $this->branch->id, 'subtotal' => 10, 'discount' => 0, 'delivery_charge' => 0.01,
            'total' => 1, 'order_type' => (string) $orderType, 'is_advance_order' => Ask::NO,
            'delivery_time' => 'ASAP', 'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'coupon_id' => $couponId,
            'coupon_code' => (string) \App\Models\Coupon::query()->whereKey($couponId)->value('code'), // [W3-P1-2] code tapé envoyé
            'items' => json_encode([['item_id' => $this->item->id, 'quantity' => $quantity, 'item_variations' => [], 'item_extras' => []]]),
        ];
        if ($orderType === OrderType::DELIVERY) {
            $payload['address_id'] = ($address ?? $this->homeAddress())->id;
        }
        $payload = $extra + $payload;

        return $this->actingAs($this->customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', $payload);
    }

    public function test_le_ticket_cree_un_coupon_livraison_offerte_sans_remise_sur_les_articles(): void
    {
        $flyer = $this->flyer();
        $coupon = Coupon::withoutGlobalScopes()->findOrFail($flyer->coupon_id);

        $this->assertSame(PromoFlyer::OFFER_FREE_DELIVERY, $flyer->offer);
        $this->assertTrue($coupon->free_delivery);
        $this->assertEqualsWithDelta(0.0, (float) $coupon->discount, 0.001, 'Aucune remise sur les articles.');
        $this->assertEqualsWithDelta(10.0, (float) $coupon->minimum_order, 0.001, 'Propriétaire : dès 10 € minimum.');
        $this->assertEqualsWithDelta(7.0, (float) $coupon->free_delivery_max_km, 0.001, 'Propriétaire : 7 km maximum.');
        $this->assertSame(1, (int) $coupon->max_uses_global, 'Usage unique, comme le −10 %.');
        $this->assertMatchesRegularExpression('/^CHRISTOPHE-[2-9A-HJKMNP-TW-Z]{4}$/', $flyer->code, 'Code unique au prénom.');
    }

    public function test_le_ticket_historique_reste_un_moins_dix_pour_cent(): void
    {
        $flyer = app(PromoFlyerService::class)->create('Camille', (int) $this->branch->id, null, 'test');
        $coupon = Coupon::withoutGlobalScopes()->findOrFail($flyer->coupon_id);

        $this->assertSame(PromoFlyer::OFFER_PERCENT, $flyer->offer);
        $this->assertFalse($coupon->free_delivery);
        $this->assertEqualsWithDelta(10.0, (float) $coupon->discount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $coupon->minimum_order, 0.001);
    }

    public function test_livraison_offerte_dans_les_7_km_et_des_10_euros(): void
    {
        $flyer = $this->flyer();

        $r = $this->place((int) $flyer->coupon_id);
        $this->assertContains($r->status(), [200, 201], $r->getContent());

        $order = FrontendOrder::withoutGlobalScopes()->findOrFail((int) $r->json('data.id'));
        $this->assertEqualsWithDelta(0.0, (float) $order->delivery_charge, 0.001, 'La livraison doit être offerte.');
        $this->assertEqualsWithDelta(0.0, (float) $order->discount, 0.001, 'Aucune remise sur les articles.');
        $this->assertEqualsWithDelta(10.0, (float) $order->total, 0.001, '10 € de repas, 0 € de livraison.');

        $usage = OrderCoupon::where('coupon_id', $flyer->coupon_id)->firstOrFail();
        $this->assertEqualsWithDelta(3.90, (float) $usage->delivery_waived, 0.001, 'Les 3,90 € offerts sont tracés.');
        $this->assertEqualsWithDelta(0.0, (float) $usage->discount, 0.001);
    }

    public function test_un_ancien_site_qui_annonce_le_total_frais_compris_n_est_pas_bloque(): void
    {
        $flyer = $this->flyer();

        // 10 € de repas + 3,90 € de livraison : ce qu'affichait un site qui ignore le code.
        $r = $this->placeWith((int) $flyer->coupon_id, ['expected_total' => 13.90]);
        $this->assertContains($r->status(), [200, 201], $r->getContent());
        $order = FrontendOrder::withoutGlobalScopes()->findOrFail((int) $r->json('data.id'));
        $this->assertEqualsWithDelta(10.0, (float) $order->total, 0.001, 'Le client paie le repas seul.');
    }

    public function test_tout_autre_ecart_de_total_reste_refuse(): void
    {
        $flyer = $this->flyer();

        $this->placeWith((int) $flyer->coupon_id, ['expected_total' => 12.00])->assertStatus(422);
        $this->assertSame(0, OrderCoupon::where('coupon_id', $flyer->coupon_id)->count());
    }

    public function test_le_code_est_a_usage_unique(): void
    {
        $flyer = $this->flyer();
        $this->assertContains($this->place((int) $flyer->coupon_id)->status(), [200, 201]);
        $this->place((int) $flyer->coupon_id)->assertStatus(422);
        $this->assertSame(1, OrderCoupon::where('coupon_id', $flyer->coupon_id)->count());
    }

    public function test_au_dela_de_7_km_le_code_est_refuse_et_reste_valable(): void
    {
        $flyer = $this->flyer();

        $r = $this->place((int) $flyer->coupon_id, 1, $this->farAddress());

        $r->assertStatus(422);
        $this->assertStringContainsString('7 km', (string) $r->json('message'), $r->getContent());
        $this->assertSame(0, OrderCoupon::where('coupon_id', $flyer->coupon_id)->count(), 'Le code ne doit pas être consommé.');
        $this->assertSame(0, FrontendOrder::withoutGlobalScopes()->count(), 'Aucune commande à moitié créée.');

        // Toujours utilisable ensuite, à une adresse dans les 7 km.
        $this->assertContains($this->place((int) $flyer->coupon_id)->status(), [200, 201]);
    }

    public function test_a_emporter_le_code_est_refuse_et_reste_valable(): void
    {
        $flyer = $this->flyer();

        $r = $this->place((int) $flyer->coupon_id, 1, null, OrderType::TAKEAWAY);

        $r->assertStatus(422);
        $this->assertStringContainsString('Livraison', (string) $r->json('message'), $r->getContent());
        $this->assertSame(0, OrderCoupon::where('coupon_id', $flyer->coupon_id)->count());
    }

    public function test_sous_10_euros_le_code_est_refuse(): void
    {
        $this->item->forceFill(['price' => 8.50])->save();
        $flyer = $this->flyer();

        $this->place((int) $flyer->coupon_id)->assertStatus(422);
        $this->assertSame(0, OrderCoupon::where('coupon_id', $flyer->coupon_id)->count());
    }

    public function test_le_controle_du_site_annonce_la_livraison_offerte(): void
    {
        $flyer = $this->flyer();

        $r = $this->withHeader('x-api-key', 'test-api-key')->postJson('/api/frontend/coupon/coupon-checking', [
            'code' => $flyer->code, 'total' => 12, 'branch_id' => $this->branch->id, 'surface' => 'web',
        ]);

        $r->assertOk();
        $this->assertTrue((bool) $r->json('data.free_delivery'), $r->getContent());
        $this->assertEqualsWithDelta(7.0, (float) $r->json('data.free_delivery_max_km'), 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $r->json('data.minimum_order'), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $r->json('data.discount'), 0.001);
    }

    public function test_le_ticket_imprime_livraison_offerte_et_aucune_condition(): void
    {
        $flyer = $this->flyer();
        $octets = app(PromoFlyerEscPosRenderer::class)->render(array_merge($flyer->rendered_payload, ['logo_path' => '']), 48);
        $texte = $this->texteImprime($octets);

        $this->assertStringContainsString('LIVRAISON', $texte);
        $this->assertStringContainsString('OFFERTE', $texte);
        $this->assertStringContainsString($flyer->code, $texte, 'Le code unique doit être imprimé.');
        $this->assertStringContainsString('Bonsoir M. Christophe,', $texte);
        $this->assertStringContainsString('commande directement chez nous', preg_replace('/\s+/u', ' ', $texte));
        $this->assertStringContainsString('A très vite chez Le Cayenne', $this->sansAccentMajuscule($texte));
        // Propriétaire : « mettre livraison offerte, c'est tout ».
        $this->assertStringNotContainsString('%', $texte, 'Aucun pourcentage sur le ticket livraison offerte.');
        $this->assertStringNotContainsString('km', $texte, 'Aucune condition de distance imprimée.');
        $this->assertDoesNotMatchRegularExpression('/\b10\s*(€|EUR)/u', $texte, 'Aucun minimum imprimé.');

        foreach (explode("\n", $texte) as $ligne) {
            if (str_contains($ligne, 'http')) {
                continue; // données du QR code, pas du texte imprimé
            }
            $this->assertLessThanOrEqual(48, mb_strlen($ligne), "Ligne trop large : « {$ligne} »");
        }
    }

    public function test_la_caisse_choisit_l_offre_et_le_bilan_est_ventile_par_offre(): void
    {
        $admin = User::factory()->create(['branch_id' => $this->branch->id]);
        $admin->assignRole('Branch Manager');

        $this->actingAs($admin, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/promo-flyer', ['customer_name' => 'Christophe', 'offer' => 'free_delivery'])
            ->assertStatus(201)->assertJsonPath('flyer.offer', 'free_delivery');
        $this->actingAs($admin, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/promo-flyer', ['customer_name' => 'Camille'])
            ->assertStatus(201)->assertJsonPath('flyer.offer', 'percent');
        $this->actingAs($admin, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/promo-flyer', ['customer_name' => 'Sarah', 'offer' => 'inconnue'])
            ->assertStatus(422);

        $stats = $this->actingAs($admin, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->getJson('/api/admin/promo-flyer')->assertOk()->json('stats.by_offer');

        $this->assertSame(1, $stats['free_delivery']['total']);
        $this->assertSame(1, $stats['percent']['total']);
    }

    /** Texte lisible du flux ESC/POS (codes de contrôle retirés, CP858 → UTF-8). */
    private function texteImprime(string $octets): string
    {
        $propre = preg_replace('/\x1D\(k(.)(.)/s', '', $octets) ?? $octets;
        $propre = preg_replace('/\x1B[@]|\x1B[a-zA-Z!\-t]\C|\x1D[!BVLa]\C|\x1DV\C\C/', '', $propre) ?? $propre;
        $propre = preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $propre) ?? $propre;
        $utf8 = @iconv('CP858', 'UTF-8//IGNORE', $propre);

        return $utf8 !== false ? $utf8 : $propre;
    }

    private function sansAccentMajuscule(string $s): string
    {
        return str_replace(['À'], ['A'], $s);
    }
}
