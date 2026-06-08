# LOCK REQUEST — Fiscal/AuditLogService config:cache hardening (NF525)
**Date:** 2026-06-08 · **Frozen file:** `app/Services/Fiscal/AuditLogService.php:273` (NF525 audit-chain signing — frozen list, `.cursor/hooks/safety-check.sh:25`)
**Requested by:** owner (decision "Oui, prépare LOCK + fix") · **Author:** Claude orchestrator
**Verdict of this doc: PREPARE-ONLY + RECOMMEND DEFER (no-op for V1). Apply only at a deliberate multi-branch/config:cache milestone with fiscal test coverage.**

## The line
```php
// AuditLogService::secretFor(?int $branchId)
$override = env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId);   // :273
if (is_string($override) && $override !== '') { return ...$override...; }
$configured = Config::get('fiscal.audit_secret');           // primary path — CONFIG-BASED
... // string or per-branch array; throws if unconfigured
```

## CORRECTED severity (PR-07 over-stated it)
PR-07 framed this as "config:cache → null → chaîne HMAC NF525 cassée (catastrophique)". **Inspection disproves that for V1:**
1. The **actual signing secret** comes from `Config::get('fiscal.audit_secret')` = `config/fiscal.php:31` = `env('FISCAL_AUDIT_SECRET','')`. Config values ARE captured correctly by `config:cache` (that's its purpose) → **the primary secret is already config:cache-safe.**
2. The raw `env()` at :273 is **only an OPTIONAL per-branch OVERRIDE** (`FISCAL_AUDIT_SECRET_BRANCH_{id}`), documented optional in `config/fiscal.php:27-28`.
3. **No `FISCAL_AUDIT_SECRET_BRANCH_*` is set** in the operating/deployed `.env` (verified: only the global `FISCAL_AUDIT_SECRET` exists). V1 = **single branch** (Le Cayenne, branch_id=1).
4. Therefore under `config:cache`: `env('FISCAL_AUDIT_SECRET_BRANCH_1')` → null **both cached and uncached** → override branch skipped identically → falls through to the config-based secret → **chain hash unchanged. NO break in V1.**

**Real (residual) risk:** ONLY a multi-branch operator who (a) SETS a per-branch override AND (b) runs `config:cache` would have that branch silently fall back to the global secret → chain for that branch would differ cached-vs-uncached. **Not reachable in V1.**

## The fix (ready, when needed)
Make the per-branch override config-sourced so it survives `config:cache`:
- `config/fiscal.php`: add `'audit_secret_branch' => [ /* built from env at config-build time */ ]` (e.g. map known branch ids, or fold per-branch into making `audit_secret` an array).
- `AuditLogService:273`: replace `env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId)` with `Config::get('fiscal.audit_secret_branch.'.$branchId)`.
- **Verification required before merge (fiscal):** re-sign a sample audit row on a CLONE under both uncached AND `config:cache`, assert identical `current_hash`; run the fiscal audit-chain test suite; assert operating chain bit-identical (count+last_hash unchanged). Do NOT run `php artisan test` on the shared box without DEVDB-GUARD (use targeted filter or tinker-on-clone).

## RECOMMENDATION (supervisor)
**DEFER the frozen edit.** It is a verified no-op for V1, touches the NF525 signing path (inherent risk), and delivers zero V1 benefit. Apply it as part of the **config:cache go-live hardening milestone** (alongside the AppLibrary P0 already fixed) and/or when **multi-branch** is actually adopted — with the fiscal verification above. The owner's original "fix it" was premised on PR-07's catastrophe framing, now corrected.

## OWNER GATE
- [ ] Apply now (I'll do it on a clone with full fiscal verification), OR
- [x] **DEFER** to config:cache/multi-branch milestone (recommended) — this doc is the standing artifact.
- Either way: **do not run `config:cache` on the live box** until this is resolved AND the AppLibrary P0 is deployed (it already is on pre-cloud-exec).
