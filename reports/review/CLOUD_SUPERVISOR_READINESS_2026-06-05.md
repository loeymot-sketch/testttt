# Cloud-Supervisor Readiness Confirmation

**Date:** 2026-06-05
**Author:** Cloud Claude Code (supervisor + executor)
**Scope:** Confirm which systems are functional after tests/audits, as part of the cloud-as-supervisor
migration; reconcile stale reports with merged code; state the honest gap to a production GO.
**Verdict:** **CONDITIONAL GO** — headless code/test base is green and verified live; production GO remains
gated on three documented items (see §4). This report does **not** claim full production-ready.

---

## 1. Why this report exists (reconciliation)

The previous `reports/review/latest.md` (2026-03-31) returned **`NEEDS_ANTIGRAVITY`** and predates a large
body of merged work. `git log` and `tasks/phase9-sync/CROSS_TRACK_STATUS.md` show the codebase advanced well
beyond that verdict:

| Track | Item | State (per CROSS_TRACK_STATUS + git) |
|---|---|---|
| Kiosk A | P9.1 stop-the-bleed | **merged** to main (`0fd3aceac`, 14/14 verified) |
| Kiosk A | P9.5 order pipeline hardening | **merged** to main (8/8 verified, BROADCAST published) |
| POS B | POS-9.1 stop-the-bleed | **merged** (`bee6333cb`, 14/14) |
| POS B | Phase H hardening (fiscal) | **merged** (`3914ae059`, Gate H PASS, 81 Fiscal + 17 Vitest green, CI invariants 6/6) |
| POS B | POS-9.4.BL (3 blockers) | **verified/merged** (93/93 Feature, 3 BLOCKERs closed) |
| Kiosk A | P9.2, P9.4 | verified, **awaiting human merge** |
| POS B | **POS-9.2, POS-9.3** | **pending** (state machine canonicalisation + multi-tender) |

So the 2026-03-31 verdict is **stale**, not authoritative. The live re-run below is the current evidence.

## 2. Live verification (run 2026-06-05 in the cloud container)

Network policy **allows** dependency installs (contrary to the initial assumption).

| Step | Command | Result |
|---|---|---|
| PHP deps | `composer install --no-interaction --prefer-dist` | ✅ installed (44,774 classes). Note: post-autoload `artisan package:discover` requires `.env` first — see §5. |
| JS deps | `npm install` | ✅ installed |
| JS suite | `npm test` (Vitest) | ✅ **407 passed / 53 files** |
| PHP smoke | `php artisan test tests/Feature/OrderFlowTest.php` | ✅ 3 passed (auth 401, server-side price recalc, illegal transition rejected) |
| PHP batches | `scripts/run_php_feature_batches.sh all` | ✅ **196 passed across 43 files** (auth-security + kiosk-pos-sync + admin-seeders-reports), pipeline exit 0 |

Environment notes: container PHP is **8.4.19** (composer requires `^8.1`); Laravel 9 + Collision emit
*nullable-parameter deprecation notices* under 8.4 — **non-fatal**, tests pass. PHP tests run on SQLite
`:memory:` with the baked `APP_KEY` from `phpunit.xml`.

## 3. Audit posture

The standing audits (`AUDIT_KIOSK_GLOBAL_2026-04-18`, `AUDIT_POS_GLOBAL_2026-04-18`) were closed through the
per-wave `VERIFY_*` sub-agent reports and the Gate H PASS. Fiscal NF525 surface (Z/X reports, HMAC-chained
`audit_logs`, fiscal sequence) is implemented and test-covered (Phase H). No open CRITICAL from those audits
remains unaddressed per `CROSS_TRACK_STATUS.md`.

## 4. Gate to production GO (honest, per CLAUDE.md §11)

Headless correctness is strong, but **production-ready is not yet true**. GO is gated on:

1. **POS-9.2 / POS-9.3 `pending`** — state-machine canonicalisation + multi-tender/canonical events not yet implemented/merged.
2. **Browser/device E2E never run** — the Anti-Gravity flows (card validated → KDS; card refused/timeout → no ghost ticket; cash → drawer; loyalty/coupon edge cases; maintenance-mode no auto-login) require Playwright MCP + a configured kiosk/device runtime (`kiosk_auto=no` blocks an autonomous tunnel).
3. **Monolithic `php artisan test` is memory-bound** — use the batch pipeline as the reference CI path until the runner ceiling is resolved.

## 5. Operational finding for the SessionStart hook

`composer install` runs `post-autoload-dump → artisan package:discover`, which boots the app and hits the
`production` guard in `app/Providers/AppServiceProvider.php` (`BROADCAST_DRIVER must be explicitly set`) when
no `.env` exists. **The cloud SessionStart hook must create `.env` from `.env.example` BEFORE `composer install`**
(the inverse of the first draft). Sequence: copy `.env` → `composer install` → `npm install` → `key:generate`.

## 6. Closing checklist to GO

- [ ] Merge Kiosk P9.2 + P9.4 (verified, awaiting human gate).
- [ ] Implement + verify POS-9.2, POS-9.3 (cloud cycle).
- [ ] Run Playwright MCP critical flows on a configured kiosk runtime → `reports/antigravity/latest.md`.
- [ ] Commit the cloud SessionStart hook + `.claude/settings.json` (blocked pending user permission — see migration summary).
- [ ] Re-run `scripts/run_php_feature_batches.sh all` + `npm test` green on the merge head.
