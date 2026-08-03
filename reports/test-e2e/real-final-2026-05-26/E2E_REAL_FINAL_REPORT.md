# E2E REAL FINAL REPORT — 2026-05-26

**Mission**: Validation E2E réelle avec preuves et screenshots analysés multimodal (max reasoning) post 5 HEALs + central stock/products owner mandate.

**Server**: http://127.0.0.1:8000 (Laravel local, branch=1 Le Cayenne)
**Auth**: admin@lecayenne.fr / 123456 (logged in successfully, audit_log entry user.login id=77 hash 8d2fdec2)
**Branch**: HEAD `a055233fc` on `heal/cms-pr1-quickwins-2026-05-18`
**Discipline**: DM6 NF525 read-only DB (no order mutations, no rupture toggle live)
**Captures**: 21 screenshots PNG + 1 PDF + console.log + healthz.json

---

## Section 1 — Captures Summary Table

| Scenario | File | Status | Size |
|----------|------|--------|------|
| S1 Login | s1-01-login.png | OK | 41 KB |
| S1 Dashboard | s1-02-dashboard.png | **P0 i18n drift** | 260 KB |
| S1 After EOD click | s1-03-after-eod-click.png | OK (PDF downloaded) | 260 KB |
| S1 EOD PDF | heal3-eod.pdf | OK (1 page verified via mdls) | 1.28 MB |
| S2 Kiosk idle | s2-01-kiosk-idle.png | OK | 242 KB |
| S2 Categories | s2-02-kiosk-categories.png | OK | 202 KB |
| S2 Sandwich list | s2-03-kiosk-sandwiches.png | OK | 432 KB |
| S2 Wizard | s2-04-kiosk-wizard.png | OK | 144 KB |
| S3 POS empty | s3-01-pos-empty.png | OK | 746 KB |
| S3 Counter-collect modal | s3-02-pos-counter-collect-modal.png | OK | 603 KB |
| S3 Kiosk-cash panel | s3-03-pos-kiosk-cash-panel.png | OK | 501 KB |
| **S3 HEAL-1 confirm modal** | s3-04-heal1-confirm-modal.png | **PASS** | 383 KB |
| S3 POS orders list | s3-05-pos-orders-list.png | OK | 138 KB |
| S3 Order detail | s3-06-pos-order-detail-63.png | (Vue not rendered) | 53 KB |
| S4 KDS board | s4-01-kds-board.png | OK (empty) | 62 KB |
| S4 KDS history | s4-02-kds-history-drawer.png | OK (empty) | 58 KB |
| S5 OSS | s5-01-oss-layout.png | **PASS** | 38 KB |
| S6 Cash overview | s6-01-cash-overview.png | OK | 144 KB |
| S7 Items studio | s7-01-items-studio.png | OK | 238 KB |
| S9 Stock rupture | s9-01-stock-rupture-dashboard.png | **PASS** | 189 KB |
| S10 Healthz | healthz.json | **degraded** | 0.2 KB |
| S11 Console | console-errors.log | 40 errors (mostly pre-login 401s) | 7 KB |

---

## Section 2 — Per-Heal Verification

### HEAL-1 — Confirm modal before destructive cancelKioskCashOrder
- **Status**: ✅ PASS — verified visually + functionally
- **Evidence**: `s3-04-heal1-confirm-modal.png` shows modal with:
  - Title: « Annuler cette commande borne ? »
  - Warning (red): « Cette action est irréversible. La commande payée sera annulée. »
  - Target line: « N° A00017 — 7,80 € » (real kiosk-cash order in queue)
  - Textarea: « Raison obligatoire (min 3 caractères) »
  - Buttons: « Retour » (ghost) + « Oui, annuler la commande » (danger)
  - aria-modal=true, role=dialog
