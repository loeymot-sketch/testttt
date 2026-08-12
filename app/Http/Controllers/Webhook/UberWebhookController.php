<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Order;
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
            try {
                DB::table('webhook_events')->insert([
                    'provider' => 'uber_eats',
                    'webhook_id' => $webhookId,
                    'event_type' => $eventType,
                    'payload' => $raw,
                    'signature' => (string) $request->header('X-Uber-Signature', ''),
                    'status' => 'pending',
                    'received_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // [SELF-AUDIT R3 P3 2026-07-05 — TOCTOU idempotence webhook] check-then-insert : deux
                // livraisons CONCURRENTES du même event passaient toutes deux le SELECT (0 ligne) puis
                // inséraient → la 2e violait l'UNIQUE (provider, webhook_id) → 500 non capturé + le même
                // event pouvait entrer 2× en traitement. On avale la violation d'unicité = un autre worker
                // a déjà pris cet event → ack 200 sans re-traiter.
                if ($this->isUniqueViolation($e)) {
                    return response()->json(['status' => 'already_processing'], 200);
                }
                throw $e;
            }
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
            // [SELF-AUDIT P3 2026-07-05 — routage cancel explicite] « contient fulfillment » avalait
            // orders.fulfillment_issues.resolved (une RÉSOLUTION d'incident, PAS une annulation) vers
            // cancelFromUber → commande LIVE annulée à tort. On route sur des signaux d'annulation
            // EXPLICITES uniquement.
            $isCancel = str_contains($etLower, 'cancel')
                || str_contains($etLower, 'denied')
                || str_contains($etLower, 'reject')
                || str_contains($etLower, 'failure');
            if ($isCancel) {
                $orderId = $this->cancelFromUber($uberOrderId);
                $this->markProcessed($webhookId, $orderId);

                return response()->json(['status' => $orderId ? 'canceled' : 'ack_cancel_unknown'], 200);
            }

            // Création = signal Uber orders.notification UNIQUEMENT. Tout autre event « order » qui
            // n'est ni création ni annulation (fulfillment_issues.resolved, adjust…) est acquitté SANS
            // effet de bord — ne PAS le router vers createFromUber (dédup → RÉ-ACCEPT parasite d'une
            // commande peut-être déjà annulée, le bug même décrit ci-dessus).
            if (! str_contains($etLower, 'notification')) {
                $this->markProcessed($webhookId, null);

                return response()->json(['status' => 'ack_order_event_noop', 'event' => $etLower], 200);
            }

            $orderId = $this->createFromUber($uberOrderId);
            $this->markProcessed($webhookId, $orderId);

            if ($orderId && (bool) config('uber.auto_accept', true)) {
                $this->client->acceptOrder($uberOrderId);
            }
        } catch (\Throwable $e) {
            Log::error('[Uber webhook] traitement échec: '.$e->getMessage(), ['uber_order' => $uberOrderId]);
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

    /**
     * Crée la commande dans notre système à partir du détail Uber. Retourne l'order_id ou null.
     *
     * [UBER-PHOTO 2026-08-10] La CRÉATION elle-même vit désormais dans {@see UberOrderIngestor},
     * partagée avec le canal « ticket photographié ». Toutes les protections historiques y ont
     * été déplacées TELLES QUELLES — idempotence au niveau commande, pierre tombale d'annulation
     * antérieure, boucle anti-collision de numéro d'appel, utilisateur technique d'ancrage,
     * diffusion `OrderCreated`. Ce contrôleur ne garde que ce qui lui est propre : récupérer le
     * détail chez Uber et le convertir.
     */
    private function createFromUber(string $uberOrderId): ?int
    {
        if ($uberOrderId === '') {
            return null;
        }

        $dedupKey = 'uber:'.$uberOrderId;

        // Dédup AVANT l'appel réseau : inutile d'interroger Uber pour une commande déjà connue.
        $existing = Order::withoutGlobalScopes()->where('transaction_id', $dedupKey)->first();
        if ($existing) {
            return (int) $existing->id;
        }
        // Annulation arrivée AVANT la création : ne rien créer (l'ingesteur revérifie aussi, mais
        // on évite ici un appel réseau inutile).
        if (DB::table('webhook_events')->where('provider', 'uber_eats')
            ->where('webhook_id', 'uber-cancel-tombstone:'.$uberOrderId)->exists()) {
            Log::info('[Uber webhook] création ignorée — commande annulée AVANT création', ['uber' => $uberOrderId]);

            return null;
        }

        $detail = $this->client->fetchOrder($uberOrderId);
        if (! $detail) {
            throw new \RuntimeException('fetchOrder null pour '.$uberOrderId);
        }
        $mapped = $this->mapper->map($detail);
        // Le nom du client était extrait par le mapper puis JETÉ : la cuisine ne voyait qu'un
        // numéro. Il rejoint le canal que le ticket et la caisse affichent déjà.
        $mapped['customer_name'] = (string) ($mapped['raw_customer'] ?? '');

        return app(\App\Services\Uber\UberOrderIngestor::class)->ingest($mapped, $dedupKey, [
            'tombstone_key' => 'uber-cancel-tombstone:'.$uberOrderId,
        ]);
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
        // [Z6-P1-WGS] Bypass branch-fence SEULEMENT (singulier) : une commande Uber SOFT-DELETED
        // n'a pas à être annulée (déjà partie) → on garde le SoftDeletingScope actif.
        $order = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('transaction_id', 'uber:'.$uberOrderId)->first();
        if (! $order) {
            // [SELF-AUDIT R3 P2 2026-07-05 — annulation AVANT création] Le cancel peut précéder la
            // notification de création (create en retry 503, ou désordre Uber). Sans trace, un rejeu
            // ultérieur du create ressusciterait une commande DÉJÀ ANNULÉE (stock décrémenté + KDS +
            // ré-accept Uber d'une commande annulée). On pose une PIERRE TOMBALE keyée sur l'uber order
            // id (webhook_id synthétique distinct de tout event_id) ; createFromUber la consulte avant
            // de créer. insertOrIgnore = idempotent (l'UNIQUE (provider, webhook_id) absorbe les rejeux).
            DB::table('webhook_events')->insertOrIgnore([
                'provider' => 'uber_eats',
                'webhook_id' => 'uber-cancel-tombstone:'.$uberOrderId,
                'event_type' => 'orders.cancel',
                'payload' => json_encode(['note' => 'uber cancel-before-create tombstone', 'uber_order_id' => $uberOrderId]),
                'signature' => null,
                'status' => 'processed',
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return null; // jamais reçue (ou création échouée) : on n'invente rien, mais on trace l'annulation.
        }
        // [SELF-AUDIT P3 2026-07-05 — garde d'état terminal] Ne JAMAIS re-basculer une commande
        // déjà dans un état terminal. CANCELED = idempotent (rejeu du même webhook).
        // DELIVERED/REJECTED/RETURNED = déjà clôturée : un cancel Uber tardif ne doit pas
        // « ré-annuler » (fausserait le reporting + déclencherait un 2e release stock). On
        // journalise DELIVERED (cancel après remise = signal suspect) mais on n'agit pas.
        //
        // [TERRAIN-HEAL 2026-07-16 · UBER-CANCEL-RACE] La lecture du statut ci-dessus (ligne $order
        // fetché sans verrou) puis save() était sujette à une RACE : deux webhooks cancel concurrents
        // (Uber rejoue volontiers) lisaient tous deux un statut non-terminal → deux OrderCanceled →
        // DOUBLE release stock/dispo (fuite inverse : article ressuscité en double). Miroir du fix
        // FRONT-CANCEL-RACE : on relit la commande SOUS verrou dans une transaction, on re-teste
        // l'état terminal DANS le verrou (le 2e webhook voit CANCELED et sort no-op), puis on ne
        // dispatch les événements de compensation QU'UNE fois.
        $terminal = [
            \App\Enums\OrderStatus::CANCELED,
            \App\Enums\OrderStatus::REJECTED,
            \App\Enums\OrderStatus::RETURNED,
            \App\Enums\OrderStatus::DELIVERED,
        ];

        return DB::transaction(function () use ($order, $uberOrderId, $terminal) {
            $locked = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                return (int) $order->id;
            }
            if (in_array((int) $locked->status, $terminal, true)) {
                if ((int) $locked->status === \App\Enums\OrderStatus::DELIVERED) {
                    Log::warning('[Uber webhook] cancel reçu sur commande déjà REMISE — ignoré', [
                        'order_id' => $locked->id, 'uber' => $uberOrderId,
                    ]);
                }

                return (int) $locked->id; // no-op idempotent (inclut le 2e webhook concurrent).
            }
            $oldStatus = (int) $locked->status;
            $locked->status = \App\Enums\OrderStatus::CANCELED;
            $locked->save();
            // Broadcast canonique → le KDS retire la carte en temps réel (mêmes listeners que les
            // annulations POS/admin) ; le flux sync la sort via leftWindow (CANCELED).
            \App\Events\OrderStatusChanged::dispatch($locked, $oldStatus, \App\Enums\OrderStatus::CANCELED);
            // [SELF-AUDIT P2 2026-07-05 — FUITE STOCK] OrderCreated (createFromUber) déclenche
            // DecrementItemAvailabilityOnOrder + DecrementStockOnOrderCreated → sans l'événement de
            // compensation, l'annulation Uber laissait stock/dispo décrémentés À VIE (article
            // faussement épuisé borne/POS). OrderCanceled → ReleaseStock + ReleaseAvailability.
            \App\Events\OrderCanceled::dispatch($locked);
            Log::info('[Uber webhook] commande annulée', ['order_id' => $locked->id, 'uber' => $uberOrderId]);

            return (int) $locked->id;
        });
    }

    /**
     * Violation d'unicité MySQL/SQLite ? (index UNIQUE (branch, business_date, queue_number)).
     * SQLSTATE 23000 + code moteur 1062 (MySQL) / 19 ou 2067 (SQLite) selon le driver de test.
     */
    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000'
            || in_array($driverCode, [1062, 19, 2067], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    private function markProcessed(string $webhookId, ?int $orderId): void
    {
        DB::table('webhook_events')->where('provider', 'uber_eats')->where('webhook_id', $webhookId)->update([
            'status' => 'processed',
            'order_id' => $orderId,
            'processed_at' => now(),
            'updated_at' => now(),
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
