# Agent 4 — OSS Order Status Screen — Phase A Audit (2026-05-18)

**Branch** `v1-0-1-hardening-2026-05-17` · **Mode** READ-ONLY · **Plan ref** `plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md §6`

---

## 1. Anchor verification

| Anchor | Path | Status |
|---|---|---|
| OSS controller | `app/Http/Controllers/Admin/OrderStatusScreenController.php` (137 lines) | OK |
| OSS service | `app/Services/OrderStatusScreenOrderService.php` (218 lines) | OK |
| Compiled JS | `public/js/admin-oss.js` (1432 lines, 100 KB, regen 2026-05-18 00:29) | OK |
| Vue root | `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue` | OK |
| Vue cols | `…/PreparingAndReadyComponent.vue` (392 lines, wakeLock + Echo) | OK |
| Vue popular | `…/PopularItemComponent.vue` (63 lines) | OK |
| Sync service | `resources/js/services/OssSyncService.js` (428 lines) | OK |
| Vuex module | `resources/js/store/modules/orderStatusScreenOrder.js` (auth-state branching) | OK |
| Router | `resources/js/router/modules/orderStatusScreenRoutes.js` (`permissionUrl: "order-status-screen"`) | OK |
| Admin route | `routes/api.php:1032-1035` (`auth:sanctum + permission:order-status-screen`) | OK |
| Public route | `routes/api.php:1104-1109` (frontend group, `throttle:oss-public`) | OK |
| Rate limiter | `app/Providers/RouteServiceProvider.php:145-152` (60/min/IP, hard 429) | OK |
| Config | `config/oss.php` (`stale_window_hours` via `OSS_STALE_WINDOW_HOURS`, default 8) | OK |
| Event | `app/Events/OrderStatusChanged.php` (uses `DispatchableAfterCommit`, KI-001) | OK |
| Listener | `app/Listeners/PersistOrderStatusChangedToOutbox.php` (outbox → `private-branch.{id}`) | OK |
| Resource list | `app/Http/Resources/CDSOrderDetailsResource.php` (id / serial / token / queue / type / status — no PII) | OK |
| Resource popular | `app/Http/Resources/CDSPopularItemResource.php` (id / name / price / thumb) | OK |
| Permission seed | `database/seeders/PermissionTableSeeder.php:205`, `…VersionTwo.php:30,127,147`, `RolePermissionTableSeeder.php:41,104,119` | OK |

---

## 2. Sub 4.1 — Display findings

**T-4.1.1 columns + deterministic order**
- Two columns rendered by `PreparingAndReadyComponent.vue` (PRÉPARATION / PRÊT), `<transition-group>` `oss-slide` + `oss-pop`. Empty state `—` cohérent.
- Deterministic FIFO `orderBy('queue_number','asc')->orderBy('id','asc')` present in BOTH `list()` (line 85) and `listForBranch()` (line 180) — heal Z4-P1-02 (Sprint 5C 2026-05-16) appears intact.
- **P3 [OSS-A-01]** — `queue_number` is varchar in seeders ("A0001", "100"). `orderBy` does lexical sort. Single-prefix branch is fine, but if a branch ever mixes "A0009" / "A0010" / "100", ordering will not be numeric. Out of scope V1 (single Le Cayenne). Document.

**T-4.1.2 popular items widget**
- `mostPopularItems(?int $branchId = null)` branch-scoped via `withCount(['orders' => fn($q) => $q->where('branch_id', $branchId)])` — heal Z4-P2-04 (Sprint H5-B) intact (`OrderStatusScreenOrderService.php:122-124`).
- `null` branch preserves legacy global behaviour for `branch_id=0` Admin. Sentinel: `OssPolishClusterTest::test_z4_p2_04_*`.

**T-4.1.3 public vs admin IDOR**
- Admin endpoint `index()` uses `resolveBranchScope()` — non-Admin with `branch_id=0` requested → `abort(403)`; mismatched `branch_id` vs `user->branch_id` → `abort(403)`. Sentinel: `OssAdminBranchPolicySentinelTest::test_branch_staff_cannot_request_global_oss_scope_*`.
- Public `publicIndex()` bypasses auth resolver (no session). Branch resolution = `?branch_id=N` query param OR first `Status::ACTIVE` branch. PII surface = empty (only id/serial/token/queue/type/status — verified `CDSOrderDetailsResource`).
- **P2 [OSS-A-02]** — `publicIndex` accepts ANY positive integer `branch_id` and returns rows without verifying the branch exists or is ACTIVE. `Order::where('branch_id', N)` returns empty for unknown N (not 404), but it is a probe oracle (response time differs between known-empty branch vs nonexistent branch via possible eager-load count). Mitigated by `throttle:oss-public` 60/min/IP. Documented in RouteServiceProvider:142-144 as "deferred to V1.0.2".