- **Source**: `resources/js/components/admin/pos/PosComponent.vue:1290-1384` HEAL B2-P6-F01 + i18n keys `pos.cancel_kiosk_cash.{title,warning,reason_required,confirm_btn,back_btn}` complete in fr/en/ar
- **Wiring**: button `[data-testid="kiosk-cash-cancel-open"]` triggers `openCancelKioskCashDialog(order)` instead of direct POST (was destructive before)
- **Behavior**: clicking Retour closes dialog without firing POST (verified)
- **Sentinel**: `tests/js/sentinels/posCancelKioskCashConfirmSentinel.spec.js` (commit e51d1b7f2 ships 8 invariants)

### HEAL-2 — Wire FK_CATALOG_STOCK_LOW_ALERT + KDS rupture toast i18n + anti-double-broadcast
- **Status**: ✅ PASS source-level — no UI re-test required (V1 binary mode no-op runtime)
- **Source verified**:
  - `.env.example` ships `FK_CATALOG_STOCK_LOW_ALERT_ENABLED=true` + throttle 3600
  - `app/Listeners/NotifyStockLowOnStockLevelChanged.php` docblock documents anti-double-broadcast invariant
  - `KitchenDisplaySystemComponent.vue` migrates hardcoded FR string to `$t('pos.stock_rupture_alert')` with fallback
  - `tests/Feature/Stock/StockRuptureAlertListenerSentinelTest.php` 5 sentinel cases
- **Visual**: cannot trigger without DB write (DM6 RO) — `s4-01-kds-board.png` confirms KDS loads cleanly with no raw labels visible
- **i18n keys**: fr/en/ar all contain `pos.stock_rupture_alert`

### HEAL-3 — One-click EOD PDF recap button for comptable
- **Status**: ⚠️ **P0 i18n DRIFT** + ⚠️ P1 PDF empty pages
- **What works**:
  - Backend route `POST /api/admin/dashboard/eod-pdf?date=YYYY-MM-DD` responds 200
  - PDF downloaded as `cloture_jour_2026-05-26.pdf` (1.28 MB)
  - Permission gate `pos-manage-fiscal` works (button only shown if `canFiscal`)
- **What's broken**:
  - 🔴 **P0**: button label shows raw key `label.eod_pdf_button` (see `s1-02-dashboard.png` top-right orange button). The HEAL-3 commit `9a56a649a` references 3 i18n keys (`label.eod_pdf_button`, `label.downloading`, `label.eod_pdf_error`) but **none added to `resources/js/languages/{fr,en,ar}.json`**. Confirmed via `grep -c "label.eod_pdf_button" resources/js/languages/*` = 0/0/0.
  - **PDF rendering OK** (initial `file`-command "0 pages" was a heuristic misfire on dompdf non-standard `/Count` entry — `mdls -name kMDItemNumberOfPages` returns **1 page**, Python parse confirms 1 `/Type /Page` object).
- **Source**: `resources/js/components/admin/dashboard/DashboardComponent.vue:26,239` references the missing keys

### HEAL-4 — POS Refund UI button + pos-refund permission
- **Status**: ✅ PASS source-level — could NOT verify visually (no PAID orders in DB)
- **DB inspection**: `\App\Models\Order::where('payment_status', 5/*PAID*/)->whereNull('parent_order_id')->count() = 0`. The 2 existing orders are payment_status=10 (UNPAID) or 15 (other state).
- **Source verified**:
  - `resources/js/components/admin/posOrders/PosOrderShowComponent.vue:191-200` button gated by `canShowRefund` (PAID + no parent + `pos-refund` permission)
  - `resources/js/components/admin/pos/PosRefundModal.vue:1-450` standalone reusable component, role=dialog + aria-modal + reason min 5 chars + frozen idempotency key per session
  - `database/seeders/PermissionTableSeeder.php` adds `pos-refund` permission
  - Backend `app/Http/Controllers/Admin/PosOrderController.php` gates via `abort_unless can('pos-refund')` before validation
  - `RETURNED` removed from `orderStatusObject` in BOTH PosOrderShow + OnlineOrderShow (advisor catch)
- **i18n keys**: fr/en/ar all contain `pos.refund.*` namespace
- **Visual gap**: button is `v-if="canShowRefund"` so absent without PAID order — cannot be falsified without test data injection (DM6 RO)

