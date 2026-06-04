# INTERSECTION POS×OSS — SYNTHESIS STATUS

- **Branch**: `heal/cms-pr1-quickwins-2026-05-18`
- **HEAD**: `13982f8ccd8600dd7a52480a4da1d1a59794ac31`
- **Auditor**: master sub-agent B (parallel with POS×Stock, POS×Fiscal, POS×Loyalty)
- **Date**: 2026-05-18
- **Round**: 1
- **Specialists**: 4 (Architect, Security, SRE/Sync, RED-team) — all READ-ONLY
- **DIRTY files (read-only honored)**: `app/Services/OrderService.php`, `app/Services/OrderStatusScreenOrderService.php`, `public/js/admin-oss.js`
- **FROZEN files (read-only honored)**: `app/Models/Scopes/BranchScope.php`, `app/Http/Middleware/IdempotencyKeyMiddleware.php`

---

## VERDICT — CONVERGED, NO HEAL REQUIRED

The POS→OSS contract end-to-end (`POS marks PREPARED` → `OrderStatusChanged` event → `PersistOrderStatusChangedToOutbox` → `DispatchDomainEventsJob` → `private-branch.{id}` channel → OSS Echo handler → `_markNewReady()` chime+flash → `OssSyncService` polling refresh) is intact. The 7 Wave-3b/3c heals already absorbed every finding this audit could legitimately raise:

1. TZ-aware Paris→UTC bounds (heal `KDS-ADV3B-01` mirrored to both `list()` and `listForBranch()`).
2. UTC stale-prune window (heal `KDS-ADV3C-04`).
3. Cadence ceiling clamp 60s (heal `KDS-ADV3C-08`).
4. Cadence floor clamp 250ms (heal `KDS-ADV3C-08`).
5. Fail-closed source-surface allowlist `whereIn(KIOSK, TAKEAWAY)` (heal `RED R-3`).
6. Channel-auth wildcard-token defense (heal `F-SEC-W6-01`).
7. Public-wall chime structural skip + visual-only fallback (heal `P0-OSS-01`).

Two adversarial probes (RED-OSS-02 chime burst, RED-OSS-07 branch enumeration) surface BY-DESIGN behaviors with explicit documentation; neither is a V1 blocker.

---

## 4-LIST

### DEAD-CODE (none in this intersection)

Nothing detected. Both query bodies (`list()` and `listForBranch()`) are actively consumed:
- `list()` consumed by `OrderStatusScreenController::index` (admin)
- `listForBranch()` consumed by `OrderStatusScreenController::publicIndex` (customer wall)
- Both Vue paths (`PreparingAndReadyComponent.vue` → `orderStatusScreenOrder/lists` → axios branch on `authStatus`) actively switch between `admin/oss-order` and `frontend/oss-order`.

### SAFE-TO-CONSOLIDATE (1 optional, V1.0.2)

- `OrderStatusScreenOrderService.php::list()` (lines 45..120) and `::listForBranch()` (lines 185..238) are functionally byte-identical query bodies kept duplicated **on purpose** per the docstring line 174..181 (admin-auth resolver vs callsite-supplied branch). A future refactor could extract a private `buildBaseQuery(int $branchId): Builder` and have both public methods append branch + auth gating, reducing the surface to ~20 lines instead of ~50. **Risk**: any non-byte-identical change between the two would re-introduce drift exactly like Wave 3b/3c had to heal twice. **Recommendation**: defer to V1.0.2 only if accompanied by a dedicated symmetry test (`assertEquals(list()->toSql, listForBranch()->toSql)`).

### KEEP-AS-IS (verified working — do not touch)

1. `app/Services/OrderStatusScreenOrderService.php` lines 53..62 + 193..199 — fail-closed `whereIn(KIOSK, TAKEAWAY)` allowlist (Wave 3c heal, covered by `OssCustomerScreenFilterTest.php` 8 tests).
2. `app/Services/OrderStatusScreenOrderService.php` lines 77..81 + 208..211 — Paris→UTC day bounds (Wave 3b heal, covered by `SisterServicesTzAwareTest.php`).
3. `app/Services/OrderStatusScreenOrderService.php` lines 108 + 228 — UTC stale-prune `now('UTC')->subHours(8)` (Wave 3c heal).
4. `app/Listeners/PersistOrderStatusChangedToOutbox.php` lines 26..32 — idempotency key including `correlation_id` (anti-collapse across legitimate re-transitions).
5. `app/Listeners/PersistOrderStatusChangedToOutbox.php` lines 64..66 — `wasRecentlyCreated` skip on listener replay (matches `PersistOrderCreatedToOutbox` / `PersistCatalogChangedToOutbox` parity).
6. `app/Listeners/PersistOrderStatusChangedToOutbox.php` lines 73..85 — `try/catch` best-effort broadcast (gate `test-e2e-fix-E-001-round-3`).
7. `routes/channels.php` lines 43..70 — token-NAME-based kiosk discriminator (immune to Sanctum `*` wildcard, closes wildcard-token escalation).
8. `app/Providers/RouteServiceProvider.php` lines 145..152 — `oss-public` 60/min/IP rate limit (anti branch_id enumeration).
9. `app/Http/Resources/CDSOrderDetailsResource.php` lines 17..24 — minimal public payload (id, order_serial_no, token, queue_number, order_type, status — no PII).
10. `resources/js/services/OssSyncService.js` lines 34..35 + 122..134 + 430..435 — `_clampCadence` clamps `[250..60_000]ms`.
11. `resources/js/services/OssSyncService.js` lines 162..189 — WS state edge-detector firing `_burstPoll('ws_reconnected')` on previous!=connected→connected.
12. `resources/js/services/OssSyncService.js` lines 214..252 — visibility-resume burst-poll with 1s throttle.
13. `public/js/admin-oss.js` lines 152..158 + 244..251 + 273..278 + 296..355 — wake-lock acquire on mount, re-acquire on visibilitychange, release on unmount, plus native sentinel `release` event tracker.
14. `public/js/admin-oss.js` lines 219..243 + 469..477 — chime structural-skip on public wall (`authBranchId() <= 0`); visual-flash remains.
15. `public/js/admin-oss.js` lines 420..423 + 523..530 — Echo×poll dual-path dedupe via `_echoMarkedReady` one-shot Set.

