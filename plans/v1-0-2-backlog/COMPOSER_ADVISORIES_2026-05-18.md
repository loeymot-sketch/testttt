# Composer security advisories — V1.0.2 backlog
**Date**: 2026-05-18 · **Branch**: `v1-0-1-hardening-2026-05-17`
**Why deferred**: composer-update is a heavy operation with QA implications
(touches many packages, may require config / migration adjustments).
Documented here so the V1.0.2 hardening sprint picks them up explicitly.

## Snapshot — `composer audit` 2026-05-18

**12 advisories across 8 packages** : 4 high, 6 medium, 2 low.

| Package | Severity | CVE | Title | Affected | Recommended action |
|---|---|---|---|---|---|
| `aws/aws-sdk-php` | high | — (GHSA-27qh-8cxx-2cr5) | CloudFront Policy Document Injection via Special Characters | `>=3.11.7,<=3.371.3` | `composer update aws/aws-sdk-php` (latest 3.x) |
| `aws/aws-sdk-php` | medium | CVE-2025-14761 | Key Commitment Issues in S3 Encryption Clients | `>=3.0.0,<3.368.0` | Same upgrade as above |
| `phpseclib/phpseclib` | **high** | **CVE-2026-44167** | OID amplification DoS in ASN1::decodeOID() — CVE-2024-27355 mitigation bypass | `<3.0.42` | `composer update phpseclib/phpseclib --with-dependencies` (currently `3.0.47` per `composer show` — **may already be patched** ; re-run audit post-deploy) |
| `phpseclib/phpseclib` | **high** | **CVE-2026-32935** | AES-CBC padding oracle | (overlaps prior) | Same upgrade |
| `phpseclib/phpseclib` | low | (third advisory) | (third CVE) | (overlaps) | Same upgrade |
| `phpunit/phpunit` | high | (test-only) | (test framework) | various | Test-only — defer to next major test refresh |
| `laravel/framework` | medium | CVE-2025-27515 | File Validation Bypass | `<10.48.29` (V1 on 9.x — assess applicability) | Patch upgrade within 9.x line OR pin minor |
| `league/commonmark` | medium | CVE-2026-33347 | embed extension allowed_domains bypass | `>=2.3.0,<=2.8.1` | `composer update league/commonmark` to ≥2.8.2 |
| `league/commonmark` | medium | CVE-2026-30838 | DisallowedRawHtml extension bypass via whitespace | `>=2.0.0,<=2.8.0` | Same upgrade as above |
| `firebase/php-jwt` | low | CVE-2025-45769 | Weak encryption in php-jwt | `<7.0.0` | Audit usage — currently used? If yes, upgrade to 7.x (breaking) |
| `psy/psysh` | medium | (dev tool) | (psysh repl) | various | Dev-only — defer |
| `symfony/process` | medium | (varies) | (varies) | various | Check `composer why symfony/process` — if transitive only, no app impact |

## V1.0.2 PR scope (proposed)

**Per ultra-review PR-D T6 (corrected)** — phpseclib P0 + aws-sdk + commonmark + laravel/framework patch.

### Steps
1. `composer audit --no-interaction --format=plain > /tmp/audit-pre-v1-0-2.txt`
2. `composer update phpseclib/phpseclib --with-dependencies`
3. `composer update league/commonmark`
4. `composer update aws/aws-sdk-php` (within ^3.x major)
5. `composer update laravel/framework` (within ^9.x line — verify no breaking)
6. Re-run `composer audit` — expect ≥80% reduction
7. Run full PHPUnit + Vitest + Playwright smoke
8. Visual mandate per CLAUDE.md §6 if any frontend touched
9. NF525 chain attestation pre/post (count + last_hash unchanged)
10. Commit `chore(v1-0-2): composer advisory closure (Wave A)`

### Out-of-scope (separate PR)
- `firebase/php-jwt` v6→v7 (breaking changes, audit usage first)
- `phpunit/phpunit` major (test-framework upgrade — separate refresh)
- `psy/psysh` (dev-only)
- `symfony/process` (transitive — root upgrade triggers)

## Owner gate

| Gate | Description | WHO | WHAT | WHERE |
|---|---|---|---|---|
| G-CV2-1 | Composer update window for V1.0.2 | Physical owner | Approve maintenance window (15-30 min smoke after each batch) | Branch `v1-0-2-hardening-<date>` + this doc |

## References

- `composer audit --no-interaction --format=plain` raw output: `/tmp/composer-audit-2026-05-18.txt` (re-run during V1.0.2)
- Ultra-review finding: `reports/ultra-review-2026-05-18/PR_D_findings.json` finding ID `F-D6`
- CLAUDE.md §8 NF525 invariants (must stay green pre/post)
- `memory/project_pos_first_page_oss_filter_2026-05-18.md` (related PR-D hardening session)
