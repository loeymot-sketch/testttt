# Round-1 — LIVE UI/UX visual sweep (WS-9) + cross-surface integrity

**Harness:** clone foodking_2dot0 @ :8780 (serve+soketi+queue UP, restarted), authenticated admin.
**Method:** navigate + screenshot + Read-the-image + DOM/console probe per surface.

## Surfaces — ALL CLEAN (0 console errors steady-state, no raw i18n key, no NaN/undefined, FR money/dates, branding intact)
| Surface | Verdict | Key evidence |
|---|---|---|
| Dashboard `/admin/dashboard` | CLEAN | Total ventes 37 072,37 € ; CA jour 0,00 € (correct — no orders today 2026-06-15) |
| Sales-report `/admin/sales-report` | CLEAN | Revenus 37 072,37 € (== dashboard), Remises 13,93 €, Livraison 62,00 € ; 699 993 € test order shows "Non payé" (excluded) |
| Encaissement `/admin/encaissement` | CLEAN | Unified queue (Borne + Client passage), Total attente 315,90 €, "Encaisser" buttons, composition shown |
| Items `/admin/items` | CLEAN | 46 produits / 15 catégories / 46 actifs ; action icons ; FR money |
| OSS `/admin/order-status-screen` | CLEAN | **DUX-3 confirmed**: both columns "Aucune commande" (28px muted, centered) — NOT bare '—' ; magenta/green TV headers |
| Stock-rupture `/admin/stock/rupture` | CLEAN | **A-012b confirmed**: names 110px, 0 clipped (Sandwich Cayenne / Big Cayenne) |
| Kiosk-idle `/kiosk/idle` | CLEAN | "Bienvenue !", on-brand orange, start button, "À emporter" card |
| Historique `/admin/historique` | CLEAN | 10 rows, no raw-key leak, no NaN/undefined |
| POS/caisse `/admin/pos` | CLEAN | Unified caisse, "À ENCAISSER BORNE (69)", PosSyncService UP (fallback polling disabled), ticket panel |
| KDS `/kds` | CLEAN | 0 console errors, empty board (correct — no active orders) |

## Cross-surface numeric integrity (live) — CONSISTENT
- Revenue **37 072,37 €**: dashboard == sales-report (== `scopeRealizedRevenue` SSOT, netting heals hold).
- Pending counter-collect **69**: POS "À ENCAISSER BORNE" == encaissement page badge == dashboard.
- Total à encaisser **315,90 €**: encaissement page.

## Candidate finding (for the adversarial round to adjudicate)
- **UX-BOOT-401 (P2?):** the post-login transition fires ~39 transient **401s** on the dashboard API endpoints BEFORE the Bearer token hydrates into axios — they self-heal to **0 errors on reload** (the documented SPA token-hydration boot-race). A real user sees a brief flash of failed dashboard calls on first login. Known/transient, but worth deciding if the login flow should hydrate the token before firing the dashboard API batch.

## Verdict
WS-9 live UI/UX sweep = **PASS** (10 surfaces clean, cross-surface integrity exact, A-012b + DUX-3 confirmed live). One transient boot-race candidate (UX-BOOT-401) carried to the finding ledger.
