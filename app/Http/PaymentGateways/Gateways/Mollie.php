<?php

namespace App\Http\PaymentGateways\Gateways;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\FrontendOrder;
use App\Models\PaymentGateway;
use App\Models\Scopes\BranchScope;
use App\Models\WebhookEvent;
use App\Services\FrontendOrderService;
use App\Services\PaymentAbstract;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * [W5 STRUCTURE Mollie — GOAL_ULTRA_SYNC_STRUCTURE_2026-07-20]
 *
 * Paiement carte du site web via Mollie (API v2 REST, `Http::` facade —
 * volontairement SANS package composer pour ne pas toucher le lock).
 * Imité de {@see Stripe} : garde « non configuré » (jamais de 500),
 * idempotence webhook via `webhook_events` UNIQUE (provider, webhook_id).
 *
 * FAIL-CLOSED : tant que `payment.mollie.enabled=false` OU
 * `MOLLIE_API_KEY=''` (défaut), createPayment() lève « Mollie non
 * configuré » et le webhook répond 503. Gate owner G-W5 = clés + flag.
 *
 * Modèle de sécurité webhook (documenté Mollie — pas de signature HMAC) :
 * le POST ne porte qu'un `id` ; la vérité (status/montant/metadata) est
 * TOUJOURS re-fetchée via GET /v2/payments/{id} authentifié par notre clé.
 * Un POST forgé ne peut donc jamais marquer une commande PAID.
 *
 * NF525 : la confirmation « paid » réutilise le chemin kiosk-paid
 * EXISTANT — mêmes transitions que Frontend\OrderController::paymentConfirm
 * (lock → payment_status=PAID + transaction_id) puis
 * {@see FrontendOrderService::finalizePaidKioskOrder()} (allocation
 * fiscale + promotion + OrderCreated). AUCUN nouveau chemin fiscal :
 * FiscalSequenceService n'est jamais appelé directement ici.
 */
class Mollie extends PaymentAbstract
{
    public function __construct()
    {
        $paymentService = new PaymentService();
        parent::__construct($paymentService);

        // Ligne DB payment_gateways optionnelle (parité squelette SaaS —
        // absente en V1 Le Cayenne ; la config env est la seule source).
        $this->paymentGateway = PaymentGateway::with('gatewayOptions')->where(['slug' => 'mollie'])->first()
            ?? new PaymentGateway();
    }

    /**
     * Verrou fail-closed : flag serveur ET clé non-vide requis.
     */
    public function isMollieConfigured(): bool
    {
        return (bool) config('payment.mollie.enabled', false)
            && (string) config('payment.mollie.api_key', '') !== '';
    }

    private function unconfiguredResponse(): JsonResponse
    {
        return response()->json([
            'status'  => false,
            'message' => 'Mollie non configuré.',
        ], 503);
    }

