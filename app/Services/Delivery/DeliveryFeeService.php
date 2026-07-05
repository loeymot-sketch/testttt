<?php

namespace App\Services\Delivery;

use App\Models\Branch;

class DeliveryFeeService
{
    /**
     * Compute delivery fee from a raw distance (km).
     *
     * [Sprint H3 DEL-5 2026-05-17] Optional second argument `?Branch $branch`
     * enables per-branch configurable fees (SaaS multi-tenant invariant,
     * CLAUDE.md §9). When ALL THREE branch columns are set
     * (delivery_fee_base / delivery_fee_per_km / delivery_fee_minimum),
     * the new formula `max(minimum, base + per_km * distance)` is used.
     *
     * When the branch is omitted, OR any of the three columns is NULL, the
     * legacy formula `max(5, ceil(d/5)*5)` is preserved — zero-risk for
     * existing branches not yet migrated to per-branch config. Admin UI
     * to populate these columns is V1.0.2 (operators use tinker/SQL for V1.0.1).
     *
     * Backward-compat: existing 5+ call sites that pass only the distance
     * keep working unchanged.
     */
    public function fromDistanceKm(mixed $distanceKm, ?Branch $branch = null): float
    {
        $distance = is_numeric($distanceKm) ? (float) $distanceKm : null;
        if ($distance === null || ! is_finite($distance) || $distance < 0) {
            return 0.0;
        }

        if ($branch !== null) {
            $base    = $branch->delivery_fee_base !== null ? (float) $branch->delivery_fee_base : null;
            $perKm   = $branch->delivery_fee_per_km !== null ? (float) $branch->delivery_fee_per_km : null;
            $minimum = $branch->delivery_fee_minimum !== null ? (float) $branch->delivery_fee_minimum : null;

            if ($base !== null && $perKm !== null && $minimum !== null) {
                // [DEL-FEE whole-km, owner decision; base 5€→4€ on 2026-06-27] When a
                // free radius is configured, `base` covers the first `free_km` and each
                // STARTED km beyond adds `per_km` (rounded UP) — the owner's "4€ ≤5km,
                // +1€/km" rule. NULL free_km keeps the original linear `base + per_km*d`.
                $freeKm = $branch->delivery_fee_free_km !== null ? (float) $branch->delivery_fee_free_km : null;
                if ($freeKm !== null) {
                    $chargeableKm = (int) ceil(max(0.0, $distance - $freeKm));
                    $fee = $base + $perKm * $chargeableKm;
                } else {
                    $fee = $base + $perKm * $distance;
                }
                return round(max($minimum, $fee), 2);
            }
        }

        // [Legacy fallback — Sister Sprint 2B / DEL-5 origin]
        return (float) max(5, (int) ceil($distance / 5) * 5);
    }
}
