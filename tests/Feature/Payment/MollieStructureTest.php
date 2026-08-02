<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Events\OrderCreated;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [W5 STRUCTURE Mollie — GOAL_ULTRA_SYNC_STRUCTURE_2026-07-20]
 *
 * Suite 100% MOCKÉE (Http::fake — zéro appel réseau réel, zéro clé réelle).
 * Prouve la structure fail-closed :
 *  - checkout → paiement créé avec le TOTAL SCELLÉ BACKEND → checkout_url ;
 *  - webhook paid → PAID via le chemin kiosk-paid EXISTANT (fiscal_seq
 *    alloué par finalizePaidKioskOrder, jamais par le webhook lui-même) ;
 *  - rejeu webhook → idempotent (webhook_events UNIQUE provider+webhook_id) ;
 *  - montant fetché ≠ total scellé → REFUS (jamais PAID) ;
 *  - failed/canceled/expired → commande laissée UNPAID ;
 *  - non configuré (flag OFF ou clé '') → 503 fail-closed, AUCUN appel sorti.
 */
class MollieStructureTest extends TestCase
{
    use RefreshDatabase;

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->ensureInstalledFlag();
        // Posture par défaut = celle du produit : FAIL-CLOSED (flag OFF, clé '').
        Config::set('payment.mollie.enabled', false);
        Config::set('payment.mollie.api_key', '');
    }

    protected function tearDown(): void
    {
        if ($this->createdInstalledFlag && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Checkout (POST /api/frontend/order/{order}/mollie-checkout)
    // ------------------------------------------------------------------

    public function test_checkout_creates_payment_from_sealed_backend_total_and_returns_checkout_url(): void
    {
        $this->configureMollie();
        [$customer, $order] = $this->webCardOrder(['total' => 11.80]);

        Http::fake([
            'https://api.mollie.com/v2/payments' => Http::response(
                $this->molliePaymentPayload('tr_W5create1', $order->id, 'open', '11.80'),
                201
            ),
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout")
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('payment_id', 'tr_W5create1')
            ->assertJsonPath('checkout_url', 'https://www.mollie.com/checkout/select-method/tr_W5create1');

        Http::assertSent(function (ClientRequest $request) use ($order): bool {
            $body = $request->data();

            return str_ends_with($request->url(), '/v2/payments')
                && $request->hasHeader('Authorization', 'Bearer test_dummyMollieKey123')
                // Montant = total SCELLÉ backend (11.80 en DB), jamais un montant client.
                && ($body['amount']['value'] ?? null) === '11.80'
                && ($body['amount']['currency'] ?? null) === 'EUR'
                && ($body['metadata']['order_id'] ?? null) === (string) $order->id
                && str_contains((string) ($body['webhookUrl'] ?? ''), '/api/webhook/mollie');
        });
    }

    /**
     * [OWNER 2026-08-01 · PAIEMENT DANS LA PAGE] La carte est saisie SUR NOTRE PAGE (champs
     * Mollie Components) → le front envoie un `card_token` à usage unique. Le paiement est
     * alors créé avec method=creditcard + cardToken : Mollie le traite DIRECTEMENT, sans
     * envoyer le client sur une page de paiement étrangère. Le montant reste le total scellé
     * backend, et seul le webhook peut passer la commande PAID.
     */
    public function test_checkout_with_card_token_pays_inline_without_sending_client_away(): void
    {
        $this->configureMollie();
        [$customer, $order] = $this->webCardOrder(['total' => 11.80]);

        $payload = $this->molliePaymentPayload('tr_INLINE1', $order->id, 'paid', '11.80');
        unset($payload['_links']['checkout']); // paiement direct : Mollie ne renvoie PAS de page hébergée

        Http::fake(['https://api.mollie.com/v2/payments' => Http::response($payload, 201)]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['card_token' => 'tkn_inline_abc123'])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('payment_id', 'tr_INLINE1')
            ->assertJsonPath('inline', true)
            ->assertJsonPath('checkout_url', null);

        Http::assertSent(function (ClientRequest $request) use ($order): bool {
            $body = $request->data();

            return str_ends_with($request->url(), '/v2/payments')
                && ($body['method'] ?? null) === 'creditcard'
                && ($body['cardToken'] ?? null) === 'tkn_inline_abc123'
                && ($body['amount']['value'] ?? null) === '11.80'          // total scellé backend
                && ($body['metadata']['order_id'] ?? null) === (string) $order->id;
        });
    }

    /**
     * [OWNER 2026-08-01] Si la banque impose une authentification 3-D Secure, Mollie renvoie
     * quand même une URL : on la transmet, mais marquée `inline=false` + `reason=3ds` pour que
     * le front la traite comme une étape bancaire explicite — jamais comme « le paiement se
     * passe sur un autre site ».
     */
    public function test_checkout_with_card_token_surfaces_3ds_step_explicitly(): void
    {
        $this->configureMollie();
        [$customer, $order] = $this->webCardOrder(['total' => 9.50]);

        Http::fake([
            'https://api.mollie.com/v2/payments' => Http::response(
                $this->molliePaymentPayload('tr_3DS1', $order->id, 'open', '9.50'),
                201
            ),
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['card_token' => 'tkn_3ds_xyz'])
            ->assertOk()
            ->assertJsonPath('inline', false)
            ->assertJsonPath('reason', '3ds')
            ->assertJsonPath('checkout_url', 'https://www.mollie.com/checkout/select-method/tr_3DS1');
    }

    public function test_checkout_fails_closed_503_when_not_configured(): void
    {
        Http::fake();
        [$customer, $order] = $this->webCardOrder();

        // Cas 1 : défaut produit (flag OFF + clé '').
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Mollie non configuré.');

        // Cas 2 : flag ON mais clé absente → toujours OFF (clé = 2e verrou).
        Config::set('payment.mollie.enabled', true);
        Config::set('payment.mollie.api_key', '');
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout")
            ->assertStatus(503);

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::UNPAID, (int) $order->fresh()->payment_status);
    }

    public function test_checkout_refuses_foreign_order_and_non_card_order(): void
    {
        $this->configureMollie();
        Http::fake();
        [, $order] = $this->webCardOrder();
        $stranger = User::factory()->create(['branch_id' => $order->branch_id]);

        // Commande d'un autre client → 403.
        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout")
            ->assertStatus(403);

        // Commande cash (paymentMethod≠4) → 422.
        [$customer2, $cashOrder] = $this->webCardOrder(['payment_method' => PaymentGateway::CASH_ON_DELIVERY]);
        $this->actingAs($customer2, 'sanctum')
            ->postJson("/api/frontend/order/{$cashOrder->id}/mollie-checkout")
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Webhook (POST /api/webhook/mollie)
    // ------------------------------------------------------------------

    public function test_webhook_paid_marks_kiosk_order_paid_via_kiosk_paid_path_and_replay_is_idempotent(): void
    {
        $this->configureMollie();
        $order = $this->kioskCardOrder(['total' => 12.00]);
        Event::fake([OrderCreated::class]);

        Http::fake([
            'https://api.mollie.com/v2/payments/tr_W5paid01' => Http::response(
                $this->molliePaymentPayload('tr_W5paid01', $order->id, 'paid', '12.00'),
                200
            ),
        ]);

        $this->postJson('/api/webhook/mollie', ['id' => 'tr_W5paid01'])
            ->assertOk()
            ->assertJsonPath('status', 'paid_confirmed');

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertSame('mollie:tr_W5paid01', (string) $fresh->transaction_id);
        // PREUVE chemin kiosk-paid EXISTANT : l'allocation fiscale vient de
        // finalizePaidKioskOrder (le webhook n'appelle JAMAIS FiscalSequenceService).
        $this->assertSame(1, (int) $fresh->fiscal_sequence_no);
        // Wave S-1 : kiosk payé en ligne auto-avance ACCEPT → PREPARING.
        $this->assertSame(OrderStatus::PREPARING, (int) $fresh->status);

        // REJEU du même webhook (storm Mollie) → idempotent, zéro double.
        $this->postJson('/api/webhook/mollie', ['id' => 'tr_W5paid01'])
            ->assertOk()
            ->assertJsonPath('status', 'duplicate_ignored');

        $this->assertSame(1, WebhookEvent::query()
            ->where('provider', WebhookEvent::PROVIDER_MOLLIE)
            ->where('webhook_id', 'tr_W5paid01:paid')
            ->count());
        $this->assertSame(1, (int) $order->fresh()->fiscal_sequence_no);
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, WebhookEvent::query()
            ->where('webhook_id', 'tr_W5paid01:paid')->value('status'));
    }

    public function test_webhook_amount_mismatch_is_refused(): void
    {
        $this->configureMollie();
        $order = $this->kioskCardOrder(['total' => 12.00]);
        Event::fake([OrderCreated::class]);

        Http::fake([
            'https://api.mollie.com/v2/payments/tr_W5bad001' => Http::response(
                // Mollie confirme 99.99 € alors que le total scellé backend = 12.00 €.
                $this->molliePaymentPayload('tr_W5bad001', $order->id, 'paid', '99.99'),
                200
            ),
        ]);

        $this->postJson('/api/webhook/mollie', ['id' => 'tr_W5bad001'])
            ->assertOk()
            ->assertJsonPath('status', 'amount_mismatch_refused');

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status);
        $this->assertNull($fresh->transaction_id);
        $this->assertNull($fresh->fiscal_sequence_no);
        $this->assertSame(WebhookEvent::STATUS_FAILED, WebhookEvent::query()
            ->where('webhook_id', 'tr_W5bad001:paid')->value('status'));
    }

    public function test_webhook_failed_status_leaves_order_unpaid_for_counter_collect(): void
    {
        $this->configureMollie();
        [, $order] = $this->webCardOrder(['total' => 11.80]);

        Http::fake([
            'https://api.mollie.com/v2/payments/tr_W5fail01' => Http::response(
                $this->molliePaymentPayload('tr_W5fail01', $order->id, 'failed', '11.80'),
                200
            ),
        ]);

        $this->postJson('/api/webhook/mollie', ['id' => 'tr_W5fail01'])
            ->assertOk()
            ->assertJsonPath('status', 'ack_failed');

        $fresh = $order->fresh();
        // UNPAID conservé → le site propose le paiement en caisse (chemin
        // « web encaissable » existant). Aucune mutation, aucun fiscal.
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status);
        $this->assertNull($fresh->transaction_id);
        $this->assertNull($fresh->fiscal_sequence_no);
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, WebhookEvent::query()
            ->where('webhook_id', 'tr_W5fail01:failed')->value('status'));
    }

    public function test_webhook_paid_web_order_marks_paid_and_leaves_fiscal_gap_flagged_for_gw5(): void
    {
        $this->configureMollie();
        [, $order] = $this->webCardOrder(['total' => 11.80]);
        Event::fake([OrderCreated::class]);

        Http::fake([
            'https://api.mollie.com/v2/payments/tr_W5web001' => Http::response(
                $this->molliePaymentPayload('tr_W5web001', $order->id, 'paid', '11.80'),
                200
            ),
        ]);

        $this->postJson('/api/webhook/mollie', ['id' => 'tr_W5web001'])
            ->assertOk()
            ->assertJsonPath('status', 'paid_confirmed');

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertSame('mollie:tr_W5web001', (string) $fresh->transaction_id);
        // COMPORTEMENT DOCUMENTÉ (activation G-W5) : commande WEB pure (sans
        // KioskMachine) → le gate kiosk de finalizePaidKioskOrder no-op → pas
        // d'allocation ici (warning fiscal `mollie_webhook_fiscal_finalize_noop`
        // émis). L'élargissement du gate = décision owner à l'activation,
        // PAS un chemin fiscal improvisé par le webhook.
        $this->assertNull($fresh->fiscal_sequence_no);
    }

    public function test_webhook_fails_closed_503_when_not_configured_and_rejects_malformed_id(): void
    {
        Http::fake();

        // Sans flag ni clé : 503 fail-closed, AUCUN fetch sortant, zéro ledger.
        $this->postJson('/api/webhook/mollie', ['id' => 'tr_W5none01'])
            ->assertStatus(503);
        Http::assertNothingSent();
        $this->assertSame(0, WebhookEvent::query()->where('provider', WebhookEvent::PROVIDER_MOLLIE)->count());

        // Configuré mais id malformé (forge) → 400 sans fetch.
        $this->configureMollie();
        $this->postJson('/api/webhook/mollie', ['id' => 'DROP TABLE orders'])
            ->assertStatus(400);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function configureMollie(): void
    {
        Config::set('payment.mollie.enabled', true);
        Config::set('payment.mollie.api_key', 'test_dummyMollieKey123');
    }

    /**
     * Commande carte BORNE (KioskMachine) — le chemin kiosk-paid complet
     * (fiscal + PREPARING) est prouvable dessus.
     */
    private function kioskCardOrder(array $overrides = []): Order
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['user_id' => $kioskUser->id, 'branch_id' => $branch->id]);

        return Order::factory()->create($overrides + [
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
            'transaction_id' => null,
            'fiscal_sequence_no' => null,
            'subtotal' => 12.00,
            'total' => 12.00,
        ]);
    }

    /**
     * Commande carte WEB (client authentifié, PAS de KioskMachine) —
     * le funnel 'card' du site (paymentMethod=4 → myOrderStore).
     *
     * @return array{0: User, 1: Order}
     */
    private function webCardOrder(array $overrides = []): array
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        $order = Order::factory()->create($overrides + [
            'branch_id' => $branch->id,
            'user_id' => $customer->id,
            'order_type' => OrderType::TAKEAWAY,
            'source' => Source::WEB,
            'source_surface' => 'web',
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'transaction_id' => null,
            'fiscal_sequence_no' => null,
            'subtotal' => 11.80,
            'total' => 11.80,
        ]);

        return [$customer, $order];
    }

    private function molliePaymentPayload(string $id, int $orderId, string $status, string $value): array
    {
        return [
            'resource' => 'payment',
            'id' => $id,
            'mode' => 'test',
            'status' => $status,
            'amount' => ['value' => $value, 'currency' => 'EUR'],
            'description' => 'Le Cayenne — Commande test',
            'metadata' => ['order_id' => (string) $orderId],
            '_links' => [
                'checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/' . $id],
            ],
        ];
    }

    private function ensureInstalledFlag(): void
    {
        if (file_exists(storage_path('installed'))) {
            return;
        }
        touch(storage_path('installed'));
        $this->createdInstalledFlag = true;
    }
}
