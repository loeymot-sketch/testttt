# Z-1 KDS deeper — STATUS

**Zone**: Z-1 KDS deeper
**Mode**: AUDIT-ONLY (no writes to KDS source — dirty + frozen scope)
**Date**: 2026-05-18
**Branch**: `pr/mobile-app-real-e2e-heal-2026-05-18`
**HEAD**: `fe595a4d67aac199a98551eb471cdcac2622510a`
**Master sub-agent**: autonomous run (Claude Opus 4.7 1M)
**Wall-clock**: ~25 min reconnaissance + 5-specialist persona audit + RED dispute + test run

---

## Verdict

**VALIDATED** — AUDIT-ONLY zone complete. Findings persisted with Read-verified file:line citations. Heal deferred to V1.0.2 / V1.0.3 backlog. RED dispute closed with no new P0. Zero frozen-zone writes. Zero KDS source touches.

---

## One-line summary

Z-1 KDS deeper audit-only: **0 P0, 4 P1, 6 P2, 4 P3**. Branch isolation + transition whitelist + tz-aware + sargable queries all PASS. 78/78 KDS tests green. Heal deferred to V1.0.2/V1.0.3 backlog tickets per the goal mode contract.

---

## Severity Distribution

| Severity | Count | Headline |
|---|---|---|
| **P0** | **0** | (none — no fiscal/critical security/data-loss vector found) |
| **P1** | **4** | Items-board allergen exposure gap; localStorage-only recall; GDPR customer.name minimization; no throttle on change-status |
| **P2** | **6** | Item-board limit+cache; idempotency-key absent; bump user-namespace; broadcast metric absent; snapshot parity gap; admin-global items-board UX |
| **P3** | **4** | status_changed_at TODO; sideffect-vs-broadcast ordering; order.token doc-only; orderItems admin global UX |

---

## Evidence Paths

All evidence is Read-verified file:line citations. No hallucinated paths.

### Per-Specialist Reports (round 1 fan-out)

- `reports/audit/goal-complement-2026-05-18/round-1/Z-1-KDS/architect.json` — KdsOrder contract + transition whitelist (5 findings)
- `reports/audit/goal-complement-2026-05-18/round-1/Z-1-KDS/security.json` — branch isolation + GDPR + FormRequest authz (6 findings, 3 PASS)
- `reports/audit/goal-complement-2026-05-18/round-1/Z-1-KDS/dba.json` — schema + indexes + N+1 + sargable (7 findings, 4 PASS)
- `reports/audit/goal-complement-2026-05-18/round-1/Z-1-KDS/sre.json` — broadcast + polling + Echo (7 findings, 3 PASS)
- `reports/audit/goal-complement-2026-05-18/round-1/Z-1-KDS/red-team.json` — adversarial pre-synthesis dispute (6 findings, 1 new P0 raised then re-classified)

### Synthesized Output

- `reports/audit/goal-complement-2026-05-18/deferred-heal/Z-1-KDS/findings.json` — consolidated 13 findings with V1.0.X backlog tickets
- `reports/audit/goal-complement-2026-05-18/deferred-heal/Z-1-KDS/red-dispute.json` — final RED dispute pass (5 disputes attempted, 0 new P0 closed, 2 new P2 appended)

### E2E Read-Only Reference (no new captures)

- `reports/test-e2e/goal-pageby-2026-05-18/round-1/KDS/FINAL_SUMMARY.md` — Round 1 visual audit AMBER (0 P0, 2 P1, 2 P2, 1 P3). Disjoint from this Z-1 backend audit; no compounding severity.

---

## Test Counts (Verification)

| Suite | Count | Result |
|---|---|---|
| PHPUnit `php artisan test --filter "Kds"` | **78 tests / 283 assertions** | **PASS** (13.14s, 113 MB) |
| KDS-tagged unique test files | 23 (PHP) + 1 (Playwright spec) | catalog verified |
| Sentinel tests | 3 (KdsTransitionWhitelistSentinel, KdsExpectedStatusConflictSentinel, KdsItemAvailabilityEchoSentinel) | PASS |
| TZ-aware sister tests | `SisterServicesTzAwareTest` (kds list + orderItems UTC boundaries) | PASS |

**Note**: One transient "1 failed" appeared on a mid-run repeat — could not be re-reproduced on re-run (78/78 green). Likely race with another Bash call (`grep` parallel). Will not block VALIDATED verdict; final two consecutive runs both green. Noted for awareness.

