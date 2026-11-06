<?php

namespace App\Services\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\DeliveryHandoverToken;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\UberDirectDelivery;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [LIVRAISON QR 2026-11-01] Jeton de remise à usage unique, imprimé en QR sur le ticket CLIENT d'une livraison.
 *
 * Principes (voir docs/LIVRAISON.md §8) :
 *  - jeton = 256 bits, DÉRIVÉ (HMAC d'une clé dérivée d'APP_KEY) : une réimpression redonne EXACTEMENT le même QR sans
 *    qu'aucun clair ne soit stocké ; seul `sha256(jeton)` est en base. Révoquer = générer `generation + 1`.
 *  - URL = {APP_URL}/livreur/remise#{jeton} : le FRAGMENT n'est jamais envoyé au serveur (ni journaux, ni Referer).
 *  - validité évaluée EN DIRECT à chaque lecture ({@see self::inspect()}), jamais mémorisée : commande annulée,
 *    arrêt réattribué, course Uber, déjà remis, périmé.
 *  - brûlure atomique {@see self::burn()} : `UPDATE … WHERE used_at IS NULL` ; une seule remise gagne.
 */
class DeliveryHandoverService
{
    public const SCAN_PATH = '/livreur/remise';
    /** Le QR vit jusqu'à `wave_at` + ce nombre d'heures (décision propriétaire, valeur par défaut à confirmer). */
    public const LIFETIME_HOURS_AFTER_WAVE = 6;

    public const REASON_REVOKED = 'revoked';
    public const REASON_USED = 'used';
    public const REASON_EXPIRED = 'expired';
    public const REASON_CANCELLED = 'cancelled';
    public const REASON_NOT_ASSIGNED = 'not_assigned';
    public const REASON_DELIVERED = 'delivered';
    public const REASON_NOT_DEPARTED = 'not_departed';

    // ── dérivation ───────────────────────────────────────────────────────────────────────────

    /** Clé dédiée, dérivée de la clé de l'application : ne sert qu'à ce jeton. */
    private function derivationKey(): string
    {
        return hash_hmac('sha256', 'lecayenne.delivery-handover.v1', (string) config('app.key'), true);
    }

    /** Jeton en clair (43 caractères base64url) d'une génération d'un arrêt. Jamais stocké. */
    public function tokenFor(DeliveryStop $stop, int $generation): string
    {
        $raw = hash_hmac('sha256', sprintf('v1|%d|%d|%d', $stop->id, $stop->order_id, $generation), $this->derivationKey(), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function looksLikeToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1;
    }

    /** URL encodée dans le QR : hôte de la PLATEFORME (APP_URL), jeton dans le fragment. */
    public function urlFor(string $token): string
    {
        return rtrim((string) config('app.url'), '/').self::SCAN_PATH.'#'.$token;
    }

    // ── émission ─────────────────────────────────────────────────────────────────────────────

    /** Un QR n'a de sens que pour une livraison NOTRE tournée, encore à remettre. */
    private function issuable(DeliveryStop $stop, ?Order $order): bool
    {
        if (! $order || $stop->delivered_at !== null) {
            return false;
        }
        if ((int) $order->order_type !== (int) OrderType::DELIVERY || strtolower((string) $order->source_surface) === 'uber_eats') {
            return false;
        }
        if (in_array((int) $order->status, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED, OrderStatus::DELIVERED], true)) {
            return false;
        }

        return ! $this->hasActiveUberCourse((int) $order->id);
    }

    private function hasActiveUberCourse(int $orderId): bool
    {
        return UberDirectDelivery::query()->withoutGlobalScope(BranchScope::class)->where('order_id', $orderId)->activeCourse()->exists();
    }

    private function expiryFor(DeliveryStop $stop): Carbon
    {
        return Carbon::parse($stop->wave_at)->addHours(self::LIFETIME_HOURS_AFTER_WAVE);
    }

    /**
     * Jeton en clair à imprimer pour cet arrêt, ou null si aucun ne peut (plus) l'être.
     * Idempotent : même arrêt, même génération → même jeton (réimpression = même QR). Un jeton révoqué ou brûlé n'est JAMAIS
     * ressuscité par une réimpression — voir {@see self::regenerate()} pour en émettre un neuf, volontairement.
     */
    public function issueForStop(DeliveryStop $stop): ?string
    {
        $order = Order::query()->withoutGlobalScope(BranchScope::class)->find($stop->order_id);
        if (! $this->issuable($stop, $order)) {
            return null;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return DB::transaction(fn () => $this->issueLocked($stop, $order));
            } catch (QueryException $e) {
                // Deux impressions simultanées : l'UNIQUE (arrêt, génération) a départagé, on relit.
                Log::info('delivery.handover.issue_race', ['stop_id' => $stop->id]);
            }
        }

