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

    /** Crée la commande dans notre système à partir du détail Uber. Retourne l'order_id ou null. */
    private function createFromUber(string $uberOrderId): ?int
    {
        if ($uberOrderId === '') {
            return null;
        }
        // [UBER-DEDUP 2026-07-02] Idempotence AU NIVEAU COMMANDE : l'idempotence webhook est keyée sur
        // event_id ; deux events Uber pour la MÊME commande (resource_id identique, event_id distincts,
        // filtre 'order' large) créeraient 2 commandes internes. On dédup ici sur l'uber order id.
        $existing = Order::withoutGlobalScopes()->where('transaction_id', 'uber:'.$uberOrderId)->first();
        if ($existing) {
            return (int) $existing->id;
        }
        // [SELF-AUDIT R3 P2 2026-07-05 — annulation AVANT création] Si un cancel Uber est arrivé AVANT ce
        // create (pierre tombale posée par cancelFromUber), la commande a été annulée par le client avant
        // même sa création interne → on NE crée PAS (sinon commande fantôme LIVE : stock décrémenté, KDS,
        // ré-accept Uber). Ack neutre, rien créé.
        $canceledBeforeCreate = DB::table('webhook_events')
            ->where('provider', 'uber_eats')
            ->where('webhook_id', 'uber-cancel-tombstone:'.$uberOrderId)
            ->exists();
        if ($canceledBeforeCreate) {
            Log::info('[Uber webhook] création ignorée — commande annulée AVANT création', ['uber' => $uberOrderId]);

            return null;
        }
        $detail = $this->client->fetchOrder($uberOrderId);
        if (! $detail) {
            throw new \RuntimeException('fetchOrder null pour '.$uberOrderId);
        }
        $mapped = $this->mapper->map($detail);

        $branchId = (int) config('uber.branch_id', 1);
        $userId = $this->uberSystemUserId($branchId);
        $baseQueue = (string) ($mapped['queue_number'] ?? '');

        // [SELF-AUDIT P1 2026-07-05 — perte de commande PAYÉE] queue_number = 'U'+4 derniers du
        // display_id n'est PAS unique par commande → 2 commandes Uber du même jour dont les
        // display_id se terminent pareil COLLISIONNENT sur l'index UNIQUE (branch, business_date,
        // queue_number) → l'INSERT de la 2e throw → rollback → 5×503 → 200 give-up → commande PAYÉE
        // perdue (le bug MÊME que le go-live prétendait éliminer ; mon commentaire « vrai verrou »
        // était faux : l'index verrouille sur le suffixe NON-unique du display_id). Fix = boucle de
        // récupération sur violation d'unicité : (a) si la MÊME commande (transaction_id) existe déjà
        // (insert concurrent) → dédup VRAI, on la retourne ; (b) sinon AUTRE commande qui collisionne
        // sur queue_number → on désambiguïse le queue et on retente.
        for ($attempt = 0; $attempt <= 5; $attempt++) {
            $queue = $attempt === 0 ? $baseQueue : ($baseQueue.'-'.$attempt);
            try {
                $order = DB::transaction(function () use ($mapped, $uberOrderId, $branchId, $userId, $queue) {
                    $order = (new Order)->forceFill([
                        'branch_id' => $branchId,
                        // [GO-LIVE UBER 2026-07-04] orders.user_id est NOT NULL → SANS ancre user,
                        // l'INSERT échouait TOUJOURS (la « commande perdue » était encore plus totale
                        // que l'audit ne le disait : aucune commande Uber ne pouvait être créée).
                        // Ancre = user technique NON privilégié (aucun rôle), même pattern que l'item
                        // placeholder du mapper. Hoisté hors boucle : une seule résolution firstOrCreate.
                        'user_id' => $userId,
                        'order_type' => \App\Enums\OrderType::DELIVERY, // canal agrégateur
                        'source' => \App\Enums\Source::WEB, // canal API/web ; Uber distingué par source_surface
                        'source_surface' => 'uber_eats',
                        'status' => \App\Enums\OrderStatus::ACCEPT, // visible KDS immédiatement (board-release OK car PAID)
                        'payment_status' => \App\Enums\PaymentStatus::PAID, // Uber prépayé
                        // [GO-LIVE UBER 2026-07-04] Commande IMMÉDIATE : sans ce champ, le défaut DB
                        // (Ask::YES) classait chaque commande Uber en PRÉCOMMANDE → le KDS affichait
                        // delivery_date=DEMAIN et la rangeait dans la branche « advance ».
                        'is_advance_order' => \App\Enums\Ask::NO,
                        // [GO-LIVE UBER 2026-07-04] business_date posé → l'index UNIQUE existant
                        // (branch_id, business_date, queue_number) devient un vrai verrou anti-doublon
                        // concurrent (avant : NULL = NULLs distincts sous MySQL, index inopérant).
                        'business_date' => now()->toDateString(),
                        'total' => $mapped['total'],
                        'subtotal' => $mapped['total'],
                        'discount' => 0,
                        'queue_number' => $queue, // désambiguïsé par la boucle de récupération anti-collision
                        'order_serial_no' => $mapped['display_id'],
                        // [UBER-DEDUP 2026-07-02] Clé d'idempotence AU NIVEAU COMMANDE (uber order id), pour
                        // qu'un 2e event Uber sur la MÊME commande (event_id distinct) ne duplique pas.
                        'transaction_id' => 'uber:'.$uberOrderId,
                        'order_datetime' => now(),
                        // Fiscal : NON par défaut (canal séparé). Si config uber.fiscalize=true, le
                        // cron/encaissement alloue un fiscal_sequence_no ; sinon reste null (Uber facture à part).
                    ]);
                    $order->save();

                    foreach ($mapped['items'] as $line) {
                        (new OrderItem)->forceFill([
                            'order_id' => $order->id,
                            'branch_id' => $branchId,
                            'item_id' => $line['item_id'],
                            'quantity' => $line['quantity'],
                            'price' => $line['unit_price'],
                            'discount' => 0,
                            'total_price' => $line['total'],
                            'composition_snapshot' => $line['composition_snapshot'],
                            'instruction' => $line['instruction'],
                        ])->save();
                    }

                    Log::info('[Uber webhook] commande créée', ['order_id' => $order->id, 'uber' => $uberOrderId, 'total' => $mapped['total']]);

                    return $order;
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e; // erreur DB réelle → on ne l'avale pas.
                }
                // (a) Insert concurrent de la MÊME commande Uber (2e event pendant la création) →
                // dédup VRAI : la ligne existe déjà sous ce transaction_id, on la retourne.
                $existing = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where('transaction_id', 'uber:'.$uberOrderId)->first();
                if ($existing) {
                    return (int) $existing->id;
                }
                // (b) AUTRE commande, même queue_number → l'itération suivante désambiguïse
                // (queue-1, queue-2…). On ne relève qu'après épuisement des tentatives : jamais
                // de commande PAYÉE perdue en silence.
                if ($attempt >= 5) {
                    throw $e;
                }

                continue;
            }

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

        // Inatteignable : chaque itération retourne ou relève avant la 6e passe.
        throw new \RuntimeException('Uber: résolution queue_number impossible pour '.$uberOrderId);
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
        $terminal = [
            \App\Enums\OrderStatus::CANCELED,
            \App\Enums\OrderStatus::REJECTED,
            \App\Enums\OrderStatus::RETURNED,
            \App\Enums\OrderStatus::DELIVERED,
        ];
        if (in_array((int) $order->status, $terminal, true)) {
            if ((int) $order->status === \App\Enums\OrderStatus::DELIVERED) {
                Log::warning('[Uber webhook] cancel reçu sur commande déjà REMISE — ignoré', [
                    'order_id' => $order->id, 'uber' => $uberOrderId,
                ]);
            }

            return (int) $order->id; // no-op idempotent.
        }
        $oldStatus = (int) $order->status;
        $order->status = \App\Enums\OrderStatus::CANCELED;
        $order->save();
        // Broadcast canonique → le KDS retire la carte en temps réel (mêmes listeners que les
        // annulations POS/admin) ; le flux sync la sort via leftWindow (CANCELED).
        \App\Events\OrderStatusChanged::dispatch($order, $oldStatus, \App\Enums\OrderStatus::CANCELED);
        // [SELF-AUDIT P2 2026-07-05 — FUITE STOCK] OrderCreated (createFromUber) déclenche
        // DecrementItemAvailabilityOnOrder + DecrementStockOnOrderCreated → sans l'événement de
        // compensation, l'annulation Uber laissait stock/dispo décrémentés À VIE (article
        // faussement épuisé borne/POS). OrderCanceled → ReleaseStock + ReleaseAvailability.
        \App\Events\OrderCanceled::dispatch($order);
        Log::info('[Uber webhook] commande annulée', ['order_id' => $order->id, 'uber' => $uberOrderId]);

        return (int) $order->id;
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

    /** User technique Uber (non privilégié, aucun rôle) — ancre FK pour orders.user_id NOT NULL. */
    private function uberSystemUserId(int $branchId): int
    {
        // [Z6-P1-WGS] Branch-fence only (singulier) : le user système ne doit PAS ressusciter
        // une ligne soft-deleted → SoftDeletingScope conservé.
        $user = \App\Models\User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->firstOrCreate(
            ['username' => 'uber-eats-system'],
            [
                'name' => 'Uber Eats',
                'email' => 'uber-eats@system.local',
                'phone' => '0000000042',
                'password' => bcrypt(bin2hex(random_bytes(16))),
                'branch_id' => $branchId,
            ]
        );

        return (int) $user->id;
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
