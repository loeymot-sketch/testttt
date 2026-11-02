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
use App\Models\User;
use App\Services\Delivery\DoorPaymentSplit;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [LIVREUR CAISSE 2026-11-02] Règlement d'une livraison à la porte en PLUSIEURS moyens (espèces / carte / titre-restaurant),
 * par l'écran /livreur (`deliver`) ET par le QR de remise (`confirm`).
 *
 * Invariants : le total est celui de la COMMANDE (relu côté serveur) ; une ligne `order_payments` par tranche ; seules les espèces entrent
 * dans la caisse du livreur ; PAYÉ + numéro fiscal dans la même transaction ; un rejeu n'écrit rien de plus ; un refus n'écrit RIEN.
 * Sans `payments`, le chemin historique est strictement inchangé.
 *
 * @group delivery
 */
class DeliveryDoorSplitTest extends TestCase
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

    // ── utilitaires ─────────────────────────────────────────────────────────────────────────────

    private function road(array $order = [], ?User $courier = null): DeliveryStop
    {
        return $this->departed($this->delivery($order, $courier));
    }

    /** @return \Illuminate\Testing\TestResponse */
    private function deliver(DeliveryStop $stop, array $body = [], ?User $as = null, ?string $idem = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->courier, 'sanctum')
            ->withHeaders(['x-api-key' => 'test-api-key', 'X-Idempotency-Key' => $idem ?? (string) Str::uuid(), 'Accept' => 'application/json'])
            ->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver", $body);
    }

    private function order(DeliveryStop $s): Order
    {
        return Order::query()->withoutGlobalScopes()->findOrFail($s->order_id);
    }

    /** @return \Illuminate\Support\Collection<int, OrderPayment> */
    private function payments(DeliveryStop $s)
    {
        return OrderPayment::query()->withoutGlobalScopes()->where('order_id', $s->order_id)->orderBy('id')->get();
    }

    private function movements(?DeliveryStop $s = null)
    {
        return DeliveryBoyCashMovement::query()->withoutGlobalScopes()
            ->when($s, fn ($q) => $q->where('order_id', $s->order_id))->orderBy('id')->get();
    }

    private function audits(string $action, ?DeliveryStop $s = null)
    {
        return AuditLog::query()->where('action', $action)->orderBy('id')->get()
            ->filter(fn ($a) => $s === null || (int) ($a->payload['order_id'] ?? 0) === (int) $s->order_id)->values();
    }

    /** Rien n'a été écrit : commande toujours en route et non payée, aucun paiement, aucun mouvement, aucune trace d'encaissement. */
    private function assertNothingWritten(DeliveryStop $s): void
    {
        $o = $this->order($s);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $o->status);
        $this->assertSame(PaymentStatus::UNPAID, (int) $o->payment_status);
        $this->assertNull($o->fiscal_sequence_no, 'aucun numéro fiscal alloué');
        $this->assertCount(0, $this->payments($s));
        $this->assertCount(0, $this->movements());
        $this->assertCount(0, $this->audits('delivery.cash_collected_escrow'));
        $this->assertCount(0, $this->audits('order.payment_tranche_persisted'));
        $this->assertNull($s->fresh()->delivered_at);
    }

    // ── (a) un seul mode : strictement inchangé ──────────────────────────────────────────────────

    public function test_without_payments_the_historic_single_mode_path_is_unchanged(): void
    {
        $stop = $this->road();

        $this->deliver($stop, ['collected_cents' => 2750])->assertOk();

        $o = $this->order($stop);
        $this->assertSame(OrderStatus::DELIVERED, (int) $o->status);
        $this->assertSame(PaymentStatus::PAID, (int) $o->payment_status);
        $this->assertSame(PosPaymentMethod::CASH, (int) $o->pos_payment_method);
        $this->assertNotNull($o->fiscal_sequence_no);
        $this->assertCount(0, $this->payments($stop), 'espèces historiques : aucune ligne order_payments (comme avant)');
        $m = $this->movements($stop);
        $this->assertCount(1, $m);
        $this->assertEquals(27.50, (float) $m[0]->amount);
        $this->assertCount(1, $this->audits('delivery.cash_collected_escrow', $stop));
        $this->assertCount(0, $this->audits('order.payment_tranche_persisted'));
        $this->assertSame('cash', $stop->fresh()->collect_method);
    }

    public function test_without_payments_a_card_at_door_delivery_is_unchanged_and_opens_the_shift(): void
    {
        $stop = $this->road(['door_payment_mode' => 'card']);

        $this->deliver($stop, ['collected_cents' => 2750])->assertOk();

        $o = $this->order($stop);
        $this->assertSame(PosPaymentMethod::CARD, (int) $o->pos_payment_method);
        $p = $this->payments($stop);
        $this->assertCount(1, $p);
        $this->assertSame(PosPaymentMethod::CARD, (int) $p[0]->mode);
        $this->assertCount(0, $this->movements(), 'une carte n\'entre jamais dans la sacoche d\'espèces');
        $this->assertCount(1, $this->audits('order.payment_tranche_persisted', $stop));
        // Nouveau : la carte encaissée ouvre le service du livreur (fond 0 €), pour que « Ma caisse » la voie.
        $this->assertDatabaseHas('delivery_boy_cash_sessions', ['delivery_boy_id' => $this->courier->id, 'status' => 'open', 'opening_amount' => 0]);
    }

    // ── (b) 1, 2 et 3 tranches ───────────────────────────────────────────────────────────────────

    public function test_cash_plus_card_writes_one_row_per_tranche_and_only_the_cash_enters_the_courier_cash(): void
    {
        $stop = $this->road();

        $r = $this->deliver($stop, ['payments' => [['mode' => 'card', 'amount' => 17.5], ['mode' => 'cash', 'amount' => 10]]]);

        $r->assertOk()->assertJsonPath('data.door_payments.0.mode', 'cash')->assertJsonPath('data.door_payments.1.mode', 'card');
        $o = $this->order($stop);
        $this->assertSame(OrderStatus::DELIVERED, (int) $o->status);
        $this->assertSame(PaymentStatus::PAID, (int) $o->payment_status);
        $this->assertNotNull($o->fiscal_sequence_no, 'numéro fiscal alloué à la remise, comme avant');
        $this->assertSame(PosPaymentMethod::CARD, (int) $o->pos_payment_method, 'moyen dominant (17,50 € de carte contre 10 € d\'espèces)');

        $p = $this->payments($stop);
        $this->assertCount(2, $p);
        $this->assertSame([PosPaymentMethod::CASH, PosPaymentMethod::CARD], $p->map(fn ($x) => (int) $x->mode)->all(), 'ordre canonique espèces, carte');
        $this->assertEquals([10.0, 17.5], $p->map(fn ($x) => (float) $x->amount)->all());
        $this->assertSame(['livraison', 'livraison'], $p->pluck('reference')->all());
        $this->assertEquals(27.5, round($p->sum('amount'), 2));

        $m = $this->movements();
        $this->assertCount(1, $m, 'UN seul mouvement : les espèces');
        $this->assertEquals(10.0, (float) $m[0]->amount);
        $this->assertSame('in', $m[0]->direction);

        $esc = $this->audits('delivery.cash_collected_escrow', $stop);
        $this->assertCount(1, $esc);
        $this->assertEquals(10.0, (float) $esc[0]->payload['amount_collected']);
        $this->assertTrue($esc[0]->payload['declared_differs']);
        $this->assertSame('cash', $esc[0]->payload['chosen_mode']);
        $tr = $this->audits('order.payment_tranche_persisted', $stop);
        $this->assertCount(1, $tr, 'carte : une trace de tranche ; les espèces ont leur trace d\'escrow');
        $this->assertSame(PosPaymentMethod::CARD, (int) $tr[0]->payload['mode']);
        $this->assertSame(1, (int) $tr[0]->payload['tranche_index']);
        $this->assertEquals(17.5, (float) $tr[0]->payload['amount']);
        $this->assertTrue($tr[0]->payload['declared_differs']);
        $this->assertSame('doorstep_card_collection', $tr[0]->payload['event']);

        $stop = $stop->fresh();
        $this->assertSame('split', $stop->collect_method);
        $this->assertSame(2750, (int) $stop->amount_collected_cents);
        $this->assertNotNull($stop->delivered_at);
    }

    public function test_three_means_cash_card_and_meal_voucher(): void
    {
        $stop = $this->road();

        $this->deliver($stop, ['payments' => [
            ['mode' => 'meal_voucher', 'amount' => 9.75], ['mode' => 'cash', 'amount' => 5], ['mode' => 'card', 'amount' => 12.75],
        ]])->assertOk();

        $p = $this->payments($stop);
        $this->assertSame([PosPaymentMethod::CASH, PosPaymentMethod::CARD, PosPaymentMethod::TICKET_RESTAURANT], $p->map(fn ($x) => (int) $x->mode)->all());
        $this->assertEquals([5.0, 12.75, 9.75], $p->map(fn ($x) => (float) $x->amount)->all());
        $this->assertEquals(5.0, (float) $this->movements()[0]->amount);
        $this->assertCount(1, $this->movements());
        $tr = $this->audits('order.payment_tranche_persisted', $stop);
        $this->assertSame([1, 2], $tr->map(fn ($a) => (int) $a->payload['tranche_index'])->all(), 'index croissants, alignés sur les lignes order_payments');
        $this->assertSame(['doorstep_card_collection', 'doorstep_meal_voucher_collection'], $tr->map(fn ($a) => $a->payload['event'])->all());
        $this->assertSame(PosPaymentMethod::CARD, (int) $this->order($stop)->pos_payment_method);
        $this->assertCount(1, $this->audits('delivery.cash_collected_escrow', $stop));
    }

    public function test_a_single_means_matching_the_customer_choice_is_not_flagged_but_a_different_one_is(): void
    {
        $same = $this->road();
        $this->deliver($same, ['payments' => [['mode' => 'cash', 'amount' => 27.5]]])->assertOk();
        $this->assertFalse($this->audits('delivery.cash_collected_escrow', $same)[0]->payload['declared_differs']);
        $this->assertSame('cash', $same->fresh()->collect_method);

        $other = $this->road();
        $this->deliver($other, ['payments' => [['mode' => 'meal_voucher', 'amount' => 27.5]]])->assertOk();
        $t = $this->audits('order.payment_tranche_persisted', $other);
        $this->assertCount(1, $t);
        $this->assertTrue($t[0]->payload['declared_differs'], 'le client avait choisi les espèces : on accepte le titre-restaurant mais on le trace');
        $this->assertSame(PosPaymentMethod::TICKET_RESTAURANT, (int) $this->order($other)->pos_payment_method);
        $this->assertCount(1, $this->movements($same), 'seules les espèces de la première commande sont en caisse');
        $this->assertCount(0, $this->movements($other));
    }

    public function test_a_meal_voucher_order_can_be_settled_in_several_means(): void
    {
        $stop = $this->road(['payment_method' => PaymentGateway::TICKET_RESTAURANT]);

        $this->deliver($stop, ['payments' => [['mode' => 'meal_voucher', 'amount' => 20], ['mode' => 'cash', 'amount' => 7.5]]])->assertOk();

        $this->assertSame(PosPaymentMethod::TICKET_RESTAURANT, (int) $this->order($stop)->pos_payment_method);
        $this->assertCount(2, $this->payments($stop));
        $this->assertEquals(7.5, (float) $this->movements()[0]->amount);
    }

    // ── (c) refus : 422 en français et RIEN d'écrit ──────────────────────────────────────────────

    /** @return array<string, array{0: array, 1: string}> */
    public static function badPayments(): array
    {
        return [
            'somme trop faible'      => [[['mode' => 'cash', 'amount' => 10], ['mode' => 'card', 'amount' => 17]], 'doit être exactement le total'],
            'somme trop forte'       => [[['mode' => 'cash', 'amount' => 10], ['mode' => 'card', 'amount' => 18]], 'doit être exactement le total'],
            'un centime de moins'    => [[['mode' => 'cash', 'amount' => 27.49]], 'doit être exactement le total'],
            'doublon de moyen'       => [[['mode' => 'cash', 'amount' => 10], ['mode' => 'cash', 'amount' => 17.5]], 'indiqué deux fois'],
            'montant nul'            => [[['mode' => 'cash', 'amount' => 0], ['mode' => 'card', 'amount' => 27.5]], 'au moins 0,01'],
            'montant négatif'        => [[['mode' => 'cash', 'amount' => -5], ['mode' => 'card', 'amount' => 32.5]], 'Montant invalide'],
            'moyen inconnu'          => [[['mode' => 'cheque', 'amount' => 27.5]], 'Moyen de paiement inconnu'],
            'trois décimales'        => [[['mode' => 'cash', 'amount' => 27.505]], 'au centime'],
            'montant texte'          => [[['mode' => 'cash', 'amount' => 'vingt']], 'Montant illisible'],
            'montant absent'         => [[['mode' => 'cash']], 'Montant illisible'],
            'liste vide'             => [[], 'au moins un moyen'],
            'cinq tranches'          => [[['mode' => 'cash', 'amount' => 1], ['mode' => 'card', 'amount' => 1], ['mode' => 'meal_voucher', 'amount' => 1], ['mode' => 'cash', 'amount' => 1], ['mode' => 'card', 'amount' => 23.5]], 'Au plus 4'],
            'pas une liste'          => [['cash' => 27.5], 'au moins un moyen'],
        ];
    }

    /** @dataProvider badPayments */
    public function test_an_invalid_split_is_refused_in_french_and_nothing_is_written(array $payments, string $needle): void
    {
        $stop = $this->road();

        $r = $this->deliver($stop, ['payments' => $payments]);

        $r->assertStatus(422);
        $this->assertStringContainsString($needle, (string) $r->json('message'));
        $this->assertNothingWritten($stop);
    }

    public function test_the_client_amount_never_counts_the_server_total_does(): void
    {
        $stop = $this->road(['total' => 27.50]);
        // Le téléphone prétend 20 € dus : la commande vaut 27,50 € en base, c'est elle qui fait foi.
        $this->deliver($stop, ['collected_cents' => 2000, 'payments' => [['mode' => 'cash', 'amount' => 20]]])->assertStatus(422);
        $this->assertNothingWritten($stop);

        $this->deliver($stop, ['collected_cents' => 1, 'payments' => [['mode' => 'cash', 'amount' => 27.5]]])->assertOk();
        $this->assertSame(2750, (int) $stop->fresh()->amount_collected_cents, 'le montant encaissé est la somme vérifiée des tranches');
    }

    public function test_an_order_already_paid_online_refuses_a_split_and_stays_untouched(): void
    {
        $stop = $this->road(['payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD, 'fiscal_sequence_no' => null]);

        $r = $this->deliver($stop, ['payments' => [['mode' => 'cash', 'amount' => 27.5]]]);

        $r->assertStatus(422);
        $this->assertStringContainsString('déjà payée', (string) $r->json('message'));
        $this->assertCount(0, $this->payments($stop));
        $this->assertCount(0, $this->movements());
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $this->order($stop)->status);
        $this->assertNull($stop->fresh()->delivered_at);
    }

    public function test_a_non_door_settleable_order_is_refused_by_the_official_path_too(): void
    {
        // Carte en ligne jamais payée : le serveur refuse même si l'écran a laissé passer (défense en profondeur dans OrderService).
        $stop = $this->road(['payment_method' => PaymentGateway::CARD]);

        $this->deliver($stop, ['payments' => [['mode' => 'cash', 'amount' => 27.5]]])->assertStatus(422);

        $this->assertNothingWritten($stop);
    }

    public function test_order_service_itself_refuses_a_split_with_a_declared_mode(): void
    {
        $stop = $this->road();
        $this->actingAs($this->courier, 'sanctum');

        try {
            app(OrderService::class)->deliveryBoyOrderChangeStatus(
                $this->order($stop), \Illuminate\Http\Request::create('', 'POST', ['status' => OrderStatus::DELIVERED]), 'cash', [['mode' => 'cash', 'amount' => 27.5]]
            );
            $this->fail('un mode unique ET plusieurs moyens ne se combinent pas');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertNothingWritten($stop);
    }

    // ── (d) rejeu et course ──────────────────────────────────────────────────────────────────────

    public function test_a_replay_with_the_same_idempotency_key_duplicates_nothing(): void
    {
        $stop = $this->road();
        $key = (string) Str::uuid();
        $body = ['payments' => [['mode' => 'cash', 'amount' => 10], ['mode' => 'card', 'amount' => 17.5]]];

        $this->deliver($stop, $body, null, $key)->assertOk();
        $fiscal = $this->order($stop)->fiscal_sequence_no;
        $this->deliver($stop, $body, null, $key); // même clé : réponse rejouée

        $this->assertCount(2, $this->payments($stop));
        $this->assertCount(1, $this->movements());
        $this->assertCount(1, $this->audits('delivery.cash_collected_escrow', $stop));
        $this->assertCount(1, $this->audits('order.payment_tranche_persisted', $stop));
        $this->assertSame($fiscal, $this->order($stop)->fiscal_sequence_no);
    }

    public function test_a_second_tap_with_a_new_key_is_refused_and_duplicates_nothing(): void
    {
        $stop = $this->road();
        $body = ['payments' => [['mode' => 'cash', 'amount' => 10], ['mode' => 'meal_voucher', 'amount' => 17.5]]];

        $this->deliver($stop, $body)->assertOk();
        $fiscal = $this->order($stop)->fiscal_sequence_no;
        $this->deliver($stop, $body)->assertStatus(409);

        $this->assertCount(2, $this->payments($stop));
        $this->assertCount(1, $this->movements());
        $this->assertCount(1, $this->audits('delivery.cash_collected_escrow', $stop));
        $this->assertCount(1, $this->audits('order.payment_tranche_persisted', $stop));
        $this->assertSame($fiscal, $this->order($stop)->fiscal_sequence_no);
    }

    public function test_a_twin_process_calling_the_official_path_after_the_first_writes_nothing_more(): void
    {
        $stop = $this->road();
        $this->actingAs($this->courier, 'sanctum');
        $payments = [['mode' => 'cash', 'amount' => 10], ['mode' => 'card', 'amount' => 17.5]];
        $request = fn () => \Illuminate\Http\Request::create('', 'POST', ['status' => OrderStatus::DELIVERED]);
        $stale = $this->order($stop); // le jumeau a lu la commande AVANT la fin du premier

        app(OrderService::class)->deliveryBoyOrderChangeStatus($this->order($stop), $request(), null, $payments);
        app(OrderService::class)->deliveryBoyOrderChangeStatus($stale, $request(), null, $payments);

        $this->assertCount(2, $this->payments($stop));
        $this->assertCount(1, $this->movements());
        $this->assertCount(1, $this->audits('delivery.cash_collected_escrow', $stop));
        $this->assertCount(1, $this->audits('order.payment_tranche_persisted', $stop));
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->whereNotNull('fiscal_sequence_no')->count());
    }

    // ── (e) isolation ────────────────────────────────────────────────────────────────────────────

    public function test_another_courier_cannot_settle_my_stop(): void
    {
        $stop = $this->road();

        $this->deliver($stop, ['payments' => [['mode' => 'cash', 'amount' => 27.5]]], $this->otherCourier)->assertStatus(403);

        $this->assertNothingWritten($stop);
    }

    // ── QR de remise ─────────────────────────────────────────────────────────────────────────────

    private function qr(array $order = []): array
    {
        $stop = $this->road($order);

        return [$stop, $this->qrToken($stop), $this->handoverLogin('livreur.a@test.fr')];
    }

    private function confirm(string $bearer, string $qr, array $extra = [])
    {
        return $this->withHeaders($this->bearer($bearer))->postJson('/api/frontend/delivery-handover/confirm', ['token' => $qr] + $extra);
    }

    public function test_qr_confirm_accepts_a_split_and_traces_it(): void
    {
        [$stop, $qr, $bearer] = $this->qr();

        $r = $this->confirm($bearer, $qr, ['payments' => [['mode' => 'cash', 'amount' => 10], ['mode' => 'card', 'amount' => 10], ['mode' => 'meal_voucher', 'amount' => 7.5]]]);

        $r->assertOk()->assertJsonPath('data.declared_mode', 'split')->assertJsonPath('data.mode_mismatch', true)->assertJsonPath('data.chosen_mode', 'cash');
        $this->assertCount(3, $this->payments($stop));
        $this->assertEquals(10.0, (float) $this->movements()[0]->amount);
        $this->assertCount(1, $this->movements());
        $tok = DeliveryHandoverToken::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertNotNull($tok->used_at);
        $this->assertSame('split', $tok->declared_mode);
        $this->assertTrue($tok->mode_mismatch);
        $v = $this->audits('delivery.handover_validated')[0]->payload;
        $this->assertSame('split', $v['declared_mode']);
        $this->assertTrue($v['declared_differs']);
        $this->assertSame('cash', $v['chosen_mode']);
        $this->assertSame([['mode' => 'cash', 'amount_cents' => 1000], ['mode' => 'card', 'amount_cents' => 1000], ['mode' => 'meal_voucher', 'amount_cents' => 750]], $v['payments']);
        $this->assertCount(2, $this->audits('order.payment_tranche_persisted', $stop));
        $this->assertSame(OrderStatus::DELIVERED, (int) $this->order($stop)->status);
        $this->assertNotNull($this->order($stop)->fiscal_sequence_no);
    }

    public function test_qr_split_replay_and_busy_twin_duplicate_nothing(): void
    {
        [$stop, $qr, $bearer] = $this->qr();
        $payments = [['mode' => 'cash', 'amount' => 10], ['mode' => 'card', 'amount' => 17.5]];

        // Un jumeau tient le verrou : 409, et l'encaissement officiel n'est PAS rappelé.
        $other = Cache::lock(\App\Services\Delivery\DeliveryHandoverService::lockKey((int) $stop->id), 30);
        $this->assertTrue($other->get());
        $this->confirm($bearer, $qr, ['payments' => $payments])->assertStatus(409)->assertJsonPath('code', 'in_progress');
        $this->assertNothingWritten($stop);
        $other->release();

        $this->confirm($bearer, $qr, ['payments' => $payments])->assertOk()->assertJsonPath('already', false);
        $fiscal = $this->order($stop)->fiscal_sequence_no;
        $this->confirm($bearer, $qr, ['payments' => $payments])->assertOk()->assertJsonPath('already', true);

        $this->assertCount(2, $this->payments($stop));
        $this->assertCount(1, $this->movements());
        $this->assertCount(1, $this->audits('delivery.cash_collected_escrow', $stop));
        $this->assertSame($fiscal, $this->order($stop)->fiscal_sequence_no);
        $this->assertCount(1, $this->audits('delivery.handover_validated'));
    }

    public function test_qr_split_refusals_write_nothing_and_leave_the_qr_alive(): void
    {
        [$stop, $qr, $bearer] = $this->qr();

        $r = $this->confirm($bearer, $qr, ['payments' => [['mode' => 'cash', 'amount' => 10], ['mode' => 'card', 'amount' => 10]]]);
        $r->assertStatus(422)->assertJsonPath('code', 'wrong_split');
        $this->assertStringContainsString('doit être exactement le total', (string) $r->json('message'));

        $this->confirm($bearer, $qr, ['payments' => [['mode' => 'cash', 'amount' => 27.5]], 'declared_mode' => 'cash'])->assertStatus(422)->assertJsonPath('code', 'mode_conflict');
        $this->confirm($bearer, $qr, ['payments' => [['mode' => 'cash', 'amount' => 0], ['mode' => 'card', 'amount' => 27.5]]])->assertStatus(422);

        $this->assertNothingWritten($stop);
        $this->assertNull(DeliveryHandoverToken::query()->withoutGlobalScopes()->firstOrFail()->used_at, 'le QR reste utilisable après un refus');
    }

    public function test_qr_split_on_an_order_paid_online_is_refused(): void
    {
        [$stop, $qr, $bearer] = $this->qr(['payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD]);

        $this->confirm($bearer, $qr, ['payments' => [['mode' => 'cash', 'amount' => 27.5]]])->assertStatus(422)->assertJsonPath('code', 'mode_forbidden');

        $this->assertCount(0, $this->payments($stop));
        $this->assertCount(0, $this->movements());
        $this->assertNull(DeliveryHandoverToken::query()->withoutGlobalScopes()->firstOrFail()->used_at);
    }

    public function test_qr_resolve_announces_that_a_split_is_possible(): void
    {
        [, $qr, $bearer] = $this->qr();

        $this->withHeaders($this->bearer($bearer))->postJson('/api/frontend/delivery-handover/resolve', ['token' => $qr])
            ->assertOk()->assertJsonPath('data.split_allowed', true)->assertJsonPath('data.amount_due_cents', 2750);
    }

    // ── le service de normalisation, seul ───────────────────────────────────────────────────────

    public function test_normalize_returns_canonical_cents_and_accepts_exact_decimal_strings(): void
    {
        $out = DoorPaymentSplit::normalize([['mode' => 'card', 'amount' => '17.50'], ['mode' => 'cash', 'amount' => 10]], 2750);
        $this->assertSame([['mode' => 'cash', 'cents' => 1000], ['mode' => 'card', 'cents' => 1750]], $out);
        $this->assertSame('cash', DoorPaymentSplit::dominant([['mode' => 'cash', 'cents' => 1000], ['mode' => 'card', 'cents' => 1000]]), 'égalité : espèces d\'abord');
        $this->assertSame('card', DoorPaymentSplit::dominant($out));
    }
}