**T-4.1.4 polish cluster B**
- Z4-P2-03 stale prune (`order_datetime >= now()->subHours(stale_window_hours)`) — present line 73 + 172. Sentinel: `OssPolishClusterTest::test_z4_p2_03_stale_prune_*` (×2 tests).
- Z4-P2-04 branch-scoped popularity — see T-4.1.2.
- Z4-P2-05 rate limiter `oss-public` — present in route + RouteServiceProvider. **No 429 test exists** `(test TO BE CREATED at tests/Feature/OSS/OssPublicRateLimitTest.php)`.
- Z4-P2-06 / NEW-Z4-01 — commit `3c21644dd` covers Z4-P2-05 (rate limit) and Z4-P2-06 (publicMostPopularItems branch param wired). NEW-Z4-01 = service `listForBranch()` byte-identical contract with `list()`. **P2 [OSS-A-03]** comment line 145 says "MUST stay byte-identical" with no enforcement (no shared private builder, no equivalence test). Next heal will silently drift.

---

## 3. Sub 4.2 — Real-time + notifications findings

**T-4.2.1 Echo subscription**
- `subscribeEcho()` at `PreparingAndReadyComponent.vue:230-263` listens on `private-branch.{branchId}` for `OrderStatusChanged` + `OrderCreated` (via `eventContract.onEvents`). On PREPARED → registers `_echoMarkedReady` set then calls `_markNewReady(oid)` + `list()`. De-dup guard with `_hydrateFromRows` prevents double chime/flash (AUDIT-P1).
- **P2 [OSS-B-01]** — Public customer wall (`authBranchId() <= 0` → `subscribeEcho` returns early line 233) has NO Echo subscription. Real-time updates rely solely on the 2 s polling fallback (`OssSyncService.intervalMsWhenDisconnected = 2_000`). §6 contract reads "ticker real-time" — flag as a documented constraint, not a defect, but acceptance T-4.2.1 budget of <2 s lag is at risk on the public path under load.

**T-4.2.2 ticker rotation + sound (call number)**
- "Ticker" = the `oss-pop` transition + 4 s `oss-ready-flash` highlight + 3-tone chime. `_playReadySound()` builds an AudioContext oscillator chain (lazy via user gesture).
- **P0 [OSS-B-02]** — `_audioInitListener` wired with `{ once: true }` on `pointerdown` / `keydown` (lines 115-116). **A real public TV wall never fires either gesture**, so `_audioCtx` stays null forever and `_playReadySound()` returns silently on line 302. The chime documented in §6 (Sub 4.2 acceptance) is dead on the customer-wall use case. Iter15-mega-fix C-034 (round 7, 2026-05-10) traded an autoplay warning flood for total silence on the only screen that needs the chime. Heal candidate: detect `/admin/order-status-screen` opened without admin session AND `autoplay` policy permits → pre-warm AudioContext at mount with HTML5 `<audio>` decoded silence trick, or surface visible "Tap to enable sound" CTA. Owner-gate needed (UX vs. console-noise tradeoff).

**T-4.2.3 reconnect strategy**
- `OssSyncService` covers all 4 vectors:
  - WS state listener (`connected`/`disconnected`/`reconnect_storm`/`state_change`) switches cadence between `intervalMsWhenConnected` (60 s) and `intervalMsWhenDisconnected` (2 s).
  - 5xx → exponential backoff `5 → 10 → 20 → 30 s` cap (`OssSyncService.js:312-321`). Sentinel: `ossSyncFallback.spec.js::test_5xx_backoff_doubling_*`.
  - `visibilitychange` → burst poll on tab refocus (throttled 1 s). Sentinel: same file `visibility_burst_*`.
  - Dev-only console warn after 10 s sustained disconnect (suppressed in production).
- Echo bound via `eventContract.onEvents` which handles re-subscription on reconnect (out of scope here; verified by cross-ref to KDS audit).

---

## 4. Sub 4.3 — TV walls + a11y + i18n findings

**T-4.3.1 wakeLock**
- `_acquireWakeLock()` at `PreparingAndReadyComponent.vue:171-181` — calls `navigator.wakeLock.request('screen')`, idempotent sentinel, graceful no-op on Safari iOS <16.4 (API absent), gated by `window.foodkingConfig.ossWakeLockEnabled` (default true). Re-acquires on `visibilitychange` → `visible` (browsers auto-release on tab switch / OS lock). Released on `beforeUnmount`. Sentinel: `tests/js/ossWakeLockOnMount.spec.js` (5 specs).

