<?php

namespace App\Services\UberDirect;

use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\UberDirectDelivery;
use App\Services\Delivery\DeliveryWaveService;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [UBER-DIRECT 2026-10-22 · owner] Bascule d'UNE livraison entre NOS livreurs et un coursier Uber, depuis la caisse.
 *
 * Quand les livreurs sont occupés ou absents, le caissier confie l'arrêt à Uber d'un appui (après avoir vu le prix
 * du devis) ; un second appui le rend à nos livreurs tant que le coursier Uber n'a pas le sac.
 *
 * Règles qui ne se discutent pas :
 *  · Uber ne peut PAS encaisser à la porte. Un arrêt « à encaisser » (espèces, carte, titre-restaurant) ne part
 *    jamais chez Uber : il faut l'avoir encaissé avant (paiement en ligne ou au comptoir).
 *  · Le cycle de LIVRAISON vit dans `delivery_stops` et `uber_direct_deliveries`, jamais dans la cuisine. Seule la
 *    clôture de la commande (PREPARED → EN ROUTE → LIVRÉE) passe par `OrderStateMachine::apply`, la voie prévue
 *    pour le code neuf — la zone gelée n'est pas modifiée.
 *  · Une panne Uber ne casse jamais la caisse : toute méthode rend un tableau `['ok' => bool, ...]` avec un message
 *    lisible, jamais une exception. La commande reste alors à nos livreurs.
 *  · Aucun secret n'est journalisé (le client Uber expurge ses journaux).
 *
 * ⚠️ Les CORPS de requête suivent la documentation publique d'Uber Direct (devis, création, annulation). Ils n'ont
 * pas encore été confrontés au compte réel (l'application n'a pas le droit `eats.deliveries`, cf. docs/LIVRAISON.md §6) :
 * le premier devis réel est la vérification.
 */
class UberDirectDispatchService
{
    public const MODE_OWN = 'own';
    public const MODE_UBER = 'uber';

    /** Statuts après lesquels le coursier a le sac : on ne peut plus rendre la course à nos livreurs. */
    private const BAG_TAKEN = [
        UberDirectDelivery::STATUS_PICKUP_COMPLETE,
        UberDirectDelivery::STATUS_DROPOFF,
        UberDirectDelivery::STATUS_DELIVERED,
    ];

    public function __construct(
        private readonly UberDirectClient $client,
        private readonly DeliveryWaveService $waves,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    /** Course Uber ACTIVE de cette commande (dépêchée et ni annulée ni revenue), sinon null. */
    public function activeCourse(int $orderId): ?UberDirectDelivery
    {
        return UberDirectDelivery::query()->withoutGlobalScope(BranchScope::class)
            ->where('order_id', $orderId)->activeCourse()->first();
    }

    public function mode(DeliveryStop $stop): string
    {
        return $this->activeCourse((int) $stop->order_id) ? self::MODE_UBER : self::MODE_OWN;
    }

    /**
     * Pourquoi cet arrêt ne peut PAS partir chez Uber (message pour le caissier), ou null s'il le peut.
     * Lu aussi par la planche pour griser le bouton avec la vraie raison.
     */
    public function blockedReason(DeliveryStop $stop, ?Order $order = null): ?string
    {
        if (! $this->isAvailable()) {
            return 'Uber Direct n\'est pas activé.';
        }
        if ($stop->left_at !== null || $stop->delivered_at !== null) {
            return 'Le livreur est déjà parti avec cette commande.';
        }
        $order ??= Order::query()->withoutGlobalScope(BranchScope::class)->with(['address', 'user'])->find($stop->order_id);
        if (! $order) {
            return 'Commande introuvable.';
        }
        if (in_array((int) $order->status, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::DELIVERED], true)) {
            return 'Cette commande est déjà clôturée.';
        }
        // Montant relu EN DIRECT : un paiement fait depuis la création de l'arrêt compte.
        if ($this->waves->collectionFor($order)['collect_method'] !== DeliveryStop::COLLECT_PAID_ONLINE) {
            return 'À encaisser à la porte : Uber ne peut pas encaisser. Faites payer la commande avant de la confier à Uber.';
        }
        $a = $order->address;
        if (! $a || ! is_numeric($a->latitude) || ! is_numeric($a->longitude) || blank($a->address)) {
            return 'Adresse de livraison incomplète.';
        }
        if ($this->phone($order->user?->phone) === null) {
            return 'Téléphone du client manquant ou invalide : Uber doit pouvoir l\'appeler.';
        }

        return null;
    }

