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
    | Z-chain verification
    |----------------------------------------------------------------------
    |
    | `genesis_prev_hash` documents the expected sentinel for the first
    | Z report in a branch. Legacy rows may still store a null/empty
    | prev_hash; verification accepts that historical shape so the
    | check remains read-only and backward compatible.
    |
    | `verify_chain_strict` defaults to null so the service can infer
    | the policy from the current environment (strict in production,
    | degraded/log-only elsewhere). Set an explicit bool to override.
    */
    'genesis_prev_hash' => env('FISCAL_GENESIS_PREV_HASH', str_repeat('0', 64)),
    'verify_chain_strict' => env('FISCAL_VERIFY_CHAIN_STRICT', null),

    /*
    |----------------------------------------------------------------------
    | [W9.A / G2] verifyChain pre-archive (defense in depth)
    |----------------------------------------------------------------------
    |
    | When true, FiscalArchiveCommand calls ZReportService::verifyChain()
    | before producing the bundle. Goal : never ship an archive whose Z
    | chain HMAC is broken (NF525 evidence integrity).
    |
    | - true  + chain OK  → archive proceeds, manifest records z_chain_verified=true
    | - true  + chain KO  → archive ABORTED, log fiscal CRITICAL, exit FAILURE
    | - false             → verify skipped, manifest records z_chain_verified=null
    |
    | The CLI flag --no-verify on the artisan command bypasses verification
    | regardless of this config (intended for ops recovery scenarios; use
    | with care as the resulting bundle should not be considered evidence).
    */
    'verify_chain_before_archive' => filter_var(
        env('FISCAL_VERIFY_CHAIN_BEFORE_ARCHIVE', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? true,

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
