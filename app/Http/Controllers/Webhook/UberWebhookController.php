<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Uber\UberClient;
use App\Services\Uber\UberOrderMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [UBER-EATS 2026-07-01] Réception des commandes Uber Eats en temps réel.
 *
 * Flux : Uber POST le webhook → on vérifie la SIGNATURE HMAC → idempotence (webhook_events)
 * → on FETCH le détail complet de la commande (Orders API) → on MAPPE vers composition_snapshot
 * → on CRÉE la commande dans NOTRE système (source Uber, visible caisse « en cours » + KDS)
 * → auto-accept côté Uber si configuré.
 *
 * Sécurité : endpoint PUBLIC (pas d'auth Sanctum), mais body signé HMAC-SHA256. Répond TOUJOURS
 * 200 après idempotence pour éviter les rejeux inutiles ; 401 seulement si signature invalide.
 *
 * ⚠️ À VALIDER EN LIVE une fois le Production Access Uber accordé + le webhook enregistré.
 */
class UberWebhookController extends Controller
{
    public function __construct(
        private UberClient $client,
        private UberOrderMapper $mapper,
    ) {}

    public function handle(Request $request)
    {
        $raw = $request->getContent();

        // 1) Signature HMAC-SHA256 (clé = webhook signing secret, souvent le client_secret).
        if (! $this->signatureValid($request, $raw)) {
            Log::warning('[Uber webhook] signature invalide');
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $payload = json_decode($raw, true) ?: [];
        $webhookId = (string) ($payload['event_id'] ?? $payload['meta']['resource_id'] ?? $payload['resource_id'] ?? '');
        $eventType = (string) ($payload['event_type'] ?? $payload['meta']['event_type'] ?? '');
        $uberOrderId = (string) ($payload['meta']['resource_id'] ?? $payload['resource_id'] ?? '');

        if ($webhookId === '') {
            return response()->json(['status' => 'ignored_no_id'], 200);
        }

        // 2) Idempotence : une seule fois même si Uber rejoue.
        $event = DB::table('webhook_events')->where('provider', 'uber_eats')->where('webhook_id', $webhookId)->first();
        if ($event && $event->status === 'processed') {
            return response()->json(['status' => 'already_processed'], 200);
        }
        if (! $event) {
            DB::table('webhook_events')->insert([
                'provider'    => 'uber_eats',
                'webhook_id'  => $webhookId,
                'event_type'  => $eventType,
                'payload'     => $raw,
                'signature'   => (string) $request->header('X-Uber-Signature', ''),
                'status'      => 'pending',
                'received_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // On ne traite que les nouvelles commandes (les autres events sont acquittés 200).
        if ($eventType !== '' && ! str_contains(strtolower($eventType), 'order')) {
            $this->markProcessed($webhookId, null);
            return response()->json(['status' => 'ack'], 200);
        }

        try {
            // [GO-LIVE UBER 2026-07-04] Router par TYPE d'event. Le filtre large « contient order »
            // laissait passer orders.cancel/denied/fulfillment_issues vers createFromUber : le dédup
            // renvoyait la commande existante puis on RÉ-ACCEPTAIT côté Uber une commande ANNULÉE,
            // et la cuisine préparait une commande annulée (jamais passée CANCELED en interne).
            $etLower = strtolower($eventType);
            if (str_contains($etLower, 'cancel') || str_contains($etLower, 'denied') || str_contains($etLower, 'fulfillment')) {
                $orderId = $this->cancelFromUber($uberOrderId);
                $this->markProcessed($webhookId, $orderId);
                return response()->json(['status' => $orderId ? 'canceled' : 'ack_cancel_unknown'], 200);
            }

            $orderId = $this->createFromUber($uberOrderId);
            $this->markProcessed($webhookId, $orderId);

            if ($orderId && (bool) config('uber.auto_accept', true)) {
                $this->client->acceptOrder($uberOrderId);
            }
        } catch (\Throwable $e) {
            Log::error('[Uber webhook] traitement échec: ' . $e->getMessage(), ['uber_order' => $uberOrderId]);
            DB::table('webhook_events')->where('provider', 'uber_eats')->where('webhook_id', $webhookId)
                ->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            // [UBER-RETRY 2026-07-02] 2xx = ACK Uber (PAS de rejeu) → une commande PAYÉE serait perdue.
            // On renvoie 5xx sur échec transitoire → Uber REJOUE ; la dédup par uber order id
            // (createFromUber) empêche le doublon. Après N tentatives (poison définitif) on acquitte
            // 200 pour ne pas boucler indéfiniment ; la ligne webhook_events status=failed reste pour
            // le monitoring/alerte (à brancher : superviser webhook_events provider=uber_eats status=failed).
            $attempts = (int) (DB::table('webhook_events')->where('provider', 'uber_eats')->where('webhook_id', $webhookId)->value('attempts') ?? 1);
            if ($attempts < 5) {
                return response()->json(['status' => 'retry_requested', 'attempt' => $attempts], 503);
            }
            return response()->json(['status' => 'error_gave_up', 'note' => 'superviser webhook_events uber_eats status=failed'], 200);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /** Crée la commande dans notre système à partir du détail Uber. Retourne l'order_id ou null. */
    private function createFromUber(string $uberOrderId): ?int
    {
        if ($uberOrderId === '') {
            return null;
        }
        // [UBER-DEDUP 2026-07-02] Idempotence AU NIVEAU COMMANDE : l'idempotence webhook est keyée sur
        // event_id ; deux events Uber pour la MÊME commande (resource_id identique, event_id distincts,
        // filtre 'order' large) créeraient 2 commandes internes. On dédup ici sur l'uber order id.
        $existing = Order::withoutGlobalScopes()->where('transaction_id', 'uber:' . $uberOrderId)->first();
        if ($existing) {
            return (int) $existing->id;
        }
        $detail = $this->client->fetchOrder($uberOrderId);
        if (! $detail) {
            throw new \RuntimeException('fetchOrder null pour ' . $uberOrderId);
        }
        $mapped = $this->mapper->map($detail);

        $order = DB::transaction(function () use ($mapped, $uberOrderId) {
            $branchId = (int) config('uber.branch_id', 1);
            $order = (new Order)->forceFill([
                'branch_id'          => $branchId,
                // [GO-LIVE UBER 2026-07-04] orders.user_id est NOT NULL → SANS ancre user,
                // l'INSERT échouait TOUJOURS (la « commande perdue » était encore plus totale
                // que l'audit ne le disait : aucune commande Uber ne pouvait être créée).
                // Ancre = user technique NON privilégié (aucun rôle), même pattern que l'item
                // placeholder du mapper.
                'user_id'            => $this->uberSystemUserId($branchId),
                'order_type'         => \App\Enums\OrderType::DELIVERY, // canal agrégateur
                'source'             => \App\Enums\Source::WEB, // canal API/web ; Uber distingué par source_surface
                'source_surface'     => 'uber_eats',
                'status'             => \App\Enums\OrderStatus::ACCEPT, // visible KDS immédiatement (board-release OK car PAID)
                'payment_status'     => \App\Enums\PaymentStatus::PAID, // Uber prépayé
                // [GO-LIVE UBER 2026-07-04] Commande IMMÉDIATE : sans ce champ, le défaut DB
                // (Ask::YES) classait chaque commande Uber en PRÉCOMMANDE → le KDS affichait
                // delivery_date=DEMAIN et la rangeait dans la branche « advance ».
                'is_advance_order'   => \App\Enums\Ask::NO,
                // [GO-LIVE UBER 2026-07-04] business_date posé → l'index UNIQUE existant
                // (branch_id, business_date, queue_number) devient un vrai verrou anti-doublon
                // concurrent (avant : NULL = NULLs distincts sous MySQL, index inopérant).
                'business_date'      => now()->toDateString(),
                'total'              => $mapped['total'],
                'subtotal'           => $mapped['total'],
                'discount'           => 0,
                'queue_number'       => $mapped['queue_number'],
                'order_serial_no'    => $mapped['display_id'],
                // [UBER-DEDUP 2026-07-02] Clé d'idempotence AU NIVEAU COMMANDE (uber order id), pour
                // qu'un 2e event Uber sur la MÊME commande (event_id distinct) ne duplique pas.
                'transaction_id'     => 'uber:' . $uberOrderId,
                'order_datetime'     => now(),
                // Fiscal : NON par défaut (canal séparé). Si config uber.fiscalize=true, le
                // cron/encaissement alloue un fiscal_sequence_no ; sinon reste null (Uber facture à part).
            ]);
            $order->save();

            foreach ($mapped['items'] as $line) {
                (new OrderItem)->forceFill([
                    'order_id'             => $order->id,
                    'branch_id'            => $branchId,
                    'item_id'              => $line['item_id'],
                    'quantity'             => $line['quantity'],
                    'price'                => $line['unit_price'],
                    'discount'             => 0,
                    'total_price'          => $line['total'],
                    'composition_snapshot' => $line['composition_snapshot'],
                    'instruction'          => $line['instruction'],
                ])->save();
            }

            Log::info('[Uber webhook] commande créée', ['order_id' => $order->id, 'uber' => $uberOrderId, 'total' => $mapped['total']]);
            return $order;
        });

        // [GO-LIVE UBER 2026-07-04] Uber était le SEUL chemin de création sans OrderCreated →
        // pas de décrément stock/disponibilité (= SURVENTE silencieuse : un article épuisé via
        // Uber restait commandable borne/POS) ni de broadcast temps réel (KDS/OSS/caisse au
        // polling seul). DispatchableAfterCommit : la transaction ci-dessus est commitée, le
        // dispatch part immédiatement. Les impressions kiosk restent source-gated (kiosk-only),
        // donc AUCUNE impression parasite. Dédup : le chemin « existing » plus haut retourne
        // avant d'arriver ici → jamais re-dispatché.
        \App\Events\OrderCreated::dispatch($order);

        return (int) $order->id;
    }

    /**
     * [GO-LIVE UBER 2026-07-04] Annulation Uber → commande interne CANCELED (la cuisine arrête).
     * Retourne l'order_id interne, ou null si commande inconnue (ack simple, rien créé).
     */
    private function cancelFromUber(string $uberOrderId): ?int
    {
        if ($uberOrderId === '') {
            return null;
        }
        $order = Order::withoutGlobalScopes()->where('transaction_id', 'uber:' . $uberOrderId)->first();
        if (! $order) {
            return null; // jamais reçue (ou création échouée) : on n'invente rien.
        }
        if ((int) $order->status === \App\Enums\OrderStatus::CANCELED) {
            return (int) $order->id; // idempotent.
        }
        $oldStatus = (int) $order->status;
        $order->status = \App\Enums\OrderStatus::CANCELED;
        $order->save();
        // Broadcast canonique → le KDS retire la carte en temps réel (mêmes listeners que les
        // annulations POS/admin) ; le flux sync la sort via leftWindow (CANCELED).
        \App\Events\OrderStatusChanged::dispatch($order, $oldStatus, \App\Enums\OrderStatus::CANCELED);
        Log::info('[Uber webhook] commande annulée', ['order_id' => $order->id, 'uber' => $uberOrderId]);

        return (int) $order->id;
    }

    /** User technique Uber (non privilégié, aucun rôle) — ancre FK pour orders.user_id NOT NULL. */
    private function uberSystemUserId(int $branchId): int
    {
        $user = \App\Models\User::withoutGlobalScopes()->firstOrCreate(
            ['username' => 'uber-eats-system'],
            [
                'name'      => 'Uber Eats',
                'email'     => 'uber-eats@system.local',
                'phone'     => '0000000042',
                'password'  => bcrypt(bin2hex(random_bytes(16))),
                'branch_id' => $branchId,
            ]
        );

        return (int) $user->id;
    }

    private function markProcessed(string $webhookId, ?int $orderId): void
    {
        DB::table('webhook_events')->where('provider', 'uber_eats')->where('webhook_id', $webhookId)->update([
            'status'       => 'processed',
            'order_id'     => $orderId,
            'processed_at' => now(),
            'updated_at'   => now(),
        ]);
    }

    private function signatureValid(Request $request, string $raw): bool
    {
        $secret = (string) config('uber.webhook_signing_secret', '');
        if ($secret === '') {
            // Pas de secret configuré (.env non renseigné) : on REFUSE tout (fail-closed sécurité).
            return false;
        }
        $provided = (string) $request->header('X-Uber-Signature', '');
        if ($provided === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $raw, $secret);
        return hash_equals($expected, $provided);
    }
}
