# W5 — E2E + visual real-web (page-by-page, real Playwright on :8000)

**Date:** 2026-06-05 · authenticated as admin@lecayenne.fr · all screenshots Read+analyzed (visual mandate).

## 8/8 core surfaces — VISUAL PASS

| # | Surface | URL | Verdict | Notes |
|---|---|---|---|---|
| 1 | Kiosk idle (BORNE) | `/kiosk/idle` | ✅ PASS | "Bienvenue !", Cayenne orange, "À emporter", 0 raw label. Console: dev-env CSP report-only + 401 probe (see V5-1). |
| 2 | Login | `/login` | ✅ PASS | "Bon retour", FR Email/Mot de passe/Connexion, clean. |
| 3 | Dashboard (CENTRAL) | `/admin/dashboard` | ✅ PASS | Total ventes 32 098,20 €, commandes 3483, **45 articles menu** (SSOT), live tracking, SLA alerts. 0 err. |
| 4 | POS (CAISSE) | `/admin/pos` | ✅ PASS | "À ENCAISSER BORNE (200)" Plan-B queue, "Article indisponible : Œuf" rupture banner, category bar + product grid w/ images. 0 err. |
| 5 | KDS | `/kds` → `/admin/kitchen-display-system` | ✅ PASS | "Mode admin centralisé 60s" poll banner (correct for admin viewer), "Aucune commande en cours", RÉCEMMENT SERVIES. 0 err. |
| 6 | OSS | `/admin/order-status-screen` | ✅ PASS | 2-col "En préparation"/"Prêt", large readable headers, empty (correct). 0 err. |
| 7 | Catalogue (CENTRAL) | `/admin/items` | ✅ PASS | 45 PRODUITS / 11 CATÉGORIES / 44 ACTIFS / 1 INDISPONIBLE (Œuf), table w/ images+FR+prices+Actif. 0 err. |
| 8 | Stock rupture (CENTRAL) | `/admin/stock/rupture` | ✅ PASS | "Gestion Produits & Stock" real-time toggle UI, full FR category tree w/ counts, EN STOCK badges. 0 err. |

## Findings (triaged per §0.3 — 0 V1-LOCAL visual blocker)

| # | Finding | Triage | Action |
|---|---|---|---|
| V5-1 | Kiosk console: CSP report-only `connect-src` violations + 401 `/api/login` probe — caused by API base `localhost:8000` ≠ page `127.0.0.1:8000` origin | **☁️ CLOUD-PREP** | Dev-env artifact. In production single-domain, API base == page origin → no CSP violation. CSP is report-only (not enforced). Set prod API base == origin; consider enforcing CSP post-validation. Page renders + works. |
| V5-2 | Stale doc URL: CLAUDE.md §6 + GOAL list `/admin/stock-rupture-dashboard` (404s) | **📋 DOC FIX** | Real route is `/admin/stock/rupture` (`stockRoutes.js:7`). 404 page is a CLEAN SPA not-found (correct UX). Not a defect — update the doc URL. |
| V5-3 | Catalogue admin table prices show "1.50" without € symbol | **📋 P3 cosmetic** | Known prior P3. Money formatting elsewhere uses `AppLibrary::currencyAmountFormat` (FR €). Admin table is internal. Non-blocking. |

## Verdict
Every core surface of every system renders correctly on real web — 0 raw labels, 0 layout breaks, 0 functional
console errors, FR throughout, Cayenne branding, 45-item SSOT, Plan-B borne-encashment queue live, real-time
stock toggle. The only console noise is dev-env origin mismatch (cloud-prep, disappears in prod single-domain).

**W5 STATUS: CLOSED — 8/8 surfaces VISUAL PASS, 0 V1-LOCAL blocker, 1 doc-fix (stock URL), 1 cloud-prep (CSP/origin).**
