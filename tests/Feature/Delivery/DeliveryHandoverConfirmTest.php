<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\AuditLog;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryHandoverToken;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\UberDirectDelivery;
use App\Services\Delivery\DeliveryHandoverService;
use App\Services\Fiscal\AuditLogService;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [LIVRAISON QR 2026-11-01] Lecture de l'arrêt par le jeton, puis « Remis » : matrice des modes, montant exact, rejeu, course,
 * atomicité, refus qui ne révèlent rien.
 *
 * @group delivery
 */
class DeliveryHandoverConfirmTest extends TestCase
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
        $this->tearDownHandover();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function resolve(string $bearer, string $qr)
    {
        return $this->withHeaders($this->bearer($bearer))->postJson('/api/frontend/delivery-handover/resolve', ['token' => $qr]);
    }

    private function confirm(string $bearer, string $qr, array $extra = [], ?string $idem = null)
    {
        return $this->withHeaders($this->bearer($bearer, $idem))->postJson('/api/frontend/delivery-handover/confirm', ['token' => $qr] + $extra);
    }

    /** Arrêt EN ROUTE + jeton imprimé + connexion du livreur attribué. @return array{0: DeliveryStop, 1: string, 2: string} */
    private function onTheRoad(array $order = []): array
    {
        $stop = $this->departed($this->delivery($order));

        return [$stop, $this->qrToken($stop), $this->handoverLogin('livreur.a@test.fr')];
    }

    private function order(DeliveryStop $stop): Order
    {
        return Order::query()->withoutGlobalScopes()->findOrFail($stop->order_id);
    }

    private function unchanged(DeliveryStop $stop): void
    {
        $o = $this->order($stop);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $o->status, 'la commande n\'a pas bougé');
        $this->assertNull($stop->fresh()->delivered_at);
        $this->assertNull(DeliveryHandoverToken::query()->withoutGlobalScopes()->where('delivery_stop_id', $stop->id)->first()->used_at, 'le jeton n\'est pas brûlé');
    }

    // ── lecture ──────────────────────────────────────────────────────────────────────────────

    public function test_resolve_returns_the_stop_for_the_assigned_courier_only(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();

        $r = $this->resolve($bearer, $qr)->assertOk();

        $this->assertSame('ready', $r->json('data.state'));
        $this->assertSame('10 Rue Test, Hénin-Beaumont', $r->json('data.address'));
        $this->assertStringStartsWith('https://waze.com/ul?ll=50.43,2.96&navigate=yes', $r->json('data.waze_url'));
        $this->assertSame('cash', $r->json('data.chosen_mode'));
        $this->assertSame(2750, $r->json('data.amount_due_cents'));
        $this->assertSame(['cash', 'card'], $r->json('data.allowed_modes'));
        $this->assertStringNotContainsString($qr, $r->getContent(), 'le jeton n\'est jamais renvoyé');
    }

    public function test_resolve_for_a_paid_online_order_offers_no_mode_and_no_amount(): void
    {
        [, $qr, $bearer] = $this->onTheRoad(['payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD]);

        $r = $this->resolve($bearer, $qr)->assertOk();

        $this->assertSame([], $r->json('data.allowed_modes'));
        $this->assertSame(0, $r->json('data.amount_due_cents'));
        $this->assertSame('paid_online', $r->json('data.chosen_mode'));
    }

    public function test_resolve_for_a_meal_voucher_order_offers_only_the_voucher(): void
    {
        [, $qr, $bearer] = $this->onTheRoad(['payment_method' => PaymentGateway::TICKET_RESTAURANT]);

        $this->assertSame(['meal_voucher'], $this->resolve($bearer, $qr)->json('data.allowed_modes'));
    }

    public function test_every_dead_or_foreign_token_gets_one_identical_answer_that_reveals_nothing(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();
        $other = $this->handoverLogin('livreur.b@test.fr');
        $answers = [];

        $answers['unknown'] = $this->resolve($bearer, str_repeat('A', 43));
        $answers['malformed'] = $this->resolve($bearer, '<script>alert(1)</script>');
        $answers['other_courier'] = $this->resolve($other, $qr);
        app(DeliveryHandoverService::class)->revoke($stop->fresh(), 'test');
        $answers['revoked'] = $this->resolve($bearer, $qr);

        $bodies = [];
        foreach ($answers as $name => $r) {
            $r->assertStatus(404);
            $this->assertSame('QR_UNAVAILABLE', $r->json('code'), $name);
            $c = $r->getContent();
            foreach (['Karim', '0612345678', 'Rue Test', 'Hénin', '2B', 'waze', '27', 'order'] as $leak) {
                $this->assertStringNotContainsString($leak, $c, "$name ne doit pas fuiter « $leak »");
            }
            $bodies[$name] = $r->json();
        }
        $this->assertCount(1, array_unique(array_map('json_encode', $bodies)), 'même réponse pour tous les cas');
    }

    public function test_a_stale_livreur_session_is_not_enough_the_login_is_always_demanded(): void
    {
        [, $qr] = $this->onTheRoad();
        // Le navigateur du livreur contient encore sa session /livreur (jeton ordinaire, toutes aptitudes).
        $session = $this->courier->createToken('auth_token', ['*'], now()->addHours(8))->plainTextToken;

        $this->resolve($session, $qr)->assertStatus(403)->assertJsonPath('code', 'HANDOVER_LOGIN_REQUIRED');
        $this->confirm($session, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertStatus(403);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $this->order(DeliveryStop::query()->withoutGlobalScopes()->first())->status);
    }

    public function test_without_any_token_the_endpoints_refuse(): void
    {
        [, $qr] = $this->onTheRoad();
        $this->withHeaders(['x-api-key' => 'test-api-key'])->postJson('/api/frontend/delivery-handover/resolve', ['token' => $qr])->assertStatus(401);
        $this->withHeaders(['x-api-key' => 'test-api-key'])->postJson('/api/frontend/delivery-handover/confirm', ['token' => $qr])->assertStatus(401);
    }

    public function test_a_customer_token_cannot_use_the_endpoints(): void
    {
        [, $qr] = $this->onTheRoad();
        $client = $this->customerAccount->createToken('auth_token', ['kiosk:order'], now()->addDay())->plainTextToken;

        $this->resolve($client, $qr)->assertStatus(403);
        $this->confirm($client, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertStatus(403);
    }

    // ── matrice des modes ────────────────────────────────────────────────────────────────────

    public function test_cash_chosen_and_cash_declared_settles_in_cash_with_escrow_fiscal_number_and_trace(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();

        $r = $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertOk();

        $this->assertTrue($r->json('status'));
        $this->assertFalse($r->json('already'));
        $this->assertSame('/livreur', $r->json('next_url'));
        $o = $this->order($stop);
        $this->assertSame(OrderStatus::DELIVERED, (int) $o->status);
        $this->assertSame(PaymentStatus::PAID, (int) $o->payment_status);
        $this->assertSame(PosPaymentMethod::CASH, (int) $o->pos_payment_method);
        $this->assertNotNull($o->fiscal_sequence_no, 'numéro fiscal alloué');
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
        $this->assertSame(1, DeliveryBoyCashMovement::query()->withoutGlobalScopes()->where('order_id', $o->id)->count());
        $this->assertSame(0, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $o->id)->count());

        $s = $stop->fresh();
        $this->assertNotNull($s->delivered_at);
        $this->assertSame('cash', $s->collect_method);
        $this->assertSame(2750, (int) $s->amount_collected_cents);
        $tok = DeliveryHandoverToken::query()->withoutGlobalScopes()->sole();
        $this->assertNotNull($tok->used_at);
        $this->assertSame($this->courier->id, (int) $tok->used_by_user_id);
        $this->assertSame('cash', $tok->chosen_mode);
        $this->assertSame('cash', $tok->declared_mode);
        $this->assertFalse($tok->mode_mismatch);
    }

    public function test_cash_chosen_but_card_declared_is_settled_by_card_and_flagged(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();

        $this->confirm($bearer, $qr, ['declared_mode' => 'card', 'collected_cents' => 2750])->assertOk();

        $o = $this->order($stop);
        $this->assertSame(PaymentStatus::PAID, (int) $o->payment_status);
        $this->assertSame(PosPaymentMethod::CARD, (int) $o->pos_payment_method);
        $this->assertNotNull($o->fiscal_sequence_no);
        $this->assertNull($o->door_payment_mode, 'le choix initial du client n\'est pas réécrit');
        $this->assertSame(PaymentGateway::CASH_ON_DELIVERY, (int) $o->payment_method);
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count(), 'pas d\'escrow espèces pour une carte');
        $this->assertSame(0, DeliveryBoyCashMovement::query()->withoutGlobalScopes()->where('order_id', $o->id)->count());
        $pay = OrderPayment::query()->withoutGlobalScopes()->where('order_id', $o->id)->sole();
        $this->assertSame(PosPaymentMethod::CARD, (int) $pay->mode);
        $this->assertEqualsWithDelta(27.5, (float) $pay->amount, 0.001);
        $tok = DeliveryHandoverToken::query()->withoutGlobalScopes()->sole();
        $this->assertSame('cash', $tok->chosen_mode);
        $this->assertSame('card', $tok->declared_mode);
        $this->assertTrue($tok->mode_mismatch);
        $this->assertSame('card', $stop->fresh()->collect_method);
        $audit = AuditLog::query()->where('action', 'delivery.handover_validated')->sole();
        $this->assertTrue($audit->payload['mismatch']);
        $this->assertSame('cash', $audit->payload['chosen_mode']);
        $this->assertSame('card', $audit->payload['declared_mode']);
    }

    public function test_card_chosen_but_cash_declared_is_settled_in_cash_with_escrow_and_flagged(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad(['door_payment_mode' => 'card']);

        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertOk();

        $o = $this->order($stop);
        $this->assertSame(PosPaymentMethod::CASH, (int) $o->pos_payment_method);
        $this->assertSame('card', $o->door_payment_mode, 'le choix du client reste tracé sur la commande');
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
        $this->assertSame(1, DeliveryBoyCashMovement::query()->withoutGlobalScopes()->where('order_id', $o->id)->count());
        $this->assertSame(0, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $o->id)->count());
        $tok = DeliveryHandoverToken::query()->withoutGlobalScopes()->sole();
        $this->assertSame('card', $tok->chosen_mode);
        $this->assertTrue($tok->mode_mismatch);
    }

    public function test_card_chosen_and_card_declared_is_not_a_mismatch(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad(['door_payment_mode' => 'card']);

        $this->confirm($bearer, $qr, ['declared_mode' => 'card', 'collected_cents' => 2750])->assertOk();

        $this->assertSame(PosPaymentMethod::CARD, (int) $this->order($stop)->pos_payment_method);
        $this->assertFalse(DeliveryHandoverToken::query()->withoutGlobalScopes()->sole()->mode_mismatch);
    }

    public function test_paid_online_order_needs_no_mode_changes_no_money_and_keeps_its_fiscal_number(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad(['payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD, 'fiscal_sequence_no' => 777]);

        $this->confirm($bearer, $qr)->assertOk();

        $o = $this->order($stop);
        $this->assertSame(OrderStatus::DELIVERED, (int) $o->status);
        $this->assertSame(777, (int) $o->fiscal_sequence_no, 'numéro fiscal inchangé');
        $this->assertSame(0, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $o->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
        $this->assertSame(0, (int) $stop->fresh()->amount_collected_cents);
        $this->assertSame('paid_online', $stop->fresh()->collect_method);
        $this->assertNull(DeliveryHandoverToken::query()->withoutGlobalScopes()->sole()->declared_mode);
    }

    public function test_paid_online_order_refuses_a_declared_mode_or_an_amount_and_keeps_the_token_alive(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad(['payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD]);

        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertStatus(422)->assertJsonPath('code', 'mode_forbidden');
        $this->confirm($bearer, $qr, ['collected_cents' => 2750])->assertStatus(422)->assertJsonPath('code', 'wrong_amount');
        $this->unchanged($stop);
    }

    public function test_meal_voucher_is_settled_as_voucher_and_any_other_declared_mode_goes_back_to_the_caisse(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad(['payment_method' => PaymentGateway::TICKET_RESTAURANT]);

        foreach (['cash', 'card'] as $wrong) {
            $this->confirm($bearer, $qr, ['declared_mode' => $wrong, 'collected_cents' => 2750])
                ->assertStatus(422)->assertJsonPath('code', 'caisse_required');
        }
        $this->unchanged($stop);

        $this->confirm($bearer, $qr, ['declared_mode' => 'meal_voucher', 'collected_cents' => 2750])->assertOk();
        $o = $this->order($stop);
        $this->assertSame(PosPaymentMethod::TICKET_RESTAURANT, (int) $o->pos_payment_method);
        $pay = OrderPayment::query()->withoutGlobalScopes()->where('order_id', $o->id)->sole();
        $this->assertSame(PosPaymentMethod::TICKET_RESTAURANT, (int) $pay->mode);
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
    }

    public function test_meal_voucher_declared_on_a_cash_order_is_refused(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();

        $this->confirm($bearer, $qr, ['declared_mode' => 'meal_voucher', 'collected_cents' => 2750])->assertStatus(422);
        $this->unchanged($stop);
    }

    public function test_online_card_order_that_was_never_paid_goes_back_to_the_caisse(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad(['payment_method' => PaymentGateway::CARD, 'payment_status' => PaymentStatus::UNPAID]);

        $this->confirm($bearer, $qr, ['declared_mode' => 'card', 'collected_cents' => 2750])
            ->assertStatus(422)->assertJsonPath('code', 'caisse_required');
        $this->unchanged($stop);
    }

    public function test_missing_mode_or_wrong_amount_is_refused_and_nothing_moves(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();

        $this->confirm($bearer, $qr, ['collected_cents' => 2750])->assertStatus(422)->assertJsonPath('code', 'mode_required');
        $this->confirm($bearer, $qr, ['declared_mode' => 'virement', 'collected_cents' => 2750])->assertStatus(422)->assertJsonPath('code', 'mode_required');
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertStatus(422)->assertJsonPath('code', 'wrong_amount');
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2000])->assertStatus(422)->assertJsonPath('code', 'wrong_amount');
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 9999])->assertStatus(422)->assertJsonPath('code', 'wrong_amount');
        $this->unchanged($stop);
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
    }

    public function test_a_declared_mode_contradicting_an_already_recorded_pos_method_is_refused(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad(['pos_payment_method' => PosPaymentMethod::CASH]);

        $this->confirm($bearer, $qr, ['declared_mode' => 'card', 'collected_cents' => 2750])->assertStatus(422);
        $this->unchanged($stop);
    }

    public function test_a_deferred_caisse_delivery_is_settled_with_the_declared_mode(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad([
            'payment_status' => PaymentStatus::PENDING_COUNTER, 'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED, 'source_surface' => 'delivery',
        ]);

        $this->confirm($bearer, $qr, ['declared_mode' => 'card', 'collected_cents' => 2750])->assertOk();

        $o = $this->order($stop);
        $this->assertSame(PosPaymentMethod::CARD, (int) $o->pos_payment_method);
        $this->assertSame(PaymentStatus::PAID, (int) $o->payment_status);
        $this->assertNotNull($o->fiscal_sequence_no);
    }

    // ── attribution, vie du jeton ─────────────────────────────────────────────────────────────

    public function test_another_courier_cannot_confirm_anyones_stop_and_nothing_changes(): void
    {
        [$stop, $qr] = $this->onTheRoad();
        $other = $this->handoverLogin('livreur.b@test.fr');

        $this->confirm($other, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertStatus(404)->assertJsonPath('code', 'QR_UNAVAILABLE');
        $this->unchanged($stop);
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.handover_validated')->count());
        $refused = AuditLog::query()->where('action', 'delivery.handover_refused')->first();
        $this->assertNotNull($refused, 'le refus est tracé');
        $this->assertSame($this->otherCourier->id, (int) $refused->user_id);
    }

    public function test_replay_by_the_same_courier_is_idempotent_and_changes_nothing(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();
        $first = $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertOk();
        $fiscal = (int) $this->order($stop)->fiscal_sequence_no;
        $deliveredAt = $stop->fresh()->delivered_at;
        Carbon::setTestNow(now()->addMinutes(5));

        $again = $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750]);

        $again->assertOk();
        $this->assertTrue($again->json('already'));
        $this->assertSame($first->json('data.delivered_at'), $again->json('data.delivered_at'));
        $this->assertSame($fiscal, (int) $this->order($stop)->fiscal_sequence_no);
        $this->assertTrue($stop->fresh()->delivered_at->equalTo($deliveredAt), 'l\'heure de remise ne bouge pas');
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
        $this->assertSame(1, DeliveryBoyCashMovement::query()->withoutGlobalScopes()->where('order_id', $stop->order_id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.handover_validated')->count());
    }

    public function test_replay_by_anyone_else_after_validation_is_refused_and_the_qr_is_dead(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertOk();

        $other = $this->handoverLogin('livreur.b@test.fr');
        $this->confirm($other, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertStatus(404);
        $this->resolve($other, $qr)->assertStatus(404);
        // Le livreur lui-même revoit « déjà remis », rien d'autre à faire.
        $r = $this->resolve($bearer, $qr)->assertOk();
        $this->assertSame('delivered', $r->json('data.state'));
        $this->assertNotEmpty($r->json('data.delivered_at'));
        $this->assertNull(app(DeliveryHandoverService::class)->issueForStop($stop->fresh()), 'aucune réimpression possible');
    }

    public function test_a_lost_race_is_refused_with_a_conflict_and_does_not_touch_the_order(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();
        // Une requête jumelle a déjà brûlé le jeton sans (encore) livrer.
        $row = app(DeliveryHandoverService::class)->find($qr);
        $this->assertTrue(app(DeliveryHandoverService::class)->burn($row, $this->otherCourier->id));

        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertStatus(409)->assertJsonPath('code', 'already_used');
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $this->order($stop)->status);
        $this->assertNull($stop->fresh()->delivered_at);
    }

    public function test_a_failure_of_the_official_handover_burns_nothing_and_gives_the_token_back(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();
        $this->mock(OrderService::class, function ($m) {
            $m->shouldReceive('deliveryBoyOrderChangeStatus')->andThrow(new \RuntimeException('boom'));
        });

        $r = $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750]);

        $this->assertGreaterThanOrEqual(400, $r->status());
        $this->unchanged($stop);
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.handover_validated')->count());
    }

    public function test_order_cancelled_after_the_ticket_was_printed_cannot_be_handed_over(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();
        Order::query()->withoutGlobalScopes()->whereKey($stop->order_id)->update(['status' => OrderStatus::CANCELED]);

        $this->resolve($bearer, $qr)->assertStatus(409)->assertJsonPath('code', 'cancelled');
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertStatus(409)->assertJsonPath('code', 'cancelled');
        $this->assertSame(OrderStatus::CANCELED, (int) $this->order($stop)->status);
        $this->assertNull($stop->fresh()->delivered_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
    }

    public function test_uber_direct_takeover_makes_the_qr_unavailable(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();
        DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->update(['delivery_boy_id' => null]);
        Order::query()->withoutGlobalScopes()->whereKey($stop->order_id)->update(['delivery_boy_id' => null]);
        UberDirectDelivery::query()->withoutGlobalScopes()->create(['branch_id' => $this->branch->id, 'order_id' => $stop->order_id, 'delivery_id' => 'del_9', 'status' => 'pickup']);

        $this->resolve($bearer, $qr)->assertStatus(404);
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertStatus(404);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $this->order($stop)->status);
    }

    public function test_an_expired_qr_is_unavailable(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();
        Carbon::setTestNow($stop->wave_at->copy()->addHours(6)->addMinutes(2));
        $bearer = $this->handoverLogin('livreur.a@test.fr'); // la session de remise d'avant a expiré aussi

        $this->resolve($bearer, $qr)->assertStatus(404)->assertJsonPath('code', 'QR_UNAVAILABLE');
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertStatus(404);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $this->order($stop)->status);
    }

    public function test_the_handover_session_itself_expires_after_fifteen_minutes(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();
        Carbon::setTestNow(now()->addMinutes(16));

        $this->resolve($bearer, $qr)->assertStatus(401);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $this->order($stop)->status);
    }

    public function test_a_stop_not_yet_departed_is_reported_to_its_courier_and_not_delivered(): void
    {
        $stop = $this->delivery(); // PRÊTE mais pas partie
        $qr = $this->qrToken($stop);
        $bearer = $this->handoverLogin('livreur.a@test.fr');

        $this->resolve($bearer, $qr)->assertStatus(409)->assertJsonPath('code', 'not_departed');
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertStatus(409);
        $this->assertSame(OrderStatus::PREPARED, (int) $this->order($stop)->status);
    }

    public function test_a_delivery_already_closed_by_the_legacy_screen_is_reported_as_delivered(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();
        $this->withHeaders($this->bearer($this->courier->createToken('auth_token', ['*'], now()->addHour())->plainTextToken))
            ->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver", ['collected_cents' => 2750])->assertOk();

        $r = $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750]);

        $r->assertStatus(409)->assertJsonPath('code', 'already_delivered');
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count(), 'une seule remise comptée');
    }

    // ── géolocalisation, audit, garde-fous ─────────────────────────────────────────────────────

    public function test_geolocation_is_recorded_when_given_and_never_required(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();

        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750, 'lat' => 50.4301, 'lng' => 2.9601, 'accuracy' => 12.6])->assertOk();

        $tok = DeliveryHandoverToken::query()->withoutGlobalScopes()->sole();
        $this->assertEqualsWithDelta(50.4301, $tok->lat, 0.00001);
        $this->assertEqualsWithDelta(2.9601, $tok->lng, 0.00001);
        $this->assertSame(13, (int) $tok->accuracy_m);
        $this->assertEqualsWithDelta(50.4301, (float) $stop->fresh()->arrival_lat, 0.00001);
    }

    public function test_an_absurd_position_is_refused_but_a_missing_one_is_fine(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();

        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750, 'lat' => 200, 'lng' => 2.9])->assertStatus(422);
        $this->unchanged($stop);
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750])->assertOk();
        $this->assertNull(DeliveryHandoverToken::query()->withoutGlobalScopes()->sole()->lat);
    }

    public function test_the_audit_event_carries_who_when_mode_tour_device_and_the_chain_stays_intact(): void
    {
        [$stop, $qr, $bearer] = $this->onTheRoad();
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750, 'lat' => 50.43, 'lng' => 2.96], null)->assertOk();

        $a = AuditLog::query()->where('action', 'delivery.handover_validated')->sole();
        $this->assertSame($this->courier->id, (int) $a->user_id);
        $p = $a->payload;
        foreach (['order_id', 'stop_id', 'wave_at', 'chosen_mode', 'declared_mode', 'mismatch', 'amount_cents', 'via', 'token_id', 'device_id', 'lat', 'lng', 'handed_over_at'] as $k) {
            $this->assertArrayHasKey($k, $p, $k);
        }
        $this->assertSame('qr', $p['via']);
        $this->assertSame($stop->order_id, (int) $p['order_id']);
        $this->assertStringNotContainsString($qr, json_encode($p), 'le jeton n\'est pas dans le journal');
        $this->assertNull(app(AuditLogService::class)->verifyChain((int) $this->branch->id), 'chaîne d\'audit intacte');
    }

    public function test_the_confirm_route_demands_an_idempotency_key(): void
    {
        config(['idempotency.enabled' => true]);
        [, $qr, $bearer] = $this->onTheRoad();
        $h = $this->bearer($bearer);
        unset($h['X-Idempotency-Key']);

        $r = $this->withHeaders($h)->postJson('/api/frontend/delivery-handover/confirm', ['token' => $qr, 'declared_mode' => 'cash', 'collected_cents' => 2750]);

        $this->assertGreaterThanOrEqual(400, $r->status());
        $this->assertLessThan(500, $r->status());
    }

    public function test_the_same_idempotency_key_replays_the_same_answer(): void
    {
        config(['idempotency.enabled' => true]);
        [$stop, $qr, $bearer] = $this->onTheRoad();
        $key = (string) Str::uuid();

        $a = $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750], $key)->assertOk();
        $b = $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750], $key)->assertOk();

        $this->assertSame($a->json('data.delivered_at'), $b->json('data.delivered_at'));
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.handover_validated')->count());
    }

    public function test_resolve_is_throttled_per_courier(): void
    {
        [, , $bearer] = $this->onTheRoad();
        $last = null;
        for ($i = 0; $i < 31; $i++) {
            $last = $this->resolve($bearer, str_repeat('B', 43));
        }
        $last->assertStatus(429);
    }

    public function test_responses_never_contain_a_redirect_target_other_than_the_fixed_one(): void
    {
        [, $qr, $bearer] = $this->onTheRoad();
        $r = $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750, 'next' => 'https://evil.example', 'redirect' => '//evil.example'])->assertOk();

        $this->assertSame('/livreur', $r->json('next_url'));
        $this->assertStringNotContainsString('evil', $r->getContent());
    }
}
