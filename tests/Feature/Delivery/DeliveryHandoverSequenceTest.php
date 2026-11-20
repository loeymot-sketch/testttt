<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Models\AuditLog;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryHandoverToken;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\Delivery\DeliveryHandoverService;
use App\Services\Fiscal\AuditLogService;
use App\Services\Delivery\HandoverRefused;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Carbon\Carbon;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [LIVRAISON QR 2026-11-01 · relectures adverses C et D] La remise officielle (`OrderService`) n'est JAMAIS enveloppée par une
 * transaction, et aucun état « en cours » n'est persisté : l'exclusion de deux appuis est un VERROU applicatif à expiration (30 s),
 * le mode enregistré est celui que la COMMANDE porte, le jeton n'est brûlé qu'APRÈS un succès.
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

    private function lockKey(DeliveryStop $s): string
    {
        return DeliveryHandoverService::lockKey((int) $s->id);
    }

    // ── relecture C : pas de transaction externe ─────────────────────────────────────────────────

    public function test_notifications_and_nf525_audits_are_written_outside_any_extra_transaction(): void
    {
        [, $qr, $bearer] = $this->road();
        $levels = [];
        Event::listen(SendOrderMail::class, function () use (&$levels) { $levels['mail'] = DB::transactionLevel(); });
        Event::listen(SendOrderSms::class, function () use (&$levels) { $levels['sms'] = DB::transactionLevel(); });
        Event::listen(SendOrderPush::class, function () use (&$levels) { $levels['push'] = DB::transactionLevel(); });
        AuditLog::creating(function (AuditLog $a) use (&$levels) { $levels[$a->action] = DB::transactionLevel(); });

        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertOk();

        foreach (['mail', 'sms', 'push'] as $k) {
            $this->assertArrayHasKey($k, $levels, $k);
            $this->assertSame($this->baseLevel, $levels[$k], "« $k » ne part pas à l'intérieur d'une transaction de remise");
        }
        $this->assertSame($this->baseLevel + 1, $levels['delivery.cash_collected_escrow'], 'escrow : seulement la transaction propre d\'AuditLogService');
    }

    // ── relecture D : verrou applicatif à expiration ─────────────────────────────────────────────

    public function test_a_lock_held_by_someone_else_gives_409_and_never_calls_the_official_handover(): void
    {
        [$stop, $qr, $bearer] = $this->road();
        $other = Cache::lock($this->lockKey($stop), 30);
        $this->assertTrue($other->get());
        $this->mock(OrderService::class, fn ($m) => $m->shouldNotReceive('deliveryBoyOrderChangeStatus'));

        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertStatus(409)->assertJsonPath('code', 'in_progress');

        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $this->order($stop)->status);
        $this->assertNull($this->tok()->used_at);
        $this->assertFalse(Cache::lock($this->lockKey($stop), 30)->get(), 'le verrou de l\'autre propriétaire n\'a PAS été libéré par la requête refusée');
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.handover_refused')->count(), 'un appui jumeau n\'est pas un refus métier');
    }

    public function test_a_dead_process_blocks_nobody_after_thirty_seconds_and_the_new_mode_is_the_one_recorded(): void
    {
        [$stop, $qr, $bearer] = $this->road();
        // Un processus a pris le verrou puis est mort (jamais libéré). Il n'a laissé AUCUN état sur le jeton.
        $this->assertTrue(Cache::lock($this->lockKey($stop), 30)->get());
        $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertStatus(409);
        Carbon::setTestNow(now()->addSeconds(31));

        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertOk();

        $o = $this->order($stop);
        $this->assertSame(PosPaymentMethod::CASH, (int) $o->pos_payment_method, 'les espèces sont rangées en espèces (plus jamais en carte)');
        $this->assertSame('cash', $this->tok()->declared_mode);
        $this->assertSame('cash', $stop->fresh()->collect_method);
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
        $this->assertSame(0, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $o->id)->count());
    }

    public function test_the_lock_taken_by_a_confirmation_expires_by_itself_after_thirty_seconds(): void
    {
        [$stop, $qr, $bearer] = $this->road();
        $seen = [];
        $key = $this->lockKey($stop);
        // Pendant la remise officielle (verrou tenu) : il est bien tenu, puis il expire tout seul si le processus meurt.
        Event::listen(SendOrderMail::class, function () use (&$seen, $key) {
            if ($seen === []) {
                $seen['held'] = ! Cache::lock($key, 30)->get();
                Carbon::setTestNow(now()->addSeconds(31));
                $free = Cache::lock($key, 30);
                $seen['free_after_31s'] = $free->get();
                $free->release();
            }
        });

        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertOk();

        $this->assertTrue($seen['held'], 'verrou tenu pendant tout le corps');
        $this->assertTrue($seen['free_after_31s'], 'TTL 30 s : un processus mort ne bloque personne');
    }

    public function test_the_recorded_mode_is_the_one_the_order_carries_even_if_another_path_delivered_it_just_before(): void
    {
        [$stop, $qr, $bearer] = $this->road();
        $real = app(OrderService::class);
        // L'ancien écran livre en ESPÈCES entre nos contrôles et notre appel : OrderService renvoie sans rien refaire (statut déjà atteint).
        $this->mock(OrderService::class, fn ($m) => $m->shouldReceive('deliveryBoyOrderChangeStatus')->andReturnUsing(
            fn ($order, $req, $declared) => $real->deliveryBoyOrderChangeStatus($order, $req, null)
        ));
        $this->actingAs($this->courier, 'sanctum');

        $r = $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertOk();

        $this->assertSame('cash', $r->json('data.declared_mode'), 'le mode enregistré est celui de la commande, pas de la requête');
        $this->assertSame('cash', $stop->fresh()->collect_method);
        $this->assertSame(PosPaymentMethod::CASH, (int) $this->order($stop)->pos_payment_method);
        $this->assertSame('cash', AuditLog::query()->where('action', 'delivery.handover_validated')->sole()->payload['declared_mode']);
    }

    public function test_the_lock_is_released_after_success_and_after_failure(): void
    {
        [$stop, $qr, $bearer] = $this->road();
        $this->mock(OrderService::class, fn ($m) => $m->shouldReceive('deliveryBoyOrderChangeStatus')->andThrow(new \RuntimeException('boom')));
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertStatus(500);
        $free = Cache::lock($this->lockKey($stop), 30);
        $this->assertTrue($free->get(), 'verrou libéré après un échec');
        $free->release();

        $this->app->forgetInstance(OrderService::class);
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertOk();
        $this->assertTrue(Cache::lock($this->lockKey($stop), 30)->get(), 'verrou libéré après un succès');
    }

    public function test_a_failure_inside_the_official_handover_burns_nothing_and_a_retry_with_another_mode_is_recorded(): void
    {
        [$stop, $qr, $bearer] = $this->road();
        $this->mock(OrderService::class, fn ($m) => $m->shouldReceive('deliveryBoyOrderChangeStatus')->andThrow(new \RuntimeException('boom')));
        $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertStatus(500);

        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $this->order($stop)->status);
        $this->assertNull($stop->fresh()->delivered_at);
        $t = $this->tok();
        $this->assertNull($t->used_at);
        $this->assertNull($t->declared_mode);
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.handover_validated')->count());

        // (d) puis une 2ᵉ requête (un jumeau) réussit : l'arrêt porte le VRAI montant et le VRAI mode.
        $this->app->forgetInstance(OrderService::class);
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertOk();
        $s = $stop->fresh();
        $this->assertSame('cash', $s->collect_method);
        $this->assertSame(2750, (int) $s->amount_collected_cents);
        $this->assertSame(2750, (int) $s->amount_due_cents);
        $this->assertSame('cash', $this->tok()->declared_mode);
    }

    public function test_after_a_refusal_the_courier_can_retry_with_another_mode(): void
    {
        [$stop, $qr, $bearer] = $this->road(['pos_payment_method' => PosPaymentMethod::CASH]);

        $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertStatus(422); // contredit le pos_payment_method posé
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertOk();

        $this->assertSame('cash', $this->tok()->declared_mode);
        $this->assertSame(OrderStatus::DELIVERED, (int) $this->order($stop)->status);
    }

    public function test_no_customer_notification_leaves_when_the_handover_fails(): void
    {
        Event::fake([SendOrderMail::class, SendOrderSms::class, SendOrderPush::class]);
        [$stop, $qr, $bearer] = $this->road(['pos_payment_method' => PosPaymentMethod::CASH]);

        $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertStatus(422);
        $this->mock(OrderService::class, fn ($m) => $m->shouldReceive('deliveryBoyOrderChangeStatus')->andThrow(new \RuntimeException('boom')));
        $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertStatus(500);

        Event::assertNotDispatched(SendOrderMail::class);
        Event::assertNotDispatched(SendOrderSms::class);
        Event::assertNotDispatched(SendOrderPush::class);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $this->order($stop)->status);
    }

    public function test_a_twin_arriving_during_the_official_handover_gets_409_and_causes_no_duplicate_effect(): void
    {
        $count = ['mail' => 0, 'sms' => 0, 'push' => 0, 'status' => 0];
        Event::listen(SendOrderMail::class, function () use (&$count) { $count['mail']++; });
        Event::listen(SendOrderSms::class, function () use (&$count) { $count['sms']++; });
        Event::listen(SendOrderPush::class, function () use (&$count) { $count['push']++; });
        Event::listen(OrderStatusChanged::class, function () use (&$count) { $count['status']++; });
        [$stop, $qr, $bearer] = $this->road();
        $twin = null;
        $svc = app(DeliveryHandoverService::class);
        $courier = $this->courier;
        Event::listen(SendOrderMail::class, function () use (&$twin, $svc, $courier, $qr) {
            if ($twin === null) {
                $twin = 'running';
                try {
                    $svc->confirm($courier, $qr, ['declared_mode' => 'cash', 'collected_cents' => 2750]);
                    $twin = 'accepted';
                } catch (HandoverRefused $e) {
                    $twin = $e->errorCode;
                }
            }
        });
        // NB : le verrou est TENU pendant l'appel d'OrderService, donc la jumelle (même processus de test, autre « appui ») ne le prend pas.
        $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertOk();

        $this->assertSame('in_progress', $twin, 'la jumelle est refusée (409), elle ne rappelle pas OrderService');
        $this->assertSame(['mail' => 1, 'sms' => 1, 'push' => 1, 'status' => 1], $count, 'aucun doublon de notification ni d\'événement');
        $o = $this->order($stop);
        $this->assertSame(PosPaymentMethod::CARD, (int) $o->pos_payment_method);
        $this->assertSame(1, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $o->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
        $this->assertSame(1, DeliveryHandoverToken::query()->withoutGlobalScopes()->whereNotNull('used_at')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.handover_validated')->count());
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->where('branch_id', $this->branch->id)->whereNotNull('fiscal_sequence_no')->count());
    }

    public function test_stop_update_failure_after_a_successful_handover_is_completed_from_the_order_not_from_the_request(): void
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
        $this->assertSame(OrderStatus::DELIVERED, (int) $o->status);
        $fiscal = (int) $o->fiscal_sequence_no;
        $this->assertGreaterThan(0, $fiscal);
        $this->assertNull($this->tok()->used_at);
        $this->assertNull($stop->fresh()->delivered_at);
        $this->confirm($this->handoverLogin('livreur.b@test.fr'), $qr, ['declared_mode' => 'cash'])->assertStatus(404);

        // 2ᵉ appui : le livreur redemande « espèces » ; la commande, elle, a été payée par CARTE : c'est la commande qui fait foi.
        $r = $this->confirm($bearer, $qr, ['declared_mode' => 'cash'])->assertOk();

        $this->assertFalse($r->json('already'));
        $this->assertSame('card', $r->json('data.declared_mode'));
        $this->assertSame($fiscal, (int) $this->order($stop)->fiscal_sequence_no);
        $this->assertSame(1, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $stop->order_id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
        $this->assertSame('card', $stop->fresh()->collect_method);
        $this->assertSame(2750, (int) $stop->fresh()->amount_collected_cents);
        $this->assertSame('card', $this->tok()->declared_mode);
        $this->assertTrue((bool) $this->tok()->mode_mismatch);
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.handover_validated')->count());
        $this->assertSame(1, $mails, 'une seule notification « livrée »');
    }

    public function test_an_order_delivered_in_cash_by_the_legacy_path_is_recorded_as_cash_when_the_qr_is_pressed_after(): void
    {
        $mails = 0;
        [$stop, $qr, $bearer] = $this->road();
        // Remise par l'ancienne voie (OrderService classique, sans passer par l'écran d'arrêt) : espèces, escrow, numéro fiscal.
        $this->actingAs($this->courier, 'sanctum');
        app(OrderService::class)->deliveryBoyOrderChangeStatus($this->order($stop), Request::create('', 'POST', ['status' => OrderStatus::DELIVERED]));
        Event::listen(SendOrderMail::class, function () use (&$mails) { $mails++; });
        $fiscal = (int) $this->order($stop)->fiscal_sequence_no;
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());

        $r = $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertOk();

        $this->assertSame('cash', $r->json('data.declared_mode'), 'la commande est « espèces » : ni la requête ni un état intermédiaire ne décident');
        $this->assertFalse((bool) $r->json('data.mode_mismatch'));
        $this->assertSame('cash', $stop->fresh()->collect_method);
        $this->assertSame(2750, (int) $stop->fresh()->amount_collected_cents);
        $p = AuditLog::query()->where('action', 'delivery.handover_validated')->sole()->payload;
        $this->assertSame('cash', $p['declared_mode']);
        $this->assertSame($fiscal, (int) $this->order($stop)->fiscal_sequence_no);
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count(), 'rien n\'est rejoué');
        $this->assertSame(0, $mails, 'OrderService n\'a pas été rappelé : aucune notification de plus');
    }

    public function test_cash_is_still_recognised_through_the_cash_movement_when_the_escrow_audit_was_lost(): void
    {
        [$stop, $qr, $bearer] = $this->road();
        // L'audit d'escrow est écrit « au mieux » après le commit d'OrderService : ici il est PERDU (panne).
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function write(array $data): AuditLog
            {
                if (($data['action'] ?? '') === 'delivery.cash_collected_escrow') {
                    throw new \RuntimeException('audit perdu');
                }

                return parent::write($data);
            }
        });
        $this->actingAs($this->courier, 'sanctum');
        app(OrderService::class)->deliveryBoyOrderChangeStatus($this->order($stop), Request::create('', 'POST', ['status' => OrderStatus::DELIVERED]));
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count(), 'pas de trace d\'audit');
        $this->assertSame(1, DeliveryBoyCashMovement::query()->withoutGlobalScopes()->where('order_id', $stop->order_id)->count(), 'mais une ligne de caisse livreur');

        $r = $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertOk();

        $this->assertSame('cash', $r->json('data.declared_mode'));
        $this->assertSame('cash', $stop->fresh()->collect_method);
        $this->assertSame(2750, (int) $stop->fresh()->amount_collected_cents, '27,50 € d\'espèces ne retombent pas à 0 €');
    }

    public function test_the_answer_flags_a_mismatch_between_the_client_choice_and_what_was_collected(): void
    {
        [, $qr, $bearer] = $this->road();

        $r = $this->confirm($bearer, $qr, ['declared_mode' => 'card'])->assertOk();

        $this->assertTrue($r->json('data.mode_mismatch'));
        $this->assertSame('card', $r->json('data.declared_mode'));
        $this->assertSame('cash', $r->json('data.chosen_mode'));
    }

    // ── relecture C : écart de départ ────────────────────────────────────────────────────────────

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
