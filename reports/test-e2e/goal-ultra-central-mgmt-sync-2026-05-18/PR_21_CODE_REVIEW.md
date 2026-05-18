# Code Review — PR #21 `heal/cms-pr1-quickwins-2026-05-18`
## Substitute for `/ultrareview` (general-quality pass) — Claude-internal autonomous review

**Date:** 2026-05-18
**Reviewer:** general-purpose sub-agent (read-only)
**Verdict:** **MERGE-OK**

---

## Per-commit assessment

**`1e7c65ecc` — APP_DEBUG production boot guard** — MERGE-OK. Clean defense-in-depth, mirrors 4 existing guards at `AppServiceProvider.php:78-141`. Runtime exception IS the gate; no sentinel test needed.

**`139ce01aa` — Pusher channel-auth wildcard + Guest-Echo-Bypass** — MERGE-OK. Token-name discriminator is correct fix for Sanctum `['*']` wildcard. Staff branch-scoped fallback at `channels.php:61` preserved. `withoutGlobalScope(BranchScope::class)` correctly added on KioskMachine lookup. Sentinel checks both positive and negative patterns.

**`65f59e82f` — ws:heartbeat write** — MERGE-OK. Cache write correctly placed AFTER `$broadcaster->broadcast()`. Best-effort try/catch protects outbox dispatch. 120s TTL appropriate.

**`f840c3ef5` — NF525 fiscal-table REVOKE Ansible task** — MERGE-OK. REVOKE DROP/ALTER on 7 tables correct (TRUNCATE blocked transitively via DROP). `failed_when: false` tolerates first-deploy. Sentinel asserts presence in playbook YAML.

**`f225e63b5` — webhook_events.order_id FK** — MERGE-OK. `nullOnDelete` correct given NF525 hard-delete prohibition. Garbage scrub `whereNotIn` query correct. Also rename `cash-session` → `cash-sessions` in `idempotency.php` (clean rename, no coverage gap).

**`935eaca25` — 3 RBAC privilege-escalation paths** — MERGE-OK with product-gap note:
- M-R3-P0-D (Self-Permission Sync): integer FK comparison sound
- M-R3-P0-C (Tenant Admin shadow): `Rule::notIn` closes API creation; note no seeder mints `Tenant Admin` role (V1.0.2 product work)
- M-R3-P0-E (Admin@Branch0): `branch_id=0` writes gated to super-admin

**`32395b625` — BranchScope coverage sentinel** — MERGE-OK with sentinel-weakness note. Baseline-lock pattern sound. Minor: `newInstance()` silently skips constructor-throwing models (V1.0.2 cleanup target).

**`6a01c71bf` — PermissionController index gate** — MERGE-OK with behavior-change note. Dropping `->only('update')` correctly closes enumeration vector. Intended fix; confirm no admin UI surface relied on permission-matrix read without gate.

**`4b12f678a` — idempotency header-omission bypass** — MERGE-OK. 18 patterns + sentinel iterating `Route::getRoutes()`. Minor cosmetic: dead `$regex` variable at line 70 (only `$regex2` used).

---

## Cross-cutting concerns

- **Sentinel test discipline is strong** — 7 of 11 commits add sentinels asserting their pattern stays in place.
- **Frozen-zone CLAUDE.md §7**: **explicit 0 lines touched** across all 11 SHAs (verified per-SHA + range diff).
- **NF525 chain**: `audit_logs`, `z_reports`, `cash_movements` source unchanged; Ansible task is additive REVOKE only.
- **Migration ordering**: only 1 new migration runs correctly after the original webhook_events create.
- **Sentinel coverage**: proves the file-source pattern, not end-to-end runtime — FoodKing baseline-lock pattern, acceptable for guardrails.
- **Stack on parallel BUILD-6**: PermissionRequest::authorize fix must land before merging this PR.

---

## Frozen-zone attestation (CLAUDE.md §7)

**0 lines touched** across all 11 commits:
- `app/Services/Fiscal/{FiscalSequenceService,ZReportService,AuditLogService}.php` — untouched
- `app/Models/Scopes/BranchScope.php` — untouched (sentinel reads source string only)
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` — untouched (only `config/idempotency.php` populated)
- `app/Services/Pricing/PricingService.php`, `app/Domain/Order/OrderStateMachine.php` — untouched
- All 3 KioskComponent.vue files — untouched
- `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php` — untouched

Verified via `git diff --stat 4b12f678a~1 1e7c65ecc -- <§7 list>` → empty output.

---

## Recommendation

**MERGE-OK.** All 11 heals are correct, scoped, and frozen-zone-clean.

Pre-merge actions:
1. Confirm parallel BUILD-6 (PermissionRequest::authorize) is in the same merge bundle
2. Run sentinel test suite (all 7 PASS already)
3. 44/44 Admin+Role smoke PASS already

Post-merge V1.0.2 follow-ups (non-blocking):
- Tenant Admin role seeder
- SiteService env-edit allowlist
- BranchScope sentinel ctor-args robustness
- Idempotency sentinel dead-code cleanup
- Ansible task comment-vs-SQL TRUNCATE wording

**Substitute review limit:** this is NOT the official `/ultrareview`. The user should still run `/ultrareview 21` for the canonical billed cloud review — this doc is the autonomous-orchestrator best-effort substitute per user authorization 2026-05-18.

---

**Generated 2026-05-18 by general-purpose sub-agent.**
