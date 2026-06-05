# GOAL exec — Playwright E2E validation pass (current state, pre-PR-landing)
**Date** 2026-06-05 · **Server** :8765 · **Method** Playwright MCP navigate + full screenshot + Read/analyze + console-error check per surface. Captures in `./captures/`.

This is the **per-step validation layer** the owner demanded ("each step deeply confirmed with playwright test-e2e screenshot and validation"). The heal CODE is produced by the approved remote PR (anti-duplication §0.5); this pass = the proof layer + the baseline before the PR lands.

## Results — 5/5 surfaces CLEAN
| System | URL | Screenshot | Console err | Visual verdict |
|---|---|---|---|---|
| S2 BORNE | `/kiosk/idle` | `captures/goal-e2e-S2-kiosk-idle.png` | 0 | ✅ "Bienvenue !" + "À emporter", Cayenne dark+orange, FR, 0 raw labels |
| S5 CENTRAL | `/admin/dashboard` | `captures/goal-e2e-S5-dashboard.png` | 0 | ✅ "Bon Après-Midi !", 45 menu items, 32 056,20 €/3483 cmd, nav incl. **"Vue Caisse Unifiée"** (G-H Path B foundation), FR, flat |
| S1 POS | `/admin/pos` | `captures/goal-e2e-S1-pos.png` | **0** | ✅ "À ENCAISSER BORNE (200)" + Encaisser, "TICKET CAISSE", canonical menu, stock banner "Œuf indispo", FR, 0 raw labels |
| S3 KDS | `/admin/kitchen-display-system` | `captures/goal-e2e-S3-kds.png` | 0 | ✅ "Aucune commande en cours" empty-state + "RÉCEMMENT SERVIES", "Mode admin centralisé 60s" banner, FR |
| S4 OSS | `/admin/order-status-screen` | `captures/goal-e2e-S4-oss.png` | 0 | ✅ "En préparation"/"Prêt" 2-col board, correct empty state, FR, no PII |

## Verdict
Current-state (16/19) is **visually + console clean across all 5 admin/kiosk surfaces** — confirms the baseline the PR will build on. No raw labels, no layout breaks, no console errors, FR locale + Cayenne brand intact, canonical 45-item menu.

## Next (per-step validation harness for when the PR lands — §G-REVIEW operationalized)
These Playwright flows confirm each GOAL heal once the PR's code is present:
1. **M3-02**: POS → add Frites → select Grande + Cheddar → screenshot wizard preview total (+2,00) → add to cart → screenshot ticket → place → screenshot receipt → assert `grand_total` includes +2,00 € (vs current: dropped). Confirm kiosk #402/#403 unchanged.
2. **M6-002**: POS → split-pay an order 30€ cash + 20€ card → close Z → assert `total_by_method` = {cash:30, card:20} not {50}; `verify-chain --all` before==after.
3. **S13-02**: discounted order → receipt TVA == Z TVA (post-discount); gate on `OrderTotalHtDecompositionTest` + `PosReceiptTaxLinesTest` preserving `total = subtotal ± tax − discount`.
4. **G-H**: unified encaissement surface → 3 modes (Espèces/TR/Terminal) → 1 OrderPayment/mode; card/TR no CashMovement; screenshot.