**T-4.3.2 a11y**
- `role="main"` + `aria-label="$t('label.oss_main_aria')"` on root (`OrderStatusScreenComponent.vue:13`). `role="region"` + `aria-label="$t('label.preparing|ready')"` on each column (`PreparingAndReadyComponent.vue:16,36`). `role="region"` + `aria-label="$t('label.oss_popular_region_aria')"` on popular widget (`PopularItemComponent.vue:6`).
- Font sizes huge (`text-[40px]`, `text-[22px]` / `text-lg`). PREPARING column text `text-[#1F1F39]` on white = AAA. PREPARED text `text-[#2AC769]` (green) on white = ~2.6:1 = **WCAG AA FAIL**.
- **P1 [OSS-C-01]** — Green-on-white queue numbers in PRÊT column miss WCAG AA 4.5:1 contrast threshold. Target SAA acceptance (Sub 4.3 ≥95 Lighthouse) would FAIL. Heal: shift to `text-[#0E7C3A]` or use white-on-green (mirror header bar treatment).
- **P2 [OSS-C-02]** — `PopularItemComponent.vue:15` `<img alt="">` empty. Lighthouse a11y flag. Replace with `:alt="item.name"`.
- **P3 [OSS-C-03]** — `text-[40px]` baseline is fine for TV viewing distance; no per-viewport rescaling. Document V1 fix: TV is always 1080p+, scale not needed.
- **Lighthouse a11y ≥95 acceptance** — `(test TO BE CREATED at .github/workflows/lighthouse-ci.yml + tests/lighthouse/oss-public.json)` — no CI gate today.

**T-4.3.3 i18n**
- Keys verified in FR/EN/AR/DE/BN: `label.preparing` (5/5), `label.ready` (5/5), `label.popular_menu_items` (5/5), `label.oss_main_aria` (3/5 — present FR/EN/AR, **missing DE/BN**), `label.oss_popular_region_aria` (3/5 — present FR/EN/AR, **missing DE/BN**).
- **P2 [OSS-C-04]** — `oss_main_aria` + `oss_popular_region_aria` absent from `de.json` and `bn.json`. Vue $t() falls back to key string → raw "label.oss_main_aria" announced to screen reader on those locales. Out of V1 NORTH STAR (single Le Cayenne FR-only) but flagged for V1.0.2 SaaS.
- AR direction: no explicit `dir="rtl"` wrapper observed in OSS Vue templates. Likely inherited from app root; verify with capture in Phase B.

---

## 5. Visual capture specs (Phase B)

Run captures during Phase B at port 8000 :

1. `/admin/order-status-screen` **authenticated as admin** — viewport `1920×1080` (TV wall canonical), seeded orders mix `PREPARING`/`PREPARED`/`token`/`queue_number`. Verify columns + popular widget + headers.
2. `/admin/order-status-screen` **fresh anonymous context** (no session cookie) — same viewport. Triggers store `authStatus=false` branch → hits `/api/frontend/oss-order*`. Verify columns populate (regression for iter15-mega-fix C-016).
3. `/admin/order-status-screen?branch_id=2` anonymous, viewport `1920×1080` — Verify branch routing (Z4-P2-04 popularity + Z4-P2-06 wired). Asserts no leak from default first-active branch.
4. `/admin/order-status-screen` **3 langues** (FR, EN, AR) — viewport `1920×1080`. Verify no raw label `label.X` rendered, AR direction visually correct.
5. `/admin/order-status-screen` **stale-prune** — seed 12 h-old PREPARED order with `OSS_STALE_WINDOW_HOURS=8`, verify dropped (Z4-P2-03 visual proof).
6. `/admin/order-status-screen` **flash + chime** trigger — transition test order PREPARING → PREPARED while screen mounted. Capture `.oss-ready-flash` highlight frame + DevTools console (validate OSS-B-02 chime silence finding).
7. `/admin/order-status-screen` **4K** `3840×2160` (existing fleet has 4K walls per `tests/e2e/__screenshots__/oss/oss-oss-public-3840x2160.png`) — verify layout intact at native 4K.

---

## 6. Acceptance gate inventory

