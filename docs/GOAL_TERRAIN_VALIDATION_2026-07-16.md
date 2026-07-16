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

## Audit W1 — 12 lentilles adversaire → 37 bruts → 14 confirmés (workflow wa4xvmw00)

### Healés + testés
- **P0 LOYAL-409-TTC** (backend `9ac33d34d`) : quote fidélité borne double-comptait la TVA en TTC →
  sealForCommit 409 = TOUTE commande borne avec points cassée (« le garde ne marchait pas »).
  Fix garde TTC (OrderQuoteService:379). Test régression TTC=9,00/HT=9,91.
- **WEB-EXTRAMEAT-N0** (web `38d959f`, MA régression f853a37) : viande en plus injoignable sur
  Cayenne/Suprême/6 burgers (viande_count:0) → branche else-if étape 'Viande en plus ?'. Vérifié e2e.
- **WEB-NOTE-DEAD** (web) : note cuisine (allergies) jamais transmise → rattachée à la 1ère ligne.
- **WEB-FAKE25PTS** (web) : faux « +25 pts inscription » retiré (3 endroits, backend=0 pt).
- **WEB-PAYONLINE-COPY / WEB-APP-DEADLINK** (web) : copy paiement corrigée, liens store morts retirés.

- **MGMT-86-REACTIVATE** (`96bc5988e`, owner a délégué « c'est toi qui gères ») : réactiver un extra
  86'd laissait on_hand=0 → borne épuisée pour toujours. Fix V1 flag-only : delete row si pas de stock
  réel (on_hand<=0 && reserved<=0) → « absent=disponible ». Tests alignés, 30 verts.

### Breadth e2e RÉELLE tous systèmes (captures `tests/captures/e2e-web-real-2026-07-16/`)
- Web (mobile 392px) : Suprême/Tacos/Bol flux complet + récap « INCLUS », panier. `01-06`.
- Caisse POS (desktop 1440px, login pos@lecayenne.fr) : colonnes commandes + compo + panneau en-cours. `10`.
- KDS : empty state propre « Aucune commande en cours ». `11`.
- Gestion dashboard : ventes/commandes/alertes SLA/répartition canal. `12`.
- Tous chargent + rendent proprement (0 défaut visuel bloquant constaté).

### Healés cycle 1 (suite) + testés
- **CAISSE-REFUND-SPLIT** `6f0856a56` : cashBack sortait $order->total du tiroir ; sur split (cash+carte)
  seule la portion CASH (mode==CASH) doit sortir → helper refundCashTranchePortion. Test split=4,00/mono=7,50.
- **MGMT-CATALOG-BLIND** `7d709243e` : catalogue admin incluait pas on_hand<=0 → divergence borne. 27 verts.
- **WEB-SLOT-STUB** + **WEB-BORNE-FRITES-CASCADE (web)** `d387ede` : faux créneau/heures + prix cheddar fantôme récap.

### Bilan heals : **11 findings healés + testés** (backend régression 2137 verts, 0 régression)

### Couverture produit web COMPLÈTE (buildSteps + prix, 0 erreur)
Sandwich (Suprême/Cayenne) · Tacos (M/L) · Bol (Frites/Riz) · Burger (Big/Chicken) · Galette
(viandes min1/max3+sauce) · Frites (style) · Boisson/Dessert/Menu-enfant (ajout direct, pas de wizard).
Tous logiquement sains sur le code actuel → « tout payant » = uniquement le déployé obsolète.

### Restant (non-Claude)
- WEB-BORNE-FRITES-CASCADE **borne side** (FROZEN §7) : borne facture cheddar-frites, web=gratuit →
  aligner la borne = KioskWizardComponent frozen → gate+LOCK owner.
- WEB-LEGAL-TODO : 26 [À COMPLÉTER] mentions légales = DATA owner (pas code).

## Gates owner (à confirmer)
- **PUSH-WEB** : `git push origin main` repo site (HEAD `38d959f`) → Vercel prod. **Répare le vrai site.**
- **DEPLOY-BACKEND** : déployer au VPS (P0 loyalty-409 + migration sauce-extra + heals).
- **MGMT-86-REACTIVATE** : trancher flag-only vs stock-tracké.

## Captures
`tests/captures/e2e-web-real-2026-07-16/` : 01-menu, 02-supreme-detail, 03-pain, 04-sauce, 05-bol-recap-inclus, 06-panier (mobile 392px). Preuve « INCLUS » vs extras payants.
