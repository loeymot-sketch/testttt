# 00 — INDEX — Audit mobile app Le Cayenne 2026-05-10

Mode : YC GStack 6 sub-agents en parallèle (read-only) + RED TEAM cross-validation.
Branche : `feature/mobile-app-le-cayenne-2026-05-10`
HEAD au démarrage : `1ba7aaf59`
Mobile preview : http://127.0.0.1:8081
Kiosk reference : http://127.0.0.1:8000/kiosk/idle (Laravel up, 200 OK)
DB live : 63 items / 7 attrs / 180 addons / 674 extras / 0 wizard_profile / 0 wizard_step

---

## Sommaire

| # | Fichier | Lignes | Auteur | Rôle |
|---|---------|--------|--------|------|
| 00 | [00_INDEX.md](00_INDEX.md) | (ce fichier) | orchestrateur | Table des matières |
| 01 | [01_architect.md](01_architect.md) | 210 | AGENT-ARCHITECT | Cohérence flow wizard kiosk vs mobile, gap table 15 rows P0×6/P1×4/P2×4 |
| 02 | [02_dba.md](02_dba.md) | 565 | AGENT-DBA | Extraction DB par catégorie, 7 findings F-DBA-1..7 |
| 02b | [02_dba_tinker.txt](02_dba_tinker.txt) | 449 | AGENT-DBA | Raw tinker output (live DB snapshot) |
| 03 | [03_ux.md](03_ux.md) | 288 | AGENT-UX | Gap single-scroll vs multi-page, 8 ScreenStep blueprint avec ASCII mockups |
| 04 | [04_tester.md](04_tester.md) | 395 | AGENT-TESTER | Plan E2E par catégorie + 8 pricing combos + invariants cross-cat |
| 05 | [05_a11y.md](05_a11y.md) | 201 | AGENT-A11Y | WCAG 2.1 AA wizard scope, 4 P0 / 7 P1 / 4 P2 / 2 P3 |
| 06 | [06_adversarial.md](06_adversarial.md) | 233 | AGENT-ADVERSARIAL | 15 contestations cross-validées : 13 SURVIVES / 1 FAILS / 1 NEEDS-RECONCILE + 3 user mis-assertions |
| 99 | [99_VERDICT.md](99_VERDICT.md) | (synthèse) | orchestrateur | Findings consolidés + 6 owner-gate decisions D1-D6 + 3 user-prompt corrections U2/U3/U4 + plan d'action priorisé |

**Total audit** : 8 fichiers, ~2100 lignes markdown + 449 lignes raw tinker.

---

## Findings headline (cf. 99_VERDICT pour détails)

### P0 — blockers refactor
- **F-01** Mobile cat IDs `1..13` ≠ DB `306..318` (DBA + Adv C-01)
- **F-02** Cat 9 Menus Enfants `has_sauce: false` (mobile) ≠ `true` (DB+config+V3.8) — **D2 owner-gate**
- **F-03** `frites_style` step manquant mobile, existe en DB sur 19 items — wire sur cats 312/313/315 SEULEMENT (V3.8 dormant Ojja/Omelettes)
- **F-04** Pas de state machine multi-page : single-scroll, pas de prev/next, pas de Recap, pas de validation per-step
- **F-05** A11y 4 P0 : interactive divs sans keyboard, IconBtn sans accessible name, 0 focus styles, contrast orange/orange-soft 2.49:1
- **F-06** `wizard_template` exposé en mobile data layer mais ignoré par ScreenItem + 2 mismatches (Ojja+Menus Enfants `simple`→`omelette`)
- **F-07** **User prompt FAUX** : "Salades = no wizard" — kiosk a wizard 5-steps salade — **D1 owner-gate**

### Owner-gated decisions
- D1 Salades wizard (= U3) : (A) implémenter parity 5-steps OU (B) override no-wizard
- D2 Menus Enfants sauce : (A) flip mobile à true OU (B) migration backend remove
- D3 Ojja/Omelettes frites_style dormant : (A) leave dormant (B) cleanup migration (C) réactiver
- D4 Cheddar fondu duplicate items 402/403 : (A) migration delete legacy (B) projection filter
- D5 Mobile cat IDs alignment timing : (A) remap maintenant (B) reporté à wireup
- D6 `addon.role` NULL 180 rows : (A) backfill migration (B) status quo (backend, hors scope mobile)
- U2 Wings BBQ/Nashville : confirm FAUX (15 sauces génériques) ou owner veut nouvelle migration ?
- U4 Assiette Poulet "style cuisson" : description text (A) status quo OU (B) nouvelle migration backend

### Failed contestation
- F-DBA-7 (P1 → P3) : "has_menu drift Ojja" n'est PAS un drift, revert intentionnel migration `2026_05_10_060000` V3.8

---

## Discipline d'exécution

✅ 0 modification de frozen-zone Kiosk Vue (Wizard, App, Upsell, steps/*)
✅ 0 modification backend (FiscalSequence, BranchScope, PricingService, OrderState)
✅ 0 modification frontend POS Vanilla JS (pos-wizard.js, pos-wizard.css, admin-pos-v4.blade.php)
✅ Reads only — chaque sub-agent a confirmé READ-ONLY discipline
✅ Adversarial cross-validation forçant primary-source verification

---

## Conditions de retour orchestrateur

Audit livré 100%, refactor BLOQUÉ jusqu'à owner-gate sur D1-D6 + U2/U3/U4. Voir 99_VERDICT.md §6.

— *Index 2026-05-10*
