# Agent 3 — KDS Cuisine Audit (Phase A, READ-ONLY)

GOAL Production-Readiness Le Cayenne 2026-05-18 — Round 1
System 3 (KDS), sub-systems 3.1–3.4

Branch verified: `v1-0-1-hardening-2026-05-17`

---

## 1. Anchor verification

| Anchor | Result | File / Note |
|---|---|---|
| Kitchen/KDS controllers exist | OK | `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`, `app/Http/Controllers/Admin/KdsSyncController.php` |
| `public/js/admin-kds.js` exists, 6297 lines | OK | size 493 KB, built artifact (Vue compiled bundle) |
| KDS test corpus | OK | **9 files** (task said 6 — actual is 9, listed §7) |
| `config/kds.php` | OK | exists but content is **V2 layout kill-switch only** (no station routing config) |
| KDS routes | OK | `routes/api.php:1005-1011` — `kds-order` prefix, 4 endpoints |
| `DispatchKdsTicket` listener | OK | `app/Listeners/DispatchKdsTicket.php` (21 lines, simple) |
| `routes/channels.php` `branch.{branchId}` authz | OK | P4-1 fix present (kiosk token restricted to its machine's branch) |
| Recent commits | OK | last KDS-relevant: `6c935fcd0` (KDS source bucketing E-003/E-004), `f912e5a9c` (sticky error banner C-009) |

---

## 2. Sub 3.1 — Orders Board

`KitchenDisplaySystemOrderService::list()` (lines 52–142) and `KitchenDisplaySystemController::index` (L25–39).

**Strengths**
- Whitelisted `order_column` / `order_by` against allowed list (L57–61) — SQL injection guard.
- Admin (`branch_id=0`) sees all branches; staff `branch_id>0` scoped via L83–85. Matches CLAUDE.md §9 multi-tenant invariant.
- Cap of 51 fetched / 50 returned, `overflow` meta flag exposed (L132–137 + controller L31–34). Frontend consumes via `kdsOverflowDetected` (admin-kds.js L1690).
- LIKE→= on `branch_id`/`order_type`/`source` (L110–116) — POS-9.1.5 cross-branch substring-leak fix verified.
- Advance-order overdue range query uses sargable `>=, <` instead of `whereDate` (L91–102) — RED-team P1 perf fix landed.
- `payment_status` whitelist (PAID, PENDING_COUNTER, POS-CASH only) at L72–79 — chefs never see unreleased tickets.

**P1 findings**
- **P1-3.1-A** — `list()` returns *only one* `overflow` boolean (`>50`); operator never told *by how much*. With 4 cols × 2 rows = 8 cards visible by default (`.kds-v2__grid` CSS line 112 of bundle), seeing `overflow=true` gives no priority signal. Fix sketch: expose `meta.totalEligible` count, frontend renders "8/120 affichés" badge.
- **P1-3.1-B** — `KdsSyncService::sync()` (L78–81) hard-codes a different limit (50) than `list()` (51) with no shared constant. Drift risk: an operator increasing one and not the other gets card stutter on poll. Fix sketch: extract `KDS_LIST_HARD_CAP = 50` to `KitchenReleaseRule`.

**P2 findings**
- **P2-3.1-A** — Catch-all `Exception → 422` in controller L36–38 maps DB errors to "Unprocessable" — operator sees an error toast indistinguishable from validation. Fix sketch: differentiate DB transport errors → 503.
- **P2-3.1-B** — No `created_at`/`updated_at` index audit done in this pass; the new `whereBetween('order_datetime', …)` claims `idx_orders_datetime` exists (RED-team comment). Cross-system flag for Agent 8 (DB).

---

## 3. Sub 3.2 — Item-Level Workflow

### Historic P0s status update (vs. memory 2026-05-11, 6 days stale)

| Historic Finding | Memory verdict | Current code state |
|---|---|---|
| L1290 `allergenModal` ≠ `allergensModal` typo | P0 bug | **RESOLVED**. State property is `allergensModal` (L891). `allergenModalReturnFocus` at L896/1326/1341/1346 is an *intentionally distinct* variable holding the return-focus DOM element. Not a typo — verified by code path inspection. Future audits should not re-flag. |
| Bump button 32 px (sous WCAG 44, sous kitchen 60) | P0 UX/a11y | **PARTIAL — P2**. `.kds-card__cta { height: 52px }` (CSS bundle L20). Above WCAG 2.5.5 (44 px); below ~60 px kitchen-glove standard. |
| Texte gris #6E7191 contrast 3.2:1 | P0 a11y | **APPEARS RESOLVED — UNVERIFIED-VISUAL**. Current palette: `#111827` queue, `#374151` group, `#4B5563` muted, `#6B7280` shortcut — all ≥4.5:1 on white per static hex inspection. No axe-core run in this pass; flag for visual capture. |
| 4 cols × 2 dont 2 vides | P0 UX | **UNVERIFIED-VISUAL**. CSS at L112 of bundle hard-codes `repeat(4, …)` × 2 rows; whether 2 are blank depends on runtime data. |
| Accordéon fermé / items cachés | P0 UX | **UNVERIFIED-VISUAL** — KDSOrderCard accordion state not statically inferrable from bundle. |
| 5 banners empilés | P0 UX | **UNVERIFIED-VISUAL** — sticky-danger consolidation landed C-009 round-3, but cumulative stack still possible. |
| 18 raw FR labels | P0 i18n | **APPARENTLY RESOLVED — grep-verified**. All bump/recall/banner/source/source-pill paths sampled use `$t('label.kds_*')` / `$t('button.kds_*')` / `$t('message.kds_*')`. Spot-check 30+ sites all i18n. Mark P3 only if Playwright capture surfaces a stray raw string. |

### Current P0/P1/P2 findings

- **P1-3.2-A** (recall grace) — Recall is dispatched via `kds/recallItem` Vuex action (admin-kds.js L1504) and shows "kds_recall_grace_expired" toast on rejection. The grace window enforcement is client-side based on toast UX; backend enforcement should be re-verified by Agent 7 (SRE/observability). Fix sketch: confirm a server-side `recall_grace_seconds` config exists; if not, add and gate the controller.
- **P1-3.2-B** (CTA height) — `.kds-card__cta` 52 px is below the 60 px target the historic audit set. Owner UX preference (kitchen rush, gloved hand) wants 60 px minimum. Fix sketch: change one CSS line `height: 60px`.
- **P2-3.2-A** (allergen modal focus return) — `closeAllergensModal` (L1340–1354) restores `allergenModalReturnFocus.focus()` best-effort. If DOM element was unmounted (e.g. order moved off board between open & close), focus silently lands on `<body>`. Fix sketch: fallback to `kdsCardRoot` of the order if return target is detached.
- **P2-3.2-B** (allergen badge OOS overlap) — `kds-allergens-badge` at `right: 0.5rem` z=5 and `kds-oos-warning-badge` at `right: 5.5rem` z=5 (CSS bundle L135 fragment). On a 280-px-wide narrow card both could overflow the queue-number row. Fix sketch: visual capture in §6.

---

## 4. Sub 3.3 — Station Routing

**Fresh finding (uncovered this pass, not in historic memory):**

- **P1-3.3-A — Station dispatch is frontend-filter only** — `app/Listeners/DispatchKdsTicket.php` (21 lines) only calls `OrderStatusChanged::dispatch($order, $oldStatus, $newStatus)`. `OrderStatusChanged` (`app/Events/OrderStatusChanged.php`) carries `$order, $oldStatus, $newStatus` and uses `DispatchableAfterCommit` — no `station_id`, no `station_name`, no broadcast partitioning. `config/kds.php` is a **V2 layout kill-switch only** (`v2_default_enabled`); contains zero station-routing keys. Repo-wide grep for `station_id|station_name|kdsStation|KdsStation` in `app/` and `routes/` returns **zero hits**. Multi-station "routing" in the GOAL contract is therefore a frontend display filter at best, not a real backend dispatch. For a single-resto Le Cayenne V1 (1 station = whole kitchen) this is acceptable, but the GOAL claim "multi-station routing" is currently **vapor**. Fix sketch: either (a) document in §5 of GOAL doc that V1 = single-station, defer multi-station to V1.0.2; or (b) introduce `station_id` on `OrderItem` + dispatch sub-channel `branch.{b}.station.{s}` — non-trivial backend work, not P0 for Le Cayenne single-station.
- **P1-3.3-B — Overflow flag UI gap (V1.0.1 backlog)** — meta.overflow is exposed (Sub 3.1) but the user-facing component to *act* on overflow (load-more, page-2, filter-down) is absent from the bundle (no `loadMore`/`page-next` UI element found). Operators hit cap silently — already a known V1.0.1 backlog item per `project_v1_0_1_hardening_2026-05-17`. Fix sketch: render a sticky "8 commandes supplémentaires" pill below the grid linking to a `?page=2` view (requires backend pagination beyond cap of 51).

---

## 5. Sub 3.4 — Sync (Echo + polling)

**Strengths verified**
- `routes/channels.php` `branch.{branchId}` (L24–40) — **P4-1 fix present and correct**:
  - Kiosk token (`tokenCan('kiosk:order')`) restricted to its `KioskMachine.branch_id` — prevents kiosk token cross-branch subscription (GAP-21-5 mitigation).
  - Admin (`branch_id=0`) wildcard.
  - Staff: own branch only.
- `OrderStatusChanged` uses `DispatchableAfterCommit` — broadcast deferred until commit, dropped on rollback (anti-ghost-ticket — gate C9 / KI-001).
- `KdsSyncService::sync()` cache key `kds.sync.{branchOrAll}.{YmdHi}.{md5(since|includeDeleted)}` (L42–47) with 5 s TTL — **cross-branch cache leak impossible**; per-minute bucket bounds staleness.
- `KdsSyncController` (L50–66) — admin-only `?branch_id=N` override; non-admin gets **403** on mismatch. Branch isolation enforced at controller boundary.
- `KitchenDisplaySystemOrderService::changeStatus` (L156–209) — triple defense for race:
  1. DB `lockForUpdate()` (L160)
  2. Per-row branch check (L164–167) — abort 403
  3. **Optimistic `expected_status` check (L171–184)** — returns **409** + structured `[KDS_409]` log on stale client. Covered by `KdsExpectedStatusConflictTest` (L30/52/87/115 — 3 scenarios: stale, missing, no-op).

**P1 findings**
- **P1-3.4-A — Echo failure path swallowed** — `changeStatus` wraps `kdsTicketDispatcher->dispatch` in a `try/catch` that **only logs** (L223–227). If broadcast fails (Pusher down, Soketi crashed), the chef sees a successful 202, the next chef does not get the screen update, and only the polling fallback (≥5 s) plus the per-card 5 s ticker close the loop. Acceptable degradation but observability should emit a sentinel — file an OSS metric. Fix sketch: increment a Prometheus/StatsD counter on catch.
- **P1-3.4-B — `KdsSyncService::computeOrderVersion` TODO unresolved** — L138 comment notes the planned `status_changed_at` column never landed; current version stamp is `updated_at.timestamp`. If an order's status moves but no other field touches the row (rare, but possible via direct SQL or future code path), the frontend version-gate skips it. Fix sketch: track in `plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md` (already referenced in code comment).

**P2 findings**
- **P2-3.4-A** — `KdsSyncService::sync()` (L78) caps active orders at 50 with no overflow signal in payload, while `list()` exposes `overflow` flag. After warm-cache poll, a freshly-overflowed branch silently loses cards on the delta channel. Fix sketch: add `overflow_active` boolean to sync payload symmetric with `list()`.
- **P2-3.4-B** — Local-dev `wsConnected=false` banner suppressed via `appEnv==='local'` guard (admin-kds.js L988 comment). Confirms staging may behave differently than prod; verify staging APP_ENV.

---

## 6. Visual capture specs (`/kds` surface)

**Pass note**: this is READ-ONLY; no Playwright/axe-core run. Items below MUST be captured by the next visual round.

| Capture | URL | Trigger | Assert |
|---|---|---|---|
| KDS-VIS-01 board empty | `/kds` | no orders | empty-state SVG + i18n title + sub, no raw labels |
| KDS-VIS-02 board 4 cards | `/kds` | seed 4 orders 1 station | 4 cards in 4-col row 1, row 2 placeholders or 4 more |
| KDS-VIS-03 board 8+ overflow | `/kds` | seed 51 orders | `meta.overflow=true` triggers visual cap warning banner |
| KDS-VIS-04 bump CTA tap zone | `/kds` order card | hover/measure | computed height ≥52 px (current); flag P2 if owner wants ≥60 px |
| KDS-VIS-05 allergen pill+modal | `/kds` w/ allergen order | click `kds-allergens-badge` | modal opens, close-button focused, Tab cycle (focus-trap) holds, Esc closes, focus returns to badge |
| KDS-VIS-06 OOS + allergen co-existence | `/kds` w/ allergen + 86'd item | inspect right edge | OOS badge at `right: 5.5rem` does not overlap allergen badge at `right: 0.5rem` (`min-width: 300 px` card) |
| KDS-VIS-07 stale-sync banner | `/kds` | kill ws, wait 10 s | `kds-hint-banner--danger` sticky at top z=50, sync stamp turns `--stale` orange |
| KDS-VIS-08 axe-core scan | `/kds` | axe.run on board | report critical violations only — known unknowns: color contrast on `#4B5563` body text @ 14 px, focus indicators on cards |
| KDS-VIS-09 RTL `dir="rtl"` | `/kds?lang=ar` | render | qty column right-aligned, source pill flipped, no overflow |

---

## 7. Acceptance gate — KDS test corpus (9 files, not 6)

```
tests/Feature/KdsBranchFilterExactTest.php                    (1.9 KB, 2026-04-18)
tests/Feature/KdsChangeStatusConcurrencyTest.php              (2.7 KB)
tests/Feature/KdsExpectedStatusConflictTest.php               (3.9 KB) — 409 optim-lock × 3 scenarios
tests/Feature/KdsPaginationOverflowTest.php                   (3.5 KB) — cap-51 meta.overflow
tests/Feature/KdsTransitionWhitelistTest.php                  (1.5 KB) — allow-list ACCEPT→PREPARING→PREPARED
tests/Feature/Admin/KdsSyncControllerTest.php                 (7.8 KB) — 403 cross-branch, 400 since, deltas
tests/Feature/Admin/ItemRequestBarcodeKdsStationTest.php      (1.5 KB, 2026-05-17) — adjacent station marker
tests/Feature/Kds/KdsAllergenAggregationSplitTest.php         (8.5 KB) — Lot 2.I G-5 hash-split
tests/Feature/Kds/KDSDeliveryEnrichmentTest.php              (10.7 KB) — Sprint 2A DEL-3 address+user
tests/Feature/Kds/KdsSnapshotImmutableTest.php               (12.2 KB) — NF525 composition_snapshot immutability
tests/Feature/Kds/BackfillAllergensSnapshotTest.php           (8.2 KB, 2026-05-17) — 2026_04_18 backfill round-trip
```

Gate: `php artisan test --filter='Kds'` must remain GREEN; any failure blocks merge to main.

---

## 8. Cross-system flags

- **POS → KDS Outbox**: KDS receives via `OrderStatusChanged` broadcast after POS `changeStatus` commits. Outbox-style guarantee comes from `DispatchableAfterCommit` (anti-ghost) — verify Agent 2 (POS) confirms POS state-transition writes use the same after-commit pattern.
- **Kiosk → KDS**: same `OrderStatusChanged` channel; channels.php P4-1 fix prevents kiosk token cross-branch listen. Verify Agent 1 (Kiosk) flags any kiosk path that dispatches the event *before* commit.
- **KDS → OSS sync**: OSS reads same `branch.{b}` channel; no separate KDS→OSS surface. Verify Agent 4 (OSS) that OSS subscribes correctly to branch channel and doesn't expect a `kds-ticket.*` event name.
- **DB indexes** (Sub 3.1-B): `idx_orders_datetime` referenced in service comments; Agent 8 (DBA) verify it exists.
- **Observability (Sub 3.4-A)**: KDS broadcast catch swallows exceptions; Agent 7 (SRE) verify a sync-overview metric increments on failure.
- **Frontend `kdsCustomization.js`** (referenced L153 of bundle but not opened this pass): contains sandwich/taco/burger/menu_formule customization helper — verify Agent 5 (i18n/UX) for parity with mobile app composer.

---

## Summary verdict

KDS V1 surface is **shippable for Le Cayenne single-station single-branch**. Backend invariants (branch isolation, 409 race, NF525 snapshot immutability, after-commit broadcast) are intact and test-covered. Multi-station routing is **frontend-only vapor** in current code — call it explicitly V1=single-station to avoid an unhonored GOAL claim. Bump CTA at 52 px is above WCAG (44) but below kitchen-glove ideal (60) — owner-gate cosmetic decision. Six "P0" findings from the 6-day-old memory file are **resolved or downgraded** by current code (`allergenModal` typo not a bug; bump 32→52 px; raw labels grep-clean; contrast palette upgraded). Visual round-2 mandatory to confirm 4 P0s that cannot be inferred from static bundle (accordéon, grid emptiness, banner stack, contrast under live data).

---

100-word return summary:
KDS audit complete. Backend solid: branch-scoped channel (P4-1 fix verified), 409 optimistic lock + lockForUpdate triple defense, NF525 snapshot immutable, 9 test files covering corpus. **Fresh finding**: multi-station routing is frontend-filter vapor — `DispatchKdsTicket` carries no `station_id`, `config/kds.php` is V2 kill-switch only, zero `station_*` hits in `app/`. For V1 Le Cayenne single-station: acceptable; rename GOAL claim. **6 historic P0s resolved or downgraded** (allergenModal not a typo, bump 32→52 px, labels i18n-clean, contrast upgraded). 4 P0s require visual capture round (accordéon, grid emptiness, banner stack, axe-core). Bump 52→60 px is owner-gate. Report at `reports/test-e2e/goal-2026-05-18/round-1/agent-3-kds.md`.
