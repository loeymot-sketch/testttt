<?php

/**
 * [Sprint 1D / F-4 — 2026-05-16] Cash drawer configuration — variance gate.
 *
 * NF525 / Loi Finance 2018 anti-fraude requires every reconciliation
 * gap to be (a) measured, (b) reasoned, (c) approved by someone whose
 * role explicitly carries that responsibility. The threshold below
 * defines the rounding noise tolerated before that gate fires.
 *
 * See:
 *   - app/Services/Cash/CashDrawerService::reconcileSession()
 *   - app/Exceptions/CashVarianceRequiresApprovalException
 *   - tests/Feature/Cash/CashVarianceGateTest
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Variance threshold (euros, absolute value)
    |--------------------------------------------------------------------------
    |
    | Any |variance| > threshold requires both:
    |   - a non-empty `variance_reason` string (max 500 chars), and
    |   - the operating user holding `cash.reconcile.variance.override`
    |     (granted to Admin + Branch Manager by RolePermissionTableSeeder).
    |
    | The default 2.00 euros covers normal coin-counting rounding noise.
    | Decreasing it tightens fiscal discipline; increasing it must be
    | justified in the deploy runbook.
    */
    'variance_threshold_eur' => (float) env('CASH_VARIANCE_THRESHOLD_EUR', 2.00),

    /*
    |--------------------------------------------------------------------------
    | Manager approval required above threshold
    |--------------------------------------------------------------------------
    |
    | When true (default), crossing the threshold triggers the
    | CashVarianceRequiresApprovalException 422 unless the calling user
    | holds the permission. Set to false ONLY for emergency rollback;
    | leaving it false in production is an NF525 audit finding.
    */
    'variance_manager_approval_required' => filter_var(
        env('CASH_VARIANCE_MANAGER_APPROVAL_REQUIRED', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? true,

    /*
    |--------------------------------------------------------------------------
    | Permission name (Spatie) gating variance override
    |--------------------------------------------------------------------------
    */
    'variance_override_permission' => env(
        'CASH_VARIANCE_OVERRIDE_PERMISSION',
        'cash.reconcile.variance.override'
    ),

    /*
    |--------------------------------------------------------------------------
    | variance_reason max length (DB column = 255 char)
    |--------------------------------------------------------------------------
    */
    'variance_reason_max_length' => 255,
];