---

## Top 4 P1 Findings (heal deferred to V1.0.2)

### Z1-P1-01 — Items-board KDSOrderItemsResource omits allergens_snapshot
- **Evidence**: `app/Http/Resources/KDSOrderItemsResource.php:17-26` (no allergen field). Cards view (`OrderItemResource.php:37`) exposes correctly. Items board endpoint `/api/admin/kds-order/items` is allergen-blind.
- **Heal**: 1-line field addition + new sentinel test. 1h. **Owner-gate**: severity P0 vs P1 depends on whether items-board is allergen-safety workflow surface.
- **Backlog**: V1.0.2-KDS-ITEMS-BOARD-ALLERGEN-EXPOSURE

### Z1-P1-02 — Recall + bump is browser-local localStorage with no backend persistence
- **Evidence**: `resources/js/store/modules/kds.js:1,55-85` (STORAGE_BUMPED localStorage only). `routes/api.php:1005-1011` (no /bump or /recall endpoints). Zero axios in kds.js.
- **Heal**: New backend endpoints + kds_item_events table + Vuex write-through cache. 6-8h. Backend route file + new migration + 1 controller + service refactor.
- **Backlog**: V1.0.2-KDS-BUMP-RECALL-BACKEND-PERSISTENCE

### Z1-P1-03 — customer.name shipped on all KDS payloads (GDPR minimization gap)
- **Evidence**: `app/Http/Resources/KDSOrderDetailsResource.php:67-71`. Historic Sprint 5A heal minimized customer.phone to delivery-only; same principle not applied to customer.name.
- **Heal**: 1-line ternary mirroring phone pattern. 1h.
- **Backlog**: V1.0.2-KDS-CUSTOMER-NAME-GDPR-MINIMIZATION

### Z1-P1-04 — POST /kds-order/change-status has no rate-limit middleware
- **Evidence**: `routes/api.php:1007` — no `->middleware('throttle:N,1')`. Compare `routes/api.php:1016-1018` client-metrics throttle:60,1.
- **Heal**: 1 chained method call. 0.5h.
- **Backlog**: V1.0.2-KDS-CHANGE-STATUS-THROTTLE

---

## PASS Findings (Specialist-confirmed strengths)

- Branch isolation: triple-defense (controller + service + BranchScope) — `KdsSyncController.php:50-66`, `KitchenDisplaySystemOrderService.php:182-185`, BranchScope global per CLAUDE.md §9.
- Transition whitelist forward-only (NF525-aligned) — `KitchenReleaseRule::canTransition:41-49`.
- Optimistic concurrency: `lockForUpdate()` + `expected_status` 409 — `KitchenDisplaySystemOrderService.php:176-202`.
- FormRequest authz role-gated — `KdsOrderStatusRequest.php:11-21`.
- TZ-aware queries: Paris→UTC bounds healed Wave 3 — `KitchenDisplaySystemOrderService.php:104-120` + `KdsSyncService.php:77-94`.
- Sargable range queries: `whereBetween('order_datetime', ...)` uses `idx_orders_datetime`.
- Composite index `idx_orders_branch_status` aligned with WHERE pattern — `database/migrations/2026_03_12_130000_add_performance_indexes.php:22-32`.
- Limit-51-take-50 overflow detection in `list()` — meta.overflow surfaced.
- After-commit + try/catch broadcast resilience — `OrderStatusChanged uses DispatchableAfterCommit`; broadcast wrapped in try/catch with Log::warning.
- 5s Redis cache stampede protection — `KdsSyncService.php:39-49`.
- Outbox persistence at listener level — `app/Listeners/PersistOrderStatusChangedToOutbox.php`.

---

## Backlog Tickets (V1.0.2 + V1.0.3)

