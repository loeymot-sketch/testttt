<?php

namespace App\Services\Menu;

use App\Enums\Status;
use App\Events\ItemAvailabilityChanged;
use App\Events\ItemExtraAvailabilityChanged;
use App\Events\ItemVariationAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for branch-scoped menu availability (rupture / 86).
 *
 * Responsibilities:
 *  - Toggle manual rupture on/off for a given (item, branch).
 *  - Snapshot current availability for read paths (POS / Kiosk filters).
 *  - Auto-86 when daily counters reach the configured cap.
 *
 * All mutations are transactional and emit {@see ItemAvailabilityChanged::forBranch()}
 * on success, which is persisted to the outbox and broadcast on the branch channel.
 *
 * ---------------------------------------------------------------------------
 * REASON VOCABULARY (item_branch_availability.unavailable_reason) — verified
 * 2026-07-22 (Vague 2 STOCK-HARDENING, finding panel-manual-86-reason-collision):
 *
 *  - 'out_of_stock'  = AUTO daily-quota 86 (max_daily_qty reached). Written ONLY
 *                      here by {@see setMaxDailyQty()} + {@see decrementForOrder()}.
 *                      It is the ONE reason the quota machinery auto-heals:
 *                      {@see setMaxDailyQty()} (cap raised), {@see releaseForOrderItems()}
 *                      (cancel/refund credit) and ResetStaleDailyQuotaCommand /
 *                      {@see reconcileStaleDailyQuota()} (day rollover) ALL key on
 *                      `=== 'out_of_stock'` before flipping back to available.
 *  - 'stock_rupture' (+ any other slug e.g. 'out_of_stock_manual', 'supplier_issue')
 *                      = MANUAL 86 (admin/POS/KDS panel, carnet PIN app) OR PHYSICAL
 *                      stock rupture (StockService on_hand<=0 / stock:scan-rupture
 *                      cron). This service NEVER auto-clears it — a manual/physical 86
 *                      is STICKY here and survives every quota auto-restore path
 *                      (guards above check `=== 'out_of_stock'` exclusively).
 *
 * So within AvailabilityService there is NO manual↔auto collision: the manual panel
 * writing 'stock_rupture' is safe against quota self-heal. The StockService
 * restock-reactivation collision (a replenishment un-doing a manual 86 that shared
 * the 'stock_rupture' slug — owner « friteuse » symptom) is RESOLVED (owner decision
 * « A », 2026-07-23) WITHOUT a wide reason rename: {@see toggle()} stamps a distinct
 * PROVENANCE marker (item_branch_availability.manual_unavailable_since, $manual=true
 * by default) on every manual 86, which
 * {@see \App\Services\Stock\StockService::syncItemAvailabilityForStockLevel} checks
 * before auto-reactivating. The slug stays 'stock_rupture' everywhere (dashboards /
 * borne / projection / POS+KDS toasts unchanged); provenance is a separate signal.
 * Auto stock ruptures (StockService decrement + cron stock:scan-rupture with
 * $manual=false) leave the marker null and still reactivate on restock. See migration
 * 2026_07_23_100000_add_manual_unavailable_since_to_item_branch_availability +
 * tests/Feature/Stock/ManualEightySixStickyThroughRestockTest.
 * ---------------------------------------------------------------------------
 */
