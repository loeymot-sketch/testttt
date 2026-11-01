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

    /**
     * Valide la remise. ⚠️ AUCUNE transaction ne doit envelopper `OrderService::deliveryBoyOrderChangeStatus` : il écrit ses audits
     * NF525 (escrow, tranche porte, caisse livreur) et envoie mail/SMS/push APRÈS son propre COMMIT ; sous une transaction externe ils
     * partiraient verrous tenus (Lock wait timeout 1205 sur la chaîne d'audit, notification « livrée » avant un échec). Séquence :
     *   1. contrôles en direct (attribution, annulée, Uber, livrée, périmée, parti) → mode et montant (sans écrire) ;
     *   2. RÉSERVATION atomique du mode sur la ligne du jeton (`UPDATE … WHERE chosen_mode IS NULL AND used_at IS NULL`) : la 1re requête
     *      fixe le mode, toute requête jumelle applique CELUI-LÀ ;
     *   3. la remise officielle, SEULE (sa transaction, ses audits après commit) ; en cas d'échec la réservation est libérée, rien n'est brûlé ;
     *   4. SEULEMENT après succès : petite transaction à part — verrou de l'arrêt, brûlure atomique du jeton, arrêt mis à jour, trace.
     * Si l'étape 4 échoue, la commande est LIVRÉE (donc le jeton est déjà inutilisable : `inspect()` refuse une commande livrée) et un
     * nouvel appui TERMINE la comptabilité de l'arrêt sans rien rejouer (ni encaissement, ni numéro fiscal).
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

        $v = $this->inspect($row, $user);
        if (! $v['ok']) {
            $stop = $v['stop'];
            if (in_array($v['reason'], [self::REASON_USED, self::REASON_DELIVERED], true)
                && $row->used_at !== null && (int) $row->used_by_user_id === (int) $user->id && $stop?->delivered_at !== null) {
                // Double appui / réponse perdue : la remise de CE livreur est déjà faite. Rien n'est rejoué.
                return ['already' => true, 'data' => $this->result($stop, $v['order'], $row)];
            }
            if ($v['reason'] === self::REASON_DELIVERED && $row->used_at === null && $row->chosen_mode !== null && $stop !== null) {
                // La remise officielle est passée mais la comptabilité de l'arrêt / du jeton est restée en plan (panne après coup) :
                // on la TERMINE avec le mode réservé, sans rien rejouer.
                return $this->finalize($user, $row, $in);
            }
            $this->throwForReason($v['reason'], $row, $user);
        }
        $order = $v['order'];

        $due = app(DeliveryWaveService::class)->collectionFor($order);
        $dueCents = (int) $due['amount_due_cents'];
        $chosen = (string) $due['collect_method'];
        $declared = $this->validatedMode($order, $due, $in['declared_mode'] ?? null, $in['collected_cents'] ?? null, $ids);

        // 2. Réservation du mode : le premier gagne, les jumelles appliquent son mode (jamais le leur).
        $claimed = DeliveryHandoverToken::query()->withoutGlobalScope(BranchScope::class)
            ->whereKey($row->id)->whereNull('used_at')->whereNull('revoked_at')->whereNull('chosen_mode')
            ->update([
                'chosen_mode'            => $chosen,
                'declared_mode'          => $declared,
                'mode_mismatch'          => $declared !== null && $declared !== $chosen,
                'amount_due_cents'       => $dueCents,
                'amount_collected_cents' => $dueCents > 0 ? (int) $in['collected_cents'] : 0,
                'device_id'              => isset($in['device_id']) ? substr((string) $in['device_id'], 0, 64) : null,
                'ip'                     => isset($in['ip']) ? substr((string) $in['ip'], 0, 45) : null,
                'user_agent'             => isset($in['user_agent']) ? substr((string) $in['user_agent'], 0, 255) : null,
                'lat'                    => $in['lat'] ?? null,
                'lng'                    => $in['lng'] ?? null,
                'accuracy_m'             => isset($in['accuracy']) ? (int) min(65535, max(0, round((float) $in['accuracy']))) : null,
                'updated_at'             => now(),
            ]) === 1;
        $row = $row->refresh();
        if (! $claimed) {
            if ($row->used_at !== null) {
                $s = DeliveryStop::query()->withoutGlobalScope(BranchScope::class)->find($row->delivery_stop_id);
                if ((int) $row->used_by_user_id === (int) $user->id && $s?->delivered_at !== null) {
                    return ['already' => true, 'data' => $this->result($s, $order, $row)];
                }
                throw new HandoverRefused(409, 'already_used', 'Ce QR vient d\'être utilisé.', ...$ids);
            }
            if ($row->chosen_mode === null) { // réservation libérée entre-temps : on laisse le livreur réessayer
                throw new HandoverRefused(409, 'retry', 'Un autre appui est en cours. Réessayez dans un instant.', ...$ids);
            }
            $declared = $row->declared_mode; // le mode du 1er appui fait foi
        }

        // 3. Voie officielle, SEULE : encaissement à la porte, numéro fiscal NF525, escrow, audits et notifications APRÈS son commit.
        try {
            app(OrderService::class)->deliveryBoyOrderChangeStatus(
                $order,
                Request::create('', 'POST', ['status' => OrderStatus::DELIVERED]),
                $declared
            );
        } catch (\Throwable $e) {
            if ($claimed) {
                $this->releaseClaim($row); // ni jeton brûlé, ni mode figé : le livreur peut corriger et réessayer
            }
            throw $e;
        }

        $after = Order::query()->withoutGlobalScope(BranchScope::class)->find($order->id);
        if (! ($after && (int) $after->status === OrderStatus::DELIVERED && ($dueCents === 0 || (int) $after->payment_status === PaymentStatus::PAID))) {
            Log::critical('delivery.handover.not_settled', ['order_id' => $order->id, 'status' => $after?->status, 'payment_status' => $after?->payment_status]);
        }

        return $this->finalize($user, $row, $in);
    }

    /** Libère la réservation d'un mode si la commande n'a PAS été livrée (la remise officielle a échoué). */
    private function releaseClaim(DeliveryHandoverToken $row): void
    {
        $delivered = Order::query()->withoutGlobalScope(BranchScope::class)->whereKey($row->order_id)->value('status');
        if ((int) $delivered === OrderStatus::DELIVERED) {
            return;
        }
        DeliveryHandoverToken::query()->withoutGlobalScope(BranchScope::class)->whereKey($row->id)->whereNull('used_at')->update([
            'chosen_mode' => null, 'declared_mode' => null, 'mode_mismatch' => false, 'amount_due_cents' => null, 'amount_collected_cents' => null,
            'device_id' => null, 'ip' => null, 'user_agent' => null, 'lat' => null, 'lng' => null, 'accuracy_m' => null, 'updated_at' => now(),
        ]);
    }

    /**
     * Étape 4 : PETITE transaction, APRÈS une remise officielle réussie. Verrou de l'arrêt (sérialise deux appuis), brûlure atomique du
     * jeton, arrêt mis à jour avec ce qui a été RÉSERVÉ, trace d'audit. Un jumeau qui a déjà terminé → « déjà remis », rien n'est rejoué.
     *
     * @param  array<string,mixed>  $in
     * @return array{already: bool, data: array<string,mixed>}
     */
    private function finalize(User $user, DeliveryHandoverToken $row, array $in): array
    {
        return DB::transaction(function () use ($user, $row, $in) {
            $stop = DeliveryStop::query()->withoutGlobalScope(BranchScope::class)->whereKey($row->delivery_stop_id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->withoutGlobalScope(BranchScope::class)->findOrFail($row->order_id);
            $row = $row->refresh();
            if ($stop->delivered_at !== null || $row->used_at !== null) {
                return ['already' => true, 'data' => $this->result($stop, $order, $row)];
            }
            if (! $this->burn($row, (int) $user->id)) {
                return ['already' => true, 'data' => $this->result($stop->refresh(), $order, $row->refresh())];
            }
            $row = $row->refresh();
            $due = (int) $row->amount_due_cents;
            $stop->forceFill([
                'collect_method'         => $row->declared_mode ?? $stop->collect_method,
                'amount_due_cents'       => $due,
                'amount_collected_cents' => (int) $row->amount_collected_cents,
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

    /** @return array<string,mixed> */
    private function result(DeliveryStop $stop, Order $order, DeliveryHandoverToken $row): array
    {
        return [
            'order_number'           => $order->order_serial_no,
            'delivered_at'           => $stop->delivered_at?->toDateTimeString(),
            'declared_mode'          => $row->declared_mode,
            'chosen_mode'            => $row->chosen_mode,
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
            'chosen_mode'    => $row->chosen_mode,
            'declared_mode'  => $row->declared_mode,
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