    /**
     * Crée un paiement Mollie pour une commande web et retourne l'URL de
     * checkout hébergée. Montant = TOTAL SCELLÉ BACKEND ($order->total,
     * calculé par PricingService à la création) — JAMAIS un montant client.
     *
     * @return array{payment_id: string, checkout_url: string}
     *
     * @throws RuntimeException « Mollie non configuré. » si fail-closed,
     *                          ou erreur claire si l'API Mollie refuse.
     */
    /**
     * @param string $cardToken [OWNER 2026-08-01 · PAIEMENT DANS LA PAGE] Token à usage unique
     *                          produit par Mollie Components — les champs carte sont rendus
     *                          DANS notre page (iframes Mollie pour la conformité PCI), donc le
     *                          client n'est jamais envoyé sur une page de paiement étrangère.
     *                          Vide = ancien comportement (page hébergée Mollie).
     */
    public function createPayment(FrontendOrder $order, string $cardToken = ''): array
    {
        if (!$this->isMollieConfigured()) {
            throw new RuntimeException('Mollie non configuré.');
        }

        $redirectBase = (string) config('payment.mollie.redirect_url', '');
        if ($redirectBase === '') {
            $redirectBase = (string) config('app.url');
        }
        $redirectUrl = $redirectBase
            . (str_contains($redirectBase, '?') ? '&' : '?')
            . 'order=' . $order->id;

        $extra = [];
        if ($cardToken !== '') {
            // Paiement direct : la carte a déjà été saisie sur NOTRE page.
            $extra = ['method' => 'creditcard', 'cardToken' => $cardToken];
        }

        $response = Http::withToken((string) config('payment.mollie.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(config('payment.mollie.api_base') . '/payments', $extra + [
                'amount' => [
                    'currency' => 'EUR',
                    // Format Mollie obligatoire : chaîne à 2 décimales exactes.
                    'value'    => number_format((float) $order->total, 2, '.', ''),
                ],
                'description' => 'Le Cayenne — Commande ' . ($order->order_serial_no ?: ('#' . $order->id)),
                'redirectUrl' => $redirectUrl,
                'webhookUrl'  => route('webhooks.mollie'),
                'locale'      => 'fr_FR',
                'metadata'    => [
                    'order_id' => (string) $order->id,
                ],
            ]);

        if (!$response->successful()) {
            Log::channel('fiscal')->error('mollie.create_payment.failed', [
                'event'    => 'mollie_create_payment_failed',
                'order_id' => $order->id,
                'http'     => $response->status(),
                'body'     => mb_substr((string) $response->body(), 0, 500),
            ]);
            throw new RuntimeException('Création du paiement Mollie refusée (HTTP ' . $response->status() . ').');
        }

        $payload     = (array) $response->json();
        $paymentId   = (string) ($payload['id'] ?? '');
        $checkoutUrl = (string) ($payload['_links']['checkout']['href'] ?? '');

        // Sans cardToken, l'URL hébergée est le SEUL moyen de payer → son absence est une
        // réponse invalide. AVEC cardToken (paiement dans notre page), Mollie peut traiter la
        // carte directement : pas d'URL = cas NOMINAL, et non une erreur.
        if ($paymentId === '' || ($cardToken === '' && $checkoutUrl === '')) {
            throw new RuntimeException('Réponse Mollie invalide (id ou checkout url manquant).');
        }

        Log::channel('fiscal')->info('mollie.payment.created', [
            'event'      => 'mollie_payment_created',
            'order_id'   => $order->id,
            'payment_id' => $paymentId,
            'amount'     => number_format((float) $order->total, 2, '.', ''),
        ]);

        $mollieStatus = (string) ($payload['status'] ?? '');

        return [
            'payment_id'   => $paymentId,
            'checkout_url' => $checkoutUrl,
            // [OWNER 2026-08-04 P1-B] « Payé dans la page » = carte saisie chez nous, aucune
            // étape bancaire renvoyée, ET Mollie confirme RÉELLEMENT `paid`. AVANT : un refus
            // synchrone (status=failed, sans checkout_url) passait inline=true → écran « payé »
            // sur une carte refusée. Le montant/PAID restent scellés par le webhook ; ici on
            // ne fait que router honnêtement l'UI.
            'inline'       => $cardToken !== '' && $checkoutUrl === '' && $mollieStatus === 'paid',
            'status'       => $mollieStatus,
        ];
    }

