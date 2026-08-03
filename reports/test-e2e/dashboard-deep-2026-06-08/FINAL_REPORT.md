# GOAL Dashboard Deep — FINAL REPORT
**Date:** 2026-06-08
**GOAL:** `plans/GOAL_DASHBOARD_E2E_DEEP_2026-06-08.md` · **Inventory:** `plans/PAGE_INVENTORY_DASHBOARD_2026-06-08.md`
**Mode:** Audit/capture/propose (D-1 default) — **no fixes applied**. POS-wizard/KDS/OSS = render-smoke (D-2 default).
**Method:** 7 clusters × (parallel static code-map + serial Playwright visual on `:8766` clone) + verify-before-report. Per-wave detail in `W2..W7_FINDINGS.md` + `W2..W7_static_map.md`. **87 screenshots co-located in this folder** (`w2..w7-*.jpeg`).

### ⚠️ §0.0 BRANCH PROVENANCE (read this — two branches in play)
- **Visual passes ran against `:8766` = the `heal/pre-cloud-exec-2026-06-05` worktree (`0024f1235`)** — i.e. the **LIVE/DEPLOYED code** (same tree the OVH box + :8765 operating serve). This is what's actually running.
- **Static `file:line` citations were read in the main checkout `heal/cms-pr1-quickwins-2026-05-18` (`ad29e7875`)** — the user's working branch, which is **149 commits BEHIND** pre-cloud-exec (444 files differ).
- **Reconciliation (spot-checked):** the load-bearing finding files are **identical across both trees** — `ItemVariationController.php` (P1-A), `BranchShowComponent.vue` (P1-B), `ReceiptDuplicataMarker.vue`, `BackendMenuComponent.vue`. `TransactionListComponent.vue` differs *elsewhere* but the cited render lines (`:103/:110`) + `TransactionResource.php:24-25` are **identical on the deployed tree** → the transactions P2 holds at the same citations on both.
- **Caveat:** for any finding on a file NOT spot-checked above, confirm the exact line on the branch you intend to fix (the two trees diverge by 149 commits). The *behaviors* were all observed on the live deployed tree.

---

## 0. VERDICT — 🟢 GREEN-shippable for V1, with a focused polish backlog

| | |
|---|---|
| **P0** | **0** |
| **P1** | **2** — (A) Variante-tab 500 [confirmed], (B) delivery-zone map broken [owner-scope-conditional] |
| **P2** | **~14** (deduped) — mostly i18n/format polish; ~half on V1-hidden pages |
| **P3** | **~40** — cosmetic / latent / known |
| **Coverage** | **~58 V1-active pages + ~12 hidden** across all 5 systems (provable inventory, 0 silent cap) |
| **Dead controls** | 0 reachable (1 latent addon-PUT, 1 orphan component, 1 orphan API route — all unreachable) |
| **RBAC** | gated on every mutating surface; privilege-escalation guarded on role-assign |
| **Safety** | **operating `foodking` tripwire INTACT** (2673 / `daf60671…` unchanged); **0 operating-DB writes**; **0 push/mail sent**; frozen-diff **0** |

**Bottom line (gérant lens):** every page the gérant actually reaches renders, every button resolves to a live endpoint, the money/order/fiscal data is trustworthy (NF525 audit-trail + Z-report + encaissement verified live on the clone, fiscal_sequence_no allocation proven). The dashboard is **functionally production-coherent**. What holds it from a clean GREEN is a **bounded, mostly-cosmetic polish backlog** that collapses into ~6 high-leverage root-cause fixes — plus one real broken feature (variation editing) and one owner-scope decision (delivery).

---

## 1. THE TWO P1s (act on these)

