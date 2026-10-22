<?php

namespace App\Services\Orders;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * [VG-2026-10-21 M6-002 M5-102] Allocation du numéro de file (A0032, A0033…) sûre sous concurrence.
 *
 * Défaut corrigé : l'ancienne allocation (`OrderService` ET `FrontendOrderService`) lisait le MAX des
 * `orders.queue_number` du jour par une lecture NON verrouillante, dans la transaction englobante de la
 * vente, puis relâchait son `Cache::lock` AVANT le COMMIT. En REPEATABLE-READ (MySQL), une 2e vente
 * simultanée lisait donc le même instantané, prenait le même numéro, se heurtait à
 * `orders_branch_business_date_queue_unique`, et ses 5 reprises relisaient le MÊME instantané : elles
 * ne pouvaient jamais réussir (mesuré : 9 ventes caisse sur 15 refusées en 422).
 *
 * Correctif : un compteur par (branche, jour métier) dans `order_queue_counters`.
 *  - `INSERT … ON DUPLICATE KEY UPDATE` (upsert) pose un verrou EXCLUSIF sur la ligne, sans le
 *    verrou d'intervalle qui fait s'interbloquer « SELECT … FOR UPDATE puis INSERT » ;
 *  - la relecture `FOR UPDATE` est une lecture COURANTE (jamais l'instantané de la transaction) ;
 *  - le verrou reste tenu jusqu'au COMMIT de la vente : la vente suivante attend, puis lit le
 *    compteur à jour. Une reprise relit donc toujours une valeur fraîche et avance.
 *  - si la vente est annulée (exception → ROLLBACK), l'incrément est annulé avec elle : aucun trou.
 *
 * Le compteur se recale sur le plus grand numéro déjà présent en base (commandes créées avant ce
 * correctif, outils de simulation) et respecte `kiosk.queue_start_number` (A0032 en production).
 *
 * Le numéro FISCAL n'est pas concerné : il reste alloué par `FiscalSequenceService` (gelé).
 */
class QueueNumberAllocator
{
    public const TABLE = 'order_queue_counters';

    /**
     * Verrouille la ligne compteur du jour SANS consommer de numéro. Appelée en PREMIÈRE instruction de
     * la transaction de vente caisse : la vente attend que la précédente ait validé AVANT sa première
     * lecture, donc son instantané REPEATABLE-READ la contient (chaîne d'audit comprise, qui relit son
     * dernier maillon sans verrou). La vente reste sérialisée jusqu'à son COMMIT.
     */
    public function reserveDay(int $branchId, string $businessDate): void
    {
        DB::transaction(function () use ($branchId, $businessDate): void {
            $this->lockRow($branchId, $businessDate, Carbon::now());
        });
    }

    /**
     * Réserve le prochain numéro de file du jour pour la branche. À appeler dans la transaction de
     * la vente : la ligne compteur reste verrouillée jusqu'au COMMIT (ou annulée avec le ROLLBACK).
     */
    public function allocate(int $branchId, string $businessDate): string
    {
        return DB::transaction(function () use ($branchId, $businessDate): string {
            $now = Carbon::now();
            $last = $this->lockRow($branchId, $businessDate, $now);

            $next = max(
                $last + 1,
                $this->maxExistingQueueNumber($branchId, $businessDate) + 1,
                // [owner 2026-07-07] Le compteur quotidien démarre à kiosk.queue_start_number
                // (32 par défaut) : 1er ordre du jour = A0032, puis suit.
                max(1, (int) config('kiosk.queue_start_number', 1))
            );

            DB::table(self::TABLE)
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->update(['last_number' => $next, 'updated_at' => $now]);

            return 'A'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Crée la ligne du jour au besoin ou la verrouille (upsert = verrou exclusif direct, sans verrou
     * d'intervalle), puis la relit en lecture COURANTE : jamais l'instantané de la transaction.
     */
    private function lockRow(int $branchId, string $businessDate, Carbon $now): int
    {
        DB::table(self::TABLE)->upsert(
            [[
                'branch_id' => $branchId,
                'business_date' => $businessDate,
                'last_number' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['branch_id', 'business_date'],
            ['updated_at' => $now]
        );

        return (int) DB::table(self::TABLE)
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->lockForUpdate()
            ->value('last_number');
    }

    /**
     * Plus grand numéro « A#### » déjà porté par une commande du jour : recale le compteur sur les
     * commandes créées sans lui (avant ce correctif, simulateur). Simple garde-fou : la sérialisation
     * vient du verrou de la ligne compteur, pas de cette lecture.
     */
    private function maxExistingQueueNumber(int $branchId, string $businessDate): int
    {
        return (int) DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->whereNotNull('queue_number')
            ->where('queue_number', 'like', 'A%')
            ->pluck('queue_number')
            ->filter(static fn ($queueNumber): bool => preg_match('/^A\d+$/', (string) $queueNumber) === 1)
            ->map(static fn ($queueNumber): int => (int) substr((string) $queueNumber, 1))
            ->max();
    }
}
