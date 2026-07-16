# GOAL — Validation TERRAIN ultra-profonde (owner 2026-07-16)

> Owner furieux des « validé » superficiels/faux. Exige : validation RÉELLE sur le terrain
> (pas juste technique), tous systèmes, chaque produit, chaque page, boucle raisonnement→test→
> correction→audit jusqu'à tout vraiment validé. Playwright + screenshots réels obligatoires.
> Discipline 100× plus stricte. Rien laissé, aucun dossier non fouillé.

## Findings TERRAIN établis (preuve réelle navigateur, viewport mobile 392px)

- **F1 — CAUSE RACINE des plaintes owner : le site Vercel DÉPLOYÉ est 2 commits en retard.**
  Repo web `loeymot-sketch/Site-lecayenne`, `main...origin/main [ahead 2]` — commits NON poussés :
  `05cd406` (sauce 1ère gratuite/+0,50) + `f853a37` (viande fusion +2,50). Le déployé = ancien code
  buggé → « tout payant / rien gratuit », étapes viande séparées, etc. **Correctif = push origin main
  → Vercel redeploy.** BLOQUÉ par gate §10 (push public = autorisation owner explicite requise).

- **F2 — Le web LOCAL (à jour) est LOGIQUEMENT CORRECT** (prouvé écran par écran) :
  - Suprême : Pain(gratuit) → Sauce(12 gratuites, 2e sauce → 7,50 €) → Crudités(gratuites) →
    Suppléments(+0,90 payant, correct). Pas d'auto-avance sur multi. Pas de « tout payant ».
  - Tacos M : viande incluse gratuite, 2e viande → bandeau « 🍖 +2,50 (×1) » + 9,40 €. Pas d'auto-avance.
  - Bol : viande gratuite. Templates uniformes OK.
  → Les bugs owner = le DÉPLOYÉ, pas le code actuel.

- **F3 — Data gap sauce (session //)** : `766249da5` n'a mis l'ItemExtra 'Sauce supplémentaire' que
  sur 14 items ; 20 autres sans → 2e sauce larguée au backend. Fix // `menu:ensure-sauce-supplement-extras`
  + migration `2026_07_16_120000` (dev 20→0). RESTE : déployer au backend VPS.

## Structure campagne (boucle audit→heal→re-test jusqu'à convergence)

- **W1 — Audit LOGIQUE multi-systèmes** (parallèle adversaire) : borne, caisse/POS, KDS, OSS, web
  (tous templates+produits), management/admin, sync cross-système. Cible : gratuit/payant faux,
  min/max faux, auto-avance/step-skip, races sync, data gaps, isolation. file:line + repro.
- **W2 — Vérif adversaire** de chaque finding (réfutation) → P0/P1 confirmés.
- **W3 — Heal** scope-minimal + re-test (technique + visuel Playwright + screenshots).
- **W4 — E2E navigateur RÉEL** : chaque produit (sandwich/tacos/galette/burger/bol/frites/boisson/
  dessert/menu-enfant) + chaque page (login/compte/commandes/suivi/fidélité) + responsive (mobile/desktop).
- **Boucle** jusqu'à 2 cycles P0+P1=0 identiques.

## Gate owner (à confirmer)
- **PUSH-WEB (F1)** : autoriser `git push origin main` du repo web → Vercel prod (fixe le vrai site).
- **DEPLOY-BACKEND (F3)** : déployer migration sauce-extra au VPS.

## Captures
`tests/captures/e2e-web-real-2026-07-16/` : 01-menu, 02-supreme-detail, 03-pain, 04-sauce (mobile 392px).
