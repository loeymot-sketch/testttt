<?php

namespace Tests\Feature\Delivery;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [VG-2026-10-21 R2-001-cl] La RÉPONSE de création d'une livraison « au plus tôt » annonçait encore
 * `wait 10–15, prevue_pour null` (#8399 : départ 23:00) alors que le suivi, 11 s plus tard, disait 23:00.
 * Le site recopie cette réponse (« Prêt dans ~10–15 min »). Cause : la réponse lisait l'instance en mémoire ;
 * l'heure de tournée (`delivery_slot_at`) est écrite par l'écouteur sur une AUTRE instance.
 *
 * Attendu : la réponse de création = la même définition que le suivi public (une seule fonction).
 *
 * @group delivery
 */
class ReponseCreationLivraisonAnnonceLeDepartTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $customer;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config([
            'app.api_key' => 'test-api-key', 'delivery.wave_minutes' => 30,
            'kds.scheduled_window_open' => '18:00', 'kds.scheduled_window_close' => '00:30',
        ]);
        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30, 'order_setup_delivery' => 5, 'order_setup_takeaway' => 5,
        ]);
        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        $this->customer = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $this->address = Address::query()->create([
            'user_id' => $this->customer->id, 'label' => 'Domicile', 'address' => '10 Rue Test, Hénin-Beaumont',
            'latitude' => '50.4300', 'longitude' => '2.9600', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110', 'geocode_status' => 'OK',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function la_reponse_de_creation_annonce_le_depart_de_la_tournee_comme_le_suivi(): void
    {
        $now = CarbonImmutable::parse('2026-10-22 17:11:29', 'Europe/Paris');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $tax = Tax::factory()->create(['name' => 'TVA 10', 'code' => 'T10', 'type' => TaxType::PERCENTAGE, 'tax_rate' => 10, 'status' => Status::ACTIVE]);
        $cat = ItemCategory::factory()->create(['name' => 'Boissons', 'wizard_template' => 'simple', 'has_menu' => false]);
        $item = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tax->id, 'name' => 'Eau Plate', 'price' => 10, 'status' => Status::ACTIVE]);

        $r = $this->actingAs($this->customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', [
                'branch_id' => $this->branch->id, 'subtotal' => 10, 'discount' => 0, 'delivery_charge' => 0.01,
                'total' => 1, 'order_type' => (string) OrderType::DELIVERY, 'is_advance_order' => Ask::NO,
                'address_id' => $this->address->id, 'delivery_time' => 'ASAP', 'source' => Source::WEB,
                'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
                'items' => json_encode([['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]]),
            ]);
        $this->assertContains($r->status(), [200, 201], $r->getContent());

        $tracking = $r->json('tracking');
        // 17:11 + 20 min de préparation → première tournée de service : 18:00.
        $this->assertSame('18:00', $tracking['prevue_pour'], 'La réponse de création doit annoncer le départ, jamais « 10–15 min ».');
        $this->assertSame('2026-10-22', $tracking['prevue_le']);
        $this->assertSame(49, $tracking['wait_low']);

        // Une seule définition : le suivi public répond la même chose.
        $suivi = $this->getJson('/api/frontend/order/track/' . $tracking['tracking_token'])->json();
        $this->assertSame($suivi['prevue_pour'], $tracking['prevue_pour']);
        $this->assertSame($suivi['prevue_le'], $tracking['prevue_le']);
        $this->assertSame($suivi['wait_low'], $tracking['wait_low']);
    }
}