        return null;
    }

    private function issueLocked(DeliveryStop $stop, Order $order): ?string
    {
        $latest = DeliveryHandoverToken::query()->withoutGlobalScope(BranchScope::class)
            ->where('delivery_stop_id', $stop->id)->orderByDesc('generation')->lockForUpdate()->first();

        if ($latest === null) {
            return $this->createGeneration($stop, 1);
        }
        if ($latest->revoked_at !== null || $latest->used_at !== null) {
            return null;
        }

        $token = $this->tokenFor($stop, (int) $latest->generation);
        if (! hash_equals((string) $latest->token_hash, self::hash($token))) {
            // La clé de l'application a changé depuis l'impression : l'ancien QR est mort, on en émet un neuf.
            $latest->forceFill(['revoked_at' => now()])->save();

            return $this->createGeneration($stop, (int) $latest->generation + 1);
        }

        // La tournée a pu bouger : l'échéance la suit.
        $expires = $this->expiryFor($stop);
        if ($latest->expires_at === null || ! $latest->expires_at->equalTo($expires) || $latest->wave_at === null || ! $latest->wave_at->equalTo($stop->wave_at)) {
            $latest->forceFill(['expires_at' => $expires, 'wave_at' => $stop->wave_at])->save();
        }

        return $token;
    }

    private function createGeneration(DeliveryStop $stop, int $generation): string
    {
        $token = $this->tokenFor($stop, $generation);
        DeliveryHandoverToken::query()->withoutGlobalScope(BranchScope::class)->create([
            'branch_id'        => (int) $stop->branch_id,
            'order_id'         => (int) $stop->order_id,
            'delivery_stop_id' => (int) $stop->id,
            'token_hash'       => self::hash($token),
            'generation'       => $generation,
            'wave_at'          => $stop->wave_at,
            'expires_at'       => $this->expiryFor($stop),
        ]);

        return $token;
    }

    /** Tue le QR actuel de l'arrêt (génération courante). Renvoie le nombre de jetons révoqués. */
    public function revoke(DeliveryStop $stop, string $reason = 'manual'): int
    {
        $n = DeliveryHandoverToken::query()->withoutGlobalScope(BranchScope::class)
            ->where('delivery_stop_id', $stop->id)->whereNull('revoked_at')->whereNull('used_at')->update(['revoked_at' => now()]);
        if ($n > 0) {
            Log::info('delivery.handover.revoked', ['stop_id' => $stop->id, 'reason' => $reason, 'count' => $n]);
        }

        return $n;
    }

    /** Révoque l'ancien QR et en émet un neuf (génération + 1) : l'ancien papier ne vaut plus rien. */
    public function regenerate(DeliveryStop $stop): ?string
    {
        $order = Order::query()->withoutGlobalScope(BranchScope::class)->find($stop->order_id);
        if (! $this->issuable($stop, $order)) {
            return null;
        }

        return DB::transaction(function () use ($stop) {
            $latest = DeliveryHandoverToken::query()->withoutGlobalScope(BranchScope::class)
                ->where('delivery_stop_id', $stop->id)->orderByDesc('generation')->lockForUpdate()->first();
            if ($latest && $latest->used_at !== null) {
                return null;
            }
            if ($latest && $latest->revoked_at === null) {
                $latest->forceFill(['revoked_at' => now()])->save();
            }

            return $this->createGeneration($stop, ($latest ? (int) $latest->generation : 0) + 1);
        });
    }

    /**
     * URL à encoder sur le ticket d'une commande, ou null (pas de livraison, pas d'arrêt, jeton impossible).
     * Ne lève JAMAIS : imprimer un ticket ne doit pas dépendre du QR.
     */
    public function qrUrlForOrder(int $orderId): ?string
    {
        try {
            $stop = DeliveryStop::query()->withoutGlobalScope(BranchScope::class)->where('order_id', $orderId)->first();
            if (! $stop) {
                return null;
            }
            $token = $this->issueForStop($stop);

            return $token === null ? null : $this->urlFor($token);
        } catch (\Throwable $e) {
            Log::warning('delivery.handover.qr_url_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // ── lecture, évaluation en direct, brûlure ───────────────────────────────────────────────

    /** Recherche par jeton en clair. AVANT connexion : ignore BranchScope (le contrôle de branche est dans {@see inspect}). */
    public function find(string $token): ?DeliveryHandoverToken
    {
        if (! self::looksLikeToken($token)) {
            return null;
        }

        return DeliveryHandoverToken::query()->withoutGlobalScope(BranchScope::class)->where('token_hash', self::hash($token))->first();
    }

    /**
     * Ce jeton est-il utilisable PAR CE LIVREUR, maintenant ? Évalué en direct (jamais mémorisé).
     * Le contrôle d'attribution passe EN PREMIER : un autre que le livreur de l'arrêt n'apprend rien d'autre que « non ».
     *
     * @return array{ok: bool, reason: ?string, stop: ?DeliveryStop, order: ?Order}
     */
    public function inspect(DeliveryHandoverToken $row, User $user): array
    {
        $fail = static fn (string $reason, ?DeliveryStop $stop = null, ?Order $order = null): array => ['ok' => false, 'reason' => $reason, 'stop' => $stop, 'order' => $order];

        $stop = DeliveryStop::query()->withoutGlobalScope(BranchScope::class)->find($row->delivery_stop_id);
        $order = Order::query()->withoutGlobalScope(BranchScope::class)->find($row->order_id);
        if (! $stop || ! $order || (int) $stop->order_id !== (int) $row->order_id) {
            return $fail(self::REASON_REVOKED);
        }

        $mine = (int) $user->id > 0
            && (int) $user->branch_id > 0
            && (int) $user->branch_id === (int) $row->branch_id
            && (int) $stop->delivery_boy_id === (int) $user->id
            && (int) $order->delivery_boy_id === (int) $user->id
            && (int) $stop->branch_id === (int) $row->branch_id
            && (int) $order->branch_id === (int) $row->branch_id
            && ! $this->hasActiveUberCourse((int) $order->id);
        if (! $mine) {
            return $fail(self::REASON_NOT_ASSIGNED);
        }

        if ($row->revoked_at !== null) {
            return $fail(self::REASON_REVOKED, $stop, $order);
        }
        if (in_array((int) $order->status, [OrderStatus::CANCELED, OrderStatus::REJECTED], true)) {
            return $fail(self::REASON_CANCELLED, $stop, $order);
        }
        if ($stop->delivered_at !== null || (int) $order->status === OrderStatus::DELIVERED) {
            return $fail(self::REASON_DELIVERED, $stop, $order);
        }
        if ($row->used_at !== null) {
            return $fail(self::REASON_USED, $stop, $order);
        }
        // Échéance LIVE : la tournée peut avoir bougé depuis l'impression.
        if (Carbon::now()->gt($this->expiryFor($stop))) {
            return $fail(self::REASON_EXPIRED, $stop, $order);
        }
        if ($stop->left_at === null || (int) $order->status !== OrderStatus::OUT_FOR_DELIVERY) {
            return $fail(self::REASON_NOT_DEPARTED, $stop, $order);
        }

        return ['ok' => true, 'reason' => null, 'stop' => $stop, 'order' => $order];
    }

    /**
     * Brûle le jeton, atomiquement : une seule requête concurrente obtient `true`.
     *
     * @param  array<string,mixed>  $extra  colonnes de traçabilité (modes, montants, appareil, position…)
     */
    public function burn(DeliveryHandoverToken $row, int $userId, array $extra = []): bool
    {
        $affected = DeliveryHandoverToken::query()->withoutGlobalScope(BranchScope::class)
            ->whereKey($row->id)->whereNull('used_at')->whereNull('revoked_at')
            ->update($extra + ['used_at' => now(), 'used_by_user_id' => $userId, 'updated_at' => now()]);

        return $affected === 1;
    }

    // ── lecture pour la page (après connexion) ───────────────────────────────────────────────

    /**
     * Ce que la page de remise affiche au livreur CONNECTÉ dont c'est l'arrêt. Tout autre cas : {@see HandoverRefused::unavailable()},
     * identique pour un jeton inconnu, révoqué, périmé ou qui n'est pas le sien.
     *
     * @return array<string,mixed>
     * @throws HandoverRefused
     */
    public function resolve(User $user, string $token): array
    {
        $row = $this->find($token);
        if (! $row) {
            throw HandoverRefused::unavailable();
        }
        $v = $this->inspect($row, $user);
        $stop = $v['stop'];
        $order = $v['order'];
        $this->throwForReason($v['reason'], $row, $user, allowDelivered: true);

        if ($v['reason'] !== null) { // livrée / brûlée : le livreur attribué revoit l'heure, rien à faire
            return ['state' => 'delivered', 'order_number' => $order->order_serial_no, 'delivered_at' => $stop->delivered_at?->toDateTimeString()];
        }

        $due = app(DeliveryWaveService::class)->collectionFor($order);

        return ['state' => 'ready'] + $this->present($stop, $order, $due);
    }

    /** @return array<string,mixed> */
    private function present(DeliveryStop $stop, Order $order, array $due): array
    {
        $order->loadMissing(['address', 'user']);
        $addr = $order->address;
        $hasPoint = $addr && is_numeric($addr->latitude) && is_numeric($addr->longitude);
        $chosen = $due['collect_method'];

        return [
            'order_number'     => $order->order_serial_no,
            'customer_name'    => $order->user?->name,
            'customer_phone'   => $order->user?->phone,
            'address'          => $addr?->address,
            'apartment'        => $addr?->apartment,
            'city'             => $addr?->city,
            'postal_code'      => $addr?->postal_code,
            'waze_url'         => $hasPoint ? sprintf('https://waze.com/ul?ll=%s,%s&navigate=yes&zoom=17', $addr->latitude, $addr->longitude) : null,
            'maps_url'         => $hasPoint ? sprintf('https://www.google.com/maps/dir/?api=1&destination=%s,%s&travelmode=driving', $addr->latitude, $addr->longitude) : null,
            'chosen_mode'      => $chosen,
            'collect_label'    => match ($chosen) {
                DeliveryStop::COLLECT_CASH => 'Encaisser en espèces',
                DeliveryStop::COLLECT_CARD => 'Encaisser par carte',
                DeliveryWaveService::COLLECT_MEAL_VOUCHER => 'Encaisser en titre-restaurant',
                default => 'Déjà payé',
            },
            'amount_due_cents' => (int) $due['amount_due_cents'],
            'amount_due'       => round(((int) $due['amount_due_cents']) / 100, 2),
            'allowed_modes'    => $this->allowedModes($order, $due),
            // [LIVREUR CAISSE 2026-11-02] Règlement en plusieurs moyens possible : à encaisser et encaissable à la porte.
            'split_allowed'    => (int) $due['amount_due_cents'] > 0 && $this->doorSettleable($order),
        ];
    }

    /**
     * Modes que le livreur peut déclarer. Espèces ↔ carte seulement (décision propriétaire) ; titre-restaurant = titre-restaurant ;
     * payée en ligne = aucun ; tout ce qui n'est pas encaissable à la porte (carte en ligne jamais payée…) = [] + {@see self::doorSettleable()} faux.
     *
     * @return list<string>
     */
    private function allowedModes(Order $order, array $due): array
    {
        if ((int) $due['amount_due_cents'] <= 0 || ! $this->doorSettleable($order)) {
            return [];
        }

        return match ($due['collect_method']) {
            DeliveryStop::COLLECT_CASH, DeliveryStop::COLLECT_CARD => ['cash', 'card'],
            DeliveryWaveService::COLLECT_MEAL_VOUCHER => ['meal_voucher'],
            default => [],
        };
    }

    /** Une commande encore à payer n'est encaissable à la porte que si c'est de l'espèces/carte-à-la-livraison ou un titre-restaurant. */
    private function doorSettleable(Order $order): bool
    {
        if ((int) $order->payment_status === PaymentStatus::PAID) {
            return true; // rien à encaisser
        }

        return in_array((int) $order->payment_method, [PaymentGateway::CASH_ON_DELIVERY, PaymentGateway::TICKET_RESTAURANT], true);
    }

    /**
     * Traduit la raison d'une inspection en refus HTTP. Les raisons qui ne concernent QUE le livreur attribué (annulée, pas
     * encore parti…) ne sortent qu'après le contrôle d'attribution (inspect() le fait en premier).
     */
    private function throwForReason(?string $reason, DeliveryHandoverToken $row, User $user, bool $allowDelivered = false): void
    {
        $ids = [$row->id, $row->order_id, $row->delivery_stop_id];
        switch ($reason) {
            case null:
                return;
            case self::REASON_NOT_ASSIGNED:
            case self::REASON_REVOKED:
            case self::REASON_EXPIRED:
                throw HandoverRefused::unavailable(...$ids);
            case self::REASON_CANCELLED:
                throw new HandoverRefused(409, 'cancelled', 'Commande annulée : ne la remettez pas, rapportez-la au restaurant.', ...$ids);
            case self::REASON_NOT_DEPARTED:
                throw new HandoverRefused(409, 'not_departed', 'Partez d\'abord avec la tournée (« Je pars » sur l\'écran Livreur), puis scannez à nouveau.', ...$ids);
            case self::REASON_DELIVERED:
            case self::REASON_USED:
                if ($allowDelivered) {
                    return;
                }
                throw new HandoverRefused(409, $reason === self::REASON_USED ? 'already_used' : 'already_delivered', 'Cette commande a déjà été remise.', ...$ids);
        }
    }

    // ── validation : « Remis » ───────────────────────────────────────────────────────────────

    /** Durée de vie du verrou de remise : un processus mort ne bloque personne plus de 30 s. */
    public const LOCK_SECONDS = 30;

    public static function lockKey(int $stopId): string
    {
        return 'delivery-handover:stop:'.$stopId;
    }

    /**
     * Valide la remise. Deux règles qui ne se discutent pas :
     *  - ⚠️ AUCUNE TRANSACTION n'enveloppe `OrderService::deliveryBoyOrderChangeStatus` : il écrit ses audits NF525 (escrow, tranche
     *    porte, caisse livreur) et envoie mail/SMS/push APRÈS son propre COMMIT ; sous une transaction externe ils partaient verrous
     *    tenus (1205 sur la chaîne d'audit) ou avant un échec (relecture adverse C) ;
     *  - AUCUN ÉTAT PERSISTÉ d'« en cours » (relecture adverse D : une réservation sur la ligne du jeton survivait à un processus mort
     *    et faussait le mode) : l'exclusion de deux appuis est un VERROU APPLICATIF à expiration automatique, `Cache::lock(…, 30)`,
     *    tenu par un propriétaire pendant TOUT le corps puis libéré par lui seul (`finally`). Un processus mort ne bloque plus personne
     *    après 30 s ; un jumeau qui ne peut pas le prendre répond 409 « réessayez » et ne rappelle JAMAIS OrderService.
     * Sous le verrou : l'état est RELU ; si la commande est déjà LIVRÉE (jumeau, ancien écran, crash après commit), on ne rappelle pas
     * OrderService : on termine l'arrêt avec le mode RÉELLEMENT appliqué, lu dans la commande, jamais dans la requête courante.
     *
     * @param  array{declared_mode?: ?string, collected_cents?: ?int, lat?: ?float, lng?: ?float, accuracy?: ?float, device_id?: ?string, ip?: ?string, user_agent?: ?string}  $in
     * @return array{already: bool, data: array<string,mixed>}
     * @throws HandoverRefused
     */
    public function confirm(User $user, string $token, array $in): array
    {
        $row = $this->find($token);
        if (! $row) {
            throw HandoverRefused::unavailable();
        }
        $ids = [$row->id, $row->order_id, $row->delivery_stop_id];

        $lock = Cache::lock(self::lockKey((int) $row->delivery_stop_id), self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new HandoverRefused(409, 'in_progress', 'Validation en cours, réessayez dans un instant.', ...$ids);
        }
        try {
            return $this->confirmLocked($user, $row->refresh(), $in, $ids);
        } finally {
            $lock->release(); // ne libère que si CE processus en est encore le propriétaire (le verrou a pu expirer)
        }
    }

    /**
     * @param  array<string,mixed>  $in
     * @param  array<int,int|null>  $ids
     * @return array{already: bool, data: array<string,mixed>}
     */
    private function confirmLocked(User $user, DeliveryHandoverToken $row, array $in, array $ids): array
    {
        $v = $this->inspect($row, $user); // état RELU sous le verrou
        if (! $v['ok']) {
            $stop = $v['stop'];
            $order = $v['order'];
            if (in_array($v['reason'], [self::REASON_USED, self::REASON_DELIVERED], true)
                && $row->used_at !== null && (int) $row->used_by_user_id === (int) $user->id && $stop?->delivered_at !== null) {
                return ['already' => true, 'data' => $this->result($stop, $order, $row)]; // double appui : déjà fait, rien n'est rejoué
            }
            if ($v['reason'] === self::REASON_DELIVERED && $row->used_at === null && $stop !== null && $stop->delivered_at === null
                && (int) $order->status === OrderStatus::DELIVERED) {
                // Commande LIVRÉE sans que l'arrêt le soit (jumeau, ancien écran, crash après commit) : OrderService n'est PAS rappelé ;
                // on termine l'arrêt avec ce qui s'est RÉELLEMENT passé, lu dans la commande.
                return $this->finalize($user, $row, $in, $this->appliedModeFromOrder($order, false), (int) round(((float) $order->total) * 100));
            }
            $this->throwForReason($v['reason'], $row, $user);
        }
        $order = $v['order'];

        $due = app(DeliveryWaveService::class)->collectionFor($order);
        $dueCents = (int) $due['amount_due_cents'];
        // [LIVREUR CAISSE 2026-11-02] Règlement en plusieurs moyens (facultatif, exclusif avec `declared_mode`). Absent = chemin inchangé.
        $payments = $in['payments'] ?? null;
        $tranches = null;
        if ($payments !== null) {
            if (isset($in['declared_mode']) && $in['declared_mode'] !== '') {
                throw new HandoverRefused(422, 'mode_conflict', 'Choisissez un seul mode de règlement : un moyen unique OU plusieurs moyens.', ...$ids);
            }
            $tranches = $this->validatedSplit($order, $due, $payments, $ids);
            $declared = null;
            $in['payments_applied'] = array_map(static fn (array $t) => ['mode' => $t['mode'], 'amount_cents' => $t['cents']], $tranches);
        } else {
            $declared = $this->validatedMode($order, $due, $in['declared_mode'] ?? null, $in['collected_cents'] ?? null, $ids);
        }

        // Voie officielle, SEULE : encaissement à la porte, numéro fiscal NF525, escrow, audits et notifications APRÈS son commit.
        // Un échec laisse tout intact (rien n'est écrit par nous avant) ; le verrou est libéré par le `finally` de l'appelant.
        app(OrderService::class)->deliveryBoyOrderChangeStatus(
            $order,
            Request::create('', 'POST', ['status' => OrderStatus::DELIVERED]),
            $declared,
            $tranches === null ? null : $payments
        );

        $after = Order::query()->withoutGlobalScope(BranchScope::class)->find($order->id);
        if (! ($after && (int) $after->status === OrderStatus::DELIVERED && ($dueCents === 0 || (int) $after->payment_status === PaymentStatus::PAID))) {
            Log::critical('delivery.handover.not_settled', ['order_id' => $order->id, 'status' => $after?->status, 'payment_status' => $after?->payment_status]);
        }
        // Le mode ENREGISTRÉ est celui que la commande porte maintenant ; celui de la requête n'est qu'un repli.
        $fallback = $tranches === null ? $declared : (count($tranches) > 1 ? 'split' : $tranches[0]['mode']);
        $applied = $dueCents > 0 ? ($after ? $this->appliedModeFromOrder($after, true) : null) ?? $fallback : null;

        return $this->finalize($user, $row, $in, $applied, $dueCents);
    }

    /**
     * Mode réellement encaissé à la porte, LU dans la commande livrée (jamais dans la requête). Preuves : une tranche `order_payments`
     * de référence « livraison » (carte, titre-restaurant) ; pour l'espèces, `pos_payment_method = CASH` + la trace d'escrow
     * (ou `$knownDoorCollection` quand c'est NOTRE appel qui vient d'encaisser). Aucune preuve = payée avant la porte → null.
     */
    private function appliedModeFromOrder(Order $order, bool $knownDoorCollection): ?string
    {
        $pays = \App\Models\OrderPayment::query()->withoutGlobalScope(BranchScope::class)
            ->where('order_id', $order->id)->where('reference', 'livraison')->orderByDesc('id')->get();
        if ($pays->count() >= 2) {
            return 'split'; // [LIVREUR CAISSE 2026-11-02] plusieurs moyens encaissés à la porte
        }
        $pay = $pays->first();
        if ($pay) {
            return match ((int) $pay->mode) {
                \App\Enums\PosPaymentMethod::CARD => 'card',
                \App\Enums\PosPaymentMethod::TICKET_RESTAURANT => 'meal_voucher',
                \App\Enums\PosPaymentMethod::CASH => 'cash', // espèces notées comme tranche unique (règlement en « payments »)
                default => null,
            };
        }
        if ((int) $order->pos_payment_method === \App\Enums\PosPaymentMethod::CASH
            && ($knownDoorCollection || $this->hasCashEvidence($order))) {
            return 'cash';
        }

        return null;
    }

    /**
     * Preuve que des ESPÈCES ont été encaissées à la porte : la trace d'audit d'escrow OU la ligne de caisse livreur de la commande. Les deux
     * sont écrites « au mieux » après le commit d'OrderService ; il suffit que l'une survive pour ne pas noter 27,50 € d'espèces à 0 €.
     */
    private function hasCashEvidence(Order $order): bool
    {
        if (\App\Models\AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->where('resource_id', $order->id)->exists()) {
            return true;
        }

        return \App\Models\DeliveryBoyCashMovement::query()->withoutGlobalScope(BranchScope::class)
            ->where('order_id', $order->id)->where('type', \App\Models\DeliveryBoyCashMovement::TYPE_ORDER_COLLECT)->exists();
    }

    /** Choix initial du client, relu dans la commande (avant encaissement) : espèces / carte à la livraison / titre-restaurant / payée. */
    private function chosenModeFromOrder(Order $order, ?string $applied): string
    {
        if ($applied === null) {
            return DeliveryStop::COLLECT_PAID_ONLINE;
        }
        if ((int) $order->payment_method === PaymentGateway::TICKET_RESTAURANT) {
            return 'meal_voucher';
        }

        return (string) $order->door_payment_mode === 'card' ? 'card' : 'cash';
    }

    /**
     * Petite transaction, APRÈS une remise officielle faite (par nous ou par un autre chemin) : verrou de l'arrêt, brûlure atomique du
     * jeton avec le mode APPLIQUÉ, arrêt mis à jour avec la commande telle qu'elle est, trace d'audit.
     *
     * @param  array<string,mixed>  $in
     * @return array{already: bool, data: array<string,mixed>}
     */
    private function finalize(User $user, DeliveryHandoverToken $row, array $in, ?string $applied, int $totalCents): array
    {
        return DB::transaction(function () use ($user, $row, $in, $applied, $totalCents) {
            $stop = DeliveryStop::query()->withoutGlobalScope(BranchScope::class)->whereKey($row->delivery_stop_id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->withoutGlobalScope(BranchScope::class)->findOrFail($row->order_id);
            $row = $row->refresh();
            if ($stop->delivered_at !== null || $row->used_at !== null) {
                return ['already' => true, 'data' => $this->result($stop, $order, $row)];
            }
            $due = $applied === null ? 0 : $totalCents;
            $chosen = $this->chosenModeFromOrder($order, $applied);
            $mismatch = $applied !== null && $applied !== $chosen;
            if (! $this->burn($row, (int) $user->id, [
                'declared_mode'          => $applied,
                'mode_mismatch'          => $mismatch,
                'amount_due_cents'       => $due,
                'amount_collected_cents' => $due,
                'device_id'              => isset($in['device_id']) ? substr((string) $in['device_id'], 0, 64) : null,
                'ip'                     => isset($in['ip']) ? substr((string) $in['ip'], 0, 45) : null,
                'user_agent'             => isset($in['user_agent']) ? substr((string) $in['user_agent'], 0, 255) : null,
                'lat'                    => $in['lat'] ?? null,
                'lng'                    => $in['lng'] ?? null,
                'accuracy_m'             => isset($in['accuracy']) ? (int) min(65535, max(0, round((float) $in['accuracy']))) : null,
            ])) {
                return ['already' => true, 'data' => $this->result($stop->refresh(), $order, $row->refresh())];
            }
            $row = $row->refresh();
            $stop->forceFill([
                'collect_method'         => $applied ?? $stop->collect_method,
                'amount_due_cents'       => $due,
                'amount_collected_cents' => $due,
                'arrived_at'             => $stop->arrived_at ?? now(),
                'delivered_at'           => now(),
                'arrival_lat'            => $stop->arrival_lat ?? $row->lat,
                'arrival_lng'            => $stop->arrival_lng ?? $row->lng,
            ])->save();

            $this->auditValidated($user, $row, $stop, $in);

            return ['already' => false, 'data' => $this->result($stop, $order, $row)];
        });
    }

    /**
     * Matrice des modes (décisions propriétaire) — renvoie le mode déclaré à appliquer, ou null si rien n'est à encaisser.
     *
     * @param  array<int,int|null>  $ids
     * @throws HandoverRefused
     */
    private function validatedMode(Order $order, array $due, mixed $declared, mixed $collected, array $ids): ?string
    {
        $dueCents = (int) $due['amount_due_cents'];
        $refuse = static fn (string $code, string $msg): HandoverRefused => new HandoverRefused(422, $code, $msg, ...$ids);

        if ($dueCents <= 0) { // payée : ni mode, ni montant
            if ($declared !== null && $declared !== '') {
                throw $refuse('mode_forbidden', 'Cette commande est déjà payée : il n\'y a rien à encaisser.');
            }
            if ($collected !== null && (int) $collected !== 0) {
                throw $refuse('wrong_amount', 'Cette commande est déjà payée : il n\'y a rien à encaisser.');
            }

            return null;
        }

        if (! $this->doorSettleable($order)) {
            throw $refuse('caisse_required', 'Cette commande n\'est pas encaissable à la porte. Renvoyez-la à la caisse.');
        }
        $allowed = $this->allowedModes($order, $due);
        if (! is_string($declared) || ! in_array($declared, ['cash', 'card', 'meal_voucher'], true)) {
            throw $refuse('mode_required', 'Indiquez comment le client a payé : espèces ou carte.');
        }
        if (! in_array($declared, $allowed, true)) {
            if ($due['collect_method'] === DeliveryWaveService::COLLECT_MEAL_VOUCHER || $declared === 'meal_voucher') {
                throw $refuse($due['collect_method'] === DeliveryWaveService::COLLECT_MEAL_VOUCHER ? 'caisse_required' : 'mode_forbidden',
                    $due['collect_method'] === DeliveryWaveService::COLLECT_MEAL_VOUCHER
                        ? 'Paiement en titre-restaurant : renvoyez cette commande à la caisse si le client règle autrement.'
                        : 'Le titre-restaurant n\'était pas le mode choisi : renvoyez cette commande à la caisse.');
            }
            throw $refuse('mode_forbidden', 'Ce mode d\'encaissement n\'est pas possible pour cette commande.');
        }
        if ($collected === null || ! is_numeric($collected) || (int) $collected !== $dueCents) {
            throw $refuse('wrong_amount', sprintf('Le montant encaissé doit être exactement %s €.', number_format($dueCents / 100, 2, ',', ' ')));
        }

        return $declared;
    }

    /**
     * [LIVREUR CAISSE 2026-11-02] Règlement en plusieurs moyens : la commande doit être encore à encaisser à la porte, et la répartition
     * doit valoir EXACTEMENT le dû relu côté serveur. Refus 422 français, avant toute écriture.
     *
     * @param  array<int,int|null>  $ids
     * @return list<array{mode: string, cents: int}>
     * @throws HandoverRefused
     */
    private function validatedSplit(Order $order, array $due, mixed $payments, array $ids): array
    {
        $dueCents = (int) $due['amount_due_cents'];
        if ($dueCents <= 0) {
            throw new HandoverRefused(422, 'mode_forbidden', 'Cette commande est déjà payée : il n\'y a rien à encaisser.', ...$ids);
        }
        if (! $this->doorSettleable($order)) {
            throw new HandoverRefused(422, 'caisse_required', 'Cette commande n\'est pas encaissable à la porte. Renvoyez-la à la caisse.', ...$ids);
        }
        try {
            return DoorPaymentSplit::normalize($payments, $dueCents);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw new HandoverRefused(422, 'wrong_split', $e->getMessage(), ...$ids);
        }
    }

    /** @return array<string,mixed> */
    private function result(DeliveryStop $stop, Order $order, DeliveryHandoverToken $row): array
    {
        return [
            'order_number'           => $order->order_serial_no,
            'delivered_at'           => $stop->delivered_at?->toDateTimeString(),
            'declared_mode'          => $row->declared_mode,
            'chosen_mode'            => $row->used_at !== null ? $this->chosenModeFromOrder($order, $row->declared_mode) : null,
            'mode_mismatch'          => (bool) $row->mode_mismatch,
            'amount_collected_cents' => (int) $stop->amount_collected_cents,
        ];
    }

    /** @param array<string,mixed> $in */
    private function auditValidated(User $user, DeliveryHandoverToken $row, DeliveryStop $stop, array $in): void
    {
        $this->audit('delivery.handover_validated', $user, [
            'order_id'       => (int) $row->order_id,
            'stop_id'        => (int) $row->delivery_stop_id,
            'token_id'       => (int) $row->id,
            'wave_at'        => $stop->wave_at?->toDateTimeString(),
            'chosen_mode'    => $this->chosenModeFromOrder(Order::query()->withoutGlobalScope(BranchScope::class)->findOrFail($row->order_id), $row->declared_mode),
            'declared_mode'  => $row->declared_mode,
            'declared_differs' => (bool) $row->mode_mismatch,
            'payments'       => $in['payments_applied'] ?? null,
            'mismatch'       => (bool) $row->mode_mismatch,
            'amount_cents'   => (int) $row->amount_collected_cents,
            'handed_over_at' => $stop->delivered_at?->toIso8601String(),
            // Le QR voyage avec le livreur : rien ne prouve qu'il est à la porte. On trace l'écart avec le départ (et si une position existe).
            'left_at'        => $stop->left_at?->toIso8601String(),
            'minutes_since_departure' => $stop->left_at ? (int) round($stop->left_at->diffInSeconds($stop->delivered_at) / 60) : null,
            'position_given' => $row->lat !== null && $row->lng !== null,
            'device_id'      => $row->device_id,
            'lat'            => $row->lat,
            'lng'            => $row->lng,
            'accuracy_m'     => $row->accuracy_m,
            'user_agent'     => $row->user_agent,
        ], (int) $row->order_id, $in['ip'] ?? null, $in['user_agent'] ?? null);
    }

    /** Trace chaînée (NF525) ; une panne d'audit ne bloque jamais la remise. */
    public function audit(string $action, User $user, array $payload, ?int $resourceId = null, ?string $ip = null, ?string $ua = null): void
    {
        try {
            app(AuditLogService::class)->write([
                'branch_id'   => (int) $user->branch_id,
                'user_id'     => (int) $user->id,
                'action'      => $action,
                'resource'    => 'delivery_handover',
                'resource_id' => $resourceId ?? (int) $user->id,
                'payload'     => $payload + ['delivery_boy_id' => (int) $user->id, 'via' => 'qr'],
                'ip'          => $ip,
                'user_agent'  => $ua !== null ? substr($ua, 0, 512) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('delivery.handover.audit_failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
