<?php

namespace App\Services\Pos;

use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderCanceled;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\LoyaltyService;
use Illuminate\Support\Facades\DB;

/**
 * [GOAL CAISSE/CUISINE #3 2026-10-02] Purge des commandes « en attente d'encaissement » des JOURS
 * PRÉCÉDENTS qui n'ont JAMAIS été payées.
 *
 * Invariants NF525 (non négociables) :
 *  - AUCUN DELETE dur. Une commande purgée est soit passée à un statut terminal LÉGAL par le
 *    OrderStateMachine (apply, sans le modifier), soit — PREPARED, pour qui CANCELED est illégal —
 *    archivée par soft-delete (même précédent que CleanupStalePendingKioskOrders).
 *  - Garde ABSOLUE re-vérifiée SOUS verrou de ligne : fiscal_sequence_no NULL, payment_status
 *    PENDING_COUNTER. Une commande payée ou fiscalisée n'est jamais touchée ; si un encaissement
 *    concurrent gagne la course, la ligne est sautée.
 *  - AUCUNE séquence fiscale allouée, AUCUNE chaîne touchée hors une ligne d'audit append-only.
 *  - payment_status est laissé tel quel : REFUNDED serait un mensonge (aucun argent n'a bougé).
 *  - Une commande LIVRÉE jamais payée est une dette réelle : NON purgée, signalée comme sautée.
 *
 * Chaque ligne a sa propre transaction : une ligne en échec ne fait pas échouer le lot.
 */
class StaleCounterOrderPurger
{
    public const AUDIT_ACTION = 'order.counter_pending_purged';

    /** Périmètre historique : les commandes des JOURS PRÉCÉDENTS (toutes origines). */
    public const PERIMETRE_JOURS_PRECEDENTS = 'previous';

    /**
     * [GOAL REMARQUES 2026-10-03 · R-060] Les commandes TÉLÉPHONE de la journée de service en cours
     * (« Dans l'attente je veux tout supprimer »). Jamais la borne ni le site : ces clients peuvent être
     * devant le comptoir. Mêmes gardes sous verrou que les jours précédents.
     */
    public const PERIMETRE_TELEPHONE_DU_JOUR = 'phone_today';

    /**
     * @param  int[]|null  $ids  null = toutes les commandes du périmètre.
     * @return array{purged:int, skipped:array<int,array{id:int,reason:string}>}
     */
    public function purge(?array $ids, string $reason, ?User $actor, int $branchId = 0, string $perimetre = self::PERIMETRE_JOURS_PRECEDENTS): array
    {
        $candidates = $perimetre === self::PERIMETRE_TELEPHONE_DU_JOUR
            ? CounterCollectQueue::applyScope(CounterCollectQueue::query($branchId), CounterCollectQueue::SCOPE_TODAY)
                ->where('source_surface', 'phone')
                // [Revue adverse vague 2 · P1-2] Jamais une commande À L'AVANCE dont le créneau n'est pas
                // encore passé (ce soir 20 h, demain midi) : ce client n'est pas « non venu », et une
                // annulation ne se défait pas. SCOPE_TODAY range ces commandes dans « aujourd'hui ».
                ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            : CounterCollectQueue::applyScope(CounterCollectQueue::query($branchId), CounterCollectQueue::SCOPE_PREVIOUS);

        $eligibleIds = $candidates->pluck('id')->map(fn ($v) => (int) $v)->all();

        $purged = 0;
        $skipped = [];

        $targets = $ids === null ? $eligibleIds : array_values(array_unique(array_map('intval', $ids)));

        foreach ($targets as $id) {
            if (! in_array($id, $eligibleIds, true)) {
                $skipped[] = ['id' => $id, 'reason' => $perimetre === self::PERIMETRE_TELEPHONE_DU_JOUR
                    ? 'hors des commandes téléphone du jour en attente (déjà payée, autre origine, ou introuvable)'
                    : 'hors de la file des jours précédents (déjà payée, du jour, ou introuvable)'];

                continue;
            }

            try {
                $outcome = $this->purgeOne($id, $reason, $actor);
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => 'erreur : '.$e->getMessage()];

                continue;
            }

            if ($outcome === true) {
                $purged++;
            } else {
                $skipped[] = ['id' => $id, 'reason' => $outcome];
            }
        }

