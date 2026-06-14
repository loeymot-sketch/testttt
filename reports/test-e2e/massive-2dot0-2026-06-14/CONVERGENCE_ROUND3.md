# MASSIVE TEST-E2E 2.0 — CONVERGENCE REPORT (Round 3 — backlog heal wave)

**Date:** 2026-06-14 · **Tree:** `release/v1-integration-2026-06-12` (spine) · base `28df1a1a3` → heals.
**Harness:** `foodking_2dot0` @ :8780 (soketi+queue+redis UP). Op `foodking` untouched.

## Scope
Continue the P2/P3 backlog from Round-2 (13 remaining). Heal the meaningful, safe, non-gated items; defer the rest with explicit reasons (max-reasoning triage, no NO-OP fixes, no frozen/Z risk).

## HEALS (3 — all TDD red→green, frozen 0)
| # | Sev | System | Fix | Test (red→green) | Commit |
|---|---|---|---|---|---|
| #11 | P2 | KDS | committed bump survives post-commit notif failure (guard SendOrder* + `\Throwable`, like the ticket dispatcher) | `KdsUnreleasedOrderBumpGuardTest::committed_bump_survives_post_commit_notification_failure` (throwing-listener) 6/6 | `a263ea46d` |
| #8 | P2 | OSS | `_emit` isolates each listener (one thrower no longer freezes others) | `ossListenerIsolation` + OSS specs 17/17 | `8d27be2a5` |
| #13 | P3 | CENTRAL | hourly-traffic chart excludes refund mirrors (`whereNull(parent_order_id)`) | `DashboardRevenueNettingSentinel::hourly_traffic_excludes_refund_mirror` 4/4 | `5b3fb7920` |

### Reasoning notes (TDD mechanics)
- #11: the Bus mock was a **vacuous pass** first — `SendOrderMail` uses `Foundation\Events\Dispatchable` (event, not Bus job). Switched to a **throwing event listener** → real RED → fix → GREEN. (Discipline: verified the test was meaningful before trusting it.)

## GATES (fresh, Round-3)
- **Vitest: 372 files, 2512 passed | 3 skipped, 0 failed** (+ new OSS isolation spec).
- **PHPUnit: Kds 47/0 · Dashboard 34/0** (+ heal tests). Pos/Fiscal green from Round-2 (untouched here).
- **Frozen-zone diff (whole campaign): 0 lines.** **NF525 chain: OK.** **Frontend rebuilt** (`npm run production` ✓, `admin-oss.js` carries #8).

## DEFERRED — with explicit reasons (not skipped silently)
| # | Sev | Why deferred |
|---|---|---|
| #14 sales-report total_discounts net | P3 | correct fix sets refund-mirror `discount=-parent` = **NF525/Z-adjacent** (mirror discount feeds the signed Z; owner-LOCK area per ZRPT-SEM-01). Z-integrity risk > P3 display payoff. |
| #2 allergen vocab `poissons`/`poisson` | P3 | cosmetic sort only (icon already resolves); vocab change carries untested-risk for ~0 value. |
| #18 job `failed()` connection log | P3 | cosmetic; "correct" value ambiguous (hardcoding a driver could be wrong) — current best-effort is reasonable. |
| #5 cash received=null guard | P3 | agent-confirmed acceptable for V1-LOCAL (full total always booked; drawer never under-counted). |
| #17 dead broadcast lane | P3 | needs intent confirmation (EventType says server-side consumed) — removing a broadcast = behavior change → owner. |
| #6, #7, #10, new-P3 (array-of-objects) | P3 | V2 multi-tenant / cosmetic / non-occurring data shape. |
| #16 AuditLogService env() | P2 | **FROZEN** (UNI-03 cloud-prep) → owner gate. |
| #4 WCAG orange contrast | P2 | owner brand-mandate design gate. |

## VERDICT (Round-3): GREEN on healed scope — 3 real fixes (reliability + sync + numbers), all gates green, frozen 0, chain OK. Remaining backlog is fully triaged: justified-deferred or owner-gated. No NO-OP fixes, no frozen/Z risk taken.
**Open owner gates (unchanged + carried):** G-RUPTURE (P1, Round-2), G-WCAG, G-FROZEN(#16), G-PUSH, G-OVH.
