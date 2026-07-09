# FoodKing — Full Audit: Pages + System Infrastructure
**Date:** 2026-06-08 · **Branch:** `heal/cms-pr1-quickwins-2026-05-18` · **HEAD:** `ad29e7875`
**Mode:** READ-ONLY audit (no fixes applied — audit ≠ heal). Mutating browser work confined to the `:8766` disposable clone (`foodking_e2e`); operating DB (`foodking`, :8000/:8765) touched read-only only.
**Method:** 6 parallel read-only static lanes (Agent sub-agents — every prompt vision-filtered against the V1-LOCAL false-positive trap + framed confirm-known-PR-01..07 / surface-new) + 1 serial Playwright visual sweep of the 5 systems + verify-before-report consolidation.

---

## 0. VERDICT — GREEN (production-coherent), 1 YELLOW lane, no P0/P1

| Lane | Verdict | P0 | P1 | P2 | P3 |
|---|---|---|---|---|---|
| NF525 / Fiscal | 🟡 YELLOW | 0 | 0 | 1 | 1 (known) |
| Frozen-zone integrity + Pricing SSOT | 🟢 GREEN | 0 | 0 | 0 | 2 (known) |
| Auth / Multi-tenant / RBAC / Sanctum / Idempotency | 🟢 GREEN | 0 | 0 | 0 | 1 (known) |
| Sync bus / Queue / Outbox | 🟢 GREEN | 0 | 0 | 0 | 2 |
| Config / Boot guards / Build / i18n | 🟢 GREEN | 0 | 0 | 2 (known) | 0 |
| Routes / Controllers / Wiring | 🟢 GREEN | 0 | 0 | 0 | 3 |
| **Visual sweep (5 systems)** | 🟢 GREEN | 0 | 0 | 0 | minor |
| **TOTAL** | 🟢 **GREEN** | **0** | **0** | **3** | **9** |

**Headline:** No P0, no P1 anywhere. NF525 chain verifies **OK** (sequence 1–2000 gap-free, all 9 immutability triggers live, composition_snapshot DB-immutable, pricing 100 % backend). Frozen files show **no in-flight (uncommitted) drift** — working tree clean (see scope caveat §0.1). Boot guards complete + sentinel-locked. The single YELLOW is a narrow fiscal-alloc safety-net hole on the operating DB (mostly seed pollution). Almost every non-green item is CONFIRMED-KNOWN cloud-prep backlog (PR-01/05/07) — **not a V1-LOCAL blocker**.

### 0.1 — Scope caveat on the frozen-zone verdict (important, honest)
The frozen lane verified the **working tree is clean** (`git diff HEAD` empty for all 15 frozen paths) — i.e. **no active/in-flight edit this session**. It did **not** assert frozen files are *unchanged*: this branch is **1188 commits ahead of `main`**, and **13 of 15 frozen files differ substantially vs main** (e.g. `PaymentComponent.vue` +1515, `KioskWizardComponent.vue` +1903, `ZReportService.php` +686, `PricingService.php` +610). That is the project's **legitimate gated development history** on the live `heal/*` line (`main` is simply stale), **not** evidence of unauthorized drift — but re-verifying that each of those historical frozen edits carried its LOCK+owner gate is a **commit-history compliance audit out of this pass's scope**. So: GREEN = "no current drift + chain/pricing/integrity intact at HEAD"; it is **not** a clean bill on 1188 commits of gate compliance.

---

## 1. Findings worth owner attention (NEW / live-relevant)

### 1.1 — [P2 · NF525 · NEW] 43 PAID orders never fiscalised AND outside the retry net
- **Evidence (verified read-only on `foodking`):** `SELECT COUNT(*) FROM orders WHERE payment_status=5 AND fiscal_sequence_no IS NULL AND fiscal_alloc_error_at IS NULL` → **43** (newest 2026-06-02 16:49).
- **Root cause:** sequence is allocated at payment (`PaymentService.php:322`, `OrderService.php:1117`, `FrontendOrderService.php:1189`); on these rows it was never allocated **and** `fiscal_alloc_error_at` was never set. `RetryFiscalAllocCommand.php:68` only re-processes `whereNotNull('fiscal_alloc_error_at')` → these rows are **permanently invisible to the retry cron** (a hole in the alloc-failure safety net).
- **Live exposure is narrow:** source = `'10'`×40 (numeric code = **seed/test pollution**) + `POS`×2 + `mobile`×1 → only **~3 real-looking** paid orders. No signed-Z understatement: `ZReportService::aggregate` excludes `whereNull('fiscal_sequence_no')`; `warnOnOrphanedPaidOrders` logs them at next close.
- **Recommendation (owner-gated, no code shipped):** spot-check the 3 non-pollution rows; consider a one-off alloc sweep keyed on `fiscal_sequence_no IS NULL` (not the error flag); purge the 40 `source='10'` polluted rows from the operating DB. Touches fiscal pathways → owner call.

