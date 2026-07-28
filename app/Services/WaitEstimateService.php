<?php

namespace App\Services;

use App\Domain\Kds\KitchenReleaseRule;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\TimeSlot;
use Carbon\Carbon;
use Smartisan\Settings\Facades\Settings;

/**
 * [GOAL WEB COMMANDE Wave D 2026-07-28] Estimation d'attente retrait pour le
 * site web, dérivée de la file RÉELLE cuisine (caisse/KDS).
 *
 * Formule owner : base 15 min ; +5 min par tranche PLEINE de 3 commandes
 * actives devant ; fourchette (low, low+5) ; PLAFOND dur 30-35 (low jamais
 * au-dessus du cap 30, high = low + 5 → jamais > 35).
 *
 * File « devant » = sémantique SSOT KitchenReleaseRule (le MÊME contrat que le
 * board KDS — leçon unreleased-order-bump : ne jamais re-définir la file) :
 *  - statuts actifs cuisine = visibleStatuses() (ACCEPT/PREPARING/PREPARED),
 *    miroir de KdsSyncService::sync $activeStatuses ;
 *  - release paiement = applyBoardReleaseFilter (PAID | PENDING_COUNTER |
 *    POS cash) — une commande UNPAID non-cash n'occupe pas la cuisine ;
 *  - programmées hors fenêtre (scheduled_at > now + lead) EXCLUES via
 *    applyScheduledBoardFilter — sinon l'estimation gonfle (§0.5.4 du plan).
 *
 * SELECT-only : zéro impact NF525, zéro écriture.
 */
class WaitEstimateService
{
    public const STEP_MINUTES = 5;
    public const ORDERS_PER_STEP = 3;
    public const DEFAULT_BASE_MINUTES = 15;
    public const DEFAULT_CAP_MINUTES = 30;
    public const QUEUE_WINDOW_MINUTES = 120;

    /**
     * @return array{queue_count:int, wait_low:int, wait_high:int, closing_time:?string, server_time:string}
     */
    public function estimate(int $branchId): array
    {
        $now = now(config('app.timezone'));

        // withoutGlobalScope + filtre branche EXPLICITE : l'endpoint est public
        // (guest → BranchScope inactif), mais si le service est un jour appelé
        // dans un contexte staff authentifié, le count doit rester déterministe.
        $query = Order::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereIn('status', KitchenReleaseRule::visibleStatuses())
            // [STALE-GUARD 2026-07-28] Fenêtre 2 h : une commande ACCEPT abandonnée
            // (jamais bumpée) ne doit pas gonfler l'estimation À VIE — constat e2e :
            // la DB dev portait 414 ACCEPT fantômes → fourchette scotchée à 30-35.
            ->where('order_datetime', '>=', $now->copy()->subMinutes(self::QUEUE_WINDOW_MINUTES));

        KitchenReleaseRule::applyBoardReleaseFilter($query);
        KitchenReleaseRule::applyScheduledBoardFilter($query, $now);

        $queueCount = $query->count();

        // Même pattern Settings que OrderService.php:405 (base) ; cap = nouveau
        // setting optionnel order_setup_wait_cap (défaut 30, AUCUNE migration —
        // surchargeable owner via le repository settings existant).
        $base = (int) (Settings::group('order_setup')->get('order_setup_food_preparation_time') ?? self::DEFAULT_BASE_MINUTES);
        $cap = (int) (Settings::group('order_setup')->get('order_setup_wait_cap') ?? self::DEFAULT_CAP_MINUTES);

        if ($base <= 0) {
            $base = self::DEFAULT_BASE_MINUTES;
        }
        if ($cap <= 0) {
            $cap = self::DEFAULT_CAP_MINUTES;
        }

        // [OWNER 2026-07-28] ceil (pas intdiv) : les exemples owner font foi —
        // 3 cmds devant → 20-25 (ceil(3/3)=1) ; 7 cmds → 30-35 (ceil(7/3)=3, cap).
        $low = min($base + self::STEP_MINUTES * (int) ceil($queueCount / self::ORDERS_PER_STEP), $cap);
        $high = $low + self::STEP_MINUTES;

        return [
            'queue_count' => $queueCount,
            'wait_low' => $low,
            'wait_high' => $high,
            'closing_time' => $this->todayClosingTime($now),
            'server_time' => $now->toIso8601String(),
        ];
    }

    /**
     * Fermeture du jour (HH:MM) — dernier créneau time_slots du dayOfWeek
     * courant (même convention day = Carbon::dayOfWeek que
     * FrontendTimeSlotService::todayTimeSlot). null si aucun créneau.
     */
    private function todayClosingTime(Carbon $now): ?string
    {
        $closing = TimeSlot::where('day', $now->dayOfWeek)
            ->orderByDesc('closing_time')
            ->value('closing_time');

        return $closing ? substr($closing, 0, 5) : null;
    }
}
