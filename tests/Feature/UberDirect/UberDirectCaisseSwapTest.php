<?php

namespace Tests\Feature\UberDirect;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Events\OrderStatusChanged;
use App\Listeners\ReleaseDeliveryStopOnOrderClosed;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\UberDirectDelivery;
use App\Models\User;
use App\Services\Delivery\DeliveryWaveService;
use App\Services\UberDirect\UberDirectDispatchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [UBER-DIRECT 2026-10-22] La caisse bascule UN arrêt de livraison entre nos livreurs et un coursier Uber.
 *
 * Aucun appel réel n'est émis : Uber est simulé (`Http::fake`). Les corps de requête suivent la documentation publique ;
 * ils seront confrontés au compte réel au premier devis (cf. docs/LIVRAISON.md §6).
 *
 * @group delivery
 * @group uber-direct
 */
class UberDirectCaisseSwapTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cashier;
    private User $kiki;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedSpatieRoles();
        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $this->seedMinimalSettings();
        config([
            'app.api_key' => 'test-api-key',
            'uber_direct.enabled' => true,
            'uber_direct.client_id' => 'cid-test',
            'uber_direct.client_secret' => 'secret-test',
            'uber_direct.customer_id' => 'cust-test',
            'uber_direct.webhook_secret' => 'whsec-test',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-22 19:02:00'));
        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060', 'phone' => '0321000000']);
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
        $this->cashier->givePermissionTo('pos');
        $this->kiki = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE, 'name' => 'Kiki']);
        $this->kiki->assignRole('Delivery Boy');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Uber simulé : jeton, devis, création, annulation, relevé. */
    private function fakeUber(array $over = []): void
    {
        Http::fake(array_merge([
            'auth.uber.com/*' => Http::response(['access_token' => 'jeton', 'expires_in' => 3600]),
            '*/delivery_quotes' => Http::response(['id' => 'dqt_1', 'fee' => 742, 'currency' => 'eur', 'duration' => 25, 'expires' => now()->addMinutes(15)->toIso8601String()]),
            '*/deliveries/del_1/cancel' => Http::response(['id' => 'del_1', 'status' => 'canceled']),
            '*/deliveries/del_1' => Http::response(['id' => 'del_1', 'status' => 'pickup']),
            '*/deliveries' => Http::response(['id' => 'del_1', 'status' => 'pending', 'tracking_url' => 'https://uber.test/track/1']),
        ], $over));
    }

    private function stop(array $order = []): DeliveryStop
    {
        $c = User::factory()->create(['branch_id' => $this->branch->id, 'phone' => '0612345678', 'name' => 'Karim']);
        $o = Order::factory()->create($order + [
            'branch_id' => $this->branch->id, 'user_id' => $c->id, 'order_type' => OrderType::DELIVERY,
            'status' => OrderStatus::PREPARED, 'payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD,
        ]);
        OrderAddress::query()->create(['order_id' => $o->id, 'user_id' => $c->id, 'label' => 'x', 'address' => '10 rue Test', 'latitude' => '50.43', 'longitude' => '2.96']);

        return app(DeliveryWaveService::class)->assign($o->fresh());
    }

    private function pos(string $uri, array $data = [])
    {
        return $this->actingAs($this->cashier, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos-delivery/uber/{$uri}", $data);
    }

    private function sent(string $suffix): int
    {
        return Http::recorded(fn ($req) => str_ends_with(parse_url($req->url(), PHP_URL_PATH), $suffix) && $req->method() === 'POST')->count();
    }

    public function test_quote_gives_the_price_and_delay_and_dispatches_nothing(): void
    {
        $this->fakeUber();
        $stop = $this->stop();

        $r = $this->pos('quote', ['stop_id' => $stop->id])->assertOk();
        $this->assertSame(742, $r->json('fee_cents'));
        $this->assertEquals(7.42, $r->json('fee'));
        $this->assertSame(25, $r->json('eta_minutes'));
        $this->assertSame(0, $this->sent('/deliveries'), 'Un devis ne commande aucune course.');
        $this->assertSame($this->kiki->id, (int) $stop->fresh()->delivery_boy_id, 'L\'arrêt reste à nos livreurs.');
    }

    public function test_nothing_leaves_the_server_while_uber_direct_is_off(): void
    {
        config(['uber_direct.enabled' => false]);
        Http::fake();
        $stop = $this->stop();

        $this->pos('quote', ['stop_id' => $stop->id])->assertStatus(422)->assertJsonPath('message', 'Uber Direct n\'est pas activé.');
        Http::assertNothingSent();
    }

    public function test_an_order_to_collect_at_the_door_never_goes_to_uber(): void
    {
        $this->fakeUber();
        $stop = $this->stop(['payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'total' => 25]);

        $r = $this->pos('quote', ['stop_id' => $stop->id])->assertStatus(422);
        $this->assertStringContainsString('Uber ne peut pas encaisser', $r->json('message'));
        Http::assertNothingSent();
    }

    public function test_confirming_the_quote_hands_the_stop_to_uber_and_frees_our_courier(): void
    {
        $this->fakeUber();
        $stop = $this->stop();
        $quote = $this->pos('quote', ['stop_id' => $stop->id])->json('quote_id');

        $this->pos('dispatch', ['stop_id' => $stop->id, 'quote_id' => $quote])->assertOk()->assertJsonPath('tracking_url', 'https://uber.test/track/1');

        $this->assertSame(UberDirectDispatchService::MODE_UBER, app(UberDirectDispatchService::class)->mode($stop->fresh()));
        $this->assertNull($stop->fresh()->delivery_boy_id, 'Plus de livreur à nous : il disparaît de sa liste.');
        $this->assertNull(Order::find($stop->order_id)->delivery_boy_id);
        $this->assertSame('del_1', UberDirectDelivery::query()->where('order_id', $stop->order_id)->value('delivery_id'));
    }

    public function test_a_double_press_creates_one_course_only(): void
    {
        $this->fakeUber();
        $stop = $this->stop();
        $quote = $this->pos('quote', ['stop_id' => $stop->id])->json('quote_id');

        $this->pos('dispatch', ['stop_id' => $stop->id, 'quote_id' => $quote])->assertOk();
        $this->pos('dispatch', ['stop_id' => $stop->id, 'quote_id' => $quote])->assertOk()->assertJsonPath('already', true);

        $this->assertSame(1, $this->sent('/deliveries'), 'Deux appuis = une seule course facturée.');
    }

    public function test_an_expired_quote_is_never_dispatched_the_new_price_is_asked_again(): void
    {
        $this->fakeUber();
        $stop = $this->stop();
        $quote = $this->pos('quote', ['stop_id' => $stop->id])->json('quote_id');
        UberDirectDelivery::query()->where('order_id', $stop->order_id)->update(['quote_expires_at' => now()->addSeconds(30)]);

        $this->pos('dispatch', ['stop_id' => $stop->id, 'quote_id' => $quote])
            ->assertStatus(409)->assertJsonPath('code', 'quote_changed')->assertJsonPath('quote.fee_cents', 742);
        $this->assertSame(0, $this->sent('/deliveries'), 'Jamais une course à un prix que le caissier n\'a pas confirmé.');
    }

    public function test_a_quote_id_the_cashier_never_saw_is_refused(): void
    {
        $this->fakeUber();
        $stop = $this->stop();
        $this->pos('quote', ['stop_id' => $stop->id])->assertOk();

        $this->pos('dispatch', ['stop_id' => $stop->id, 'quote_id' => 'dqt_inconnu'])->assertStatus(409);
        $this->assertSame(0, $this->sent('/deliveries'));
    }

    public function test_when_uber_does_not_answer_the_order_stays_with_our_courier(): void
    {
        $this->fakeUber(['*/deliveries' => Http::response('boom', 500)]);
        $stop = $this->stop();
        $quote = $this->pos('quote', ['stop_id' => $stop->id])->json('quote_id');

        $this->pos('dispatch', ['stop_id' => $stop->id, 'quote_id' => $quote])->assertStatus(422);

        $this->assertSame($this->kiki->id, (int) $stop->fresh()->delivery_boy_id);
        $this->assertSame(UberDirectDispatchService::MODE_OWN, app(UberDirectDispatchService::class)->mode($stop->fresh()));
    }

    public function test_release_cancels_the_uber_course_and_gives_the_stop_back_to_our_courier(): void
    {
        $this->fakeUber();
        $stop = $this->stop();
        $quote = $this->pos('quote', ['stop_id' => $stop->id])->json('quote_id');
        $this->pos('dispatch', ['stop_id' => $stop->id, 'quote_id' => $quote])->assertOk();

        $this->pos('release', ['stop_id' => $stop->id])->assertOk();

        $this->assertSame(1, $this->sent('/deliveries/del_1/cancel'));
        $this->assertSame(UberDirectDispatchService::MODE_OWN, app(UberDirectDispatchService::class)->mode($stop->fresh()));
        $this->assertSame($this->kiki->id, (int) $stop->fresh()->delivery_boy_id);
        $this->assertSame($this->kiki->id, (int) Order::find($stop->order_id)->delivery_boy_id);
    }

    public function test_release_is_refused_once_the_uber_courier_has_the_bag(): void
    {
        $this->fakeUber();
        $stop = $this->stop();
        $quote = $this->pos('quote', ['stop_id' => $stop->id])->json('quote_id');
        $this->pos('dispatch', ['stop_id' => $stop->id, 'quote_id' => $quote])->assertOk();
        UberDirectDelivery::query()->where('order_id', $stop->order_id)->update(['status' => 'pickup_complete']);

        $this->pos('release', ['stop_id' => $stop->id])->assertStatus(422);
        $this->assertSame(0, $this->sent('/deliveries/del_1/cancel'));
        $this->assertSame(UberDirectDispatchService::MODE_UBER, app(UberDirectDispatchService::class)->mode($stop->fresh()));
    }

    public function test_designating_a_courier_for_the_wave_does_not_take_an_uber_stop_back(): void
    {
        $this->fakeUber();
        $stop = $this->stop();
        $quote = $this->pos('quote', ['stop_id' => $stop->id])->json('quote_id');
        $this->pos('dispatch', ['stop_id' => $stop->id, 'quote_id' => $quote])->assertOk();

        app(DeliveryWaveService::class)->assignCourier((int) $this->branch->id, $stop->wave_at, $this->kiki->id);

        $this->assertNull($stop->fresh()->delivery_boy_id);
    }

    public function test_the_board_says_who_delivers_and_why_uber_is_impossible(): void
    {
        $this->fakeUber();
        $uber = $this->stop();
        $door = $this->stop(['payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'total' => 25]);
        $quote = $this->pos('quote', ['stop_id' => $uber->id])->json('quote_id');
        $this->pos('dispatch', ['stop_id' => $uber->id, 'quote_id' => $quote])->assertOk();

        $stops = collect($this->actingAs($this->cashier, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->getJson('/api/admin/pos-delivery/board')->assertOk()->assertJsonPath('uber_enabled', true)->json('data'))
            ->pluck('stops')->flatten(1)->keyBy('id');

        $this->assertSame('uber', $stops[$uber->id]['dispatch']);
        $this->assertSame('https://uber.test/track/1', $stops[$uber->id]['uber']['tracking_url']);
        $this->assertSame('own', $stops[$door->id]['dispatch']);
        $this->assertStringContainsString('Uber ne peut pas encaisser', $stops[$door->id]['uber_blocked']);
    }

    // ── webhook ─────────────────────────────────────────────────────────────────────────────

    private function webhook(array $body, ?string $secret = 'whsec-test', ?string $signature = null)
    {
        $raw = json_encode($body);
        $sig = $signature ?? hash_hmac('sha256', $raw, (string) $secret);

        return $this->call('POST', '/api/webhooks/uber-direct', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_UBER_SIGNATURE' => $sig,
        ], $raw);
    }

    private function dispatched(array $over = []): DeliveryStop
    {
        $this->fakeUber($over);
        $stop = $this->stop();
        $quote = $this->pos('quote', ['stop_id' => $stop->id])->json('quote_id');
        $this->pos('dispatch', ['stop_id' => $stop->id, 'quote_id' => $quote])->assertOk();

        return $stop->fresh();
    }

    public function test_the_webhook_refuses_a_bad_or_missing_signature_and_a_missing_secret(): void
    {
        $stop = $this->dispatched();
        $body = ['kind' => 'event.delivery_status', 'delivery_id' => 'del_1', 'status' => 'delivered'];

        $this->webhook($body, signature: 'deadbeef')->assertStatus(401);
        $this->webhook($body, 'mauvaise-cle')->assertStatus(401);
        config(['uber_direct.webhook_secret' => '']);
        $this->webhook($body, '')->assertStatus(401);

        $this->assertNull($stop->fresh()->delivered_at, 'Une requête non signée ne clôture jamais une commande.');
    }

    public function test_a_signed_delivered_event_closes_the_stop_and_the_order_and_a_replay_does_nothing(): void
    {
        $stop = $this->dispatched();
        $body = ['kind' => 'event.delivery_status', 'delivery_id' => 'del_1', 'status' => 'delivered', 'data' => ['id' => 'del_1', 'status' => 'delivered']];

        $this->webhook($body)->assertOk();
        $this->webhook($body)->assertOk();

        $this->assertNotNull($stop->fresh()->delivered_at);
        $this->assertSame(OrderStatus::DELIVERED, (int) Order::find($stop->order_id)->status);
        $this->assertSame(0, (int) $stop->fresh()->amount_collected_cents, 'Payée d\'avance : rien encaissé à la porte.');
        $this->assertSame(1, \App\Models\OrderStatusTransition::query()->where('order_id', $stop->order_id)->where('to_status', OrderStatus::DELIVERED)->count());
    }

    public function test_bag_taken_moves_the_order_out_for_delivery(): void
    {
        $stop = $this->dispatched();

        $this->webhook(['delivery_id' => 'del_1', 'status' => 'pickup_complete'])->assertOk();

        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) Order::find($stop->order_id)->status);
        $this->assertNotNull($stop->fresh()->left_at);
    }

    public function test_a_course_canceled_by_uber_comes_back_to_our_courier(): void
    {
        $stop = $this->dispatched();

        $this->webhook(['delivery_id' => 'del_1', 'status' => 'canceled'])->assertOk();

        $this->assertSame(UberDirectDispatchService::MODE_OWN, app(UberDirectDispatchService::class)->mode($stop->fresh()));
        $this->assertSame($this->kiki->id, (int) $stop->fresh()->delivery_boy_id);
    }

    public function test_a_late_event_never_reopens_a_finished_course(): void
    {
        $stop = $this->dispatched();
        $this->webhook(['delivery_id' => 'del_1', 'status' => 'delivered'])->assertOk();

        $this->webhook(['delivery_id' => 'del_1', 'status' => 'pickup'])->assertOk();

        $this->assertSame('delivered', UberDirectDelivery::query()->where('order_id', $stop->order_id)->value('status'));
    }

    public function test_an_unknown_delivery_is_acknowledged_without_effect(): void
    {
        $this->webhook(['delivery_id' => 'del_inconnu', 'status' => 'delivered'])->assertOk()->assertJsonPath('ignored', true);
    }

    public function test_phone_numbers_are_sent_to_uber_in_international_format_or_not_at_all(): void
    {
        $s = app(UberDirectDispatchService::class);

        $this->assertSame('+33612345678', $s->phone('06 12 34 56 78'));
        $this->assertSame('+33612345678', $s->phone('+33 6 12 34 56 78'));
        $this->assertSame('+33612345678', $s->phone('0033612345678'));
        $this->assertSame('+33612345678', $s->phone('33612345678'));
        $this->assertNull($s->phone('12345'));
        $this->assertNull($s->phone(''));
        $this->assertNull($s->phone(null));
    }

    // ── relecture adverse E2E 2026-10-22 (B-001 · B-002 · B-003 · B-004) ───────────────────────────────

    private function closeOrder(DeliveryStop $stop): void
    {
        $order = Order::find($stop->order_id);
        $order->forceFill(['status' => OrderStatus::CANCELED])->saveQuietly();
        app(ReleaseDeliveryStopOnOrderClosed::class)->handle(new OrderStatusChanged($order, OrderStatus::PREPARED, OrderStatus::CANCELED));
    }

    public function test_cancelling_the_order_cancels_the_uber_course_before_the_stop_disappears(): void
    {
        $stop = $this->dispatched();

        $this->closeOrder($stop);

        $this->assertSame(1, $this->sent('/deliveries/del_1/cancel'), 'La course Uber est annulée, pas laissée active et facturée.');
        $this->assertNull(DeliveryStop::query()->find($stop->id));
        $this->assertSame('canceled', UberDirectDelivery::query()->where('order_id', $stop->order_id)->value('status'));
    }

    public function test_if_uber_does_not_confirm_the_cancellation_the_stop_stays_with_the_reason(): void
    {
        $stop = $this->dispatched(['*/deliveries/del_1/cancel' => Http::response('boom', 500)]);

        $this->closeOrder($stop);

        $this->assertNotNull(DeliveryStop::query()->find($stop->id), 'Jamais une course facturée sans trace à la caisse.');
        $board = collect($this->actingAs($this->cashier, 'sanctum')->withHeader('x-api-key', 'test-api-key')->getJson('/api/admin/pos-delivery/board')->json('data'))
            ->pluck('stops')->flatten(1)->keyBy('id');
        $this->assertStringContainsString('application Uber Direct', $board[$stop->id]['uber_attention']);
    }

    public function test_a_course_returned_after_the_bag_was_taken_is_flagged_never_silently_dropped(): void
    {
        $stop = $this->dispatched();
        $this->webhook(['delivery_id' => 'del_1', 'status' => 'pickup_complete'])->assertOk();

        $this->webhook(['delivery_id' => 'del_1', 'status' => 'returned'])->assertOk();

        $this->assertSame('uber_returned_after_pickup', UberDirectDelivery::query()->where('order_id', $stop->order_id)->value('failure_code'));
        $board = collect($this->actingAs($this->cashier, 'sanctum')->withHeader('x-api-key', 'test-api-key')->getJson('/api/admin/pos-delivery/board')->json('data'))
            ->pluck('stops')->flatten(1)->keyBy('id');
        $this->assertStringContainsString('la commande n\'est pas livrée', $board[$stop->id]['uber_attention']);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) Order::find($stop->order_id)->status);
    }

    public function test_the_uber_create_call_is_made_outside_any_database_transaction(): void
    {
        $niveau = DB::transactionLevel();
        $vu = null;
        $this->fakeUber(['*/deliveries' => function () use (&$vu) {
            $vu = DB::transactionLevel();

            return Http::response(['id' => 'del_1', 'status' => 'pending', 'tracking_url' => 'https://uber.test/track/1']);
        }]);
        $stop = $this->stop();
        $quote = $this->pos('quote', ['stop_id' => $stop->id])->json('quote_id');

        $this->pos('dispatch', ['stop_id' => $stop->id, 'quote_id' => $quote])->assertOk();

        $this->assertSame($niveau, $vu, 'Un appel réseau de 8 s ne doit jamais tenir un verrou de base ouvert.');
    }

    public function test_a_lost_uber_answer_says_the_course_may_exist_and_never_claims_it_is_safe(): void
    {
        $this->fakeUber(['*/deliveries' => Http::response('boom', 500)]);
        $stop = $this->stop();
        $quote = $this->pos('quote', ['stop_id' => $stop->id])->json('quote_id');

        $r = $this->pos('dispatch', ['stop_id' => $stop->id, 'quote_id' => $quote])->assertStatus(422);

        $this->assertStringContainsString('peut-être été créée', $r->json('message'));
        $this->assertSame('uber_unconfirmed', UberDirectDelivery::query()->where('order_id', $stop->order_id)->value('failure_code'));
    }

    public function test_a_replayed_older_event_never_moves_a_course_backwards(): void
    {
        $stop = $this->dispatched();
        $this->webhook(['delivery_id' => 'del_1', 'status' => 'pickup_complete'])->assertOk();

        $this->webhook(['delivery_id' => 'del_1', 'status' => 'pickup'])->assertOk();

        $this->assertSame('pickup_complete', UberDirectDelivery::query()->where('order_id', $stop->order_id)->value('status'));
    }

    public function test_the_caisse_routes_require_the_pos_permission(): void
    {
        $this->fakeUber();
        $stop = $this->stop();
        $nobody = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($nobody, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos-delivery/uber/quote', ['stop_id' => $stop->id])->assertStatus(403);
    }
}