### 1.2 — [P3 · Sync · NEW, downstream of PR-01] One stranded broadcast row invisible to alerting
- **Evidence (verified):** `domain_events.id=8194` (order.status_changed, attempts=6, `dispatched_at`=NULL, created 2026-06-04 01:35).
- **Why stranded:** `OutboxRescueCommand.php:47` skips attempts<5; `OutboxRetryFailedCommand.php:104` requires a 24 h cutoff (4-day row unreachable); the staleness monitor (`MonitorOutboxStaleness.php:45`) **and** all retry lanes require `schedule:work`, which is **DORMANT by design** (PR-01).
- **Impact bounded:** polling fallback (`OssSyncService.js:9-16`, 2 s/60 s) absorbed the missed live broadcast — the order itself is fine. Visibility gap, not data loss. Resolves when PR-01 (scheduler supervision) is actioned.

### 1.3 — [P3 · Sync · NEW] `default`/`notifications` queues not covered by the live worker
- `ProcessWebhookEventJob.php:27` + `SloEvaluatorJob.php:31` (→`default`) and `SendFcmNotificationJob.php:67` (→`notifications`) aren't consumed by the running `queue:work --queue=high` worker. **0 backlog now** (`LLEN queues:default`=0). Async webhook ingest + push, not the realtime POS/KDS/OSS bus. PR-01's prescribed `--queue=high,default` covers it.

### 1.4 — [P3 · Routes · NEW] 3 routes point at missing controller methods (latent 500, UI-unreachable)
- `routes/api.php:1008` `DELETE api/admin/online-order/{order}` → `OnlineOrderController@destroy` (method absent).
- `routes/api.php:1022` `DELETE api/admin/table-order/{order}` → `TableOrderController@destroy` (method absent; dine-in dormant anyway).
- `routes/api.php:1356` `PUT|PATCH api/frontend/message/{message}` → `MessageController@update` (method absent).
- **Impact: NONE under normal use** — none has a live frontend caller (verified vuex/axios grep); each is a 500-trap only on a hand-crafted request. Low-effort cleanup (drop the route lines or add the methods).

---

### 1.5 — [Security · the one open item needing owner action] pos-wizard.js XSS LOCK pending countersign 10+ days
- **Status:** its own LOCK doc (`plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md`) rates it **P0-security**. The fix is written but **held >10 days waiting on the owner countersign** to touch the strict-no-touch frozen `public/js/pos-wizard.js`. The file is 0-diff (fix not yet applied).
- **Why it's listed here, not buried as P3:** the vision filter suppresses *accepted choices* (cloud / TPE-sim / single-tenant); an **XSS is not an accepted choice**, so it must not be silently renumbered. A *reasoned* exploitability downgrade is defensible — the wizard runs **staff-only, on the local single-box caisse, no public network surface** — but that lowers urgency, it does not erase the item. **This is the single thing in this audit that needs an owner decision** (countersign the LOCK → apply the escape fix). Does not change the GREEN verdict; changes what to act on.

---

## 2. CONFIRMED-KNOWN (already tracked — surfaced for completeness, not re-litigated)

- **PR-01** scheduler dormant: `CleanupStalePendingKioskOrders` trap **currently disarmed** (0 pending status=0 orders verified). Worker on `high` is the *correct* lane for the realtime bus. (1.2/1.3 are its downstream tails.)
- **PR-05** dead menu-image dir `public/menu/le-cayenne-v2/` (86 files, 0 refs; catalog reads `public/images/menu/`). Safe-delete, zero impact.
- **PR-07** 30 `env()` runtime calls in `app/` incl. **frozen** `AuditLogService.php:273` (`env('FISCAL_AUDIT_SECRET_BRANCH_'.$id)`) — breaks the HMAC chain only **under `config:cache`**, never run on the LOCAL box; no per-branch override configured → no V1 impact. Cloud-blocker, LOCK+gate.
- **F1 discount→TVA split (frozen P3):** dormant — V1 hard-refuses all discounts at 0 % VAT (`assertDiscretionaryDiscountAllowed`). Owner-gated, zero active V1 impact.
- **Auth P3:** guest token TTL 30 days (documented GAP-20-5), scoped `kiosk:order` only + `BlockKioskTokenFromAdminRoutes` + sentinel.
- **Routes (known):** 2 dead Vuex `save` actions POST to GET-only URIs (`onlineOrder.js:78`, `tableOrder.js:78`, never dispatched); SPA catch-all `routes/web.php:62` returns HTML 200 on unmatched **GET** `/api/*` (GET-only masquerade; POST/PUT/DELETE unaffected). Senangpay latent-500 **RESOLVED** (class+method now present).
- **UNI-03:** boot-guard cache forbidden-list = array/null only (file/database pass) — documented cloud backlog, not a V1 defect.

---

## 3. Visual sweep — 5 systems (Playwright on `:8766` clone)

