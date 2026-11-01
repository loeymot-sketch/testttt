<?php

namespace App\Services\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\DeliveryHandoverToken;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\UberDirectDelivery;
use App\Models\User;
use Carbon\Carbon;
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
}