    /**
     * Devis (gratuit, sans engagement) : prix et délai annoncés par Uber. Le caissier le voit avant de confirmer.
     *
     * @return array{ok: bool, message?: string, quote_id?: string, fee_cents?: int, fee?: float, eta_minutes?: int|null, expires_at?: string|null}
     */
    public function quote(DeliveryStop $stop): array
    {
        $order = Order::query()->withoutGlobalScope(BranchScope::class)->with(['address', 'user'])->find($stop->order_id);
        if ($reason = $this->blockedReason($stop, $order)) {
            return ['ok' => false, 'message' => $reason];
        }
        if ($this->activeCourse((int) $stop->order_id)) {
            return ['ok' => false, 'message' => 'Un coursier Uber est déjà demandé pour cette commande.'];
        }

        $branch = Branch::query()->find($order->branch_id);
        $raw = $this->client->createQuote($this->quoteBody($branch, $order));
        if ($raw === null || ($raw['id'] ?? '') === '') {
            return ['ok' => false, 'message' => 'Uber n\'a pas donné de prix (indisponible ou adresse non couverte). La commande reste à nos livreurs.'];
        }

        $fee = (int) ($raw['fee'] ?? 0);
        $row = UberDirectDelivery::query()->withoutGlobalScope(BranchScope::class)->firstOrNew(['order_id' => $order->id]);
        $row->fill([
            'branch_id' => (int) $order->branch_id,
            'quote_id' => (string) $raw['id'],
            'quote_fee_cents' => $fee,
            'customer_fee_cents' => 0, // le frais payé par le client est déjà celui de NOTRE barème ; Uber est un coût du restaurant
            'currency' => strtoupper((string) ($raw['currency'] ?? 'EUR')),
            // [E2E UBER 2026-10-24 · U-2] Uber date en UTC (« …Z »). Eloquent enregistre une Carbon dans SON fuseau, sans
            // conversion : « 14:06Z » était écrit « 14:06 » puis relu en Europe/Paris, soit 2 h trop tôt — tout devis paraissait
            // périmé et chaque « Confirmer Uber » répondait 409 « le prix a changé », sans fin. On ramène l'heure au fuseau de l'application.
            'quote_expires_at' => isset($raw['expires']) ? \Carbon\Carbon::parse($raw['expires'])->setTimezone(config('app.timezone')) : now()->addMinutes(10),
            'eta_minutes' => isset($raw['duration']) ? (int) $raw['duration'] : null,
            'pricing_rule' => 'caisse_swap',
            'failure_code' => null,
            'failure_message' => null,
            'quote_payload' => $raw,
        ])->save();

        return [
            'ok' => true,
            'quote_id' => $row->quote_id,
            'fee_cents' => $fee,
            'fee' => round($fee / 100, 2),
            'eta_minutes' => $row->eta_minutes,
            'expires_at' => $row->quote_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Confie l'arrêt à Uber, au prix du devis que le caissier a vu. Idempotent : un double appui ne crée pas deux courses.
     * Devis périmé ou changé → on ne dépêche PAS, on rend le nouveau prix à confirmer (jamais un montant non accepté).
     *
     * [E2E 2026-10-22 · B-003] L'appel réseau n'est PLUS dans une transaction : un verrou par commande (30 s) empêche
     * le double appui, et deux petites transactions encadrent l'appel. Si la réponse d'Uber se perd, la course a peut-être
     * été créée : on le DIT (jamais « la commande reste à nos livreurs » à tort), et la même `idempotency_key` fait qu'un
     * nouvel essai ne crée pas une 2ᵉ course.
     */
    public function dispatch(DeliveryStop $stop, string $quoteId, ?int $userId = null): array
    {
        $lock = Cache::lock('uber_dispatch_'.(int) $stop->order_id, 30);
        if (! $lock->get()) {
            return ['ok' => false, 'message' => 'Une demande Uber est déjà en cours pour cette commande.'];
        }

        try {
            // 1) Vérifications et marquage de la tentative — court, sans réseau.
            $early = null;
            $order = $row = null;
            DB::transaction(function () use ($stop, $quoteId, &$early, &$order, &$row) {
                $locked = DeliveryStop::query()->withoutGlobalScope(BranchScope::class)->whereKey($stop->id)->lockForUpdate()->first();
                if (! $locked) {
                    $early = ['ok' => false, 'message' => 'Arrêt introuvable.'];

                    return;
                }
                if ($existing = $this->activeCourse((int) $locked->order_id)) {
                    $early = ['ok' => true, 'already' => true, 'tracking_url' => $existing->tracking_url];

                    return;
                }
                $order = Order::query()->withoutGlobalScope(BranchScope::class)->with(['address', 'user'])->find($locked->order_id);
                if ($reason = $this->blockedReason($locked, $order)) {
                    $early = ['ok' => false, 'message' => $reason];

                    return;
                }
                $row = UberDirectDelivery::query()->withoutGlobalScope(BranchScope::class)->where('order_id', $order->id)->lockForUpdate()->first();
                if ($row && $row->quote_id === $quoteId && $row->quoteStillUsable()) {
                    $row->forceFill(['create_attempts' => (int) $row->create_attempts + 1, 'last_attempt_at' => now()])->save();

                    return;
                }
                $row = null; // devis inconnu ou périmé : nouveau prix à confirmer (demandé hors transaction)
            });

            if ($early) {
                return $early;
            }
            if (! $row) {
                $fresh = $this->quote($stop);

                return $fresh['ok']
                    ? ['ok' => false, 'code' => 'quote_changed', 'message' => 'Le devis a expiré : confirmez le nouveau prix.', 'quote' => $fresh]
                    : $fresh;
            }

            // 2) L'appel à Uber — HORS transaction.
            $branch = Branch::query()->find($order->branch_id);
            $raw = $this->client->createDelivery($this->deliveryBody($branch, $order, $row));
            if ($raw === null || ($raw['id'] ?? '') === '') {
                $row->forceFill([
                    'failure_code' => 'uber_unconfirmed',
                    'failure_message' => 'Uber n\'a pas confirmé la course : elle a peut-être été créée. Vérifiez dans l\'application Uber Direct avant de réessayer.',
                ])->save();

                return ['ok' => false, 'message' => 'Uber n\'a pas confirmé la course — elle a peut-être été créée. Vérifiez dans l\'application Uber Direct avant de réessayer ; la commande reste à nos livreurs en attendant.'];
            }

            // 3) Enregistrement — court. Un échec ici NE DOIT PAS se taire : la course existe chez Uber.
            try {
                DB::transaction(function () use ($stop, $row, $order, $raw, $userId) {
                    $row->forceFill([
                        'delivery_id' => (string) $raw['id'],
                        'tracking_url' => $raw['tracking_url'] ?? null,
                        'status' => (string) ($raw['status'] ?? UberDirectDelivery::STATUS_PENDING),
                        'status_updated_at' => now(),
                        'failure_code' => null,
                        'failure_message' => null,
                        'delivery_payload' => $raw,
                        'dropoff_postal_code' => $order->address->postal_code,
                        'dropoff_city' => $order->address->city,
                        'dropoff_phone' => $this->phone($order->user?->phone),
                    ])->save();

                    // Plus de livreur à nous sur cet arrêt : il disparaît de sa liste.
                    DeliveryStop::query()->withoutGlobalScope(BranchScope::class)->whereKey($stop->id)->update(['delivery_boy_id' => null]);
                    Order::query()->withoutGlobalScope(BranchScope::class)->whereKey($order->id)->update(['delivery_boy_id' => null]);

                    $this->audit('delivery.uber_dispatched', $order, $userId, ['delivery_id' => $row->delivery_id, 'fee_cents' => $row->quote_fee_cents]);
                });
            } catch (\Throwable $e) {
                Log::critical('uber_direct.dispatch_unrecorded', ['order_id' => $order->id, 'uber_delivery_id' => $raw['id'], 'error' => $e->getMessage()]);

                return ['ok' => false, 'message' => 'La course Uber '.$raw['id'].' a été créée mais n\'a pas pu être enregistrée. Ne recommencez pas : prévenez le responsable.'];
            }

            return ['ok' => true, 'tracking_url' => $row->tracking_url, 'status' => $row->status];
        } finally {
            $lock->release();
        }
    }

    /**
     * Rend l'arrêt à nos livreurs : annule la course Uber tant que le coursier n'a pas le sac.
     * [E2E 2026-10-22 · ADV2-003] Même verrou par commande que `dispatch` ; l'appel réseau n'est plus dans la transaction.
     */
    public function release(DeliveryStop $stop, ?int $userId = null): array
    {
        $lock = Cache::lock('uber_dispatch_'.(int) $stop->order_id, 30);
        if (! $lock->get()) {
            return ['ok' => false, 'message' => 'Une demande Uber est déjà en cours pour cette commande.'];
        }

        try {
            $locked = DeliveryStop::query()->withoutGlobalScope(BranchScope::class)->whereKey($stop->id)->first();
            $row = $locked ? $this->activeCourse((int) $locked->order_id) : null;
            if (! $row) {
                return ['ok' => true, 'already' => true];
            }
            if (in_array((string) $row->status, self::BAG_TAKEN, true)) {
                return ['ok' => false, 'message' => 'Le coursier Uber a déjà la commande : impossible de la reprendre.'];
            }
            if (! $this->cancelAtUber($row)) {
                return ['ok' => false, 'message' => 'Uber n\'a pas confirmé l\'annulation. Réessayez : la commande reste chez Uber en attendant.'];
            }

            DB::transaction(function () use ($row, $locked, $userId) {
                $row->forceFill(['status' => UberDirectDelivery::STATUS_CANCELED, 'status_updated_at' => now(), 'failure_code' => null, 'failure_message' => null])->save();
                $this->giveBackToOwnCouriers($locked);
                $order = Order::query()->withoutGlobalScope(BranchScope::class)->find($locked->order_id);
                $this->audit('delivery.uber_released', $order, $userId, ['delivery_id' => $row->delivery_id]);
            });

            return ['ok' => true];
        } finally {
            $lock->release();
        }
    }

    /**
     * Annule la course chez Uber. Une annulation refusée parce que la course est DÉJÀ annulée (ou revenue) chez Uber
     * compte comme réussie : on relit son état avant de conclure à un échec [E2E ADV2-004], sinon l'arrêt resterait
     * indéfiniment et le cron le retenterait à chaque passage.
     */
    private function cancelAtUber(UberDirectDelivery $row): bool
    {
        if ($this->client->cancelDelivery((string) $row->delivery_id) !== null) {
            return true;
        }
        $etat = $this->client->getDelivery((string) $row->delivery_id);

        return is_array($etat) && in_array((string) ($etat['status'] ?? ''), [UberDirectDelivery::STATUS_CANCELED, UberDirectDelivery::STATUS_RETURNED], true);
    }

    /**
     * [E2E 2026-10-22 · B-001] La commande est annulée / refusée : si un coursier Uber a été demandé, on annule la
     * course AVANT de laisser disparaître l'arrêt. Rend vrai quand il n'y a plus rien chez Uber à notre charge.
     * Faux = la course court encore (sac déjà pris, ou Uber n'a pas confirmé) : l'arrêt doit rester visible à la caisse,
     * avec la raison, jamais une course facturée sans trace.
     */
    public function cancelCourseForClosedOrder(int $orderId): bool
    {
        try {
            $row = $this->activeCourse($orderId);
        } catch (\Throwable $e) {
            // [ADV2-002] Table absente / base indisponible : aucune trace Uber n'est lisible. L'annulation d'une commande
            // ORDINAIRE (la quasi-totalité) ne doit jamais en dépendre : on le journalise et on laisse l'arrêt se libérer.
            Log::warning('uber_direct.course_lookup_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);

            return true;
        }
        if (! $row) {
            return true;
        }
        if (in_array((string) $row->status, self::BAG_TAKEN, true)) {
            $row->forceFill(['failure_code' => 'order_closed_bag_taken', 'failure_message' => 'Commande annulée alors que le coursier Uber a déjà le sac : à régler avec Uber et le client.'])->save();

            return false;
        }
        if (! $this->cancelAtUber($row)) {
            $row->forceFill(['failure_code' => 'order_closed_cancel_failed', 'failure_message' => 'Commande annulée mais Uber n\'a pas confirmé l\'annulation de la course : à annuler dans l\'application Uber Direct.'])->save();

            return false;
        }
        $row->forceFill(['status' => UberDirectDelivery::STATUS_CANCELED, 'status_updated_at' => now(), 'failure_code' => null, 'failure_message' => null])->save();

        return true;
    }

    /** Ordre des statuts d'une course : un événement rejoué ou tardif ne la fait jamais reculer. */
    private const STATUS_RANK = [
        UberDirectDelivery::STATUS_PENDING => 0,
        UberDirectDelivery::STATUS_PICKUP => 1,
        UberDirectDelivery::STATUS_PICKUP_COMPLETE => 2,
        UberDirectDelivery::STATUS_DROPOFF => 3,
        UberDirectDelivery::STATUS_DELIVERED => 4,
    ];

    /**
     * Applique un statut Uber (webhook ou relevé périodique). Idempotent ; ne recule jamais d'un état terminal.
     */
    public function applyStatus(UberDirectDelivery $row, string $status, ?array $payload = null): void
    {
        if ($row->isTerminal() && $row->status === $status) {
            return;
        }
        if ($row->isTerminal()) {
            return; // livrée / annulée / revenue : un événement tardif ou rejoué ne rouvre rien
        }
        // [B-004] Un événement plus ancien que l'état connu (rejeu, courrier désordonné) ne la fait pas reculer.
        $nouveau = self::STATUS_RANK[$status] ?? null;
        $actuel = self::STATUS_RANK[(string) $row->status] ?? null;
        if ($nouveau !== null && $actuel !== null && $nouveau < $actuel) {
            return;
        }

        // [ADV2-001] Un statut INCONNU (ajouté demain par Uber) est enregistré dans la charge utile et journalisé, mais
        // n'écrase jamais un statut connu : « sac pris » ne redevient pas « inconnu » (la reprise serait alors permise à tort).
        $inconnuSurConnu = $nouveau === null && $actuel !== null
            && ! in_array($status, [UberDirectDelivery::STATUS_CANCELED, UberDirectDelivery::STATUS_RETURNED], true);
        if ($inconnuSurConnu) {
            Log::warning('uber_direct.unknown_status', ['order_id' => $row->order_id, 'status' => $status]);
        }

        $row->forceFill(array_filter([
            'status' => $inconnuSurConnu ? null : $status,
            'status_updated_at' => now(),
            'tracking_url' => $payload['tracking_url'] ?? null,
            // Le statut inconnu n'est pas perdu : il reste lu dans la charge utile (« unknown_status »).
            'delivery_payload' => $inconnuSurConnu ? array_merge((array) $row->delivery_payload, (array) $payload, ['unknown_status' => $status]) : $payload,
        ], fn ($v) => $v !== null))->save();
        if ($inconnuSurConnu) {
            return;
        }

        $stop = DeliveryStop::query()->withoutGlobalScope(BranchScope::class)->where('order_id', $row->order_id)->first();
        $order = Order::query()->withoutGlobalScope(BranchScope::class)->find($row->order_id);

        try {
            match ($status) {
                UberDirectDelivery::STATUS_PICKUP_COMPLETE, UberDirectDelivery::STATUS_DROPOFF => $this->onBagTaken($stop, $order),
                UberDirectDelivery::STATUS_DELIVERED => $this->onDelivered($stop, $order),
                UberDirectDelivery::STATUS_CANCELED, UberDirectDelivery::STATUS_RETURNED => $this->onFailed($stop, $order, $status, $row),
                default => null,
            };
        } catch (\Throwable $e) {
            // Le statut Uber est déjà enregistré ; la clôture de la commande échouée est signalée, jamais avalée.
            Log::error('uber_direct.apply_status_failed', ['order_id' => $row->order_id, 'status' => $status, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Relevé des courses actives auprès d'Uber (filet quand le webhook n'est pas branché ou s'est perdu).
     * Au plus une fois toutes les 15 s, quel que soit le nombre d'écrans ouverts.
     */
    public function syncActive(): void
    {
        if (! $this->isAvailable()) {
            return;
        }
        $lock = Cache::lock('uber_direct_sync', 15);
        if (! $lock->get()) {
            return;
        }
        try {
            UberDirectDelivery::query()->withoutGlobalScope(BranchScope::class)->activeCourse()
                ->whereNotIn('status', UberDirectDelivery::TERMINAL_STATUSES)->orderBy('status_updated_at')->limit(8)->get()
                ->each(function (UberDirectDelivery $row) {
                    $raw = $this->client->getDelivery((string) $row->delivery_id);
                    if (is_array($raw) && isset($raw['status'])) {
                        $this->applyStatus($row, (string) $raw['status'], $raw);
                    }
                });
        } finally {
            $lock->release();
        }
    }

    // ── effets d'un statut ──────────────────────────────────────────────────────────────────

    private function onBagTaken(?DeliveryStop $stop, ?Order $order): void
    {
        $stop?->forceFill(['left_at' => $stop->left_at ?? now()])->save();
        $this->moveOrder($order, OrderStatus::OUT_FOR_DELIVERY);
    }

    private function onDelivered(?DeliveryStop $stop, ?Order $order): void
    {
        $stop?->forceFill([
            'left_at' => $stop->left_at ?? now(),
            'arrived_at' => $stop->arrived_at ?? now(),
            'delivered_at' => $stop->delivered_at ?? now(),
            'amount_collected_cents' => 0, // payée d'avance : rien n'est encaissé à la porte
        ])->save();
        $this->moveOrder($order, OrderStatus::DELIVERED);
    }

    /** Uber a annulé ou renvoyé la course de son côté : l'arrêt revient à nos livreurs, et on le dit. */
    private function onFailed(?DeliveryStop $stop, ?Order $order, string $status, UberDirectDelivery $row): void
    {
        if ($stop && $stop->left_at === null) {
            $this->giveBackToOwnCouriers($stop);
        } else {
            // [E2E 2026-10-22 · B-002] Le coursier avait DÉJÀ le sac : la commande n'est pas livrée et ne peut plus être
            // reprise par nos livreurs (la commande est EN ROUTE). On ne la rend pas en silence : la caisse la voit en alerte.
            $row->forceFill([
                'failure_code' => 'uber_'.$status.'_after_pickup',
                'failure_message' => ($status === UberDirectDelivery::STATUS_RETURNED ? 'Uber a ramené la commande au restaurant' : 'Uber a annulé la course')
                    .' alors que le coursier avait le sac : la commande n\'est pas livrée. À traiter avec le client.',
            ])->save();
        }
        Log::warning('uber_direct.course_failed', ['order_id' => $order?->id, 'status' => $status, 'after_pickup' => $stop?->left_at !== null]);
    }

    /** Fait avancer la commande par la voie prévue pour le code neuf ; sans effet si elle est déjà plus loin. */
    private function moveOrder(?Order $order, int $target): void
    {
        if (! $order) {
            return;
        }
        $from = (int) $order->status;
        if ($from === $target || $from >= OrderStatus::DELIVERED) {
            return;
        }
        // [E2E UBER 2026-10-24 · U-3] Une commande de la caisse est encore EN PRÉPARATION (7) quand le coursier Uber prend le sac :
        // la machine refuse 7 → 10 et la commande restait « en préparation » pour toujours (site et tableau de suivi jamais « en
        // route » ni « livrée »). Le sac pris signifie que la cuisine a fini : on passe par PRÊTE (8) puis EN ROUTE (10), chaque
        // étape par la machine officielle et avec son événement — jamais en la contournant. Même règle que notre propre livreur,
        // qui ne part qu'une commande PRÊTE (DeliveryRunController::depart).
        if ($from === OrderStatus::PREPARING && $target === OrderStatus::OUT_FOR_DELIVERY && OrderStateMachine::allows($from, OrderStatus::PREPARED)) {
            OrderStateMachine::apply($order, OrderStatus::PREPARED);
            OrderStatusChanged::dispatch($order, $from, OrderStatus::PREPARED);
            $order->refresh();
            $from = (int) $order->status;
        }
        if (! OrderStateMachine::allows($from, $target)) {
            Log::warning('uber_direct.order_transition_refused', ['order_id' => $order->id, 'from' => $from, 'to' => $target]);

            return;
        }
        OrderStateMachine::apply($order, $target);
        OrderStatusChanged::dispatch($order, $from, $target);
    }

    private function giveBackToOwnCouriers(DeliveryStop $stop): void
    {
        $courier = $this->waves->defaultCourierId((int) $stop->branch_id);
        $stop->forceFill(['delivery_boy_id' => $courier])->save();
        Order::query()->withoutGlobalScope(BranchScope::class)->whereKey($stop->order_id)->update(['delivery_boy_id' => $courier]);
        if ($courier) {
            $orderId = (int) $stop->order_id;
            DB::afterCommit(function () use ($orderId) {
                try {
                    \App\Events\SendOrderDeliveryBoyMail::dispatch(['order_id' => $orderId, 'status' => 101]);
                    \App\Events\SendOrderDeliveryBoySms::dispatch(['order_id' => $orderId, 'status' => 101]);
                    \App\Events\SendOrderDeliveryBoyPush::dispatch(['order_id' => $orderId, 'status' => 101]);
                } catch (\Throwable $e) {
                    Log::warning('uber_direct.courier.notify_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
                }
            });
        }
    }

    // ── corps de requête ────────────────────────────────────────────────────────────────────

    private function quoteBody(?Branch $branch, Order $order): array
    {
        $a = $order->address;

        return array_filter([
            'pickup_address' => $this->addressJson($branch?->address, $branch?->zip_code, $branch?->city),
            'dropoff_address' => $this->addressJson($a->address, $a->postal_code, $a->city),
            'pickup_latitude' => $branch?->latitude !== null ? (float) $branch->latitude : null,
            'pickup_longitude' => $branch?->longitude !== null ? (float) $branch->longitude : null,
            'dropoff_latitude' => (float) $a->latitude,
            'dropoff_longitude' => (float) $a->longitude,
            'pickup_phone_number' => $this->phone($branch?->phone),
            'dropoff_phone_number' => $this->phone($order->user?->phone),
            'pickup_ready_dt' => $this->readyAt($order),
            'manifest_total_value' => (int) round(((float) $order->total) * 100),
            'external_store_id' => 'branch-'.$order->branch_id,
        ], fn ($v) => $v !== null);
    }

    private function deliveryBody(?Branch $branch, Order $order, UberDirectDelivery $row): array
    {
        $a = $order->address;
        $ref = (string) ($order->order_serial_no ?: $order->id);

        return $this->quoteBody($branch, $order) + array_filter([
            'quote_id' => $row->quote_id,
            'pickup_name' => (string) ($branch?->name ?: 'Le Cayenne'),
            'dropoff_name' => (string) ($order->user?->name ?: 'Client'),
            'dropoff_notes' => trim(implode(' — ', array_filter([$a->apartment, 'Commande #'.$ref]))),
            'manifest_reference' => 'order-'.$order->id,
            'manifest_items' => [['name' => 'Commande Le Cayenne #'.$ref, 'quantity' => 1, 'size' => 'medium']],
            // Même clé pour le même DEVIS : un réessai après délai dépassé ne fabrique pas une 2ᵉ course. [E2E UBER 2026-10-24 · P2]
            // La clé porte le devis et plus seulement la commande : après une course annulée côté Uber, re-confier la commande
            // (nouveau devis) ne renvoyait que la course ANNULÉE sous la même clé — « ok » à l'écran, aucun coursier en route.
            'idempotency_key' => 'lecayenne-order-'.$order->id.'-'.$row->quote_id,
            'external_id' => 'order-'.$order->id,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function addressJson(?string $street, ?string $zip, ?string $city): string
    {
        return json_encode([
            'street_address' => [(string) $street],
            'city' => (string) $city,
            'state' => '',
            'zip_code' => (string) $zip,
            'country' => 'FR',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Heure à laquelle le sac sera prêt : maintenant si la commande l'est déjà, sinon le temps de préparation restant. */
    private function readyAt(Order $order): string
    {
        $minutes = (int) $order->status === OrderStatus::PREPARED ? 0 : min(60, max(0, (int) $order->preparation_time));

        return now()->addMinutes($minutes)->toIso8601String();
    }

    /** Numéro au format international exigé par Uber (E.164) ; null si on ne peut pas le garantir. */
    public function phone(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if ($digits === '' || $digits === null) {
            return null;
        }
        if (str_starts_with((string) $raw, '+')) {
            return strlen($digits) >= 10 ? '+'.$digits : null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);

            return strlen($digits) >= 10 ? '+'.$digits : null;
        }
        if (strlen($digits) === 10 && $digits[0] === '0') {
            return '+33'.substr($digits, 1);
        }
        if (str_starts_with($digits, '33') && strlen($digits) === 11) {
            return '+'.$digits;
        }

        return null;
    }

    private function audit(string $action, ?Order $order, ?int $userId, array $payload): void
    {
        try {
            app(AuditLogService::class)->write([
                'branch_id' => (int) ($order?->branch_id ?? 0),
                'user_id' => (int) $userId,
                'action' => $action,
                'resource' => 'order',
                'resource_id' => (int) ($order?->id ?? 0),
                'payload' => $payload,
                'ip' => (string) (request()->ip() ?? ''),
                'user_agent' => substr((string) request()->userAgent(), 0, 512),
            ]);
        } catch (\Throwable $e) {
            Log::error('uber_direct.audit_failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