| Surface | Route | Result |
|---|---|---|
| BORNE idle | `/kiosk/idle` | 🟢 Branding + FR correct ("Bienvenue !", "À emporter"). Minor: blurred grey backdrop band behind welcome card (verify vs operating; kiosk frozen → observation only). 401 `/api/login` console = kiosk machine not auth'd on clone. |
| CAISSE | `/admin/pos` | 🟢 Cash-open modal gates correctly (NF525 cash-trail). "À ENCAISSER BORNE (16)" = kiosk→counter Plan B working. "Appliquer une réduction" disabled (correct V1). Real images, FR categories. |
| KDS | `/admin/kitchen-display-system` | 🟢 Cards render with source tags, SLA timer, full composition (Tacos→Choix/Sauce/Viandes). **Degraded-mode disclosure visible** ("rafraîchissement automatique 60 s") — addresses PR-02. |
| OSS | `/admin/order-status-screen` | 🟢 Two-column board (En préparation / Prêt), coherent empty state. **KDS-vs-OSS discrepancy RESOLVED = by design:** `OrderStatusScreenOrderService::list()` (`:51-55`) filters `status IN [PREPARING, PREPARED]` **AND `whereDate(order_datetime, today)`** (comment `[P3-4 FIX] Align with KDS`). The KDS cards are stale **Jun-4** clone data (box idle since Jun 4) → correctly excluded from OSS's today-filter. Not a status-drop. |
| CENTRAL dashboard | `/admin/dashboard` | 🟢 Total articles menu **45** (SSOT ✓), CA/Commandes/Ticket tiles, **NF525 audit trail visibly HMAC-signed**, channel split, full FR sidebar. |
| CENTRAL catalogue | `/admin/items` | 🟢 45 produits / 11 catégories / 44 actifs / 1 indispo, real thumbnails, coherent prices, Actif badges. |
| Login | `/login` | 🟢 "Bon retour", correct FR labels (a11y tree ground-truth — an earlier low-res screenshot misread "Mot De Passe" casing → dropped as a false-positive). |
| Storefront `/` | redirect | Authenticated admin → `/admin/dashboard` (expected guard). |

**Coverage note (no silent cap):** Pages were audited **representatively** — 8 production surfaces across all 5 systems, not all ~38 router modules / ~117 admin-shell lazy chunks. Two deliberate skips: (1) the **backend-served customer storefront** (`frontend/*` non-kiosk) — a live surface of *this* backend, but the dashboard channel split reads **Web 0 %**, i.e. an effectively unused V1 channel, and the admin redirect blocks in-session view → low-risk skip; (2) the **standalone web (`/Downloads/web`) + mobile (`mobile/`, NO-wireup V1)** repos — separately audited (GO-CONDITIONAL 2026-06-04). Literal every-page coverage would be a follow-up pass, not a gap in this one.

**Console health:** every admin page = 0 errors + 1 warning; the warning is the documented `ws://127.0.0.1:6001` handshake fail → **polling fallback (SYNC-WS-01), by design**.

---

## 4. Infrastructure ground-truth (read-only)
- Running: redis :6379, mysql :3306, soketi :6001/:9601 **UP**, `queue:work redis --queue=high` live, php-serve :8000/:8001/:8200/:8765/:8766.
- **Port→DB map (safety-critical):** `:8000` + `:8765` → `foodking` (OPERATING, read-only); `:8766` → `foodking_e2e` (disposable clone, safe writes; confirmed via worktree `.env.e2e`).
- NF525 chain: `fiscal:verify-chain --all` → **CHAIN OK** (branch 1); Z-sequence 1–5 gap-free; order-sequence 1–2000 gap-free; 9 immutability triggers live (audit_logs, z_reports, order_payments, cash_movements, cash_drawer_sessions, order_items composition_snapshot, stock_movements).
- Frozen-zone integrity: working tree clean (0 uncommitted drift, all 15 paths); pos-wizard.js 296912 bytes (matches S25-SinglePage). **Caveat (§0.1):** 13/15 frozen files differ vs a stale `main` (1188-commit gated dev history) — not re-audited commit-by-commit.
- Auth: BranchScope 20-model baseline + documented exemptions intact; `kiosk:order` gating broad + ability-aware; idempotency dual-layer; FormRequest authz count = 66 = baseline.
- Routes: 553 routes map to controllers (3 dormant exceptions §1.4); no duplicate/shadowed routes; no unauth state-mutating endpoints.
- Build: bundles fresh (empty `resources/js|css|sass` diff since last compile `beae2d64e`; post-build commits backend-only).

---

## 5. What was NOT done (boundaries)
- **No fixes applied** — this is a read-only audit. Healing the §1 items (esp. the frozen-zone and fiscal ones) requires owner gate per CLAUDE.md §7/§10.
- No `php artisan test` / `migrate` / `config:cache` on the live box (documented footguns).
- No deep sweep of the customer storefront or the standalone web/mobile repos (separately audited).
- admin-shell management sub-pages beyond the representative set were not each rendered.
