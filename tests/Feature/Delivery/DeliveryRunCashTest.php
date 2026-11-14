<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\DeliveryBoyCashSession;
use App\Models\DeliveryStop;
use App\Models\User;
use App\Services\Delivery\DeliveryBoyCashSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [LIVREUR CAISSE 2026-11-02] « Ma caisse » du livreur : fond de début, encaissements par moyen, espèces attendues, clôture et écart.
 *
 * @group delivery
 */
class DeliveryRunCashTest extends TestCase
{
    use RefreshDatabase;
    use HandoverFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHandover();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        DeliveryStop::flushEventListeners();
        AuditLog::flushEventListeners();
        $this->tearDownHandover();
        parent::tearDown();
    }

    private function as(User $u, string $method, string $url, array $body = [], ?string $idem = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum')
            ->withHeaders(['x-api-key' => 'test-api-key', 'X-Idempotency-Key' => $idem ?? (string) Str::uuid(), 'Accept' => 'application/json'])
            ->json($method, $url, $body);
    }

    private function road(array $order = [], ?User $courier = null): DeliveryStop
    {
        return $this->departed($this->delivery($order, $courier));
    }

    private function deliver(DeliveryStop $s, array $body = [], ?User $as = null)
    {
        return $this->as($as ?? $this->courier, 'POST', "/api/frontend/delivery-run/stops/{$s->id}/deliver", $body);
    }

    private function cash(?User $u = null)
    {
        return $this->as($u ?? $this->courier, 'GET', '/api/frontend/delivery-run/cash');
    }

    private function openShift(User $u, float $opening): DeliveryBoyCashSession
    {
        return app(DeliveryBoyCashSessionService::class)->openSession((int) $u->branch_id, (int) $u->id, $opening, (int) $this->adminAccount->id);
    }

    /** Un service typique : fond 50 €, cinq livraisons de 27,50 € réglées de cinq façons (dont une payée en ligne). */
    private function typicalShift(): DeliveryBoyCashSession
    {
        $s = $this->openShift($this->courier, 50);
        $a = $this->road();                                                             // espèces historiques : 27,50 €
        $this->deliver($a, ['collected_cents' => 2750])->assertOk();
        $b = $this->road();                                                             // en plusieurs moyens : 10 espèces + 10 carte + 7,50 titre
        $this->deliver($b, ['payments' => [['mode' => 'cash', 'amount' => 10], ['mode' => 'card', 'amount' => 10], ['mode' => 'meal_voucher', 'amount' => 7.5]]])->assertOk();
        $c = $this->road(['door_payment_mode' => 'card']);                              // carte historique : 27,50 €
        $this->deliver($c, ['collected_cents' => 2750])->assertOk();
        $d = $this->road(['payment_method' => PaymentGateway::TICKET_RESTAURANT]);      // titre historique : 27,50 €
        $this->deliver($d, ['collected_cents' => 2750])->assertOk();
        $e = $this->road(['payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD]); // payée en ligne
        $this->deliver($e)->assertOk();

        return $s->fresh();
    }

    // ── (f) récap exact, clôture, écart ──────────────────────────────────────────────────────────

    public function test_the_recap_adds_up_by_means_and_matches_the_cash_ledger(): void
    {
        $s = $this->typicalShift();

        $r = $this->cash()->assertOk();

        $d = $r->json('data');
        $this->assertTrue($d['open']);
        $this->assertSame((int) $s->id, $d['session_id']);
        $this->assertEquals(50.0, $d['opening_amount'], 'fond de début');
        $this->assertEquals(37.5, $d['by_mode']['cash']['amount'], '27,50 + 10');
        $this->assertSame(2, $d['by_mode']['cash']['count']);
        $this->assertEquals(37.5, $d['by_mode']['card']['amount'], '10 + 27,50');
        $this->assertEquals(35.0, $d['by_mode']['meal_voucher']['amount'], '7,50 + 27,50');
        $this->assertEquals(110.0, $d['total_collected']);
        $this->assertSame(4, $d['deliveries_settled'], 'quatre livraisons encaissées à la porte');
        $this->assertSame(5, $d['deliveries_delivered'], 'cinq remises, dont une payée en ligne');
        $this->assertEquals(87.5, $d['expected_cash'], 'espèces attendues = fond 50 + espèces 37,50');
        $this->assertEquals(0.0, $d['ledger_gap'], 'les espèces des commandes = le grand livre de la caisse');
        $this->assertNull($d['counted_cash']);
        $this->assertNull($d['variance']);
        $this->assertCount(5, $d['orders']);
        $split = collect($d['orders'])->first(fn ($o) => count($o['payments']) === 3);
        $this->assertSame(['cash', 'card', 'meal_voucher'], array_column($split['payments'], 'mode'));
        $this->assertTrue((bool) collect($d['orders'])->first(fn ($o) => $o['paid_online'])['paid_online']);
        $this->assertSame(0, $d['pending']['count']);
    }

    public function test_closing_with_a_counted_amount_returns_the_final_recap_with_the_variance(): void
    {
        $s = $this->typicalShift();

        $r = $this->as($this->courier, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => 85, 'session_id' => $s->id]);

        $r->assertOk()->assertJsonPath('already', false)->assertJsonPath('data.status', 'closed');
        $this->assertEquals(50.0, $r->json('data.opening_amount'));
        $this->assertEquals(87.5, $r->json('data.expected_cash'));
        $this->assertEquals(85.0, $r->json('data.counted_cash'));
        $this->assertEquals(-2.5, $r->json('data.variance'), 'il manque 2,50 € dans la sacoche');
        $this->assertEquals(110.0, $r->json('data.total_collected'));
        $s = $s->fresh();
        $this->assertSame(DeliveryBoyCashSession::STATUS_CLOSED, $s->status);
        $this->assertEquals(85.0, (float) $s->closing_amount);
        $this->assertSame($this->courier->id, (int) $s->closed_by_user_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'cash.delivery.session.closed')->count());
    }

    public function test_closing_twice_is_idempotent_and_never_overwrites_the_counted_amount(): void
    {
        $s = $this->typicalShift();
        $this->as($this->courier, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => 85, 'session_id' => $s->id])->assertOk();

        $r = $this->as($this->courier, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => 999, 'session_id' => $s->id]);

        $r->assertOk()->assertJsonPath('already', true);
        $this->assertEquals(85.0, $r->json('data.counted_cash'));
        $this->assertEquals(85.0, (float) $s->fresh()->closing_amount);
        $this->assertSame(1, AuditLog::query()->where('action', 'cash.delivery.session.closed')->count());
    }

    public function test_the_opening_float_set_by_the_administration_is_shown(): void
    {
        $this->openShift($this->courier, 40);
        $stop = $this->road();
        $this->deliver($stop, ['payments' => [['mode' => 'cash', 'amount' => 20], ['mode' => 'card', 'amount' => 7.5]]])->assertOk();

        $d = $this->cash()->json('data');

        $this->assertEquals(40.0, $d['opening_amount']);
        $this->assertEquals(60.0, $d['expected_cash']);
    }

    public function test_an_automatically_opened_shift_shows_a_zero_float(): void
    {
        $this->deliver($this->road(), ['payments' => [['mode' => 'card', 'amount' => 27.5]]])->assertOk();

        $d = $this->cash()->json('data');

        $this->assertTrue($d['open']);
        $this->assertEquals(0.0, $d['opening_amount']);
        $this->assertEquals(27.5, $d['by_mode']['card']['amount']);
        $this->assertEquals(0.0, $d['expected_cash']);
    }

    public function test_no_open_shift_says_so_without_an_error(): void
    {
        $r = $this->cash();

        $r->assertOk()->assertJsonPath('data.open', false)->assertJsonPath('data.message', 'Aucun service ouvert')->assertJsonPath('data.last', null);
        $this->as($this->courier, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => 10])->assertStatus(422);
    }

    public function test_the_last_closed_shift_stays_readable_after_the_close(): void
    {
        $s = $this->typicalShift();
        $this->as($this->courier, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => 87.5, 'session_id' => $s->id])->assertOk();

        $r = $this->cash();

        $r->assertOk()->assertJsonPath('data.open', false)->assertJsonPath('data.last.session_id', $s->id)->assertJsonPath('data.last.status', 'closed');
        $this->assertEquals(0.0, $r->json('data.last.variance'));
    }

    // ── garde-fous de clôture ────────────────────────────────────────────────────────────────────

    public function test_closing_is_refused_while_a_paid_at_door_delivery_is_still_on_the_road(): void
    {
        $s = $this->openShift($this->courier, 10);
        $this->road(); // en route, 27,50 € à encaisser

        $r = $this->as($this->courier, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => 10, 'session_id' => $s->id]);

        $r->assertStatus(422);
        $this->assertStringContainsString('Il reste 1 livraison en route à encaisser', (string) $r->json('message'));
        $this->assertSame(DeliveryBoyCashSession::STATUS_OPEN, $s->fresh()->status);
        $this->assertSame(1, $this->cash()->json('data.pending.count'));
    }

    public function test_a_paid_online_delivery_still_on_the_road_does_not_block_the_close(): void
    {
        $s = $this->openShift($this->courier, 10);
        $this->road(['payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD]);

        $this->as($this->courier, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => 10, 'session_id' => $s->id])->assertOk();
    }

    /** @return array<string, array{0: mixed}> */
    public static function badCounted(): array
    {
        return ['absent' => [null], 'texte' => ['beaucoup'], 'négatif' => [-1], 'trois décimales' => [10.123], 'énorme' => [99999999]];
    }

    /** @dataProvider badCounted */
    public function test_a_bad_counted_amount_is_refused_and_the_shift_stays_open($value): void
    {
        $s = $this->openShift($this->courier, 10);

        $this->as($this->courier, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => $value, 'session_id' => $s->id])->assertStatus(422);

        $this->assertSame(DeliveryBoyCashSession::STATUS_OPEN, $s->fresh()->status);
    }

    // ── (e) isolation ────────────────────────────────────────────────────────────────────────────

    public function test_a_courier_sees_and_closes_only_his_own_shift(): void
    {
        $mine = $this->openShift($this->courier, 20);
        $theirs = $this->openShift($this->otherCourier, 99);
        $other = $this->road([], $this->otherCourier);
        $this->deliver($other, ['payments' => [['mode' => 'cash', 'amount' => 27.5]]], $this->otherCourier)->assertOk();

        $a = $this->cash($this->courier)->json('data');
        $b = $this->cash($this->otherCourier)->json('data');

        $this->assertSame((int) $mine->id, $a['session_id']);
        $this->assertEquals(20.0, $a['opening_amount']);
        $this->assertEquals(0.0, $a['by_mode']['cash']['amount'], 'les 27,50 € de B ne sont pas dans la caisse de A');
        $this->assertSame((int) $theirs->id, $b['session_id']);
        $this->assertEquals(99.0, $b['opening_amount']);
        $this->assertEquals(126.5, $b['expected_cash']);

        // A vise le service de B : introuvable (aucune fuite), B reste ouvert.
        $this->as($this->courier, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => 0, 'session_id' => $theirs->id])->assertStatus(404);
        $this->assertSame(DeliveryBoyCashSession::STATUS_OPEN, $theirs->fresh()->status);
        $this->assertSame(DeliveryBoyCashSession::STATUS_OPEN, $mine->fresh()->status);
    }

    public function test_the_default_close_targets_my_open_shift_never_someone_elses(): void
    {
        $mine = $this->openShift($this->courier, 20);
        $theirs = $this->openShift($this->otherCourier, 99);

        $this->as($this->courier, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => 20])->assertOk()->assertJsonPath('data.session_id', $mine->id);

        $this->assertSame(DeliveryBoyCashSession::STATUS_OPEN, $theirs->fresh()->status);
    }

    // ── (g) permissions ──────────────────────────────────────────────────────────────────────────

    public function test_customers_cashiers_and_admins_are_refused_and_anonymous_is_unauthenticated(): void
    {
        $this->openShift($this->courier, 20);
        foreach ([$this->customerAccount, $this->staffAccount, $this->adminAccount] as $who) {
            $this->cash($who)->assertStatus(403);
            $this->as($who, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => 1])->assertStatus(403);
        }
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['x-api-key' => 'test-api-key', 'Accept' => 'application/json'])->getJson('/api/frontend/delivery-run/cash')->assertStatus(401);
        $this->assertSame(DeliveryBoyCashSession::STATUS_OPEN, DeliveryBoyCashSession::query()->withoutGlobalScopes()->where('delivery_boy_id', $this->courier->id)->firstOrFail()->status);
    }

    // ── côté administration ──────────────────────────────────────────────────────────────────────

    public function test_the_admin_resource_carries_the_recap_with_the_opening_float(): void
    {
        $s = $this->typicalShift();
        $s->load('movements');

        $data = (new \App\Http\Resources\DeliveryBoyCashSessionResource($s))->resolve(request());

        $this->assertEquals(50.0, $data['recap']['opening_amount']);
        $this->assertEquals(37.5, $data['recap']['by_mode']['cash']['amount']);
        $this->assertEquals(37.5, $data['recap']['by_mode']['card']['amount']);
        $this->assertEquals(35.0, $data['recap']['by_mode']['meal_voucher']['amount']);
        $this->assertEquals(87.5, $data['recap']['expected_cash']);
        // Liste (mouvements non chargés) : pas de récap, la liste reste légère.
        $lean = (new \App\Http\Resources\DeliveryBoyCashSessionResource(DeliveryBoyCashSession::query()->withoutGlobalScopes()->find($s->id)))->resolve(request());
        $this->assertArrayNotHasKey('recap', $lean);
    }

    public function test_the_admin_sees_the_variance_of_a_shift_closed_by_the_courier(): void
    {
        $s = $this->typicalShift();
        $this->as($this->courier, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => 90, 'session_id' => $s->id])->assertOk();
        $s = DeliveryBoyCashSession::query()->withoutGlobalScopes()->with('movements')->find($s->id);

        $data = (new \App\Http\Resources\DeliveryBoyCashSessionResource($s))->resolve(request());

        $this->assertEquals(90.0, $data['recap']['counted_cash']);
        $this->assertEquals(2.5, $data['recap']['variance'], 'il y a 2,50 € de trop');
        $this->assertSame('closed', $data['status']);
    }

    // ── nouveau : l'encaissement carte ouvre le service ──────────────────────────────────────────

    public function test_a_second_delivery_reuses_the_same_shift(): void
    {
        $this->deliver($this->road(), ['payments' => [['mode' => 'card', 'amount' => 27.5]]])->assertOk();
        $this->deliver($this->road(), ['payments' => [['mode' => 'cash', 'amount' => 27.5]]])->assertOk();

        $this->assertSame(1, DeliveryBoyCashSession::query()->withoutGlobalScopes()->where('delivery_boy_id', $this->courier->id)->count());
        $this->assertEquals(2, $this->cash()->json('data.deliveries_settled'));
    }

    public function test_a_cancelled_order_on_the_road_does_not_block_the_close(): void
    {
        $s = $this->openShift($this->courier, 10);
        $stop = $this->road();
        \App\Models\Order::query()->withoutGlobalScopes()->whereKey($stop->order_id)->update(['status' => OrderStatus::CANCELED]);

        $this->as($this->courier, 'POST', '/api/frontend/delivery-run/cash/close', ['counted_cash' => 10, 'session_id' => $s->id])->assertOk();
    }
}