    /**
     * Webhook Mollie — POST /api/webhook/mollie (body form-encodé `id=tr_x`).
     *
     * Contrat :
     *  - Le POST n'est JAMAIS cru : GET /v2/payments/{id} = source de vérité.
     *  - Idempotence : webhook_events UNIQUE (provider, webhook_id) avec
     *    webhook_id = "{payment_id}:{status_fetché}" — Mollie rejoue le MÊME
     *    id à chaque changement de statut ; le scope par statut évite qu'un
     *    event `open` précoce avale le `paid` suivant, tout en dédupliquant
     *    les rejeux du même statut (200 duplicate_ignored).
     *  - paid  → chemin kiosk-paid existant (PAID + finalizePaidKioskOrder).
     *  - failed/canceled/expired → commande laissée UNPAID (le web propose
     *    l'encaissement en caisse) — aucune mutation.
     *  - Montant fetché ≠ total scellé backend → REFUS (jamais PAID).
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        if (!$this->isMollieConfigured()) {
            // Fail-closed : aucune clé → aucun paiement n'a pu être créé par
            // nous → tout POST est soit forgé, soit une mauvaise config.
            Log::channel('fiscal')->warning('mollie.webhook.unconfigured', [
                'event' => 'mollie_webhook_unconfigured',
                'ip'    => $request->ip(),
            ]);

            return $this->unconfiguredResponse();
        }

        $paymentId = (string) $request->input('id', '');
        if (!preg_match('/^tr_[A-Za-z0-9]{5,64}$/', $paymentId)) {
            return response()->json([
                'error'   => 'invalid_payload',
                'message' => 'Mollie payment id missing or malformed.',
            ], 400);
        }

        // Source de vérité : re-fetch authentifié chez Mollie.
        try {
            $fetch = Http::withToken((string) config('payment.mollie.api_key'))
                ->acceptJson()
                ->timeout(15)
                ->get(config('payment.mollie.api_base') . '/payments/' . $paymentId);
        } catch (Throwable $e) {
            Log::channel('fiscal')->error('mollie.webhook.fetch_error', [
                'event'      => 'mollie_webhook_fetch_error',
                'payment_id' => $paymentId,
                'message'    => $e->getMessage(),
            ]);

            // 5xx → Mollie rejouera (blip réseau transitoire).
            return response()->json(['error' => 'fetch_failed'], 503);
        }

        if ($fetch->status() === 404) {
            // Id inconnu chez Mollie (forgé, ou clé d'un autre environnement) :
            // ack 200 pour ne pas provoquer de rejeux infinis, trace forensique.
            Log::channel('fiscal')->warning('mollie.webhook.unknown_payment', [
                'event'      => 'mollie_webhook_unknown_payment',
                'payment_id' => $paymentId,
                'ip'         => $request->ip(),
            ]);

            return response()->json(['status' => 'unknown_payment_ignored'], 200);
        }

        if (!$fetch->successful()) {
            Log::channel('fiscal')->error('mollie.webhook.fetch_failed', [
                'event'      => 'mollie_webhook_fetch_failed',
                'payment_id' => $paymentId,
                'http'       => $fetch->status(),
            ]);

            return response()->json(['error' => 'fetch_failed'], 503);
        }

        $payment = (array) $fetch->json();
        $status  = (string) ($payment['status'] ?? 'unknown');

        // [P0-2 SÉCU 2026-08-04] Remboursement / chargeback : chez Mollie le paiement RESTE
        // `paid`, seul `amountRefunded` / `amountChargedBack` évolue, et le webhook est rejoué
        // avec le MÊME id. Sans discriminant, `tr_x:paid` existe déjà → `duplicate_ignored` →
        // la commande reste PAID à vie (Z > payout). On dérive un statut effectif `refunded`
        // (clé de dédup DISTINCTE + routage vers la cascade RefundCreated, miroir Stripe).
        $refundedValue = (float) ($payment['amountRefunded']['value'] ?? 0)
            + (float) ($payment['amountChargedBack']['value'] ?? 0);
        if ($status === 'paid' && $refundedValue > 0) {
            $status = 'refunded';
        }

        // Idempotence — UNIQUE (provider, webhook_id) au plancher DB ;
        // catch violation d'unicité = rejeu concurrent (pattern TOCTOU Uber).
        try {
            $event = WebhookEvent::firstOrCreate(
                [
                    'provider'   => WebhookEvent::PROVIDER_MOLLIE,
                    'webhook_id' => $paymentId . ':' . $status,
                ],
                [
                    'event_type'  => 'payment.' . $status,
                    'payload'     => $payment,
                    'received_at' => now(),
                    'status'      => WebhookEvent::STATUS_PENDING,
                ]
            );
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return response()->json(['status' => 'already_processing'], 200);
            }
            throw $e;
        }

        if (!$event->wasRecentlyCreated) {
            Log::channel('fiscal')->info('mollie.webhook.duplicate_ignored', [
                'event'      => 'mollie_webhook_duplicate_ignored',
                'payment_id' => $paymentId,
                'status'     => $status,
            ]);

            return response()->json(['status' => 'duplicate_ignored'], 200);
        }

        try {
            return $this->processFetchedPayment($paymentId, $status, $payment, $event);
        } catch (Throwable $e) {
            Log::channel('fiscal')->error('mollie.webhook.processing_failed', [
                'event'      => 'mollie_webhook_processing_failed',
                'payment_id' => $paymentId,
                'status'     => $status,
                'message'    => $e->getMessage(),
            ]);
            $event->markFailed($e->getMessage());

            // 500 → Mollie rejoue ; la ligne webhook_events reste pour re-drive.
            return response()->json(['error' => 'processing_failed'], 500);
        }
    }

    /**
     * Routage par statut fetché. `paid` = seul statut qui mute la commande.
     */
    private function processFetchedPayment(string $paymentId, string $status, array $payment, WebhookEvent $event): JsonResponse
    {
        $orderId = (int) ($payment['metadata']['order_id'] ?? 0);

        // [P0-2 SÉCU 2026-08-04] Remboursement/chargeback détecté en amont → cascade RefundCreated
        // (payment_status=REFUNDED + clawback fidélité + release stock), avant le routage `paid`.
        if ($status === 'refunded') {
            return $this->processMollieRefund($paymentId, $orderId, $event);
        }

        if ($status !== 'paid') {
            // [OWNER 2026-08-03 SÉCU] failed / canceled / expired = TERMINAL non abouti :
            // une commande WEB carte encore PENDING+UNPAID est ANNULÉE (le client a
            // annulé/échoué son paiement — elle ne doit JAMAIS partir en cuisine ni
            // s'afficher « validée »). Gardes dans le service : jamais une commande
            // ACCEPTÉE ou PAYÉE ; idempotent au rejeu. open/pending = non terminaux → ack.
            if (in_array($status, ['failed', 'canceled', 'expired'], true) && $orderId > 0) {
                $order = FrontendOrder::withoutGlobalScope(BranchScope::class)->find($orderId);
                if ($order && app(FrontendOrderService::class)->cancelForFailedOnlinePayment($order, $status)) {
                    Log::channel('fiscal')->info('mollie.webhook.order_canceled_on_' . $status, [
                        'event'      => 'mollie_webhook_order_canceled',
                        'payment_id' => $paymentId,
                        'order_id'   => $orderId,
                        'status'     => $status,
                    ]);
                    $event->markProcessed($orderId);

                    return response()->json(['status' => 'order_canceled_' . $status], 200);
                }
            }
            $event->markProcessed($orderId > 0 ? $orderId : null);

            return response()->json(['status' => 'ack_' . $status], 200);
        }

        if ($orderId <= 0) {
            Log::channel('fiscal')->warning('mollie.webhook.paid_without_order_metadata', [
                'event'      => 'mollie_webhook_paid_without_order_metadata',
                'payment_id' => $paymentId,
            ]);
            $event->markFailed('Paid payment without metadata.order_id.');

            return response()->json(['status' => 'unknown_order'], 200);
        }

        $amountValue    = (string) ($payment['amount']['value'] ?? '');
        $amountCurrency = (string) ($payment['amount']['currency'] ?? '');

        $refusal   = null;
        $alreadyPaid = false;
        $paidNow   = false;
        /** @var FrontendOrder|null $orderRef */
        $orderRef  = null;
        // [P0-1 résidu SÉCU 2026-08-04] Auto-remboursement d'un paiement qui tombe sur une commande
        // TERMINALE (annulée pendant que le paiement était en vol) : capturé dans la transaction,
        // exécuté APRÈS commit (jamais d'appel HTTP dans un DB::transaction).
        $autoRefund = null;

        DB::transaction(function () use (
            $paymentId,
            $orderId,
            $amountValue,
            $amountCurrency,
            $event,
            &$refusal,
            &$alreadyPaid,
            &$paidNow,
            &$orderRef,
            &$autoRefund
        ): void {
            $locked = FrontendOrder::withoutGlobalScope(BranchScope::class)
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                Log::channel('fiscal')->warning('mollie.webhook.order_not_found', [
                    'event'      => 'mollie_webhook_order_not_found',
                    'payment_id' => $paymentId,
                    'order_id'   => $orderId,
                ]);
                $event->markProcessed();
                $refusal = 'order_not_found';

                return;
            }
            $orderRef = $locked;

            // Garde montant — miroir AUDIT-F-002 (amount echo TPE) : le
            // montant CONFIRMÉ chez Mollie doit être le total scellé backend.
            $expectedCents = (int) round((float) $locked->total * 100);
            $paidCents     = (int) round((float) $amountValue * 100);
            if ($amountCurrency !== 'EUR' || $paidCents !== $expectedCents) {
                Log::channel('fiscal')->warning('mollie.webhook.amount_mismatch', [
                    'event'          => 'mollie_webhook_amount_mismatch',
                    'payment_id'     => $paymentId,
                    'order_id'       => $orderId,
                    'expected_cents' => $expectedCents,
                    'paid_cents'     => $paidCents,
                    'currency'       => $amountCurrency,
                ]);
                $event->markFailed('Amount/currency mismatch — refused (expected ' . $expectedCents . 'c EUR).');
                $refusal = 'amount_mismatch_refused';

                return;
            }

            $transactionId = 'mollie:' . $paymentId;

            // Rejet transaction déjà rattachée à une AUTRE commande
            // (miroir paymentConfirm duplicate-transaction guard).
            $duplicate = FrontendOrder::withoutGlobalScope(BranchScope::class)
                ->where('transaction_id', $transactionId)
                ->where('id', '!=', $locked->id)
                ->exists();
            if ($duplicate) {
                $event->markFailed('Transaction already attached to another order.');
                $refusal = 'transaction_conflict';

                return;
            }

            if ((int) $locked->payment_status === PaymentStatus::PAID) {
                if (blank($locked->transaction_id)) {
                    $locked->transaction_id = $transactionId;
                    $locked->card_type = 'mollie';
                    $locked->save();
                }
                $alreadyPaid = true;
                $event->markProcessed($orderId);

                return;
            }

            if (in_array((int) $locked->status, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED], true)) {
                // Argent encaissé chez Mollie pour une vente void → PAS de PAID (vente hors Z,
                // miroir exclusions changePaymentStatus). [P0-1 résidu 2026-08-04] Le client a
                // payé une commande annulée pendant que le paiement était en vol → on le
                // REMBOURSE AUTOMATIQUEMENT (fin du « argent gardé, geste ops manuel »). Capturé
                // ici, exécuté après commit.
                Log::channel('fiscal')->warning('mollie.webhook.paid_on_terminal_order', [
                    'event'      => 'mollie_webhook_paid_on_terminal_order',
                    'payment_id' => $paymentId,
                    'order_id'   => $orderId,
                    'order_status' => (int) $locked->status,
                    'note'       => 'Auto-remboursement déclenché (commande terminale).',
                ]);
                $autoRefund = ['payment_id' => $paymentId, 'value' => $amountValue, 'currency' => $amountCurrency ?: 'EUR', 'order_id' => $orderId];
                $event->markProcessed($orderId);
                $refusal = 'terminal_order_auto_refunded';

                return;
            }

            // Chemin kiosk-paid EXISTANT (miroir paymentConfirm:250-253).
            $locked->payment_status = PaymentStatus::PAID;
            $locked->transaction_id = $transactionId;
            $locked->card_type = 'mollie';
            $locked->save();

            $event->markProcessed($orderId);
            $paidNow = true;
        });

        if ($refusal !== null) {
            // [P0-1 résidu 2026-08-04] Auto-remboursement APRÈS commit (appel HTTP hors transaction).
            // Best-effort : un échec de refund est loggé (canal fiscal) pour rattrapage ops, jamais
            // un 500 qui ferait rejouer le webhook. La commande reste terminale (jamais PAID).
            if (is_array($autoRefund)) {
                $this->refundMolliePayment($autoRefund);
            }
            // 200 : condition permanente — un rejeu Mollie ne la changera pas ;
            // la ligne webhook_events (status=failed) porte l'anomalie.
            return response()->json(['status' => $refusal], 200);
        }

        if (($paidNow || $alreadyPaid) && $orderRef !== null) {
            // Allocation fiscale + promotion + OrderCreated via le service
            // EXISTANT — best-effort non bloquant (miroir test-e2e-fix-B-001 :
            // le paiement est déjà commité, un échec de side-effect ne doit
            // pas provoquer un 500 + rejeu Mollie sur un état final).
            try {
                $finalized = app(FrontendOrderService::class)
                    ->finalizePaidKioskOrder($orderRef->fresh());

                $fresh = $orderRef->fresh();
                if (!$finalized && $fresh && $fresh->fiscal_sequence_no === null) {
                    // Commande WEB pure (sans KioskMachine) : le gate kiosk de
                    // finalizePaidKioskOrder no-op → pas d'allocation ici.
                    // OBSERVABILITÉ fail-loud (canal fiscal) — l'élargissement
                    // du gate est un point d'activation G-W5 (owner), PAS un
                    // nouveau chemin fiscal improvisé dans ce webhook.
                    Log::channel('fiscal')->warning('mollie.webhook.fiscal_finalize_noop', [
                        'event'      => 'mollie_webhook_fiscal_finalize_noop',
                        'payment_id' => $paymentId,
                        'order_id'   => $orderId,
                        'note'       => 'PAID sans fiscal_sequence_no — gate kiosk finalizePaidKioskOrder (activation G-W5).',
                    ]);
                }
            } catch (Throwable $e) {
                Log::channel('fiscal')->warning('mollie.webhook.finalize_side_effect_failed', [
                    'event'      => 'mollie_webhook_finalize_side_effect_failed',
                    'payment_id' => $paymentId,
                    'order_id'   => $orderId,
                    'message'    => $e->getMessage(),
                ]);
            }

            return response()->json([
                'status' => $alreadyPaid ? 'already_paid' : 'paid_confirmed',
            ], 200);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Violation d'unicité MySQL/SQLite (pattern UberWebhookController).
     */
    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000'
            || in_array($driverCode, [1062, 19, 2067], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    // ------------------------------------------------------------------
    // Contrat PaymentAbstract (parité squelette SaaS — flux blade V1 OFF :
    // routes payment.* désactivées par payment.web_payment_v1.enabled=false).
    // ------------------------------------------------------------------

    public function status(): bool
    {
        return $this->isMollieConfigured();
    }

    public function payment($order, $request): JsonResponse
    {
        if (!$this->isMollieConfigured()) {
            return $this->unconfiguredResponse();
        }

        try {
            $created = $this->createPayment($order);

            return response()->json([
                'status'       => true,
                'checkout_url' => $created['checkout_url'],
                'payment_id'   => $created['payment_id'],
            ]);
        } catch (Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 502);
        }
    }

    /**
     * [P0-1 résidu SÉCU 2026-08-04] Rembourse AUTOMATIQUEMENT un paiement Mollie qui a été
     * encaissé sur une commande devenue TERMINALE (annulée pendant que le paiement était en vol)
     * → le client récupère son argent sans geste ops manuel. Best-effort : tout échec est loggé
     * (canal fiscal) pour rattrapage, jamais propagé (le webhook a déjà répondu 200).
     *
     * @param array{payment_id:string,value:string,currency:string,order_id:int} $r
     */
    private function refundMolliePayment(array $r): void
    {
        try {
            $resp = Http::withToken((string) config('payment.mollie.api_key'))
                ->acceptJson()
                ->timeout(15)
                ->post(config('payment.mollie.api_base') . '/payments/' . $r['payment_id'] . '/refunds', [
                    'amount'      => ['value' => $r['value'], 'currency' => $r['currency']],
                    'description' => 'Auto-remboursement — commande #' . $r['order_id'] . ' annulée avant confirmation du paiement',
                    'metadata'    => ['order_id' => (string) $r['order_id'], 'reason' => 'terminal_order_auto_refund'],
                ]);

            if ($resp->successful()) {
                Log::channel('fiscal')->warning('mollie.webhook.auto_refund_ok', [
                    'event' => 'mollie_webhook_auto_refund_ok', 'payment_id' => $r['payment_id'],
                    'order_id' => $r['order_id'], 'value' => $r['value'],
                ]);
            } else {
                Log::channel('fiscal')->error('mollie.webhook.auto_refund_failed', [
                    'event' => 'mollie_webhook_auto_refund_failed', 'payment_id' => $r['payment_id'],
                    'order_id' => $r['order_id'], 'http' => $resp->status(),
                    'note' => 'Rembourser MANUELLEMENT au dashboard Mollie.',
                ]);
            }
        } catch (Throwable $e) {
            Log::channel('fiscal')->error('mollie.webhook.auto_refund_error', [
                'event' => 'mollie_webhook_auto_refund_error', 'payment_id' => $r['payment_id'],
                'order_id' => $r['order_id'], 'message' => $e->getMessage(),
                'note' => 'Rembourser MANUELLEMENT au dashboard Mollie.',
            ]);
        }
    }

    /**
     * [P0-2 SÉCU 2026-08-04] Un remboursement/chargeback Mollie fait au dashboard doit se
     * refléter dans le système : bridge vers la cascade RefundCreated EXISTANTE (miroir Stripe)
     * → PersistOrderPaymentStatusChangedOnRefundCreated (payment_status=REFUNDED + broadcast)
     * + ReleaseStock + ReleaseAvailability + ClawbackLoyaltyPointsOnRefund. Idempotent (skip si
     * déjà REFUNDED). Conservateur NF525 : aucune allocation fiscale sous concurrence webhook —
     * la contre-écriture miroir NF525 (RefundWithCounterEntryService) reste un geste ops V1.
     */
    private function processMollieRefund(string $paymentId, int $orderId, WebhookEvent $event): JsonResponse
    {
        if ($orderId <= 0) {
            Log::channel('fiscal')->warning('mollie.webhook.refund_without_order', [
                'event' => 'mollie_webhook_refund_without_order', 'payment_id' => $paymentId,
            ]);
            $event->markProcessed();

            return response()->json(['status' => 'refund_without_order'], 200);
        }

        $order = FrontendOrder::withoutGlobalScope(BranchScope::class)->find($orderId);
        if (! $order instanceof FrontendOrder) {
            Log::channel('fiscal')->warning('mollie.webhook.refund_order_not_found', [
                'event' => 'mollie_webhook_refund_order_not_found', 'payment_id' => $paymentId, 'order_id' => $orderId,
            ]);
            $event->markProcessed($orderId);

            return response()->json(['status' => 'refund_order_not_found'], 200);
        }

        if ((int) $order->payment_status === PaymentStatus::REFUNDED) {
            $event->markProcessed($orderId);

            return response()->json(['status' => 'already_refunded'], 200);
        }

        Log::channel('fiscal')->warning('mollie.webhook.refund_recorded', [
            'event' => 'mollie_webhook_refund_recorded', 'payment_id' => $paymentId, 'order_id' => $orderId,
            'note' => 'Remboursement/chargeback Mollie → REFUNDED + clawback + release. Contre-écriture NF525 = geste ops (RefundWithCounterEntryService).',
        ]);

        // Cascade RefundCreated (DispatchableAfterCommit → post-commit). Le listener
        // PersistOrderPaymentStatusChangedOnRefundCreated mute payment_status=REFUNDED.
        \App\Events\RefundCreated::dispatch($order);

        $event->markProcessed($orderId);

        return response()->json(['status' => 'refund_recorded'], 200);
    }

    /**
     * [OWNER 2026-08-04 P1-C SÉCU] Re-drive DLQ : un webhook `paid` échoué en TRANSITOIRE
     * (deadlock, hiccup DB) reste `failed` ; ses rejeux Mollie tombent sur `duplicate_ignored`
     * (dedup empoisonné) → payé chez Mollie mais UNPAID chez nous → double-encaissement au
     * comptoir. Le cron DLQ rappelle cette méthode : on RE-FETCHE l'état frais (source de
     * vérité — le statut a pu se sceller depuis) et on re-joue le vrai chemin métier. Les
     * gardes internes (montant, terminal, idempotence) rendent le re-jeu sûr.
     */
    public function handleFromStoredEvent(WebhookEvent $event): void
    {
        $payload   = is_array($event->payload) ? $event->payload : [];
        $paymentId = (string) ($payload['id'] ?? '');
        if ($paymentId === '') {
            $event->markFailed('Stored Mollie payload missing id.');

            return;
        }

        if (! (bool) config('payment.mollie.enabled', false) || (string) config('payment.mollie.api_key', '') === '') {
            $event->markFailed('Mollie not configured — DLQ deferred.');

            return;
        }

        try {
            $fetch = Http::withToken((string) config('payment.mollie.api_key'))
                ->acceptJson()
                ->timeout(15)
                ->get(config('payment.mollie.api_base') . '/payments/' . $paymentId);
        } catch (Throwable $e) {
            // Erreur réseau transitoire → reste `failed`, le prochain re-drive réessaiera.
            $event->markFailed('DLQ re-fetch error: ' . $e->getMessage());

            return;
        }

        if (! $fetch->successful()) {
            $event->markFailed('DLQ re-fetch http ' . $fetch->status());

            return;
        }

        $fresh  = (array) $fetch->json();
        $status = (string) ($fresh['status'] ?? 'unknown');
        // Re-joue le vrai routage (mutation PAID/annulation + allocation fiscale + side-effects).
        $this->processFetchedPayment($paymentId, $status, $fresh, $event);
    }

    /**
     * Retour navigateur ≠ preuve de paiement : le webhook (source de vérité
     * re-fetchée) est le SEUL chemin qui marque PAID. Jamais de mutation ici.
     */
    public function success($order, $request): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('payment.successful', ['order' => $order]);
    }

    public function fail($order, $request): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('payment.index', ['order' => $order])->with(
            'error',
            trans('all.message.something_wrong')
        );
    }

    public function cancel($order, $request): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('home')->with('error', trans('all.message.payment_canceled'));
    }
}
