<?php

namespace App\Listeners;

use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Kiosk Phase 9.1.4 — Invalide le cache `kiosk.menu.branch.{id}` dès qu'un item
 * bascule en (in)disponibilité.
 *
 * Motif. `MenuController::kiosk()` met en cache le payload `/api/frontend/menu`
 * pendant `config('kiosk.menu_cache_ttl', 60)` secondes. Sans invalidation
 * explicite, un 86 temps réel (rupture stock / flip status admin) mettait
 * jusqu'à 60 s à se propager à la borne — finding #4 de
 * `reports/review/AUDIT_KIOSK_GLOBAL_2026-04-18.md` (risque : client ajoute un
 * item indisponible au panier, découverte au POST /order → 409 Conflict +
 * frustration).
 *
 * Fonctionnement.
 *   - Event branch-scoped (`ItemAvailabilityChanged::forBranch(...)`) → on
 *     invalide une seule clé : `kiosk.menu.branch.{branchId}`.
 *   - Event global (`ItemAvailabilityChanged::fromItem(...)`, admin édite un
 *     item) → on invalide toutes les branches actives (même logique que
 *     `BumpMenuSnapshotOnItemAvailabilityChanged`).
 *   - Le listener est non-bloquant : si le cache store échoue, on log et on
 *     laisse le TTL naturel faire le boulot (≤ 60 s).
 *
 * Interaction avec `BumpMenuSnapshotOnItemAvailabilityChanged`.
 *   - Le bump snapshot sert d'autorité "version" pour les clients (long-poll /
 *     Echo) mais n'invalidait PAS la clé Cache elle-même. Les deux listeners
 *     sont donc complémentaires, pas redondants.
 *
 * Invariants.
 *   - Aucune écriture métier (pas de transition de statut, pas d'event dispatch).
 *   - Respecte `branch_id` scope (pas d'invalidation globale silencieuse).
 *   - Idempotent : un `Cache::forget()` double est no-op.
 */
class InvalidateKioskMenuCacheOnItemAvailabilityChanged
{
    private const CACHE_KEY_PREFIX = 'kiosk.menu.branch.';

    public function handle(ItemAvailabilityChanged $event): void
    {
        try {
            if ($event->branchId !== null) {
                $this->forgetBranch((int) $event->branchId, (int) $event->itemId);
                return;
            }

            // Global flip → invalider toutes les branches actives. On évite de
            // toucher aux branches désactivées pour ne pas exploser la charge
            // Cache sur les grosses chaînes franchise.
            Branch::query()->pluck('id')->each(function ($branchId) use ($event): void {
                $this->forgetBranch((int) $branchId, (int) $event->itemId);
            });
        } catch (\Throwable $e) {
            // Best-effort: on ne doit jamais bloquer l'outbox ni le bump snapshot.
            Log::warning('[KioskMenu] cache invalidation failed', [
                'item_id'    => $event->itemId,
                'branch_id'  => $event->branchId,
                'exception'  => $e->getMessage(),
            ]);
        }
    }

    private function forgetBranch(int $branchId, int $itemId): void
    {
        $key = self::CACHE_KEY_PREFIX . $branchId;
        Cache::forget($key);
        Log::info('[KioskMenu] cache invalidated', [
            'branch_id' => $branchId,
            'item_id'   => $itemId,
            'cache_key' => $key,
        ]);
    }
}