| Test ref | Status | Coverage |
|---|---|---|
| `tests/Feature/Sentinels/OssAdminBranchPolicySentinelTest.php::test_branch_staff_cannot_request_global_oss_scope_but_global_admin_can` | EXISTS | IDOR cross-branch (T-4.1.3) |
| `tests/Feature/OSS/OssPolishClusterTest.php::test_z4_p2_03_stale_prune_excludes_*` | EXISTS (×2) | Stale prune (T-4.1.4) |
| `tests/Feature/OSS/OssPolishClusterTest.php::test_z4_p2_04_most_popular_items_scoped_by_branch` | EXISTS | Branch popularity (T-4.1.2) |
| `tests/Feature/OSS/OssPolishClusterTest.php::test_z4_p2_04_most_popular_items_null_branch_returns_global` | EXISTS | Global fallback |
| `tests/Feature/OSSReadOnlyTest.php::test_oss_system_is_strictly_read_only` | EXISTS | OSS read-only enforcement (405 on POST) |
| `tests/Feature/Branch/OssAdminBranchPolicyTest.php` | EXISTS | Branch policy adjacent |
| `tests/js/orderStatusScreenOssSync.spec.js` | EXISTS | Component wires OssSyncService |
| `tests/js/ossWakeLockOnMount.spec.js` | EXISTS (×5) | wakeLock API + flag + visibility (T-4.3.1) |
| `tests/js/ossSyncFallback.spec.js` | EXISTS (×3+) | 60s/2s cadence + 5xx backoff (T-4.2.3) |
| Z4-P2-05 rate-limit 429 | `(test TO BE CREATED at tests/Feature/OSS/OssPublicRateLimitTest.php)` | Limit `oss-public` 60/min/IP returns 429 with `retry_after:60` |
| Deterministic FIFO ordering | `(test TO BE CREATED at tests/Feature/OSS/OssListOrderingTest.php)` | Assert `orderBy('queue_number','asc')->orderBy('id','asc')` between successive `list()` calls |
| Service equivalence | `(test TO BE CREATED at tests/Feature/OSS/OssListListForBranchEquivalenceTest.php)` | Reflection-based parity between `list()` and `listForBranch()` query bodies |
| POS pay → OSS visible <2 s | `(test TO BE CREATED at tests/e2e/oss-status-latency.spec.js)` | T-4.2.1 acceptance budget |
| Chime audible on TV wall | `(test TO BE CREATED — manual gate)` | T-4.2.2 acceptance — sees OSS-B-02 |
| Lighthouse a11y ≥95 | `(test TO BE CREATED at .github/workflows/lighthouse-ci.yml)` | Sub 4.3 acceptance, depends on OSS-C-01 + OSS-C-02 heals |
| i18n raw-label scan | `(test TO BE CREATED — Phase B Playwright walk capture)` | DE/BN aria keys (OSS-C-04) |

---

## 7. Cross-system flags

- **OSS receives status from KDS via Outbox** — `app/Listeners/DispatchKdsTicket.php:17` dispatches `OrderStatusChanged::dispatch($order, $oldStatus, $newStatus)`. This event flows into `PersistOrderStatusChangedToOutbox` which writes to `domain_events` + dispatches `DispatchDomainEventsJob` → Pusher channel `private-branch.{branch_id}` → OSS Echo subscriber. **Same outbox row** feeds POS tracker, OSS wall, kiosk waiting screen — no separate event. Cross-surface lag budget gated by queue worker latency.
- **OSS receives kiosk + POS payments via outbox** — when POS marks an order PAID + KDS bumps to PREPARED → single `OrderStatusChanged` row hits the wall. Coordinates with Agent-3 (KDS) and Agent-5 (Stock + Sync). Verify outbox lag = SSOT for the §Sub 4.2 <2 s acceptance.
- **Public wall has no Echo + relies on 2 s polling** (OSS-B-01) — under fleet of N walls behind one NAT, 60/min/IP limiter (Z4-P2-05) accommodates ~5 walls polling every 5–10 s. Beyond → 429 cascading degradation. Coordinate with Agent-5 fleet sizing if SaaS V1.0.2 considers wall count >5 per location.
- **Stale window 8 h** — coordinates with KDS "ALL overdue advance orders" filter (mirror at `OrderStatusScreenOrderService.php:62-64`). Should match KDS behaviour. Cross-validate with Agent-3.
- **`OssPolishClusterTest` requires `seedSpatieRoles` + `seedMinimalSettings`** trait helpers — H6 trait coverage (V1.0.1 cycle) confirmed present.

---

## Verdict (Phase A)

| Severity | Count | IDs |
|---|---|---|
| P0 | 1 | OSS-B-02 (chime silent on public wall) |
| P1 | 1 | OSS-C-01 (WCAG AA contrast PRÊT green) |
| P2 | 5 | OSS-A-02, OSS-A-03, OSS-B-01, OSS-C-02, OSS-C-04 |
| P3 | 2 | OSS-A-01, OSS-C-03 |

**Existing defense-in-depth solid** (sentinel + service tests + JS specs + rate limit + wakeLock + outbox dedupe). Phase B should heal OSS-B-02 (owner gate UX call) + OSS-C-01 (color contrast) + create the 6 missing tests listed in §6.

— end Agent 4 OSS report —
