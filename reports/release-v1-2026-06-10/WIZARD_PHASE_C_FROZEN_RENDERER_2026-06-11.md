# PHASE C — Renderer générique POS intégré (wizard frozen, owner-autorisé) 2026-06-11

> Owner GO : « je t'autorise à travailler dessus [wizards frozen] avec le maximum d'intelligence pour ne pas faire du mal à son fonctionnement … beaucoup d'attention de raisonnement avant de toucher à aucune ligne de code … une dispute avec les agents adversaire. »

## 1. Raisonnement AVANT toute ligne (mandat owner)
Diff frozen `pos-wizard.js` = **+335 / −1**. Unique suppression = `if (step.type==='viande')` → `if (step.composer_step && step.type!=='recap') renderGenericChoicesStep; else if (step.type==='viande')`. Le garde `step.composer_step` n'est posé QUE par `buildStepsFromComposerProfile` (l.533), appelé QUE derrière le double-gate flag (`isComposerAwareEnabled` l.553 + `getComposerProfileFromData` l.555). **Flag FALSE (défaut) → composer_step jamais posé → composerMode toujours faux → legacy bit-identique.** 4 fonctions ajoutées pures (escWiz/composerChoicePrice/composerAddonTotal/renderGenericChoicesStep). Conclusion : ne peut pas nuire à la fonction validée flag-OFF.

## 2. Dispute adversaire (mandat owner) — 2 agents indépendants
- **Adversaire flag-OFF : SAFE — legacy bit-identique PROUVÉ** (file:line exhaustif). Chaque insertion non-conditionnelle = no-op prouvé derrière `selections.generic`/`composer_step`/`composerMode` (tous gardés par le flag inchangé). composerAddonTotal=0, composerGenericMeta=[], composerMode=false flag-OFF (extraction runtime). CSS+blade 0-ligne. 22/22 specs.
- **Adversaire flag-ON : READY** — NF525 (composerChoicePrice lit le prix par jointure d'id du catalogue, JAMAIS du step ; soumission via form natif → PricingService inchangé ; zéro prix figé). XSS-safe (escWiz sur name/image/label/key ; strip `<>` sur le sink ticket). Rendu correct (badges Obligatoire / compteur X/N / coches / Voir-plus state-driven / steppers / gate min_select). 1 note P3 bénigne (variation sync exact-match, backend reste SSOT).

## 3. Intégration (chirurgicale, scope LOCK exact)
Via `LOCK_POS_WIZARD_GENERIC_RENDER_2026-06-10` (mécanisme frozen-override légitime : HEAD cite le LOCK, hook passé, **0 `--no-verify`, 0 bypass**). Fichiers : `pos-wizard.js` (renderer, frozen, +335/−1), `master.blade.php` (injection flag, non-frozen), `posWizardGenericRender.spec.js` (nouveau), sentinel T-COMPO-6 retourné (GAP#1 FERMÉ).

## 4. Preuves
- **Frozen scope = SEUL pos-wizard.js** (css/blade/fiscal/pricing/kiosk/PaymentComponent = 0 ligne).
- **Flag `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` = FALSE par défaut** (inerte prod ; flip = G-5 owner séparé).
- **Vitest 2141 passed** (wizard 30/30 + composer subset 86/86) ; sentinel T-COMPO-6 verrouille désormais la clôture (anti-régression).

## Verdict Phase C
GAP#1 (parité caisse↔borne) **FERMÉ** : la caisse rend désormais les wizards composés par le builder. Le système de composition est complet bout-en-bout (builder → projection → borne ET caisse). Fait dans l'ordre exact owner : raisonnement → dispute → intégration, sans nuire à la fonction validée (flag-OFF bit-identique prouvé). RESTE owner : flip prod du flag (G-5) = décision séparée.
