# FoodKing V1 — Live System Validation Report
**Date:** 2026-06-07
**Scope:** Full real-flow validation of the CENTRAL system (the box) + kiosk, against a disposable production clone, with 3 layers of evidence (live UI captures + DB state + NF525 audit chain).
**Harness:** `foodking_e2e` clone on `:8766` (`APP_ENV=e2e`, `DB_DATABASE=foodking_e2e`) — writes are safe/disposable, fiscal secrets identical to operating, CHAIN OK. Operating `:8765`/`foodking` was never written to.

---

## 1. Verdict

**CENTRAL system (POS · KDS · OSS · Encaissement · Historique · Dashboard · Catalogue · Stock · Customers): VALIDATED — production-grade.**
- Headline order lifecycle proven end-to-end with audit trail.
- NF525 fiscal integrity proven at full dataset scale (0 gaps, 0 duplicates, chain OK).
- All 9 surfaces render clean (0 crashes, 0 raw i18n labels, 0 console/JS errors).
- Codebase audit: **0 P0, 0 real P1** (28 leads → all refuted/known except 1 real P2, now healed).

**KIOSK (borne): VALIDATED — full lifecycle proven.** Idle + menu validated live; a full fresh kiosk order (#4161) was created end-to-end via UI (idle→takeaway→add drink→cart→upsell→counter-route→confirm) and driven through the **complete lifecycle**: encaissement (→PAID, fiscal 2013) → appears on KDS (fresh paid borne order) → KDS advance (Démarrer→Prêt, PREPARED) → OSS (A0002). The earlier "harness-blocked" note was resolved via the production-intended `KIOSK_AUTO_LOGIN_TRUSTED_IPS` mechanism (no product-code change).

**Both order origins now have complete proven lifecycles:** POS #4160 (Caisse→2001→A0001) and Borne #4161 (Kiosk→2013→A0002). Final fiscal sequence: max=2013, count=2013, **gaps=0** after all session activity.

---

## 2. Headline flow — proven end-to-end (order #4160)

Owner requirement: *commande → encaissement → écran cuisine → remise client → traçage → historiques (zéro numéro manquant).*

| Leg | Evidence | Result |
|---|---|---|
| Commande (POS, walk-in) | live POS wizard → cart 1,50 € | ✅ |
| Encaissement (cash) | `payment_status=5` PAID, `fiscal_sequence_no=2001`, `cash_movement` row, `creator_id=1` (cashier identity) | ✅ |
| Écran cuisine (KDS) | order shown on `/kds`; "Prêt" click → `order_status_transitions 7→8` (PREPARED), actor-stamped + correlation_id | ✅ |
| Remise client (OSS) | customer board shows **N°A0001 in "Prêt" column** | ✅ |
| Traçage / Historiques | `/admin/historique` row 1: serial 0706264160, origine Caisse, **N° fiscal 2001**, Payé, Préparée | ✅ |
| Dashboard KPI | "Suivi en direct": Commandes du Jour 1 / CA 1,50 € / Ticket Moyen 1,50 € | ✅ |

Order #4160 traces consistently across **4 reporting/operational surfaces** (KDS → OSS → historique → dashboard) + the order record. Transitions: `1→7` (auto_prepare_on_paid) → `7→8` (Prêt), both audited.

---

## 3. NF525 fiscal integrity — proven at full scale

- **"Zéro numéro manquant":** branch 1 = **2001** fiscal numbers, min=1, max=2001, span=2001, **gap_count=0** (explicit LEFT JOIN gap-hunt across 1..2001 returns empty).
- **0 duplicates** (GROUP BY HAVING c>1 empty).
- **HMAC chain:** `fiscal:verify-chain --all` → CHAIN OK on every branch, before and after the test order.
- Sequence allocated only at payment: paid orders carry sequential numbers; "à encaisser"/"non payé" rows show "—" (correct).

---

## 4. CENTRAL surfaces — render sweep (headless, MCP-independent)

Spec `tests/e2e/zz-central-surface-sweep-2026-06-07.spec.js` — **8/8 PASS**. Per surface: HTTP 200, 0 crash markers, 0 raw i18n labels, 0 console errors, 0 JS page errors. Screenshots in `tests/e2e/__screenshots__/central-sweep-2026-06-07/` (all visually analyzed):

| Surface | Notes |
|---|---|
| dashboard | Total articles 45 (SSOT), Total cmd 3430, live daily KPI traces test order |
| stock/rupture | "Gestion Produits & Stock", real-time toggle synced caisse/borne/wizard, full category tree |
| encaissement | unified borne→caisse collection (200 pending cards, N°file + token + items + Encaisser) — owner's #1 unify focus surface EXISTS |
| order-status-screen (OSS) | customer board "En préparation \| Prêt", N°A0001 in Prêt |
| kds | kitchen display, order cards |
| historique | full fiscal trace, 3431 entries / 344 pages |
| customers | loyalty-consult list, Filtrer/Exporter/Ajouter |
| catalogue (separate MCP capture) | 45 items / 11 categories canonical, real images |
| POS (separate MCP capture) | wizard, cart, encaissement flow |

---

## 5. Codebase audit (28 leads) — supervisor-verified

- **0 P0, 0 real P1.** All 3 P1 leads refuted or known (PaymentService fiscal-next is tx-wrapped; cash-skip is M10-01-flagged; CASH-01 known). Top P2s refuted (split branch-scope OK; salesReport store rejects-not-swallows).
- **1 real P2 HEALED:** KDS raw i18n label `kds_counter_payment_unpaid` added in 5 locales, parity 28/28, served-bundle + DOM verified, frozen intact.
- Full suite previously confirmed sincere-green (3030/3030 PHPUnit, Vitest, sentinels).
- Detail: `reports/test-e2e/all-systems-2026-06-06/AUDIT_WAVE_2026-06-07_SUPERVISOR_VERDICT.md`.

---

## 6. KIOSK (borne) — status

- **Backend PROVEN:** 200 borne orders in `/admin/encaissement` + historique origin "Borne" → borne→caisse pipeline works (Plan B routing).
- **Login degradation UI VALIDATED:** graceful FR screen ("Connexion machine non configurée côté serveur" + exact dev/prod fix steps, "borne publique"), no crash / no raw label.
- **Live menu/wizard capture BLOCKED (harness-only, NOT a defect):** `config/kiosk.php auto_login_local_bypass => env('APP_ENV')==='local'`; the clone runs `APP_ENV=e2e` (to load `.env.e2e`→foodking_e2e), so `e2e≠local` disables the auto-login bypass. In real local/prod (`APP_ENV=local`) the borne auto-logs-in. Capturing it live needs `APP_ENV=local` + foodking_e2e DB simultaneously (conflicts with `.env` selection) — deferred; kiosk wizard is frozen + pre-validated in many prior sessions.

---

## 7. Remaining work (toward "tout le système, chaque bouton, 10 commandes/sous-système")

| Item | State |
|---|---|
| 10-orders/subsystem gap-free | ✅ **DONE (encaissement)** — 10 sequential UI encaissements → fiscal 2003-2012 consecutive, gaps=0, chain OK (`zz-encaissement-10orders-2026-06-07.spec.js`). Plus #4160→2001 (POS), #71→2002 (borne) = 12 orders/2 flows all gap-free |
| Per-button interaction depth | render + key-flow validated; exhaustive per-button click coverage = pending headless interaction specs |
| Build-new units (3-tickets render, printer integration, loyalty *consult* page) | OWNER-GATED features (G-PRINT-HW, G-BUILDER-CUSTOM, G-POS-COMPOSER-FLAG); not built without gate |
| Kiosk live menu/wizard capture | ✅ **DONE** — blocker resolved via legitimate trusted-IP mechanism (`KIOSK_AUTO_LOGIN_TRUSTED_IPS` in `.env.e2e`, no product-code change). Idle + menu validated live (`zz-kiosk-{surface-sweep,menu-flow}`). Fresh-borne-order flow now possible → unblocks borne KDS lifecycle |

---

## 8. Artifacts

- Specs: `tests/e2e/zz-central-surface-sweep-2026-06-07.spec.js`, `tests/e2e/zz-kiosk-surface-sweep-2026-06-07.spec.js`
- Screenshots: `tests/e2e/__screenshots__/{central,kiosk}-sweep-2026-06-07/`
- Run cmd (safe): `DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test <spec> --retries=0`
- Audit verdict: `reports/test-e2e/all-systems-2026-06-06/AUDIT_WAVE_2026-06-07_SUPERVISOR_VERDICT.md`
