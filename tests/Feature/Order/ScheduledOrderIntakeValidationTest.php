<?php

namespace Tests\Feature\Order;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [E4 SCHEDULED-INTAKE 2026-07-20] Intake WEB des commandes programmées.
 *
 * `POST /api/frontend/order` accepte un champ OPTIONNEL `scheduled_at`
 * (format `Y-m-d H:i:s`, NULL/absent = ASAP — comportement historique intact).
 * Gardes métier (OrderRequest::withValidator) :
 *   (a) lead cuisine : >= now + `kds.scheduled_lead_minutes` (défaut 20) ;
 *   (b) fenêtre de service : heure cible entre `kds.scheduled_window_open`
 *       (18:00) et `kds.scheduled_window_close` (00:30) — la fenêtre ENJAMBE
 *       minuit (le service 18h-00h accepte minuit et demie) ;
 *   (c) horizon 7 jours max (garde-fou).
 * Le champ transite tel quel jusqu'à FrontendOrder (fillable + cast datetime,
 * fondations 1cde5bad7) — AUCUNE ligne financière touchée, et les champs
 * legacy `is_advance_order` / `delivery_time` restent inchangés.
 *
 * Utilisateur = WEB (branch_id, PAS de KioskMachine) — mêmes fixtures que
 * WebOrderExpectedTotalGuardTest (template sobriété expected_total).
 */
class ScheduledOrderIntakeValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $webUser;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        config(['app.api_key' => '123456']);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_takeaway' => 5,
            'order_setup_delivery' => 5,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'Scheduled Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue programmee',
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Menus',
            'slug' => 'menus-scheduled',
            'status' => 5,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'Galette Programmee',
            'slug' => 'galette-programmee',
            'price' => 10.00,
            'status' => 5,
            'item_category_id' => $category->id,
        ]);

        $this->webUser = User::forceCreate([
            'name' => 'Web Scheduled User',
            'email' => 'web-scheduled@test.local',
            'username' => 'web_scheduled',
            'phone' => '0600000021',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => Status::ACTIVE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'coupon_id' => null,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postOrder(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this
            ->actingAs($this->webUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $payload);
    }

    /**
     * Absent = ASAP : comportement historique inchangé, scheduled_at NULL en DB.
     */
    public function test_absent_scheduled_at_is_asap_and_null_in_db(): void
    {
        $this->travelTo(Carbon::parse('2026-07-21 18:30:00'));

        $resp = $this->postOrder($this->basePayload());

        $resp->assertStatus(201);
        $this->assertSame(1, FrontendOrder::count());
        $this->assertNull(FrontendOrder::first()->scheduled_at, 'Absent => ASAP => scheduled_at NULL en DB.');
    }

    /**
     * T+45 min dans la fenêtre de service → 201 + valeur EXACTE persistée en DB.
     */
    public function test_valid_scheduled_at_in_window_persists_exact_value(): void
    {
        $this->travelTo(Carbon::parse('2026-07-21 18:30:00'));

        $resp = $this->postOrder($this->basePayload(['scheduled_at' => '2026-07-21 19:15:00']));

        $resp->assertStatus(201);
        $order = FrontendOrder::first();
        $this->assertNotNull($order->scheduled_at, 'scheduled_at doit transiter jusqu\'à l\'insert (fillable, non strippé par GAP-21-2).');
        $this->assertSame('2026-07-21 19:15:00', $order->scheduled_at->format('Y-m-d H:i:s'));
    }

    /**
     * Fenêtre qui enjambe minuit : 00:15 (<= close 00:30) est un créneau valide.
     */
    public function test_scheduled_at_just_after_midnight_is_accepted(): void
    {
        $this->travelTo(Carbon::parse('2026-07-21 23:50:00'));

        $resp = $this->postOrder($this->basePayload(['scheduled_at' => '2026-07-22 00:15:00']));

        $resp->assertStatus(201);
        $this->assertSame('2026-07-22 00:15:00', FrontendOrder::first()->scheduled_at->format('Y-m-d H:i:s'));
    }

    /**
     * (a) Trop proche : T+5 min < lead 20 min → 422, aucune commande créée.
     */
    public function test_scheduled_at_too_close_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-07-21 18:30:00'));

        $resp = $this->postOrder($this->basePayload(['scheduled_at' => '2026-07-21 18:35:00']));

        $resp->assertStatus(422)->assertJsonValidationErrors(['scheduled_at']);
        $this->assertSame(0, FrontendOrder::count());
    }

    /**
     * (b) Hors fenêtre de service : 14:00 (lead OK, <7j) → 422, aucune commande créée.
     */
    public function test_scheduled_at_outside_service_window_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-07-21 18:30:00'));

        $resp = $this->postOrder($this->basePayload(['scheduled_at' => '2026-07-22 14:00:00']));

        $resp->assertStatus(422)->assertJsonValidationErrors(['scheduled_at']);
        $this->assertSame(0, FrontendOrder::count());
    }

    /**
     * Format invalide (ISO avec 'T') → 422 via la règle date_format.
     */
    public function test_scheduled_at_invalid_format_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-07-21 18:30:00'));

        $resp = $this->postOrder($this->basePayload(['scheduled_at' => '2026-07-21T19:15:00']));

        $resp->assertStatus(422)->assertJsonValidationErrors(['scheduled_at']);
        $this->assertSame(0, FrontendOrder::count());
    }

    /**
     * (c) Garde-fou horizon : > 7 jours dans le futur → 422, aucune commande créée.
     */
    public function test_scheduled_at_more_than_seven_days_ahead_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-07-21 18:30:00'));

        $resp = $this->postOrder($this->basePayload(['scheduled_at' => '2026-07-29 19:00:00']));

        $resp->assertStatus(422)->assertJsonValidationErrors(['scheduled_at']);
        $this->assertSame(0, FrontendOrder::count());
    }

    /**
     * Les champs legacy is_advance_order / delivery_time ne sont PAS altérés
     * par la présence de scheduled_at (voies indépendantes).
     */
    public function test_legacy_advance_order_fields_untouched_by_scheduled_at(): void
    {
        $this->travelTo(Carbon::parse('2026-07-21 18:30:00'));

        $resp = $this->postOrder($this->basePayload(['scheduled_at' => '2026-07-21 19:15:00']));

        $resp->assertStatus(201);
        $order = FrontendOrder::first();
        $this->assertSame(Ask::NO, (int) $order->is_advance_order, 'is_advance_order envoyé NO doit rester NO malgré scheduled_at.');
        $this->assertNull($order->delivery_time, 'delivery_time (legacy advance-order) doit rester NULL — scheduled_at est une voie indépendante.');
    }
}