### P1-A — Variante tab returns 500 on every product detail (REPRODUCED 3× + structurally root-caused)
- **Where:** `/admin/items/show/{id}` → `GET /api/admin/item/variation/group-by-attribute/{item}` (route `api.php:735` → `ItemVariationController@listGroupByAttribute`). Reproduced 3× on the live deployed tree (items 27, 48), deterministic. **Controller is byte-identical across both branches** → citation valid for the deployed code.
- **Root cause (structural — stack trace not captured; pre-cloud-exec log had no matching line):** the method's `try {…} catch (Exception $e) { return 422 }` does **not** catch a `\Error`/`\TypeError` thrown by `itemVariationService->listGroupByAttribute` (or `ItemVariationGroupByAttributeResource` serialization outside the try) → escapes as 500. The UI **masks it as "Aucune donnée disponible"** → the gérant can't tell "no variants" from "server crashed", and **cannot view/manage variations from the product page**. (To confirm exactly: `catch (\Throwable)` temporarily + read the logged message; the structural escape path is certain regardless.)
- **Fix:** `catch (\Throwable)` + surface a real error-state; fix the underlying service/data. (Studio + items-list are unaffected — they don't call this endpoint.)

### P1-B — Branch delivery-zone map broken (CONDITIONAL — owner scope decision)
- **Where:** `/admin/settings/branches/show/{id}` → "Zone de Livraison" tab. `BranchShowComponent.vue:222` calls `new google.maps.drawing.DrawingManager()` — an API **Google removed in Maps JS v3.65+** → the polygon-drawing tool never initializes. Compounded by an **empty `Clé Google Maps`** (Site settings) → map shows "Impossible de charger…/For development purposes only".
- **Impact:** a gérant **cannot draw a delivery zone**. Severity depends on whether delivery is in V1 scope — `order-setup` has **Livraison=Activer** and the nav has "Caisse livreur", so it isn't obviously disabled.
- **OWNER DECISION:** is delivery in V1? → **Yes:** replace the deprecated DrawingManager + provide a valid Maps API key. → **No:** disable Livraison + hide the Zone-de-Livraison tab. The DrawingManager break is a real code issue independent of the key.

---

## 2. HIGH-LEVERAGE GLOBAL FIXES (the "améliorer chaque page" core — 6 fixes clear most P2/P3)

1. **FR money + payment-method formatter** → apply `currencyAmountFormat`/`formatPrice` + an enum→FR map on **Transactions** (`TransactionListComponent.vue:103/110`, `TransactionResource.php:24-25`) and **cash-sessions-report**. Kills the `COUNTER_CASH` / `+ 8.50` (no €) eyesores on gérant-facing reports. *(highest visible impact)*
2. **Localize the global delete dialog** → the SweetAlert "Are you sure?/Yes, Delete it!" is English on every Supprimer (items/attributes/categories/employees/chefs). One i18n fix, app-wide.
3. **Unify time → FR 24h** → 12h AM/PM leaks on pos-orders/historique/transactions/sales-report/messages vs 24h elsewhere.
4. **i18n token/label sweep** → fr.json: add the `label.*` payment/sms-gateway family + fix `$t("label."+UPPER_ENUM)` casing; FR notification-alert templates + day names (Monday→Lundi) + role names ("Stuff"→Staff, dedupe); raw tokens `attribute:8`, `crudite`/`supplement_bol`, `VAT`→`TVA`, "ARTICLE (LEGACY)"; the recurring `N°`→`Non` boolean. *(most of this is on V1-hidden pages → lower urgency)*
5. **Variation endpoint** → P1-A above.
6. **Mass-send safety** → push-send and subscriber-mail both fire from a button labeled **"Enregistrer"** with no confirmation and no recipient count (`PushNotificationCreateComponent.vue:56`, `SubscriberMailComponent.vue:29`). Add confirm modals ("Envoyer à N destinataires ?") + rename to "Envoyer". *(latent in V1 — channels OFF/no creds — but a real foot-gun once enabled)*

---

## 3. PER-CLUSTER VERDICTS

| Cluster | Visual | Key points |
|---|---|---|
| C1 Dashboard + C10 Shell/Nav | 🟢/🟡 | 18 endpoints 200, NF525 audit-trail + Z#19 widget = real fiscal trust; 24-link nav all render; menu-vs-route drift (8/9 hidden modules reachable by URL); SLA-alert noise. |
| C2 Catalogue + C3 Stock | 🟡 | **P1-A Variante-500**; stock/rupture = best-designed page; English delete dialog, N°/Non, raw tokens, allergens-not-editable (known). |
| C4 Commandes + C5 Caisse | 🟢 | Encaissement NF525-correct (fiscal 2072 proven), refund exemplary; empty Type-de-paiement on detail; money/time format; DUPLICATA reprint timing (P3). |
| C6 Rapports + C7 Users | 🟡 | Transactions raw-enum (P2); employee null-phones (fixtures) + EN validation; "Stuff" role typo; roles-page V1-hidden (by design). |
| C8 Communications | 🟡 | Mass-send safety (P2×2); EN notification templates (latent); no FCM test-send. **0 sent.** |
| C9 Réglages (26 pages) | 🟡 | 7 visible tabs clean; i18n debt concentrated on hidden pages; **P1-B delivery map**; taxes VAT→TVA (values correct). |

---

## 4. DEAD CONTROLS / WIRING (all unreachable — low priority)
- `itemAddon.js:60-61` PUT to nonexistent route (latent — no edit button). `ItemPhotoUpload.vue` orphan component (live path = change-image). `pos-order/reorder-items/{order}` (api.php:992) orphan route, no JS caller. None reachable by a user.

## 5. SAFETY ATTESTATION
- **Operating DB:** `foodking` audit_logs = 2673 / `daf60671…` **before AND after** all 7 waves → **zero writes to the operating NF525 chain**. All mutations (encaissement fiscal 2072, message reply, config toggle) landed on the `:8766` clone.
- **No live-fire:** 0 push notifications, 0 emails sent (both never-fire buttons captured + closed). test-print never clicked. observability retry/drain never clicked.
- **Frozen zones:** 0 diff (read-only audit, no code edits).
- **Clone note:** SQL-snapshot reseed is blocked by the NF525 immutability triggers (correct behavior); the clone carries minor test pollution (fiscal 2072, 1 message) — harmless, removable only via full re-clone.

## 6. CORRECTIONS MADE DURING AUDIT (verify-before-report)
- **DROPPED** "orphan Pages settings route" (W7-static false positive) — route IS registered `settingRoutes.js:420-445`; visual confirmed it renders.
- **DOWNGRADED** "missing DUPLICATA marker" P2→P3 — marker IS wired (`ReceiptDuplicataMarker.vue` `v-if printCount>=2`); didn't render on the observed reprint = count-increment-before-render timing, not absent.
- **NOT-A-DEFECT** roles/permissions page "unreachable" — V1-hidden by design (`isSettingHidden('role')`).
- **DROPPED** login "Mot De Passe" casing — a11y tree showed correct FR; low-res screenshot misread.
- **DEDUPED** transactions raw-enum across W2/W5; reframed cross-page time/money as single root-cause classes.

## 7. COVERAGE MANIFEST (no silent cap)
All 5 systems' dashboard surfaces audited: C1 dashboard, C2 catalogue (items/studio/ingredients/attributes/categories), C3 stock/rupture, C4 commandes (pos-orders/historique/tracker), C5 caisse (encaissement/cash-overview/sessions-report/delivery-cash), C6 communications (messages/push/subscribers/+settings notif), C7 users (administrators/employees/chefs/roles), C9 settings (26 sub-pages), C10 shell/nav/profile/header. POS-wizard/KDS/OSS = render-smoke (D-2). 9 V1-hidden modules = render-smoke/confirmed-hidden. Standalone web/mobile = out of scope (separately audited).
**Not exhaustively rendered:** every paginated row-detail of every list (representative drills done); the frozen POS wizard internals (D-2).
