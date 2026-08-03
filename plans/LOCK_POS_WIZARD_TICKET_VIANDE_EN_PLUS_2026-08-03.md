# LOCK — Ligne « Viandes en plus : <noms> » manquante dans l'instruction SOUMISE (single-page caisse)

**Date** : 2026-08-03 · **Gate owner** : ✅ EXPLICITE (/goal 2026-08-03 : « dans la cuisine ça sort comme une ligne "viande" … Faudrait le type de viande. Corrige cette problématique »)

## Racine (prouvée par test rouge)
Le rendu single-page ACTIF soumet `buildTicketInstruction()` (`public/js/pos-wizard.js:4325`) qui inline la viande payée dans « Viandes : X, +Kefta » (`:3745-3757`) mais n'émet **jamais** la ligne dédiée « Viandes en plus : <noms> ». Seul `buildWizardInstruction()` (récap, `:2508`) l'émet. Or le ticket cuisine (PHP `KitchenTicketSymbolicFormatter::extraViandeNames:338-348`) et le KDS (JS `kdsSymbolic.extraViandeNames`) ne parsent QUE cette ligne dédiée (jamais « Viandes : ») → la cuisine lit « + Viande supplémentaire ×N » sans type. Le test historique validait le RÉCAP avec fallback mou (test vert encodant le bug) — durci le 2026-08-03 (`tests/js/posWizardViandeSupplementUnified.spec.js:82-94`, RED reproduit).

## Frozen (sous CE LOCK)
1. **`public/js/pos-wizard.js`** — `buildTicketInstruction()` uniquement : pousser `'Viandes en plus : <noms>'` (format `N× Nom`, miroir exact du bloc `:2487-2508` déjà validé sous LOCK_POS_WIZARD_VIANDE_SUPPL_UNIFIE_2026-07-24.md). Aucun autre changement ; money-path intouché (l'extra @2,50 et `data-wizard-qty` inchangés).

## Non-frozen (même commit logique)
2. `app/Services/Hardware/KitchenTicketSymbolicFormatter.php::cleanInstruction` (compoRe `:602`) + `resources/js/helpers/kdsCustomization.js::KDS_COMPO_LINE_RE:221` : dropper la ligne standalone `(Viandes?|Sauces?) en plus :` des notes (info déjà repliée dans « + Viande supplémentaire : X ») — parité PHP↔JS, fixture régénérée si diff.

## Preuves attendues
- `posWizardViandeSupplementUnified.spec.js` VERT (assert durci sur `.ticket-content`).
- `KitchenTicketSymbolicFormatterTest` + `KitchenTicketViandeSupplNameTest` + `MultiSauceTicketNamesTest` VERTS.
- `kdsCustomization.spec.js` VERT (drop anti-doublon) · `kitchenParityRealData.spec.js` VERT.
- Ticket cuisine simulé : « + Viande supplémentaire : <Type> » visible, zéro doublon note.

## Réversibilité
`git checkout <ref> -- public/js/pos-wizard.js` (patch additif de ~10 lignes, un seul bloc).
