# PLAN — Massive Reasoning E2E (caisse 20 + borne 20) + Ultra-Plan

> Worktree: `.claude/worktrees/massive-e2e-0604`. Artifacts under
> `reports/test-e2e/massive-reasoning-2026-06-04/`. Screenshots under
> `tests/e2e/__screenshots__/massive-reasoning/`.

## Architecture (advisor-endorsed)
1. **CAPTURE** (serialized, workers:1, server load) — authored Playwright specs drive real orders, screenshot every step, dump per-order JSON. Then surfaces + lifecycle + refund.
2. **ANALYSIS** (Workflow, heavy parallel, NO server) — agents read screenshots+JSON, structured findings (screenshot-path + step-ref each).
3. **ADVERSARIAL DISPUTE** (Workflow) — each finding validated-or-refuted, default-refute unless solid evidence.
4. **SYNTHESIS** — ultra-massive report.
5. **ULTRA-PLAN** — prioritized action plan from confirmed findings + reasoning + dispute trail.

Capture strictly BEFORE the parallel Workflow phase (mono-process server; memory note: heavy concurrent load crashes the single worker). Write artifacts to disk per order (crash-proof).

## HARD GATE (before the 20-loop)
Driver must complete ONE real order per wizard archetype AND land in DB with expected total:
- **sandwich** (Viande + Sauce + extras) — item 22 Sandwich Cayenne
- **tacos** (Viande + Sauce) — item 26 Tacos
- **bowl/custom** (Base + Sauce + supp) — item 28 Bol Curry
- **fries/custom** (Style single-select) — item 33 Petite Frites
- **simple** (no wizard, direct add) — item 52 Coca-Cola
Assertions per archetype: wizard→Add → payment confirm → orders row appears (id > baseline 4162) with expected total → screenshot tree populated. Stall ⇒ disambiguate driver-bug vs real-UI-defect (1 focused run), fix driver or record finding.

## "Sortie de la commande" = full lifecycle to exit (NOT just "appears on KDS")
- POS order → KDS card appears → chef transitions confirmé→en préparation→prête → OSS board reflects → done.
- Kiosk order → POS "À ENCAISSER BORNE" queue → cashier encaisses → KDS → OSS.
- Capture order *changing state*, not just existing. BATCH lifecycle pass (place all, then one chef pass + one OSS pass) to avoid 40× cost.

## Caisse (POS) — 20-order variety matrix (archetype × payment × supp × single/multi)
| # | products | payment | supp | items |
|---|----------|---------|------|-------|
| 1 | Sandwich Cayenne | cash | no | 1 |
| 2 | Big Cayenne + Sauce supp | cash | yes | 1 |
| 3 | Tacos | card | no | 1 |
| 4 | Big Tacos + crudités | card | no | 1 |
| 5 | Bol Curry (Frites base) | cash | no | 1 |
| 6 | Bol Tandoori (Riz) + supplément | card | yes | 1 |
| 7 | Petite Frites (Cheddar fondu) | cash | no | 1 |
| 8 | Grande Frites | card | no | 1 |
| 9 | Chicken Burger | cash | no | 1 |
| 10 | Big Chicken + Cheddar | card | yes | 1 |
| 11 | Sandwich Classique | cash | no | 1 |
| 12 | Galette Cayenne | card | no | 1 |
| 13 | Coca-Cola | cash | no | 1 |
| 14 | Tiramisu (dessert) | cash | no | 1 |
| 15 | Menu Nuggets (menu enfant) | card | no | 1 |
| 16 | Sandwich Cayenne + Petite Frites + Coca | cash | no | 3 |
| 17 | Tacos + Big Tacos | card | no | 2 |
| 18 | Bol Curry + Coca + Glace | cash | no | 3 |
| 19 | Sandwich Cayenne + 3 supplements | card | yes | 1 |
| 20 | Mixed 4-item combo | cash | yes | 4 | ← refund target (E2E only)

## Borne (Kiosk) — 20-order variety matrix (client self-order, payment→counter Plan B)
Same archetype variety, client persona. Each lands in POS encash queue → encash → KDS → OSS.
(20 rows mirroring the matrix above, kiosk navigation: idle → À emporter → category → product → wizard → cart → checkout → counter.)

## NF525 / pollution discipline
- Tag every order: customer note / token prefix `E2E-MR-0604`. Sweepable by `iter15:cleanup-test-orders`.
- `fiscal:verify-chain --all` before (CHAIN OK, baseline) and after → both in report.
- Refund ONLY an E2E-created order (#20), never pre-existing.
- Prices are PRE-update (owner's 2026-06-04 list not applied) — baked into all analysis prompts.

## Surfaces to audit (owner-named, reachable from POS first page)
- "À encaisser" (encash queue, 200 kiosk orders) · "Suivi commandes" (orders overview) · "Écran client" · "Appliquer réduction fidélité" · "Ouvrir tiroir" · "Caisse" (session dialog) · "PRÊT À LIVRER" · "Mettre en attente"/"En attente" (park) · category bar (truncation) · ticket panel · refund flow · OSS `/admin/order-status-screen` · KDS (validated, screenshot-only).