        return ['purged' => $purged, 'skipped' => $skipped];
    }

    /** @return true|string true si purgée, sinon la raison du saut. */
    private function purgeOne(int $id, string $reason, ?User $actor)
    {
        $oldStatus = null;
        $newStatus = null;
        $archived = false;
        $order = null;
        $skip = null;

        DB::transaction(function () use ($id, $reason, $actor, &$oldStatus, &$newStatus, &$archived, &$order, &$skip): void {
            $locked = Order::withoutGlobalScope(BranchScope::class)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            // Garde absolue sous verrou : on perd gracieusement la course contre un encaissement.
            if (! $locked
                || $locked->fiscal_sequence_no !== null
                || (int) $locked->payment_status !== PaymentStatus::PENDING_COUNTER) {
                $skip = 'déjà encaissée ou fiscalisée — intouchable';

                return;
            }

            $oldStatus = (int) $locked->status;

            if (! in_array($oldStatus, [OrderStatus::PENDING, OrderStatus::ACCEPT, OrderStatus::PREPARING, OrderStatus::PREPARED], true)) {
                $skip = 'commande déjà remise au client sans paiement : à traiter à la main';

                return;
            }

            // Fidélité : rend les points dépensés (idempotent) et reprend les points gagnés.
            app(LoyaltyService::class)->refundPoints($locked, 'pos');
            $this->clawbackEarnedPoints($locked);

            if ($oldStatus === OrderStatus::PREPARED) {
                // PREPARED → CANCELED est illégal dans la machine d'états gelée : archivage soft-delete.
                $newStatus = $oldStatus;
                $archived = true;
            } else {
                $newStatus = $oldStatus === OrderStatus::PENDING ? OrderStatus::REJECTED : OrderStatus::CANCELED;
                OrderStateMachine::apply($locked, $newStatus, $actor, 'Purge des anciennes commandes jamais encaissées : '.$reason);
            }

            // Casse le marqueur counter-deferred : un encaissement tardif ne peut plus la payer.
            if ((int) ($locked->pos_payment_method ?? 0) === PosPaymentMethod::COUNTER_DEFERRED) {
                $locked->pos_payment_method = null;
                $locked->save();
            }

            app(AuditLogService::class)->write([
                'branch_id' => (int) $locked->branch_id,
                'user_id' => $actor?->id,
                'action' => self::AUDIT_ACTION,
                'resource' => 'order',
                'resource_id' => (int) $locked->id,
                'payload' => [
                    'reason' => $reason,
                    'old_status' => $oldStatus,
                    'new_status' => $archived ? 'archived_soft_delete' : $newStatus,
                    'payment_status' => (int) $locked->payment_status,
                    'order_datetime' => optional($locked->order_datetime)->toIso8601String(),
                    'fiscal_sequence_no' => null,
                ],
            ]);

            if ($archived) {
                $locked->delete();
            }

            $locked->refresh();
            $order = $locked;
        });

        if ($skip !== null) {
            return $skip;
        }

        if ($order !== null) {
            if (! $archived && $newStatus !== $oldStatus) {
                OrderStatusChanged::dispatch($order, $oldStatus, $newStatus);
            }
            // Libère les compteurs branch-scoped consommés à la création (idempotent).
            OrderCanceled::dispatch($order);
        }

        return true;
    }

    private function clawbackEarnedPoints(Order $order): void
    {
        $awarded = (int) ($order->loyalty_points_awarded ?? 0);
        if ($awarded <= 0) {
            return;
        }

        $loyaltyUser = null;
        if (! empty($order->loyalty_customer_code)) {
            $loyaltyUser = User::where('loyalty_code', $order->loyalty_customer_code)->first();
        }
        if (! $loyaltyUser && ! empty($order->user_id)) {
            $cand = User::find($order->user_id);
            if ($cand && $cand->loyalty_code) {
                $loyaltyUser = $cand;
            }
        }
        if ($loyaltyUser) {
            app(LoyaltyService::class)->clawbackEarnedPoints(
                $loyaltyUser->id,
                $awarded,
                $order->id,
                'Clawback fidélité — commande jamais payée purgée'
            );
        }
    }
}
