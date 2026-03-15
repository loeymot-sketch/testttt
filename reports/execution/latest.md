# Latest Execution Report

**Sprint 21** — Wizard Logic: Sauces, Frites, Supplements

- **Report:** [sprint_21_execution.md](sprint_21_execution.md)
- **Date:** 2026-03-10
- **Status:** COMPLETED (Post-Audit Corrections Applied)
- **Executor:** Kimi / Claude

## Quick Summary

5 bugs corrigés (4 initiaux + 1 post-audit):

1. ✅ S21-1: `FORMULE: [addons]` dans KDS pour choix individuels
2. ✅ S21-2: Prix correct pour qty > 1 (formule × quantité)
3. ✅ S21-3: Cheddar + Grande Portion inline pour sandwiches
4. ✅ S21-3a: Fix visuel post-audit (sélecteur `.frites-opt`)
5. ✅ S21-6: Carte "Boisson Seule" pour Tacos

## Impact

- **KDS instruction complète** — même pour addons individuels
- **Prix frontend correct** — qty × formule
- **Upsells revenue** — cheddar (+€1) + grande (+€1) sur frites sandwich
- **UX Tacos** — boisson seule disponible

## Test Checklist (pour Anti-Gravity)

- [ ] Sandwich + Frites individuel → KDS: `FORMULE: Frites Seules`
- [ ] Sandwich qty=2 + Menu → Total: 2× prix menu
- [ ] Sandwich + Frites + Cheddar + Grande → Options visibles et cliquables
- [ ] Toggle visuel fonctionne (selected state après clic)
- [ ] Tacos + Boisson Seule → Avance vers choix boisson
