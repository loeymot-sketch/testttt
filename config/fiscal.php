<?php

/**
 * [POS-9.4] Fiscal configuration — NF525 / Loi Finance 2018 anti-fraude TVA.
 *
 * Every secret MUST be provided via environment variable in production.
 * The defaults below are only safe for local development and automated
 * tests (they are NEVER used to sign real receipts).
 */
return [
    /*
    |----------------------------------------------------------------------
    | Audit log HMAC secret
    |----------------------------------------------------------------------
    |
    | Secret used by AuditLogService to compute the chained HMAC over each
    | audit row. May be a single string (shared across branches) or an
    | array keyed by branch_id for tenants that require per-branch
    | rotation.
    |
    | Env:  FISCAL_AUDIT_SECRET           (required in production)
    |       FISCAL_AUDIT_SECRET_BRANCH_N  (optional, overrides per branch)
    */

    'audit_secret' => env('FISCAL_AUDIT_SECRET', 'dev-fiscal-audit-secret-do-not-use-in-prod'),

    /*
    |----------------------------------------------------------------------
    | Z/X report signing secret
    |----------------------------------------------------------------------
    */
    'z_report_secret' => env('FISCAL_Z_REPORT_SECRET', 'dev-fiscal-zreport-secret-do-not-use-in-prod'),

    /*
    |----------------------------------------------------------------------
    | Archive retention (NF525 = 6 years)
    |----------------------------------------------------------------------
    */
    'archive_retention_years' => (int) env('FISCAL_ARCHIVE_RETENTION_YEARS', 6),

    /*
    |----------------------------------------------------------------------
    | Archive storage
    |----------------------------------------------------------------------
    |
    | storage/fiscal/{branch}/{period}.zip (gpg-encrypted in prod when the
    | `gpg` binary is available).
    */
    'archive_disk' => env('FISCAL_ARCHIVE_DISK', 'local'),
    'archive_path' => env('FISCAL_ARCHIVE_PATH', 'fiscal'),
];
