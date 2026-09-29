<?php

namespace App\Support;

use App\Models\Order;
use Carbon\Carbon;

/**
 * [CAISSE 2026-09-29] Les commandes de la file d'encaissement appartenant aux
 * journées de service PASSÉES.
 *
 * Deux routes s'en servent : le comptage (GET stale-count) et l'annulation
 * groupée (POST cancel-stale). Elles DOIVENT désigner exactement le même
 * ensemble — sinon l'écran annonce un nombre et en annule un autre, ce qui est
 * la pire chose qu'un bouton de masse puisse faire. D'où cette définition
 * unique plutôt que deux requêtes jumelles rédigées côte à côte.
 *
 * Les deux gardes ne sont pas négociables :
 *
 *  1. `whereNull('fiscal_sequence_no')` — une commande qui porte un numéro
 *     fiscal est entrée dans la chaîne signée NF525. On n'y touche jamais. En
 *     pratique une commande de cette file n'en a pas (le numéro est alloué à
 *     l'encaissement, `PaymentService::confirmCounterPayment`), mais la garde
 *     reste écrite : c'est elle qui rend l'opération sûre, pas la coïncidence.
 *
 *  2. Le plancher de la journée de service EN COURS. On ne touche QUE l'avant.
 *     Bascule à 5 h du matin, avec recul d'un jour avant cette heure — sinon la
 *     nuit de service en cours serait comptée comme « hier » et un client en
 *     train d'arriver verrait sa commande annulée, plat déjà parti en cuisine.
 *     Même plancher que le panneau « En souffrance »
 *     (PosOrderController:494-503) et que le helper front `posServiceDay.js` :
 *     les trois doivent bouger ensemble.
 */
final class CounterCollectStale
{
    /** Heure de bascule de la journée de service. */
    public const SERVICE_DAY_START_HOUR = 5;

    /**
     * @return array{0:\Illuminate\Database\Eloquent\Builder,1:Carbon}
     */
    public static function query(int $branchId, ?Carbon $now = null): array
    {
        $now ??= Carbon::now(config('app.timezone'));

        $plancher = $now->copy()->startOfDay()->setTime(self::SERVICE_DAY_START_HOUR, 0);
        if ($now->hour < self::SERVICE_DAY_START_HOUR) {
            $plancher->subDay();
        }

        $requete = Order::query()
            ->counterCollectQueue()
            ->whereNull('fiscal_sequence_no')
            ->where('order_datetime', '<', $plancher)
            ->orderBy('created_at');

        if ($branchId > 0) {
            $requete->where('branch_id', $branchId);
        }

        return [$requete, $plancher];
    }
}