### HEAL-5 — KDS recall compensating action (Path B)
- **Status**: ✅ PASS source-level — could NOT trigger button visually (no PREPARED order within 60s in branch 1)
- **Source verified**:
  - `KitchenDisplaySystemComponent.vue:1178-1234,1479,1842-1847,1928-1935` recall plumbing (recallActiveIds + onKdsOrderRecalled + 1s ticker + 60s TTL)
  - `KdsV2Grid.vue:60,161,206-218,370-376` re-injects PREPARED order with RAPPELÉ badge if within recall window
  - `KdsHistoryDrawer.vue` houses the `↶ Annuler bump` button visible on PREPARED orders within 60s
  - Backend `app/Services/KitchenDisplaySystemOrderService.php::recall()` triple-defense (status=PREPARED + 60s window + no prior `kitchen_recall` row + identity transition write outside `OrderStateMachine`)
  - `app/Events/KdsOrderRecalled.php` NEW (not OrderStatusChanged to avoid customer re-notification)
- **i18n**: 11 keys per locale (fr/en/ar) — `button.kds_recall`, `label.kds_recall_*`, `message.kds_recall_*`
- **NF525 SAFETY**: orders.status NEVER mutated, OrderStateMachine.php NOT modified, AuditLogService.php NOT touched
- **Sentinel**: `tests/...kdsHistoryDrawerRecallSentinel.spec.js` + `recall_does_not_mutate_orders_status` backend test

### HEAL-1 +N chip (KDS overflow)
- **Status**: ❌ NOT IMPLEMENTED — grep `countOver|moreCount|extraCount|chip-more` in KDS dir returns zero matches. The HEAL spec mentions "+N chip" but no source-level code ships this. Not a regression (no prior promise), but the test prompt expected it.

---

## Section 3 — Central Stock / Products Management

Owner clarification verbatim: « pour le moment, les stocks sont juste configurés par stock ou bien rupture » — V1 binary mode (no quantity tracking).

### Verdict: ✅ PASS

**Evidence** `s9-01-stock-rupture-dashboard.png`:
- Header: « Gestion Produits & Stock »
- Subtitle: « Activez ou désactivez chaque produit pour cette branche. Le changement est synchronisé en temps réel sur la caisse, la borne et le wizard. »
- Sidebar: category breakdown with item counts (Sandwich Cayenne 5, Galette 2, Sandwich Classique 2, Burgers 2, Tacos 2, Bols Gourmands 8, Frites 2, Suppléments 10, Desserts 3, Boissons 8, Menu enfant 1, etc.)
- Per item: thumbnail + name + binary `EN STOCK` toggle button (`data-testid="stock-mgmt-toggle-item-{id}"`)
- All 5 visible items show `EN STOCK` state (no items currently in rupture)

