# Nav-Button Reachability Audit (GOAL_MGMT_TESTPLAN Wave B / DASH-T10) — 2026-06-01

**Owner's #1 unaudited concern:** "all buttons that lead to other pages... we don't have audits if they work."

## Result: 27/27 sidebar buttons WORK. 0 dead buttons, 0 orphan pages.

### Method (live, against running :8000, admin authenticated)
1. Extracted the **27 live rendered sidebar buttons** (label → href) via DOM.
2. **Route resolution:** drove the live Vue router `resolve()` over all 27 → **every one maps to a real named route** (admin.dashboard … admin.transactions.list, admin.sales-report.list, admin.settings, etc.), `orphan_or_404 = []`, matched_depth ≥ 1. No button leads to the 404 catchall.
3. **Live render:** SPA-router swept 16 un-verified routes + live-navigated the flagged ones + 5 already captured this session. Every page renders real content (heading + data), 0 error-boundary, 0 console error.

### The 3 "broken" flags were FALSE POSITIVES (verify-before-report)
A crude `/404/` body-text regex matched a substring in page content:
- `pos-orders-tracker` — real "Suivi commandes" + 184K content (kanban). WORKS.
- `transactions` — live-confirmed: full table, **620 entries**, COUNTER_CASH, Filtrer/Exporter. WORKS.
- `sales-report` — live-confirmed: KPIs (3388 cmd / 31 632,40 € revenus / remises 0 / frais 5) + table + 339 pages. WORKS.
Lesson: a `/404/` text heuristic false-matches page numbers/IDs — always live-verify a flagged page before calling it broken.

### Sidebar nav inventory (all reachable + rendering)
Tableau de bord, POS, Produits & Stock, Catalogue, Attribut d'articles, Ingrédients, Commandes caisse, Historique, Encaissement, Vue caisse unifiée, Caisse livreur, Écran cuisine, Suivi client, Notification pushs, Messages, Abonnés, Administrateurs, Employés, Chefs, Transactions, Rapport des ventes, Rapport articles, Paramètres, Suivi caisse (kanban), Rapport caisses quotidien, + profile (edit/password).

### Note on discovery's "not-in-sidebar" candidates
Those (Payment Terminals, Notification FCM/Alert) are **Settings SUB-pages**, not main-sidebar buttons — reached within the Settings cluster, a separate sub-nav (to be swept in the Settings wave). "Rapport caisses quotidien" (cash-sessions-report) and "Dining Tables" ARE reachable. No confirmed main-nav orphan.
