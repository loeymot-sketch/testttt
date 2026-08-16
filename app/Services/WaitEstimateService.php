<?php

namespace App\Services;

use App\Domain\Kds\KitchenReleaseRule;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\TimeSlot;
use Carbon\Carbon;

/**
 * [GOAL WEB COMMANDE Wave D 2026-07-28, formule owner révisée 2026-08-16]
 * Estimation d'attente retrait pour le site web, dérivée de la file RÉELLE
 * cuisine (caisse/KDS).
 *
 * [T-C TEMPS-ATTENTE 2026-08-16 · GOAL owner] Nouvelle formule PAR PALIERS
 * (remplace l'ancienne formule linéaire +5min/tranche de 3, qui décalait tout
 * d'un cran vers le haut par rapport à ce que l'owner voulait — ex. 3
 * commandes donnait 20-25 au lieu de 15-20 attendu, et plafonnait à 30-35 au
 * lieu de 25-30). Règle owner (dictée, bornes ≤N choisies pour rendre les
 * paliers non chevauchants — "1 à 3", "3 à 5", "plus de 5" laissait un
 * chevauchement à l'exact valeur 3) :
 *   - file ≤ 3 commandes actives devant  → 15-20 min
 *   - file 4 à 5 commandes               → 20-25 min
 *   - file > 5 commandes                 → 25-30 min (plafond dur, jamais plus)
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
    /** [T-C] Paliers owner : [seuil_max_commandes => [low, high]], triés croissant. */
    public const TIERS = [
        3 => [15, 20],
        5 => [20, 25],
    ];
    public const OVERFLOW_TIER = [25, 30];
    public const QUEUE_WINDOW_MINUTES = 120;
    // [T-C PLANCHER-JAMAIS-ZERO] Owner : « on va jamais dire que y a aucune
    // commande, toujours y a deux commandes avant vous minimum ». Plancher
    // artificiel affiché au client — la vraie valeur reste dans queue_count
    // (jamais menti côté staff/admin, seulement côté vitrine client).
    public const MIN_DISPLAYED_QUEUE_COUNT = 2;

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

        [$low, $high] = self::OVERFLOW_TIER;
        foreach (self::TIERS as $maxCount => $range) {
            if ($queueCount <= $maxCount) {
                [$low, $high] = $range;
                break;
            }
        }

        return [
            'queue_count' => $queueCount,
            // [T-C PLANCHER-JAMAIS-ZERO] Réservé à l'AFFICHAGE client (borne/suivi
            // mobile) — jamais utilisé pour la fourchette de temps ci-dessus, qui
            // reste sur le compte réel (queue_count) pour ne pas biaiser la cuisine.
            'queue_count_displayed' => max($queueCount, self::MIN_DISPLAYED_QUEUE_COUNT),
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