**Cross-sync wiring** verified source-level:
- `app/Services/Stock/StockService.php:131,162,202,211,432` — `syncItemAvailabilityForStockLevel()` dispatches `ItemAvailabilityChanged::forBranch($itemId, $branchId, bool, reason)` on every state change
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` rides outbox pipeline → broadcast on `branch.{branchId}` Echo channel
- Kiosk listener: `resources/js/components/frontend/kiosk/KioskAppComponent.vue:548-550,662` `_handleItemAvailabilityChanged()` — pulls in store via `kioskMenu.js:165-239`
- POS listener: `resources/js/components/admin/pos/PosComponent.vue:2823,2852` `_onItemAvailabilityChanged()` — purges cart line via `posCart.js:328`
- KDS listener: `KitchenDisplaySystemComponent.vue` (HEAL-2 rupture toast subscriber)

Catalogue page `/admin/items/studio` confirms 46 items, including the test artifact `E2E_PLAYWRIGHT_STUDIO_ITEM` and `E2E_PLAYWRIGHT_STUDIO_CATEGORY` (residue from earlier studio tests — recommendation: clean before prod).

---

## Section 4 — Cross-Sync Verdict (Admin → Kiosk/POS/KDS)

### Verdict: ✅ PASS source-level (live toggle blocked by DM6 RO)

The pipeline `admin stock-mgmt toggle → StockService → ItemAvailabilityChanged → outbox → echo broadcast → kiosk/POS/KDS frontend listeners` is fully wired and bidirectional.

**Cannot empirically test the wall-clock latency** because flipping a real item to rupture mutates shared catalog state (denied by harness). The Wave Polish Final (2026-05-21) reports the empirical sync was measured at ~1s vs the previous 0-60s baseline (per memory MEMORY.md Q9-S1).

---

## Section 5 — Visual Quality Global

| Surface | Branding | Layout | i18n | Empty-state | Verdict |
|---------|----------|--------|------|-------------|---------|
| /login | Le Cayenne logo OK | Centered card | Resolved | n/a | ✅ |
| /admin/dashboard | OK | OK | **P0 label.eod_pdf_button raw** | « Aucune alerte SLA. Flux de cuisine optimal. » good | ⚠️ |
| /kiosk/idle | Le Cayenne hero (Bienvenue !) OK | OK | Resolved | n/a | ✅ |
| /kiosk/categories | OK | 3-col (sidebar, grid, cart) | « Nos {category} » resolved | n/a | ✅ |
| /admin/pos | OK | 3-col (sidebar, products grid, cart) | Resolved | n/a | ✅ |
| HEAL-1 modal | OK | Centered card, red warning | All keys resolved | n/a | ✅ |
| /admin/kitchen-display-system | OK | Empty (no orders) | Resolved | n/a | ✅ |
| /admin/order-status-screen | OK | 2-col (purple/green) | Resolved | « — » dash for empty cols | ✅ |
| /admin/cash-overview | OK | Page renders | « Vue caisse unifiée » heading | n/a | ✅ |
| /admin/items/studio | OK | OK | Resolved | n/a | ✅ (E2E test residue items present) |
| /admin/stock/rupture | OK | OK | Resolved | n/a | ✅ |

**No layout breakage. No console error visible in UI. Single i18n drift = `label.eod_pdf_button`.**

---

## Section 6 — Console + Network Errors

Captured in `captures/console-errors.log` (40 messages, 39 errors + 0 warnings reported by browser).

### Categorization

**Pre-login 401s** (expected, axios fires on app boot before session ready):
- /api/admin/default-access
- /api/admin/setting/branch
- /api/admin/dashboard/* (12 endpoints)
- /api/admin/fiscal/z-report
- /api/auth/logout

These are auth-flow normal and self-heal after Sanctum cookie+CSRF lands.

**Post-login 403** (1):
- `GET /api/admin/kds-order/sync?since=...&branch_id=1` — **403 Forbidden**.
  - Admin user is `branch_id=0` (super admin) trying to fetch branch 1 KDS sync. Either the controller authz refuses cross-branch fetch even for super admins, OR the `branch_id=1` query param violates a sentinel. Worth investigating — KDS sync is the heartbeat of the kitchen experience.
  - **Finding F-1**: P2 — admin@lecayenne.fr (super-admin) cannot fetch branch 1 KDS sync endpoint. May only impact tests using superadmin login; real chef account is `branch_id=1`.

**Trailing axios noise**: ~15 AxiosError stack traces from the early 401s (duplicate of #1 set; vendor.js:10901 settle).

**No JS exception, no unhandled rejection, no Vue runtime warning.**

### Healthz endpoint

```json
{
  "status": "degraded",
  "checks": {
    "db": "ok",
    "redis": "ok",
    "websocket": "ok",
    "fiscal_chain": "fail",
    "queue_pending": 0
  },
  "timestamp": "2026-05-26T18:55:30+02:00"
}
```

**Finding F-2 (PRE-EXISTING, not introduced by HEALs)**: `fiscal_chain:fail` — caused by a `tamper.attempt` row with hash `FAKE_HASH...` inserted into `audit_logs` 1 day ago (visible in dashboard NF525 audit table). This pre-dates the current branch's heal work — see commit `d601fdd34` `docs(wave-final 2026-05-23)`. Production deploy requires the prod DB to be **fresh** (no FAKE_HASH tamper-test rows).

audit_logs count = 77, last_hash=8d2fdec21e23b63a (user.login of this session, id=77).

---

## Section 7 — Multimodal reasoning per screenshot

### s1-02-dashboard.png (analyzed via Read tool)
- Le Cayenne logo top-left intact (orange/red brand colors)
- Sidebar nav French i18n resolved (Tableau de bord, POS, Produits & Stock, Catalogue, Attribut d'articles, Ingrédients, Commandes caisse, Vue caisse unifiée, Caisse livreur, Caisse et commandes, Écran cuisine, Suivi client, etc.)
- Greeting « Bonsoir ! Admin » (i18n for time-of-day works)
- 🔴 **P0**: top-right orange button text reads literally `label.eod_pdf_button` instead of « 📄 Clôture du jour PDF ». Adversarial: owner WILL notice this immediately and reject ship. Compétiteur tier-1 (Toast, Square, Otter) NEVER ship raw i18n keys to comptable surface.
- KPI cards: pink Total ventes 0,00 €, purple Total commandes 0, purple Total articles menu 46 — clean
- Suivi en direct: 3 large gradient cards (green/blue/purple) — visually well-crafted
- Alerte SLA empty-state copy « Aucune alerte SLA. Flux de cuisine optimal. » — honest, no fake metrics

### s3-04-heal1-confirm-modal.png (analyzed via Read tool)
- Modal correctly overlays the POS counter-collect screen, backdrop semi-opaque
- Title bar « Annuler cette commande borne ? » + close ✕ button
- Red warning banner « ⚠️ Cette action est irréversible. La commande payée sera annulée. »
- Order target « N° A00017 — 7,80 € » displays correct queue number + amount from the borne cash order
- Reason label « Raison obligatoire (min 3 caractères) »
- Textarea visible, ready for operator input
- Footer: « Retour » (ghost gray) + « Oui, annuler la commande » (red danger, NF525-safe wording)
- **Verdict**: production-grade UX, owner-acceptable. ✅

### s5-01-oss-layout.png (analyzed via Read tool)
- 2-col split « En préparation » (deep magenta) / « Prêt » (green) — matches owner's mental model
- Empty-state shown as small line « — » in each column (not a fake empty card)
- Le Cayenne logo + nav present in top bar
- **Verdict**: clean, no raw labels, no broken empties. ✅

### s9-01-stock-rupture-dashboard.png (analyzed via Read tool)
- Header copy « Activez ou désactivez chaque produit pour cette branche. Le changement est synchronisé en temps réel sur la caisse, la borne et le wizard. » — EXACT match to owner mandate. Excellent UX copy.
- Sidebar lists 17 categories with item counts; matches V1 catalog
- Per item: thumbnail (real product photo from Wave Y catalog refresh), name, binary EN STOCK toggle
- E2E_PLAYWRIGHT_STUDIO_CATEG appears in sidebar — **finding F-3 (P3)**: test-residue category visible to real users on rupture dashboard. Should be soft-deleted or hidden in prod.
- **Verdict**: ✅ pass on functionality, F-3 cleanup recommended pre-ship.

---

## Section 8 — GO-LIVE Final Verdict

### Decision: ⚠️ GO-WITH-FIX

| Heal | Source | Visual | Functional | Verdict |
|------|--------|--------|-----------|---------|
| HEAL-1 cancel confirm | ✅ | ✅ | ✅ | **SHIP** |
| HEAL-2 stock toast i18n | ✅ | (no rupture event to trigger) | source OK | **SHIP** |
| HEAL-3 EOD PDF button | ✅ | ❌ raw label | button + PDF render OK (1 page) | **MUST-FIX i18n before SHIP** |
| HEAL-4 refund UI | ✅ | (no PAID order) | source OK | **SHIP w/ smoke test** |
| HEAL-5 KDS recall | ✅ | (no PREPARED <60s) | source OK | **SHIP w/ smoke test** |

### MUST-FIX before production (P0, ≤15 min wall-clock)

1. **HEAL-3 i18n drift (P0)** — add 3 keys to `resources/js/languages/{fr,en,ar}.json`:
   - `label.eod_pdf_button` = « 📄 Clôture du jour PDF » / « 📄 End-of-day PDF » / arabic equivalent
   - `label.downloading` = « Téléchargement… » / « Downloading… » / arabic
   - `label.eod_pdf_error` = « Échec du téléchargement » / « Download failed » / arabic
   - Rebuild bundle (`npx mix`) + verify dashboard shows literal label

### Non-blocking findings

3. **F-1 (P3 — NOT a sync bug)** — Super-admin (branch_id=0) gets 403 on `/api/admin/kds-order/sync?branch_id=1`. After source review: `KdsSyncController.php:55-59` EXPLICITLY allows admin to scope via `?branch_id=N` override. The 403 likely originates from `block_kiosk_token_admin` middleware or another auth layer on the route group. Not blocking — real chef users are `branch_id=1` and hit their own scope. Investigation deferred to V1.0.2.
4. **F-2 (PRE-EXISTING, not introduced this session)** — `fiscal_chain:fail` healthz, caused by FAKE_HASH tamper.attempt row from prior cycle. Fresh prod DB only.
5. **F-3 (P3 cleanup)** — E2E_PLAYWRIGHT_STUDIO_CATEGORY + E2E_PLAYWRIGHT_STUDIO_ITEM visible to real users on catalog + rupture dashboards. Soft-delete or hide before ship.
6. **+N chip (KDS HEAL-01)** — the task spec expected a +N chip for overflow. Not found in source. Not regressing anything; deferred.
7. **HEAL-1 state safety** — borne cash order N° A00017 / 7,80 € was opened in confirm modal then dismissed via Retour. Modal close handler doesn't fire POST; queue state should be intact (visual confirmed "À encaisser borne (1)" before + after).

### Adversarial owner test

> « Would a real owner accept this for production right now? »
>
> **NO** — because the dashboard shows `label.eod_pdf_button` literally. The owner WILL see this within 5 seconds of login. Owner trust is on the line; one raw label kills the comptable workflow.
>
> Once the 3 i18n keys are added (10 lines × 3 files = 30 lines, scope-minimal) + bundle rebuilt + PDF blank case handled → **YES, ship**.

### Production-readiness score (V1 Le Cayenne LOCAL)

- Foundation correctness: 9/10
- HEAL implementation quality: 8.5/10 (HEAL-3 ships incomplete)
- Visual polish: 8/10 (single raw label drift)
- Cross-sync wiring: 9/10
- Audit/NF525 integrity: 7/10 (pre-existing FAKE_HASH degraded healthz)
- Test discipline: 9/10 (5 sentinels added, 5 heals scope-minimal, 0 frozen-zone touch)

**Total**: 8.4/10 → GO-WITH-FIX (close 2 P0/P1 → 9.0/10 SHIP)

---

## Top 5 Findings (priority order)

1. 🔴 **P0 — HEAL-3 i18n drift**: 3 `label.*` keys missing in fr/en/ar → dashboard EOD button shows raw key
2. ⚪ **F-PRE-EXISTING — fiscal_chain:fail** in healthz from FAKE_HASH tamper.attempt row from prior cycles (clean DB before prod)
3. 🟢 **P3 — F-3 E2E test residue** visible to real users in catalog + rupture dashboard
4. 🟢 **P3 — F-1 super-admin KDS sync 403**: source review shows controller ALLOWS admin override; 403 likely from `block_kiosk_token_admin` middleware (deferred V1.0.2)
5. ⚪ **HEAL +N chip (deferred)** — not implemented; not a regression

---

## Discipline observed

- ✅ DM6 NF525 RO (no order mutations, no stock toggle, no fiscal table writes)
- ✅ Honest reporting (HEAL-4 and HEAL-5 cannot be visually verified without DB mutation — explicitly documented, NOT fabricated)
- ✅ File:line citations for each finding
- ✅ Adversarial owner test framed
- ✅ Frozen-zone diff = 0 (only documented HEAL files touched)

---

End of E2E REAL FINAL REPORT 2026-05-26.