final class AvailabilityService
{
    /**
     * Manual toggle (admin UI). Creates the row if missing. Idempotent:
     * re-toggling to the same state returns without emitting.
     *
     * @param  string|null  $reason  Required semantically when $available=false; enforced at UI level.
     */
    public function toggle(
        int $itemId,
        int $branchId,
        bool $available,
        ?string $reason = null,
        bool $manual = true
    ): ItemBranchAvailability {
        return DB::transaction(function () use ($itemId, $branchId, $available, $reason, $manual): ItemBranchAvailability {
            $row = ItemBranchAvailability::query()
                ->where('item_id', $itemId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();

            // [panel-manual-86-reason-collision 2026-07-23] PROVENANCE du 86. toggle()
            // EST l'entrée manuelle (« Manual toggle (admin UI) ») → $manual=true par
            // défaut : un 86 humain stampe manual_unavailable_since, ce qui rend la
            // rupture STICKY (StockService::syncItemAvailabilityForStockLevel refuse
            // de la réactiver au restock). Le SEUL appelant auto (cron
            // stock:scan-rupture) passe explicitement $manual=false → marker null →
            // réactivable au restock, comportement historique préservé.
            $manualSince = (! $available && $manual) ? now() : null;

            if (! $row) {
                $row = new ItemBranchAvailability([
                    'item_id' => $itemId,
                    'branch_id' => $branchId,
                    'is_available' => $available,
                    'unavailable_reason' => $available ? null : $reason,
                    'unavailable_since' => $available ? null : now(),
                    'manual_unavailable_since' => $manualSince,
                    'daily_consumed_qty' => 0,
                    // [Wave 3c KDS-ADV3C-03 P1 2026-05-18] Explicit Paris-local
                    // day for `daily_reset_at` (DATE column, TZ-agnostic in MySQL
                    // but business-day-Paris by convention). Prevents drift if
                    // PHP process inherits UTC or app.timezone is mutated.
                    'daily_reset_at' => Carbon::today(config('app.timezone'))->toDateString(),
                ]);
                $row->save();
                $this->dispatchEvent($itemId, $branchId, $available, $reason);

                return $row;
            }

            $stateAndReasonMatch = (bool) $row->is_available === $available
                && $row->unavailable_reason === ($available ? null : $reason);
            $manualMatches = $available
                ? ($row->manual_unavailable_since === null)
                : (($row->manual_unavailable_since !== null) === $manual);

            // No-op idempotent complet : état, raison ET provenance coïncident déjà.
            if ($stateAndReasonMatch && $manualMatches) {
                return $row;
            }

            // État + raison inchangés mais PROVENANCE différente (ex. un humain
            // confirme un 86 qui était auto) : on « upgrade » le marqueur STICKY
            // SANS ré-émettre d'event — la borne/POS reflètent déjà cette
            // disponibilité/raison inchangée (contrat d'idempotence event préservé).
            if ($stateAndReasonMatch) {
                $row->manual_unavailable_since = $manualSince;
                $row->save();

                return $row;
            }

            $row->is_available = $available;
            $row->unavailable_reason = $available ? null : $reason;
            $row->unavailable_since = $available ? null : now();
            $row->manual_unavailable_since = $manualSince;
            $row->save();

            $this->dispatchEvent($itemId, $branchId, $available, $reason);

            return $row;
        });
    }

    /**
     * Update the daily quota cap and re-evaluate is_available immediately.
     *
     * Symmetrical to toggle() but driven by a quota change instead of a manual
     * rupture. Plan task 2.5 — eliminates the "wait until next order" delay
     * that was visible in admin UX.
     *
     * Idempotent: setting the same max twice does not emit a duplicate event.
     *
     * @param  int|null  $maxDailyQty  null = unlimited (always available from quota perspective)
     */
    public function setMaxDailyQty(int $itemId, int $branchId, ?int $maxDailyQty): ItemBranchAvailability
    {
        return DB::transaction(function () use ($itemId, $branchId, $maxDailyQty): ItemBranchAvailability {
            $row = ItemBranchAvailability::query()
                ->where('item_id', $itemId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $row = new ItemBranchAvailability([
                    'item_id' => $itemId,
                    'branch_id' => $branchId,
                    'is_available' => true,
                    'daily_consumed_qty' => 0,
                    // [Wave 3c KDS-ADV3C-03 P1 2026-05-18] See sibling comment
                    // in toggle().
                    'daily_reset_at' => Carbon::today(config('app.timezone'))->toDateString(),
                    'max_daily_qty' => $maxDailyQty,
                ]);
                $row->save();

                return $row;
            }

            $previousMax = $row->max_daily_qty === null ? null : (int) $row->max_daily_qty;
            $newMax = $maxDailyQty;
            $consumed = (int) $row->daily_consumed_qty;
            $wasAvailable = (bool) $row->is_available;
            $previousReason = $row->unavailable_reason;

            if ($previousMax === $newMax) {
                return $row;
            }

            $row->max_daily_qty = $newMax;

            $shouldBeAvailableFromQuota = ($newMax === null) || ($consumed < $newMax);

            // Auto-86: cap lowered below current consumption
            if ($wasAvailable && ! $shouldBeAvailableFromQuota) {
                $row->is_available = false;
                $row->unavailable_reason = 'out_of_stock';
                $row->unavailable_since = now();
                $row->save();
                $this->dispatchEvent($itemId, $branchId, false, 'out_of_stock');

                return $row;
            }

            // Auto-restore: cap raised above current consumption AND was unavailable due to quota
            if (! $wasAvailable && $previousReason === 'out_of_stock' && $shouldBeAvailableFromQuota) {
                $row->is_available = true;
                $row->unavailable_reason = null;
                $row->unavailable_since = null;
                $row->save();
                $this->dispatchEvent($itemId, $branchId, true, null);

                return $row;
            }

            // Quota changed but no flip needed (e.g. raised cap while still available, or
            // unavailable for a different reason like manual rupture).
            $row->save();

            return $row;
        });
    }

    /**
     * [quota-daily-reenable-cron-only 2026-07-22 / Vague 2 STOCK-HARDENING]
     * Lazy catch-up for the daily-quota reset. The scheduled twin
     * {@see \App\Console\Commands\ResetStaleDailyQuotaCommand} runs at 00:05 — but the
     * Cayenne box is powered OFF overnight, so on a cold morning boot the 00:05 job
     * NEVER fired and every quota-auto-86'd item (unavailable_reason='out_of_stock')
     * stays blocked all day until someone runs the command by hand.
     *
     * This re-enables + zeroes any PAST-dated rows for the branch on the FIRST
     * availability read of the day (hooked into {@see getBranchAvailabilitySnapshot()}
     * and StockRuptureDashboardController::catalogOverview). A once-per-branch-per-day
     * cache flag (atomic first-writer-wins via Cache::add) makes every subsequent read
     * a single cache hit — no per-read write.
     *
     * Mirrors the command's exact Paris-local DATE predicate + writes and its
     * out_of_stock-only re-enable (manual/physical 86 preserved — see the REASON
     * VOCABULARY on the class docblock). NOT NF525: daily_consumed_qty is a UX quota
     * counter, not a fiscal counter (see {@see decrementForOrder()}). Idempotent.
     *
     * @return int rows reset (0 when already reconciled today, or nothing stale).
     */
    public function reconcileStaleDailyQuota(int $branchId): int
    {
        if ($branchId < 1) {
            return 0;
        }

        // Explicit Paris-local day (mirror decrementForOrder()/toggle()/the cron):
        // `daily_reset_at` is a DATE column, compared lexically as Y-m-d.
        $today = Carbon::today(config('app.timezone'))->toDateString();

        // Once per branch per day. Cache::add is SETNX-equivalent (atomic
        // first-writer-wins on Redis AND the array driver used in tests), so
        // concurrent morning reads reconcile exactly once. Key rolls over daily.
        if (! Cache::add("availability:daily_reconcile:{$branchId}:{$today}", 1, now()->endOfDay())) {
            return 0;
        }

        $staleScope = fn () => DB::table('item_branch_availability')
            ->where('branch_id', $branchId)
            ->whereDate('daily_reset_at', '<', $today)
            ->whereNotNull('daily_reset_at');

        // Re-enable ONLY quota-auto 86 ('out_of_stock'). Manual/physical 86
        // (stock_rupture, supplier_issue, out_of_stock_manual, …) stays 86'd —
        // same guard as ResetStaleDailyQuotaCommand and setMaxDailyQty()/release.
        $reenabled = $staleScope()
            ->where('is_available', false)
            ->where('unavailable_reason', 'out_of_stock')
            ->update([
                'is_available' => true,
                'unavailable_reason' => null,
                'unavailable_since' => null,
                'updated_at' => now(),
            ]);

        // Reset the counters for the whole stale set (available or not) so day-N+1
        // starts fresh even with zero overnight traffic. Done AFTER the re-enable
        // because the stale scope depends on `daily_reset_at < today`, which this
        // update rewrites.
        $staleScope()->update([
            'daily_consumed_qty' => 0,
            'daily_reset_at' => $today,
            'updated_at' => now(),
        ]);

        return (int) $reenabled;
    }

    /**
     * Toggle the item across every active branch. Returns the count of rows
     * actually touched (excluding idempotent no-ops).
     */
    public function toggleForAllBranches(
        int $itemId,
        bool $available,
        ?string $reason = null
    ): int {
        $count = 0;
        Branch::query()->pluck('id')->each(function (int $branchId) use ($itemId, $available, $reason, &$count): void {
            $before = ItemBranchAvailability::query()
                ->where('item_id', $itemId)
                ->where('branch_id', $branchId)
                ->first();
            $this->toggle($itemId, $branchId, $available, $reason);
            if (! $before || (bool) $before->is_available !== $available) {
                $count++;
            }
        });

        return $count;
    }

    /**
     * Read helper for POS / Kiosk snapshot consumers.
     * Absent row = available by default (V1 rule).
     */
    public function isAvailable(int $itemId, int $branchId): bool
    {
        $row = ItemBranchAvailability::query()
            ->where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->first();

        return $row ? (bool) $row->is_available : true;
    }

    /**
     * Reject checkout when any line references an item marked unavailable for this branch.
     * With `$useRowLock=true`, locks existing `item_branch_availability` rows (same DB transaction)
     * so concurrent rupture toggles serialize with order commit. Absence of a row = available (V1).
     * With `$useRowLock=false` (e.g. pricing preview), performs a read-only check without locks.
     *
     * @param  array<int|mixed>  $itemIds
     *
     * @throws \InvalidArgumentException
     */
    public function assertItemsOrderableForBranch(int $branchId, array $itemIds, bool $useRowLock = true): void
    {
        $itemIds = array_values(array_unique(array_filter(array_map(static fn ($id) => (int) $id, $itemIds))));
        if ($branchId < 1 || $itemIds === []) {
            return;
        }

        $catalogItems = Item::query()
            ->select('id', 'status', 'is_available')
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        foreach ($itemIds as $itemId) {
            $item = $catalogItems->get($itemId);
            if (! $item) {
                throw new \InvalidArgumentException(
                    "Article {$itemId} introuvable. Commande rejetée.",
                    422
                );
            }

            if ((int) $item->status !== Status::ACTIVE) {
                throw new \InvalidArgumentException(
                    "Article {$itemId} inactif dans le catalogue. Commande rejetée.",
                    422
                );
            }

            if ($item->is_available !== null && ! (bool) $item->is_available) {
                throw new \InvalidArgumentException(
                    "Article {$itemId} indisponible dans le catalogue. Commande rejetée.",
                    422
                );
            }
        }

        $query = ItemBranchAvailability::query()
            ->where('branch_id', $branchId)
            ->whereIn('item_id', $itemIds)
            ->orderBy('item_id');

        $rows = $useRowLock
            ? $query->lockForUpdate()->get()->keyBy('item_id')
            : $query->get()->keyBy('item_id');

        foreach ($itemIds as $itemId) {
            $row = $rows->get($itemId);
            $available = $row ? (bool) $row->is_available : true;
            if (! $available) {
                // [DEEP-R2 2026-07-15 / UX borne] Nom du produit, pas son ID technique ni
                // le code brut de raison — le message remonte tel quel à l'écran client
                // (constaté e2e : « Article 103 indisponible … (stock_rupture) »).
                // [TEST-E2E-HEAL 2026-07-15] Scope précis : Item n'a PAS de BranchScope, seulement
                // SoftDeletes → on bypasse UNIQUEMENT le soft-delete pour résoudre le nom même si
                // l'item a été supprimé (message client). Évite le pluriel (sentinelle WGS Z6-P1).
                $itemName = (string) (\App\Models\Item::withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
                    ->whereKey($itemId)->value('name') ?? "Article {$itemId}");
                throw new \InvalidArgumentException(
                    "« {$itemName} » n'est plus disponible — merci de le retirer de votre panier.",
                    422
                );
            }
        }
    }

    /**
     * [SYNC cross-surface 2026-08-04] Garde de SYMÉTRIE extras/variations — DÉFENSE EN PROFONDEUR,
     * CHEMIN LEGACY UNIQUEMENT.
     *
     * ⚠️ CORRECTION (audit LOGIQUE RED L3, 2026-08-05) : en PRODUCTION, la garde extras/variations
     * est déjà assurée par `ChoiceAvailabilityResolver::assertSelectionsOrderable` (appelée par
     * `PricingService::calculateOrder`, chemin SSOT `use_ssot_service=true` verrouillé en prod par
     * `PricingSsotFlagProductionStableSentinelTest`). Cette méthode n'est appelée QUE depuis la branche
     * LEGACY `else` de `FrontendOrderService` (use_ssot_service=false) → jamais exécutée en prod. Elle
     * reste comme filet defense-en-profondeur SI le flag est basculé OFF au runtime (web/kiosk). La
     * « divergence permanente » qu'un premier audit lui attribuait était en fait DÉJÀ fail-closed par
     * le resolver SSOT. Preuve du VRAI chemin : `SubmitRevalidatesChoiceAvailabilityThroughPricingTest`
     * (`test_calculate_order_rejects_manually_86_extra_on_ssot_path`).
     *
     * Même SSOT StockLevel que la borne (isExtraAvailable / isVariationAvailable → isStockableAvailable)
     * → parité par construction (règle V1 : ligne StockLevel absente = disponible → aucun faux rejet).
     *
     * @param  array<int|mixed>  $extraIds
     * @param  array<int|mixed>  $variationIds
     *
     * @throws \InvalidArgumentException 422 si un extra/variation demandé est 86 sur la branche.
     */
    public function assertExtrasAndVariationsOrderableForBranch(int $branchId, array $extraIds, array $variationIds): void
    {
        if ($branchId < 1) {
            return;
        }

        $extraIds = array_values(array_unique(array_filter(array_map(static fn ($id) => (int) $id, $extraIds))));
        foreach ($extraIds as $extraId) {
            if ($this->isExtraAvailable($extraId, $branchId)) {
                continue;
            }
            throw new \InvalidArgumentException(
                '« '.$this->stockableDisplayName(ItemExtra::class, $extraId)."\u{00A0}» n'est plus disponible — merci de le retirer de votre panier.",
                422
            );
        }

        $variationIds = array_values(array_unique(array_filter(array_map(static fn ($id) => (int) $id, $variationIds))));
        foreach ($variationIds as $variationId) {
            if ($this->isVariationAvailable($variationId, $branchId)) {
                continue;
            }
            throw new \InvalidArgumentException(
                '« '.$this->stockableDisplayName(ItemVariation::class, $variationId)."\u{00A0}» n'est plus disponible — merci de le retirer de votre panier.",
                422
            );
        }
    }

    /**
     * Nom lisible client d'un extra/variation. Bypasse le soft-delete pour un message correct
     * même si l'option a été supprimée. Ni ItemExtra ni ItemVariation n'a de BranchScope → pas
     * de fuite cross-branch.
     */
    private function stockableDisplayName(string $type, int $id): string
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
        $model = $type === ItemVariation::class ? ItemVariation::class : ItemExtra::class;

        return (string) ($model::withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
            ->whereKey($id)->value('name') ?? "Option {$id}");
    }

