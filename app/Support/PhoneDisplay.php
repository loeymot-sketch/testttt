<?php

namespace App\Support;

/**
 * Phone-display sanitizer for API response layer.
 *
 * `App\Models\User::creating` injects sentinel placeholders
 * (PENDING_<id>, PENDING_CREATE_<hex>) into the `phone` column for
 * legacy user-bootstrap paths that have no real phone at creation
 * time (admin seeders, soak-test accounts, walk-in customer rows,
 * loyalty stubs). These sentinels MUST NOT leak to any client —
 * Vue, mobile, third-party integration, audit export, paper receipt.
 *
 * This helper centralizes the policy so every Resource that emits
 * `phone` to JSON applies the same guard.
 *
 * Frontend mirror: components/admin/kitchenDisplaySystem/KdsOrderCard.vue:355
 * (Sprint 5A Z9-P1-03) — `String($phone).startsWith('PENDING_')`.
 *
 * Source of UR-4 finding (red-team adversarial 2026-05-25):
 *   reports/ultra-review-goal-2026-05-25/UR-4-red-team.json
 */
final class PhoneDisplay
{
    /**
     * Return the phone string IF it is a real number, NULL otherwise.
     *
     * - null  → null
     * - ''    → null
     * - 'PENDING_<…>'        → null
     * - 'PENDING_CREATE_<…>' → null
     * - '+33612345678'        → '+33612345678'
     *
     * Returns null (not '') so JSON consumers get a clean absence
     * signal — frontends already handle the falsy check.
     */
    public static function safe(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        if (str_starts_with($phone, 'PENDING_')) {
            return null;
        }

        return $phone;
    }
}