### RECOMMENDATIONS (V1.0.2 backlog — non-blocking)

1. **RED-OSS-07 branch enumeration hardening** — log distinct `branch_id` values per IP per 5-min window on the public OSS endpoint, threshold >10 = signal. Already mentioned by `RouteServiceProvider.php:142..144` ("Logging of >10 distinct branch_id values from the same IP within 5 min is deferred to V1.0.2"). Not a V1 blocker for single-tenant Le Cayenne.
2. **SAFE-TO-CONSOLIDATE refactor** — see §SAFE-TO-CONSOLIDATE above. Optional with paired symmetry test.
3. **Echo subscription liveness probe** — `subscribeEcho` at `public/js/admin-oss.js:411..431` could log a heartbeat ack on successful `pusher:subscription_succeeded` to differentiate "subscribed but channel idle" vs "silent broadcast outage" in dev. Cosmetic improvement, not user-facing.

---

## SPECIALIST REPORTS

- `reports/audit/intersection-pos-oss-2026-05-18/round-1/PO-1-cascade/architect.json` — cascade integrity 5 findings ALL INFO
- `reports/audit/intersection-pos-oss-2026-05-18/round-1/PO-2-security/security.json` — 8 checks ALL PASS
- `reports/audit/intersection-pos-oss-2026-05-18/round-1/PO-3-sync-red/sre-sync.json` — 10 checks ALL PASS
- `reports/audit/intersection-pos-oss-2026-05-18/round-1/PO-3-sync-red/red.json` — 10 adversarial scenarios: 7 BLOCKED, 2 BY-DESIGN, 1 PARTIAL (V1.0.2 backlog)

---

## HEAL COMMITS

**None.** Audit converged with zero P0/P1 findings — Wave 3b/3c sister-service heals absorbed every defect class this intersection could surface. The DIRTY anchors (`OrderService.php`, `OrderStatusScreenOrderService.php`, `admin-oss.js`) were respected as READ-ONLY per mandate; the clean files (`OssSyncService.js`, `orderStatusScreenOrder.js`, `OrderStatusScreenComponent.vue`, `PreparingAndReadyComponent.vue`) had no actionable defects to heal.

---

## MANDATES COMPLIANCE

| Mandate | Status |
|---|---|
| READ-ONLY on DIRTY (OrderService, OrderStatusScreenOrderService, admin-oss.js) | RESPECTED (no edits) |
| READ-ONLY on FROZEN (IdempotencyKeyMiddleware, BranchScope) | RESPECTED |
| Read-cited file:line for every finding | DONE (every entry references file+line) |
| Adversarial RED dispute | DONE (10 scenarios) |
| 4-list output (DEAD/CONSOLIDATE/KEEP/RECOMMEND) | DONE |
| KEEP what works (owner manual test attested OK) | DONE (15 items in KEEP-AS-IS) |
| HEAL-ALLOWED on clean files | NOT NEEDED (no defects in clean files) |
| Idempotency client-side intact (commits aa7b6021e + 1eebd208c) | ATTESTED non-regression — this audit made zero edits to DIRTY/FROZEN, including no edits along the POS-client idempotency-propagation path. Both commits verified present in `git log` (`aa7b6021e` wires `X-Idempotency-Key` on 7 Vue store callsites; `1eebd208c` patches 3 Kiosk callsites + shared helper). Server-side outbox idempotency (distinct dedupe layer) is covered separately by SEC-OSS-08 + ARCH-OSS-02. |

---

## SIGN-OFF

Intersection POS×OSS audit Round 1 = **CONVERGED**. Customer wall display is V1-shippable with all sister-service heals intact. Three V1.0.2 backlog items recommended but none block V1.
