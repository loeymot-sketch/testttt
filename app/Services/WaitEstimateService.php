<?php

namespace App\Services;

use App\Domain\Kds\KitchenReleaseRule;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\TimeSlot;
use Carbon\Carbon;

/**
 * [GOAL WEB COMMANDE Wave D 2026-07-28, formule owner révisée 2026-08-16,
 * puis figée 2026-09-23] Estimation d'attente retrait pour le site web,
 * affichée AVANT que la caisse ait accepté la commande.
 *
 * [T-C TEMPS-ATTENTE 2026-09-23 · GOAL owner] La formule par paliers (§tag
 * TIERS, en vigueur du 2026-08-16 au 2026-09-23) annonçait 20-30 min dès
 * quelques commandes actives — l'owner a explicitement demandé de ne plus
 * jamais faire ça : « je veux confirmer que le temps d'attente approximatif
 * c'est 10 à 15 minutes » (constant, quelle que soit la file). Un retard
 * cuisine réel sur UNE commande précise doit désormais se refléter via
 * `Order::preparation_time`, fixé par le caissier à l'ACCEPT (voir
 * OrderTrackingService::forOrder(), qui prend le relais de cette estimation
 * générique une fois la commande acceptée) — jamais via cette formule
 * générique elle-même.
 *
 * `queue_count` reste calculé (visibilité staff, contrat API existant/testé)
 * mais n'entre plus dans le calcul de `wait_low`/`wait_high`.
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
    /** [T-C 2026-09-23] Fourchette générique constante — voir doc de classe. */
    public const DEFAULT_WAIT = [10, 15];
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

        [$low, $high] = self::DEFAULT_WAIT;

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
