<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\PosPaymentMethod;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Models\AuditLog;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryHandoverToken;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [LIVRAISON QR 2026-11-01 · correctif relecture adverse C-red-team F1/F2] La remise officielle (`OrderService`) n'est JAMAIS
 * enveloppée par une transaction externe : ses audits NF525 et ses notifications partent après SON commit. Le jeton n'est brûlé
 * et l'arrêt mis à jour qu'APRÈS un succès, dans une petite transaction à part.
 *
 * @group delivery
 */
class DeliveryHandoverSequenceTest extends TestCase
{
    use RefreshDatabase;
    use HandoverFixtures;

    private int $baseLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHandover();
        Cache::flush();
        $this->baseLevel = DB::transactionLevel(); // RefreshDatabase ouvre déjà une transaction de test : on compare à CE niveau
    }

    protected function tearDown(): void
    {
        DeliveryStop::flushEventListeners();
        AuditLog::flushEventListeners();
        $this->tearDownHandover();
        parent::tearDown();
    }

    /** @return array{0: DeliveryStop, 1: string, 2: string} */
    private function road(array $order = []): array
    {
        $stop = $this->departed($this->delivery($order));

        return [$stop, $this->qrToken($stop), $this->handoverLogin('livreur.a@test.fr')];
    }

    private function confirm(string $bearer, string $qr, array $extra = [])
    {
        return $this->withHeaders($this->bearer($bearer))->postJson('/api/frontend/delivery-handover/confirm', ['token' => $qr, 'collected_cents' => 2750] + $extra);
    }

    private function order(DeliveryStop $s): Order
    {
        return Order::query()->withoutGlobalScopes()->findOrFail($s->order_id);
    }

    private function tok(): DeliveryHandoverToken
    {
        return DeliveryHandoverToken::query()->withoutGlobalScopes()->firstOrFail();
    }

    // ── F1 : pas de transaction externe ─────────────────────────────────────────────────────────

    public function test_notifications_and_nf525_audits_are_written_outside_any_extra_transaction(): void
    {
        [, $qr, $bearer] = $this->road();
        $levels = [];
        Event::listen(SendOrderMail::class, function () use (&$levels) { $levels['mail'] = DB::transactionLevel(); });
        Event::listen(SendOrderSms::class, function () use (&$levels) { $levels['sms'] = DB::transactionLevel(); });
        Event::listen(SendOrderPush::class, function () use (&$levels) { $levels['push'] = DB::transactionLevel(); });
        AuditLog::creating(function (AuditLog $a) use (&$levels) { $levels[$a->action] = DB::transactionLevel(); });

        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertOk();

        // Notifications : transaction de test seule (aucune transaction de remise autour d'OrderService).
        foreach (['mail', 'sms', 'push'] as $k) {
            $this->assertArrayHasKey($k, $levels, $k);
            $this->assertSame($this->baseLevel, $levels[$k], "« $k » ne part pas à l'intérieur d'une transaction de remise");
        }
        // Audit NF525 d'escrow : seulement la transaction propre d'AuditLogService::write (+1), jamais « remise + audit » (+2).
        $this->assertArrayHasKey('delivery.cash_collected_escrow', $levels);
        $this->assertSame($this->baseLevel + 1, $levels['delivery.cash_collected_escrow']);
    }

    public function test_a_failure_inside_the_official_handover_burns_nothing_and_leaves_the_stop_untouched(): void
    {
        [$stop, $qr, $bearer] = $this->road();
        $this->mock(OrderService::class, fn ($m) => $m->shouldReceive('deliveryBoyOrderChangeStatus')->andThrow(new \RuntimeException('boom')));

        $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertStatus(500);

        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $this->order($stop)->status);
        $this->assertNull($stop->fresh()->delivered_at);
        $t = $this->tok();
        $this->assertNull($t->used_at, 'jeton non brûlé');
        $this->assertNull($t->chosen_mode, 'la réservation du mode est libérée');
        $this->assertNull($t->declared_mode);
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.handover_validated')->count());
    }

    public function test_after_a_refusal_the_courier_can_retry_with_another_mode(): void
    {
        [$stop, $qr, $bearer] = $this->road(['pos_payment_method' => PosPaymentMethod::CASH]);

        // `card` contredit le pos_payment_method déjà posé : refus de la voie officielle.
        $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertStatus(422);
        $this->assertNull($this->tok()->chosen_mode, 'réservation libérée');
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertOk();

        $this->assertSame('cash', $this->tok()->declared_mode, 'le mode du 2ᵉ appui, pas celui du refus');
        $this->assertSame(OrderStatus::DELIVERED, (int) $this->order($stop)->status);
    }

    public function test_no_customer_notification_leaves_when_the_handover_fails(): void
    {
        Event::fake([SendOrderMail::class, SendOrderSms::class, SendOrderPush::class]);
        [$stop, $qr, $bearer] = $this->road(['pos_payment_method' => PosPaymentMethod::CASH]);

        $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertStatus(422); // refus réel d'OrderService
        $this->mock(OrderService::class, fn ($m) => $m->shouldReceive('deliveryBoyOrderChangeStatus')->andThrow(new \RuntimeException('boom')));
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertStatus(500);

        Event::assertNotDispatched(SendOrderMail::class);
        Event::assertNotDispatched(SendOrderSms::class);
        Event::assertNotDispatched(SendOrderPush::class);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $this->order($stop)->status);
    }

    public function test_stop_update_failure_after_a_successful_handover_is_completed_by_the_next_press_without_replaying_money(): void
    {
        $mails = 0;
        Event::listen(SendOrderMail::class, function () use (&$mails) { $mails++; });
        [$stop, $qr, $bearer] = $this->road();
        $failures = 1;
        DeliveryStop::updating(function () use (&$failures) {
            if ($failures-- > 0) {
                throw new \RuntimeException('deadlock simulé sur l\'arrêt');
            }
        });

        $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertStatus(500);

        $o = $this->order($stop);
        $this->assertSame(OrderStatus::DELIVERED, (int) $o->status, 'la remise officielle est faite');
        $fiscal = (int) $o->fiscal_sequence_no;
        $this->assertGreaterThan(0, $fiscal);
        $this->assertNull($this->tok()->used_at, 'jeton non brûlé (la petite transaction a été annulée)');
        $this->assertNull($stop->fresh()->delivered_at);
        $this->assertSame('card', $this->tok()->declared_mode, 'le mode réservé survit');
        // Le jeton ne peut de toute façon plus servir à une autre remise : commande LIVRÉE.
        $other = $this->handoverLogin('livreur.b@test.fr');
        $this->confirm($other, $qr, ['declared_mode' => 'cash'])->assertStatus(404);

        // 2ᵉ appui (même livreur, mode différent demandé) : termine l'arrêt, ne rejoue rien.
        $r = $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertOk();

        $this->assertTrue($r->json('status'));
        $this->assertFalse($r->json('already'), 'c\'est CET appui qui termine la remise de l\'arrêt');
        $this->assertSame($fiscal, (int) $this->order($stop)->fiscal_sequence_no, 'un seul numéro fiscal');
        $this->assertSame(1, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $stop->order_id)->count(), 'un seul encaissement');
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
        $this->assertSame(0, DeliveryBoyCashMovement::query()->withoutGlobalScopes()->where('order_id', $stop->order_id)->count());
        $this->assertNotNull($stop->fresh()->delivered_at);
        $this->assertNotNull($this->tok()->used_at);
        $this->assertSame('card', $this->tok()->declared_mode, 'le mode enregistré est celui de la 1re requête');
        $this->assertSame('card', $stop->fresh()->collect_method);
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.handover_validated')->count());
        $this->assertSame(1, $mails, 'une seule notification « livrée »');
    }

    public function test_a_twin_submission_arriving_during_the_official_handover_changes_nothing_and_the_first_mode_wins(): void
    {
        [$stop, $qr, $bearer] = $this->road();
        $twin = null;
        $svc = app(\App\Services\Delivery\DeliveryHandoverService::class);
        $courier = $this->courier;
        // La jumelle arrive pendant la remise de la 1re (au moment de l'envoi du mail, après le COMMIT d'OrderService).
        Event::listen(SendOrderMail::class, function () use (&$twin, $svc, $courier, $qr) {
            if ($twin === null) {
                $twin = $svc->confirm($courier, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750]);
            }
        });

        $first = $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertOk();

        $this->assertNotNull($twin, 'la jumelle a bien été exécutée');
        $o = $this->order($stop);
        $this->assertSame(OrderStatus::DELIVERED, (int) $o->status);
        $this->assertSame(PosPaymentMethod::CARD, (int) $o->pos_payment_method, 'mode de la 1re requête');
        $this->assertSame(1, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $o->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count(), 'la jumelle « espèces » n\'a rien encaissé');
        $this->assertSame(1, DeliveryHandoverToken::query()->withoutGlobalScopes()->whereNotNull('used_at')->count(), 'un seul jeton brûlé');
        $this->assertSame('card', $this->tok()->declared_mode);
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.handover_validated')->count());
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->where('branch_id', $this->branch->id)->whereNotNull('fiscal_sequence_no')->count(), 'un seul numéro fiscal');
        $this->assertTrue($first->json('status'));
    }

    public function test_a_request_arriving_after_another_one_reserved_the_mode_applies_that_first_mode(): void
    {
        [$stop, $qr, $bearer] = $this->road();
        // La 1re requête a déjà réservé « carte » (elle est en train d'appeler la remise officielle).
        DeliveryHandoverToken::query()->withoutGlobalScopes()->whereKey($this->tok()->id)->update([
            'chosen_mode' => 'cash', 'declared_mode' => 'card', 'mode_mismatch' => true, 'amount_due_cents' => 2750, 'amount_collected_cents' => 2750,
        ]);

        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertOk();

        $this->assertSame(PosPaymentMethod::CARD, (int) $this->order($stop)->pos_payment_method, 'le mode de la 1re requête fait foi');
        $this->assertSame('card', $this->tok()->declared_mode);
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
    }

    // ── relecture C : P2 ─────────────────────────────────────────────────────────────────────────

    public function test_the_audit_traces_the_gap_with_departure_and_whether_a_position_was_given(): void
    {
        [$stop, $qr, $bearer] = $this->road();
        DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->update(['left_at' => now()->subMinutes(42)]);

        $this->confirm($bearer, $qr, ['declared_mode' => 'cash', 'lat' => 50.43, 'lng' => 2.96, 'accuracy' => 9])->assertOk();

        $p = AuditLog::query()->where('action', 'delivery.handover_validated')->sole()->payload;
        $this->assertSame(42, $p['minutes_since_departure']);
        $this->assertTrue($p['position_given']);
        $this->assertNotEmpty($p['left_at']);
        $this->assertNotEmpty($p['handed_over_at']);
        $this->assertEqualsWithDelta(50.43, $p['lat'], 0.0001);
    }

    public function test_without_a_position_the_audit_says_so(): void
    {
        [, $qr, $bearer] = $this->road();
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertOk();

        $this->assertFalse(AuditLog::query()->where('action', 'delivery.handover_validated')->sole()->payload['position_given']);
    }
}
