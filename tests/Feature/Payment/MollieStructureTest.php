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
    /**
     * [OWNER 2026-08-04 P1-B SÉCU] Carte REFUSÉE en synchrone (petit montant sans 3DS,
     * cas le PLUS courant) : Mollie renvoie status=failed SANS checkout_url. AVANT :
     * `inline = cardToken && !checkout_url` → true → le front affichait « payé » sur une
     * carte refusée = LA plainte owner par le chemin courant. Désormais : inline UNIQUEMENT
     * si status=paid ; failed → inline=false + reason=refused, commande jamais « payée ».
     */
    public function test_checkout_refused_card_is_not_reported_inline_paid(): void
    {
        $this->configureMollie();
        [$customer, $order] = $this->webCardOrder(['total' => 9.30]);

        $payload = $this->molliePaymentPayload('tr_REFUSE1', $order->id, 'failed', '9.30');
        unset($payload['_links']['checkout']); // refus synchrone : pas de page hébergée

        Http::fake(['https://api.mollie.com/v2/payments' => Http::response($payload, 201)]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['card_token' => 'tkn_refused'])
            ->assertOk()
            ->assertJsonPath('inline', false)
            ->assertJsonPath('reason', 'refused');

        // La commande n'est JAMAIS marquée payée par ce chemin (webhook seul, et il annulera).
        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status);
        $this->assertNull($fresh->fiscal_sequence_no);
    }

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

    /**
     * [BRAIN RED 2026-08-03 P1] Avec cardToken, la CRÉATION du paiement EST l'encaissement
     * serveur-side : un retry réseau (timeout 15 s côté client alors que Mollie a accepté)
     * re-poste la même intention → 2ᵉ débit réel. La route porte désormais le middleware
     * `idempotency` : même X-Idempotency-Key ⇒ le 2ᵉ POST REJOUE la réponse 2xx cachée,
     * UN SEUL paiement créé chez Mollie.
     */
    public function test_checkout_same_idempotency_key_creates_single_mollie_payment(): void
    {
        config(['idempotency.enabled' => true]);
        $this->configureMollie();
        [$customer, $order] = $this->webCardOrder(['total' => 11.80]);

        $payload = $this->molliePaymentPayload('tr_ONCE', $order->id, 'paid', '11.80');
        unset($payload['_links']['checkout']);
        Http::fake(['https://api.mollie.com/v2/payments' => Http::response($payload, 201)]);

        $headers = ['X-Idempotency-Key' => 'mollie-retry-'.$order->id];
        $first = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['card_token' => 'tkn_retry_abc123'], $headers)
            ->assertOk()->assertJsonPath('payment_id', 'tr_ONCE');
        $second = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['card_token' => 'tkn_retry_abc123'], $headers)
            ->assertOk()->assertJsonPath('payment_id', 'tr_ONCE');

        // UN SEUL paiement créé chez Mollie — le retry a été rejoué, pas ré-encaissé.
        Http::assertSentCount(1);
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

    /**
     * [OWNER 2026-08-03 SÉCU] « J'ai ANNULÉ le paiement à la banque et la commande était
     * quand même validée. » Nouveau contrat : un paiement en ligne TERMINAL non abouti
     * (failed / canceled / expired) sur une commande WEB carte encore PENDING+UNPAID
     * ⇒ la commande est ANNULÉE côté serveur (webhook = source de vérité) — elle
     * disparaît de la caisse, stock/points libérés, transition auditée.
     */
    public function test_webhook_failed_or_canceled_cancels_pending_unpaid_web_card_order(): void
    {
        foreach ([['failed', 'tr_W5fail01'], ['canceled', 'tr_W5cxl01'], ['expired', 'tr_W5exp01']] as [$st, $pid]) {
            $this->configureMollie();
            [, $order] = $this->webCardOrder(['total' => 11.80]);

            Http::fake([
                'https://api.mollie.com/v2/payments/' . $pid => Http::response(
                    $this->molliePaymentPayload($pid, $order->id, $st, '11.80'),
                    200
                ),
            ]);

            $this->postJson('/api/webhook/mollie', ['id' => $pid])
                ->assertOk()
                ->assertJsonPath('status', 'order_canceled_' . $st);

            $fresh = $order->fresh();
            $this->assertSame(OrderStatus::CANCELED, (int) $fresh->status, "statut annulé ($st)");
            $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status);
            $this->assertNull($fresh->transaction_id);
            $this->assertNull($fresh->fiscal_sequence_no);
            $this->assertSame(WebhookEvent::STATUS_PROCESSED, WebhookEvent::query()
                ->where('webhook_id', $pid . ':' . $st)->value('status'));
        }
    }

    /** Rejeu du même webhook annulé → idempotent (déjà annulée, pas de double side-effect). */
    public function test_webhook_canceled_replay_is_idempotent(): void
    {
        $this->configureMollie();
        [, $order] = $this->webCardOrder(['total' => 11.80]);
        Http::fake(['https://api.mollie.com/v2/payments/tr_W5cxl02' => Http::response(
            $this->molliePaymentPayload('tr_W5cxl02', $order->id, 'canceled', '11.80'), 200)]);

        $this->postJson('/api/webhook/mollie', ['id' => 'tr_W5cxl02'])->assertOk();
        $this->postJson('/api/webhook/mollie', ['id' => 'tr_W5cxl02'])->assertOk();
        $this->assertSame(OrderStatus::CANCELED, (int) $order->fresh()->status);
    }

    /** Une commande DÉJÀ ACCEPTÉE (cuisine lancée) n'est JAMAIS annulée par un échec de
     *  paiement tardif — elle reste encaissable au comptoir (décision humaine, pas webhook). */
    public function test_webhook_canceled_leaves_accepted_order_untouched(): void
    {
        $this->configureMollie();
        [, $order] = $this->webCardOrder(['total' => 11.80, 'status' => OrderStatus::ACCEPT]);
        Http::fake(['https://api.mollie.com/v2/payments/tr_W5cxl03' => Http::response(
            $this->molliePaymentPayload('tr_W5cxl03', $order->id, 'canceled', '11.80'), 200)]);

        $this->postJson('/api/webhook/mollie', ['id' => 'tr_W5cxl03'])
            ->assertOk()
            ->assertJsonPath('status', 'ack_canceled');
        $this->assertSame(OrderStatus::ACCEPT, (int) $order->fresh()->status);
    }

    /** Une commande déjà PAYÉE n'est jamais annulée par un webhook non-paid retardataire. */
    public function test_webhook_canceled_never_touches_paid_order(): void
    {
        $this->configureMollie();
        [, $order] = $this->webCardOrder(['total' => 11.80, 'payment_status' => PaymentStatus::PAID]);
        Http::fake(['https://api.mollie.com/v2/payments/tr_W5cxl04' => Http::response(
            $this->molliePaymentPayload('tr_W5cxl04', $order->id, 'canceled', '11.80'), 200)]);

        $this->postJson('/api/webhook/mollie', ['id' => 'tr_W5cxl04'])
            ->assertOk()
            ->assertJsonPath('status', 'ack_canceled');
        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertNotSame(OrderStatus::CANCELED, (int) $fresh->status);
    }

    /**
     * [OWNER 2026-08-04 · LOCK_WEB_CARD_FISCAL_SEAL] Une vente carte WEB payée en ligne
     * DOIT entrer dans le Z signé NF525 : le webhook `paid` alloue le fiscal_sequence_no
     * (chemin borne-payée unifié) ET promeut PENDING→ACCEPT (entre en cuisine). L'ancien
     * test asserait assertNull(fiscal_sequence_no) — il ENCODAIT le bug (vente hors Z).
     */
    public function test_webhook_paid_web_card_order_is_sealed_and_accepted(): void
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
        // NF525 : la vente réelle est SCELLÉE (numéro fiscal alloué → entre dans le Z).
        $this->assertNotNull($fresh->fiscal_sequence_no, 'vente carte web payée = scellée NF525');
        // Cycle : promue en CUISINE au lieu de rester PENDING (zombie caisse). Comme la
        // borne TPE, un paiement carte enchaîne ACCEPT→PREPARING (Wave S-1) : « en cours »
        // sans 2ᵉ tap. On asserte donc « au-delà de PENDING, entrée en cuisine ».
        $this->assertGreaterThanOrEqual(OrderStatus::ACCEPT, (int) $fresh->status);
        $this->assertNotSame(OrderStatus::PENDING, (int) $fresh->status);
    }

    /**
     * [OWNER 2026-08-04 P1-C SÉCU] Re-drive DLQ : un event stocké `failed` (échec transitoire
     * du 1er passage) est rejoué — on re-fetche l'état frais et on scelle réellement le paiement
     * (fin du « payé chez Mollie / UNPAID chez nous » = double-encaissement comptoir).
     */
    public function test_dlq_redrive_seals_a_previously_failed_paid_webhook(): void
    {
        $this->configureMollie();
        [, $order] = $this->webCardOrder(['total' => 11.80]);

        // Event stocké en échec (le 1er passage a crashé après le fetch).
        $stored = WebhookEvent::create([
            'provider'    => WebhookEvent::PROVIDER_MOLLIE,
            'webhook_id'  => 'tr_DLQ001:paid',
            'event_type'  => 'payment.paid',
            'payload'     => $this->molliePaymentPayload('tr_DLQ001', $order->id, 'paid', '11.80'),
            'received_at' => now(),
            'status'      => WebhookEvent::STATUS_FAILED,
        ]);

        Http::fake(['https://api.mollie.com/v2/payments/tr_DLQ001' => Http::response(
            $this->molliePaymentPayload('tr_DLQ001', $order->id, 'paid', '11.80'), 200)]);

        app(\App\Http\PaymentGateways\Gateways\Mollie::class)->handleFromStoredEvent($stored->fresh());

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status, 'DLQ re-drive scelle le paiement');
        $this->assertNotNull($fresh->fiscal_sequence_no, 'et l\'entre dans le Z NF525');
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, (string) $stored->fresh()->status);
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