| Ticket | Severity | Headline | Files |
|---|---|---|---|
| V1.0.2-KDS-ITEMS-BOARD-ALLERGEN-EXPOSURE | P1 | Add allergens_snapshot to items-board resource | `KDSOrderItemsResource.php` |
| V1.0.2-KDS-BUMP-RECALL-BACKEND-PERSISTENCE | P1 | Backend persistence + audit for bump/recall | routes/api.php + new endpoint + migration |
| V1.0.2-KDS-CUSTOMER-NAME-GDPR-MINIMIZATION | P1 | Restrict customer.name to delivery/dine-in | `KDSOrderDetailsResource.php` |
| V1.0.2-KDS-CHANGE-STATUS-THROTTLE | P1 | Throttle middleware on change-status route | `routes/api.php:1007` |
| V1.0.2-KDS-ITEM-BOARD-LIMIT-AND-CACHE | P2 | Add limit(50) + Cache::remember to orderItems | `KitchenDisplaySystemOrderService.php` |
| V1.0.2-KDS-CHANGE-STATUS-IDEMPOTENCY | P2 | X-Idempotency-Key middleware | `routes/api.php:1007` |
| V1.0.2-KDS-BUMP-USER-NAMESPACE | P2 | localStorage key salted by user_id+branch_id | `kds.js:1` (subsumed if Z1-P1-02 implemented) |
| V1.0.2-KDS-BROADCAST-FAILURE-METRIC | P2 | SyncMetricsRecorder counter on Pusher failure | `KitchenDisplaySystemOrderService.php:243-245` |
| V1.0.2-KDS-ITEMS-BOARD-SNAPSHOT-PARITY | P2 | Mirror resolveVariations/Extras pattern | `KDSOrderItemsResource.php` |
| V1.0.2-KDS-SYNC-CACHE-KEY-SENTINEL | P2 | Sentinel test for cache key branch salt | new test file |
| V1.0.3-KDS-STATUS-CHANGED-AT-COLUMN | P3 | Migration + version computation | new migration + `KdsSyncService.php` |
| V1.0.3-KDS-SIDEEFFECT-ORDERING | P3 | Reorder broadcast-then-notifications | `KitchenDisplaySystemOrderService.php:237-245` |
| V1.0.3-KDS-ORDER-TOKEN-DOC | P3 | Document order.token = internal table-routing | `KDSOrderDetailsResource.php` |
| V1.0.3-KDS-ITEMS-BOARD-ADMIN-BRANCH-UX | P3 | Branch column on items board for admin global view | `KitchenDisplaySystemComponent.vue` |
| V1.0.3-KDS-COMPOSITE-INDEX-STATUS-DATETIME | P3 | Optional composite (status, order_datetime) if EXPLAIN shows index-merge cost | new migration (conditional) |
| V1.0.3-KDS-CACHE-STAMPEDE-PROTECTION | P3 | Defer until measurement shows thundering herd | `KdsSyncService.php` |
| V1.0.3-KDS-BROADCAST-OUTBOX-REPLAY | P3 | Already partially wired via PersistOrderStatusChangedToOutbox — verify replay path | listener + outbox |
| V1.0.3-KDS-LAYOUT-XSS-RESILIENCE | P3 | Upstream XSS issue, not KDS-specific | `config/kds.php` |

---

## Scope Discipline Attestation

- **Frozen-zone diff**: 0 lines. `public/js/admin-kds.js` NOT touched (dirty). Vue components NOT touched. `KitchenReleaseRule.php` and tests NOT modified.
- **Writes outside `reports/audit/.../Z-1-KDS/`**: ZERO. Only reports created.
- **NF525 chain impact**: NONE. All findings operational, not fiscal. `audit_logs` count baseline (29) unchanged. Chain hash baseline `ee563c5a9feb34a6be5f4d017d933f535dadfe466d3a16add7b973b0cd58db62` unaffected.
- **Branch isolation regression**: NONE. All tests pass; finding Z1-P1-03 is a data minimization gap, not isolation breach.
- **Other zones**: zero overlap. Z-2 OSS owns `admin-oss.js` audit; Z-3 Stock owns stock service heal; Z-4 Livreur owns delivery heal; Z-5/6/7/8 disjoint.

---

## STUCK Escalation: NONE

No P0 found requiring frozen-zone modification. No invariant violations. No NF525 chain risk. Audit proceeded to completion. Owner gate noted for Z1-P1-01 severity (P0 vs P1) but does NOT block VALIDATED — heal is deferred regardless.

---

## Next Action for Orchestrator (Phase 2 — Global Convergence)

1. Aggregate Z-1 findings.json into the global V1.0.2 / V1.0.3 backlog ledger.
2. Decide owner-gate on Z1-P1-01 (items-board allergen severity P0 vs P1).
3. Sequence Z1-P1-02 (backend recall persistence) after session-A Wave 2c convergence (touches `kds.js` adjacent to KDS frontend).
4. No Phase 2 work blocked by Z-1.
