<?php

/**
 * [POS-9.4] Fiscal configuration — NF525 / Loi Finance 2018 anti-fraude TVA.
 *
 * Every secret MUST be provided via environment variable in production.
 * The defaults below are ONLY safe for local development and automated
 * tests. In production (APP_ENV=production), AuditLogService and
 * ZReportService will REFUSE to sign with any of:
 *   - empty / missing secret
 *   - secret matching a known dev sentinel (see `dev_sentinels` below)
 *   - secret shorter than 32 characters
 *
 * See docs/FISCAL_SECRETS.md for the rotation runbook.
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

    'audit_secret' => env('FISCAL_AUDIT_SECRET', ''),

    /*
    |----------------------------------------------------------------------
    | Z/X report signing secret
    |----------------------------------------------------------------------
    */
    'z_report_secret' => env('FISCAL_Z_REPORT_SECRET', ''),

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

    /*
    |----------------------------------------------------------------------
    | Dev sentinels (refused in production)
    |----------------------------------------------------------------------
    |
    | Any secret whose literal value matches one of these strings is
    | REJECTED in production — even if env() explicitly set it. Prevents
    | the shipped-default leaking into a live fiscal trail.
    |
    | Previous versions of config/fiscal.php shipped these two strings as
    | defaults; keep them here so a production deployment that still
    | uses the old .env.example will fail loudly at first signing attempt
    | instead of forging receipts silently.
    */
    'dev_sentinels' => [
        'dev-fiscal-audit-secret-do-not-use-in-prod',
        'dev-fiscal-zreport-secret-do-not-use-in-prod',
        'changeme',
        'change-me',
        'test',
        'dev',
        'secret',
    ],

    /*
    |----------------------------------------------------------------------
    | Minimum secret length (bytes/characters)
    |----------------------------------------------------------------------
    */
    'min_secret_length' => 32,
];
