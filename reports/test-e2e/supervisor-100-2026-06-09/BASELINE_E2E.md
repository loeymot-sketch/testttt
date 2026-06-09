# Wave-4 BASELINE E2E (read-only, :8765 production tree, admin@lecayenne.fr)
Captured 2026-06-08 ~21:44. Screenshots: testttt/supervisor-100/0X-*.jpeg. NF525 anchor unchanged (no mutation).

| # | Surface | Route | Render | Console | Notes |
|---|---|---|---|---|---|
| 01 | Dashboard CENTRAL | /admin/dashboard | OK | 0 err | FR money 31 773,90 €; 45 menu items; quick-access 11 surfaces; **146 SLA alerts aged "11 j" = stale data** |
| 02 | Kiosk idle BORNE | /kiosk/idle | OK | 0 err,1 warn | "Bienvenue!", light-mode, only "À emporter" (dine-in off ✓), portrait-optimized |
| 03 | POS CAISSE | /admin/pos | OK | 0 err,1 warn | Unified "À ENCAISSER BORNE (200)" live; real cats+images; FR money; **2 cats truncate to "Sandwich …"** |
| 04 | KDS | /admin/kitchen-display-system | OK | 0 err,1 warn | Empty-state clean; "Mode admin centralisé 60s" (admin view cadence, not kitchen 5s) |
| 05 | OSS | /admin/order-status-screen | OK | 0 err,1 warn | 2-col "En préparation"/"Prêt", empty-state clean |
| 06 | Catalogue CENTRAL | /admin/items | OK | 0 err | 45 produits/11 cats/45 actifs/0 indispo; **PRIX "1.50" en-US dot, no € (FR formatter not uniform) = P2** |
| 07 | Encaissement D2 | /admin/encaissement | OK | 0 err,1 warn | D2 create-then-collect BUILT; origin badge "Borne", Encaisser btns; **200 soak-test orders = stale DB** |

## Baseline findings (live-confirmed, feed the catalog)
- **DATA-HYGIENE (go-live gate):** operating `foodking` DB has ~200 stale soak-kiosk test orders + 146 aged SLA alerts. Production needs clean-state reset (real menu, 0 test orders, NF525 chain decision). Same DB shared :8000/:8765.
- **I18N-MONEY (P2):** FR money formatter not applied uniformly — /admin/items PRIX shows "1.50" dot/no-€ vs dashboard "31 773,90 €". (Matches corpus "global FR money formatter" theme.)
- **UX-CAT (P3):** two category chips both truncate to "Sandwich …" in POS — indistinguishable.
- **DONE confirmed live:** dashboard, kiosk, POS, KDS, OSS, catalogue, D2-encaissement all render 0-error, FR, Cayenne palette. Unified encaissement (D2) + per-category POS images = built & working.
- 1 console warning recurs on most admin SPA routes (benign — to identify in Wave-4 deep pass).