    /**
     * [CAISSE-LOGIC-HEAL SYNC-P1 2026-07-11] Résout les item_id des COMPOSANTS de menu
     * (addons) référencés par une liste de lignes de commande. La garde d'orderabilité
     * ne recevait que les item_id de 1er niveau (`pluck('item_id')`) : un composant en
     * rupture (ex : la boisson d'un menu) restait donc commandable — asymétrie
     * lecture/écriture, alors que le chemin de lecture (`MenuProjectionService`) filtre
     * bien les addons. À fusionner dans les $itemIds passés à assertItemsOrderableForBranch.
     *
     * @param  iterable<mixed>  $requestItems  lignes de commande portant `item_addons[{id}]`
     * @return array<int>
     */
    public function componentItemIdsFor($requestItems): array
    {
        $addonIds = collect($requestItems)
            ->pluck('item_addons')
            ->flatten(1)
            ->pluck('id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($addonIds === []) {
            return [];
        }

        return \App\Models\ItemAddon::query()
            ->whereIn('id', $addonIds)
            ->pluck('addon_item_id')
            ->filter()
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Apply daily counters after an order is created (no-op if no row exists).
     * Auto-86 once the daily cap is reached.
     *
     * Uses the atomic conditional UPDATE pattern selected in
     * reports/audit/M2_1_9_INDUSTRY_COMPARATIVE_ANALYSIS_2026-05-02.md:
     * one capped counter update, then one CAS-style availability flip so
     * concurrent decrements emit the 86 event exactly once.
     */
    public function decrementForOrder(Model $order): void
    {
        $branchId = (int) $order->branch_id;
        $orderId = (int) ($order->id ?? 0);

        // [HEAL-C.1 Z2 P1 2026-05-19] Per-order idempotency guard.
        //
        // `OrderCreated` may fire twice for the same order (queue replay on
        // transient broadcaster failure, a refactor moving this listener to
        // `ShouldQueue`, a bad merge resurrecting an old cascade path). The
        // sibling stock listener is idempotent at the StockService layer via
        // `stock_movements.idempotency_key` (Z1 verified). This service used
        // raw `daily_consumed_qty + {$qty}` UPDATEs with ZERO per-order key —
        // re-fire = double-count toward the daily quota → premature 86 flip.
        //
        // `Cache::add()` is SETNX-equivalent (atomic first-writer-wins) on
        // Redis AND the array driver used in tests, so the guard is race-safe
        // across queue workers. 24h TTL is ~200× the queue retry horizon
        // (`tries=6, backoff=[1,5,15,60,300]` ≈ 6.4min) — bounded but ample.
        //
        // Failure mode if cache is flushed mid-day: a single re-dispatched
        // order could double-count once before the next clear. `daily_consumed_qty`
        // is a UX quota (auto-86), NOT a fiscal counter; `stock_movements`
        // remains the NF525-adjacent SSOT. The blast radius is bounded to
        // one order's quota, observable via the catalog warning dashboard.
        //
        // See: reports/audit/v1-sync-deep-audit-2026-05-19/HEAL-PLAN-C-order-lifecycle.md §3
        if ($branchId > 0 && $orderId > 0) {
            $orderKind = $order instanceof \App\Models\FrontendOrder ? 'fe' : 'o';
            $guardKey = sprintf(
                'availability:decremented:%s:%d:%d',
                $orderKind,
                $branchId,
                $orderId
            );
            if (! Cache::add($guardKey, 1, now()->addHours(24))) {
                // Already processed this order — short-circuit, no decrement.
                return;
            }
        }

        // [Wave 3c KDS-ADV3C-03 P1 2026-05-18] Explicit Paris-local day —
        // see toggle() comment. `daily_reset_at` is DATE column; predicate
        // `whereDate('daily_reset_at', '<', $today)` compares plain Y-m-d.
        $today = Carbon::today(config('app.timezone'))->toDateString();

        foreach ($order->orderItems as $line) {
            $qty = (int) $line->quantity;

            DB::table('item_branch_availability')
                ->where('item_id', $line->item_id)
                ->where('branch_id', $branchId)
                ->whereDate('daily_reset_at', '<', $today)
                ->update([
                    'daily_consumed_qty' => 0,
                    'daily_reset_at' => $today,
                ]);

            $rows = DB::table('item_branch_availability')
                ->where('item_id', $line->item_id)
                ->where('branch_id', $branchId)
                ->whereNotNull('max_daily_qty')
                ->update([
                    'daily_consumed_qty' => DB::raw(
                        "CASE WHEN daily_consumed_qty + {$qty} > max_daily_qty "
                        ."THEN max_daily_qty ELSE daily_consumed_qty + {$qty} END"
                    ),
                    'updated_at' => now(),
                ]);

            if ($rows === 0) {
                continue;
            }

            $flipRows = DB::table('item_branch_availability')
                ->where('item_id', $line->item_id)
                ->where('branch_id', $branchId)
                ->where('is_available', true)
                ->whereRaw('daily_consumed_qty >= max_daily_qty')
                ->update([
                    'is_available' => false,
                    'unavailable_reason' => 'out_of_stock',
                    'unavailable_since' => now(),
                    'updated_at' => now(),
                ]);

            if ($flipRows === 1) {
                $this->dispatchEvent(
                    (int) $line->item_id,
                    $branchId,
                    false,
                    'out_of_stock'
                );
            }
        }
    }

    private function dispatchEvent(int $itemId, int $branchId, bool $available, ?string $reason): void
    {
        DB::afterCommit(function () use ($itemId, $branchId, $available, $reason): void {
            event(ItemAvailabilityChanged::forBranch(
                itemId: $itemId,
                branchId: $branchId,
                isAvailable: $available,
                reason: $reason
            ));
        });
    }

    // ---------------------------------------------------------------------
    // [F-016a-BIS] Branch-scoped manual rupture for ItemExtra / ItemVariation.
    //
    // Backed by the polymorphic stock_levels table (stockable_type +
    // stockable_id). Manual rupture sets `manual_unavailable_reason` /
    // `manual_unavailable_since`; ChoiceAvailabilityResolver gives manual
    // priority over automatic on_hand. Available toggle clears those columns
    // and restores stock-driven availability without touching on_hand.
    //
    // Branch isolation: every query/upsert is keyed on (branch_id, type, id).
    // Idempotency: re-toggling to the same state returns the existing row
    // without dispatching duplicate events.
    // ---------------------------------------------------------------------

    public function toggleExtra(int $extraId, int $branchId, bool $available, ?string $reason = null): StockLevel
    {
        return $this->toggleStockable(ItemExtra::class, $extraId, $branchId, $available, $reason);
    }

    public function toggleVariation(int $variationId, int $branchId, bool $available, ?string $reason = null): StockLevel
    {
        return $this->toggleStockable(ItemVariation::class, $variationId, $branchId, $available, $reason);
    }

    /**
     * Read helper. True iff the (extra, branch) pair has neither a manual
     * rupture flag nor an on_hand=0 row. Absent row = available (V1 rule).
     */
    public function isExtraAvailable(int $extraId, int $branchId): bool
    {
        return $this->isStockableAvailable(ItemExtra::class, $extraId, $branchId);
    }

    public function isVariationAvailable(int $variationId, int $branchId): bool
    {
        return $this->isStockableAvailable(ItemVariation::class, $variationId, $branchId);
    }

    /**
     * @return array<int, int> sorted ascending list of extra IDs marked manually unavailable on this branch.
     */
    public function getUnavailableExtraIdsForBranch(int $branchId): array
    {
        return $this->getManuallyUnavailableIdsForBranch(ItemExtra::class, $branchId);
    }

    /**
     * @return array<int, int> sorted ascending list of variation IDs marked manually unavailable on this branch.
     */
    public function getUnavailableVariationIdsForBranch(int $branchId): array
    {
        return $this->getManuallyUnavailableIdsForBranch(ItemVariation::class, $branchId);
    }

    /**
     * Aggregate snapshot used by the admin StockManager dashboard
     * (GET /api/admin/menu/availability/branch/{branch}). Returns stable
     * shape even when some buckets are empty so the frontend can render
     * three tabs unconditionally.
     *
     * @return array{
     *   branch_id: int,
     *   items: array<int, array{item_id:int, reason:?string, since:?string}>,
     *   extras: array<int, array{extra_id:int, reason:?string, since:?string}>,
     *   variations: array<int, array{variation_id:int, reason:?string, since:?string}>
     * }
     */
    public function getBranchAvailabilitySnapshot(int $branchId): array
    {
        // [quota-daily-reenable-cron-only 2026-07-22] First read of the day for
        // this branch catches up the overnight quota reset the 00:05 cron missed
        // (box off overnight). Guarded once/day/branch — cheap on every other read.
        $this->reconcileStaleDailyQuota($branchId);

        $items = ItemBranchAvailability::query()
            ->where('branch_id', $branchId)
            ->where('is_available', false)
            ->orderBy('item_id')
            ->get(['item_id', 'unavailable_reason', 'unavailable_since'])
            ->map(fn ($row): array => [
                'item_id' => (int) $row->item_id,
                'reason' => $row->unavailable_reason,
                'since' => optional($row->unavailable_since)?->toIso8601String(),
            ])
            ->all();

        $extras = StockLevel::query()
            ->where('branch_id', $branchId)
            ->where('stockable_type', ItemExtra::class)
            ->whereNotNull('manual_unavailable_reason')
            ->orderBy('stockable_id')
            ->get(['stockable_id', 'manual_unavailable_reason', 'manual_unavailable_since'])
            ->map(fn (StockLevel $row): array => [
                'extra_id' => (int) $row->stockable_id,
                'reason' => $row->manual_unavailable_reason,
                'since' => optional($row->manual_unavailable_since)?->toIso8601String(),
            ])
            ->all();

        $variations = StockLevel::query()
            ->where('branch_id', $branchId)
            ->where('stockable_type', ItemVariation::class)
            ->whereNotNull('manual_unavailable_reason')
            ->orderBy('stockable_id')
            ->get(['stockable_id', 'manual_unavailable_reason', 'manual_unavailable_since'])
            ->map(fn (StockLevel $row): array => [
                'variation_id' => (int) $row->stockable_id,
                'reason' => $row->manual_unavailable_reason,
                'since' => optional($row->manual_unavailable_since)?->toIso8601String(),
            ])
            ->all();

        return [
            'branch_id' => $branchId,
            'items' => $items,
            'extras' => $extras,
            'variations' => $variations,
        ];
    }

    /**
     * Shared core for {@see toggleExtra()} / {@see toggleVariation()}.
     *
     * Behaviour:
     *  - Acquires a row-level lock to serialize concurrent toggles on the
     *    same (branch, type, id) tuple.
     *  - Creates the stock_levels row if missing (on_hand defaults to 0,
     *    which is the safe value when an extra was never tracked yet).
     *  - Idempotent: returns the existing row without dispatching when the
     *    requested state matches the persisted manual flag.
     *  - Dispatches the appropriate domain event AFTER commit so outbox /
     *    broadcast pipeline never observes uncommitted state.
     */
    private function toggleStockable(string $type, int $id, int $branchId, bool $available, ?string $reason): StockLevel
    {
        return DB::transaction(function () use ($type, $id, $branchId, $available, $reason): StockLevel {
            $level = StockLevel::query()
                ->where('branch_id', $branchId)
                ->where('stockable_type', $type)
                ->where('stockable_id', $id)
                ->lockForUpdate()
                ->first();

            $newReason = $available ? null : $reason;
            $newSince = $available ? null : now();

            if (! $level) {
                $level = StockLevel::query()->create([
                    'branch_id' => $branchId,
                    'stockable_type' => $type,
                    'stockable_id' => $id,
                    'on_hand' => 0,
                    'reserved' => 0,
                    'manual_unavailable_reason' => $newReason,
                    'manual_unavailable_since' => $newSince,
                ]);

                $this->dispatchStockableEvent($type, $id, $branchId, $available, $newReason);

                return $level;
            }

            $currentReason = $level->manual_unavailable_reason;
            $currentlyManualUnavailable = $currentReason !== null && $currentReason !== '';

            // [TERRAIN-HEAL 2026-07-16 · MGMT-86-REACTIVATE — owner a délégué la décision] Réactivation
            // d'un stockable FLAG-managed : effacer le flag NE SUFFIT PAS — le row conserve on_hand=0
            // (créé ainsi au 86, cf. l.582 "safe default"), que ChoiceAvailabilityResolver + isStockableAvailable
            // relisent comme rupture → extra ÉPUISÉ POUR TOUJOURS sur la borne malgré la tuile verte admin.
            // V1 Le Cayenne : extras/variations = flag on/off (pas de vrai comptage). Sans stock réel
            // (on_hand<=0 && reserved<=0), on SUPPRIME le row → règle V1 « absent = disponible » (l.622).
            // Un extra RÉELLEMENT stock-tracké (on_hand>0 ou reserved>0) N'EST PAS supprimé (juste flag effacé).
            // [CYCLE2-HEAL 2026-07-16 · MGMT-86-DELETE-FK] Le delete est GARDÉ par l'absence de
            // stock_movements enfants : en prod MySQL, `stock_movements.stock_level_id` est
            // restrictOnDelete + trigger append-only BEFORE DELETE (SIGNAL 45000) → supprimer un level
            // à mouvements crashe (500) et VERROUILLE la réactivation à vie (SQLite ne l'applique pas =
            // faux vert). Un stockable purement flag-86 n'a AUCUN mouvement → delete sûr = « absent=dispo ».
            // S'il a des mouvements (vrai stock), on ne delete pas : on efface juste le flag ci-dessous.
            if ($available && (int) $level->on_hand <= 0 && (int) $level->reserved <= 0
                && ! \App\Models\StockMovement::query()->where('stock_level_id', $level->id)->exists()) {
                $level->delete();
                $this->dispatchStockableEvent($type, $id, $branchId, true, null);
                return $level;
            }

            // Idempotency: same desired state and (when unavailable) same reason → no-op.
            if ($available && ! $currentlyManualUnavailable) {
                return $level;
            }
            if (! $available && $currentlyManualUnavailable && $currentReason === $reason) {
                return $level;
            }

            $level->manual_unavailable_reason = $newReason;
            $level->manual_unavailable_since = $newSince;
            $level->save();

            $this->dispatchStockableEvent($type, $id, $branchId, $available, $newReason);

            return $level;
        });
    }

    private function isStockableAvailable(string $type, int $id, int $branchId): bool
    {
        $level = StockLevel::query()
            ->where('branch_id', $branchId)
            ->where('stockable_type', $type)
            ->where('stockable_id', $id)
            ->first(['on_hand', 'manual_unavailable_reason']);

        if (! $level) {
            return true; // V1 rule: absent row = available.
        }

        if (is_string($level->manual_unavailable_reason) && $level->manual_unavailable_reason !== '') {
            return false;
        }

        return (int) $level->on_hand > 0;
    }

    /**
     * @return array<int, int>
     */
    private function getManuallyUnavailableIdsForBranch(string $type, int $branchId): array
    {
        return StockLevel::query()
            ->where('branch_id', $branchId)
            ->where('stockable_type', $type)
            ->whereNotNull('manual_unavailable_reason')
            ->orderBy('stockable_id')
            ->pluck('stockable_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function dispatchStockableEvent(string $type, int $id, int $branchId, bool $available, ?string $reason): void
    {
        DB::afterCommit(function () use ($type, $id, $branchId, $available, $reason): void {
            if ($type === ItemExtra::class) {
                event(new ItemExtraAvailabilityChanged(
                    extraId: $id,
                    branchId: $branchId,
                    isAvailable: $available,
                    reason: $reason
                ));

                return;
            }

            if ($type === ItemVariation::class) {
                event(new ItemVariationAvailabilityChanged(
                    variationId: $id,
                    branchId: $branchId,
                    isAvailable: $available,
                    reason: $reason
                ));

                return;
            }
        });
    }

    /**
     * [F-01 + NEW-05] Compensating release of branch-scoped daily counters when an
     * order is canceled or refunded (full or partial). Idempotent per line via
     * the `order_items.released_qty` ledger:
     *
     *   delta = min(requestedQty, quantity - released_qty)
     *
     * Duplicate event delivery (re-fired, or cancel-then-refund) becomes a safe
     * no-op once `released_qty` reaches `quantity`. // allow: docblock-only mention
     * of cancel/refund flow names — no sensitive action performed here.
     *
     * Branch isolation: queries are filtered by both `item_id` AND `branch_id`.
     * After-commit: ItemAvailabilityChanged events emitted on the unavailable→available
     * flip are queued and dispatched via DB::afterCommit (commit-before-dispatch
     * invariant — gate C9 / KI-001).
     *
     * @param  array<int, array{order_item_id:int, item_id:int, branch_id:int, qty:int}>  $lineItems
     * @param  bool  $creditDailyQuota  false = commande d'un jour PRÉCÉDENT : on écrit
     *         TOUJOURS le ledger released_qty (anti-double-release du stock physique,
     *         intemporel) mais on ne crédite PAS daily_consumed_qty (compteur du jour).
     *         [DEEP-R2b 2026-07-15 / P1] Le retour anticipé isToday() des listeners
     *         sautait le SEUL écrivain du ledger → une commande d'hier annulée
     *         aujourd'hui créditait on_hand DEUX fois (refund puis cancel, clés
     *         d'idempotence distinctes par reason).
     */
    public function releaseForOrderItems(array $lineItems, bool $creditDailyQuota = true): void
    {
        if ($lineItems === []) {
            return;
        }

        $eventsToDispatch = [];

        DB::transaction(function () use ($lineItems, $creditDailyQuota, &$eventsToDispatch): void {
            foreach ($lineItems as $lineItem) {
                $orderItemId = (int) ($lineItem['order_item_id'] ?? 0);
                $itemId = (int) ($lineItem['item_id'] ?? 0);
                $branchId = (int) ($lineItem['branch_id'] ?? 0);
                $requestedQty = max(0, (int) ($lineItem['qty'] ?? 0));

                if ($orderItemId <= 0 || $itemId <= 0 || $branchId <= 0 || $requestedQty <= 0) {
                    continue;
                }

                $orderItem = DB::table('order_items')
                    ->where('id', $orderItemId)
                    ->lockForUpdate()
                    ->first(['id', 'item_id', 'branch_id', 'quantity', 'released_qty']);

                if (! $orderItem) {
                    Log::warning('availability release skipped (order item missing)', [
                        'order_item_id' => $orderItemId,
                        'item_id' => $itemId,
                        'branch_id' => $branchId,
                    ]);

                    continue;
                }

                if ((int) $orderItem->item_id !== $itemId
                    || (int) $orderItem->branch_id !== $branchId) {
                    Log::warning('availability release skipped (line item mismatch)', [
                        'order_item_id' => $orderItemId,
                        'expected_item_id' => $itemId,
                        'actual_item_id' => (int) $orderItem->item_id,
                        'expected_branch_id' => $branchId,
                        'actual_branch_id' => (int) $orderItem->branch_id,
                    ]);

                    continue;
                }

                $remaining = max(0, (int) $orderItem->quantity - (int) $orderItem->released_qty);
                $delta = min($requestedQty, $remaining);

                if ($delta <= 0) {
                    Log::info('availability release skipped (already released)', [
                        'order_item_id' => $orderItemId,
                        'item_id' => $itemId,
                        'branch_id' => $branchId,
                        'requested_qty' => $requestedQty,
                        'released_qty' => (int) $orderItem->released_qty,
                        'quantity' => (int) $orderItem->quantity,
                    ]);

                    continue;
                }

                $availability = DB::table('item_branch_availability')
                    ->where('item_id', $itemId)
                    ->where('branch_id', $branchId)
                    ->lockForUpdate()
                    ->first([
                        'item_id',
                        'branch_id',
                        'is_available',
                        'unavailable_reason',
                        'daily_consumed_qty',
                        'max_daily_qty',
                    ]);

                if ($availability && $creditDailyQuota) {
                    $currentConsumed = max(0, (int) $availability->daily_consumed_qty);
                    $newConsumed = max(0, $currentConsumed - $delta);
                    $wasUnavailable = ! (bool) $availability->is_available;
                    // [SELF-AUDIT R4 P2 2026-07-05 — 86 manuel/cron écrasé par une annulation] La remise
                    // en vente auto ne doit concerner QUE les articles 86'd par le QUOTA journalier
                    // (unavailable_reason='out_of_stock'). Sans ce garde, une annulation/remboursement d'une
                    // commande contenant un article 86'd MANUELLEMENT (seasonal/supplier_issue/quality…) ou
                    // par le cron préventif ('stock_rupture') le remettait EN VENTE en silence → la cuisine
                    // reçoit des commandes qu'elle ne peut honorer. Miroir EXACT du garde de setMaxDailyQty
                    // (AvailabilityService:150, `$previousReason === 'out_of_stock'`).
                    $shouldFlip = $wasUnavailable
                        && $availability->unavailable_reason === 'out_of_stock'
                        && $availability->max_daily_qty !== null
                        && $newConsumed < (int) $availability->max_daily_qty;

                    $update = ['daily_consumed_qty' => $newConsumed];

                    if ($shouldFlip) {
                        $update['is_available'] = true;
                        $update['unavailable_reason'] = null;
                        $update['unavailable_since'] = null;

                        $eventsToDispatch[] = [
                            'item_id' => $itemId,
                            'branch_id' => $branchId,
                            'is_available' => true,
                            'reason' => 'released_after_cancel_or_refund',
                        ];
                    }

                    DB::table('item_branch_availability')
                        ->where('item_id', $itemId)
                        ->where('branch_id', $branchId)
                        ->update($update);
                }

                DB::table('order_items')
                    ->where('id', $orderItemId)
                    ->update([
                        'released_qty' => (int) $orderItem->released_qty + $delta,
                        'released_at' => Carbon::now(),
                    ]);
            }

            if ($eventsToDispatch !== []) {
                DB::afterCommit(function () use ($eventsToDispatch): void {
                    foreach ($eventsToDispatch as $payload) {
                        event(ItemAvailabilityChanged::forBranch(
                            itemId: $payload['item_id'],
                            branchId: $payload['branch_id'],
                            isAvailable: $payload['is_available'],
                            reason: $payload['reason'],
                        ));
                    }
                });
            }
        });
    }
}
