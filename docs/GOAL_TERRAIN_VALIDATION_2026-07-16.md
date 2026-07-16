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

## CYCLE 2 — vérif adversaire des heals + chasse (workflow wncxi1l2h, 14 agents)
Verdict : 8/8 heals vérifiés OK **SAUF 1 régression attrapée** + 5 nouveaux.

### Régression de mon heal cycle-1 attrapée + corrigée (= valeur de la boucle)
- **MGMT-86-DELETE-FK** `ebd734f45` (P1) : mon `$level->delete()` (heal 86) crashait en PROD MySQL
  (FK stock_movements restrictOnDelete + trigger append-only) + verrouillait la réactivation à vie ;
  les 30 tests SQLite passaient FAUX. Fix : delete GARDÉ par absence de stock_movements enfants
  (flag-only pur → delete sûr ; stock réel → garder + flag effacé). Test du garde ajouté, 13 verts.

### Nouveaux findings cycle-2 healés
- **CATALOG-BLIND-INGREDIENT** `a365662ea` (P2) : mon fix catalogue ne fermait que l'axe stock ;
  ajout de l'axe ingrédient (is_available extras + attribut variations). 27 verts.
- **KDS-MERGE-ORDER** `253f299fe` (P3) : board « à préparer » n'agrégeait pas les compositions
  identiques en ordre différent (sortKeys no-op → sortBy id). 133 verts.

### Bilan FINAL : **14 findings healés + testés** (cycles 1+2). Régression finale 1164 verts, 0 échec.

### Restant — gate owner (frozen §7 / data / P3 non-bloquant)
- **CAISSE-2E-SAUCE** (P1, FROZEN pos-wizard.js) : 2e sauce caisse +0,50 affichée mais NON scellée
  (sous-facturation + divergence borne 0,50 €). = bug caisse que l'owner avait DÉPRIORITISÉ → gate+LOCK.
- **CAISSE-SAUCE-FRITES** (P2, FROZEN) : sauce frites +0,50 caisse affichée non facturée. gate+LOCK.
- **KDS-RECALL-POLL** (P3) : rappel chef invisible en polling fallback — single-box 1 station = bas risque.
- **WEB-BORNE-FRITES-CASCADE borne** (FROZEN) : borne facture cheddar-frites, web gratuit. gate+LOCK.
- **WEB-LEGAL-TODO** : 26 [À COMPLÉTER] mentions légales = DATA owner.

## CYCLE 3 — e2e navigateur EXHAUSTIF page par page (Playwright + screenshots réels, mobile 392px)
Captures `tests/captures/e2e-web-real-2026-07-16/` 20-25 :
- **Accueil** (hero, chips « 1pt/euro » = fix), **Fidélité** (messaging honnête), **Connexion** (avant/après fix),
  **Menu**, **Mon compte** authentifié (350 pts réels + QR), **Commandes** (empty state propre).
- Stats accueil : « 0 Plats au menu » = état PRÉ-animation (compteur monte à 38 au scroll) → PAS un bug (vérifié).
- Login authentifié via injection token Sanctum (révoqué après) → pages authed rendues avec données réelles.

### NOUVEAU bug trouvé + healé via le parcours exhaustif
- **WEB-LOGIN-DEADFIELDS** (web `22c67a5`) : l'onglet « Connexion » affichait Email + Mot de passe MORTS
  (backend = auth SMS uniquement) → au clic tout était jeté + bascule SMS = UX trompeuse. Fix : login
  collecte directement le téléphone → OTP (comme inscription). Prouvé end-to-end (otp 200 → verify fire).

### Bilan e2e : ~20 états de page capturés + analysés (web) + 5 systèmes + tous produits. **15 findings healés total.**

## CYCLE 4 — findings caisse frozen §7 traités sous LOCK (owner délégué « c'est toi qui gères »)
- **CAISSE-2E-SAUCE** + **CAISSE-SAUCE-FRITES** (`58e961e24`, LOCK_CAISSE_SAUCE_SEAL `ae3f0f9b4`) :
  le pont caisse `pos-wizard.js` matchait la 2e sauce sur `'sauce suppl'+{nom}` → jamais l'extra générique
  → backend 0 € alors qu'écran +0,50 € (display≠sealed + sous-facturation + divergence borne). Fix :
  réplique le pattern viande-supplémentaire (extra générique + data-wizard-qty → setExtraQuantity, MÊME
  chemin PROUVÉ en prod). Frites-sauce +0,50 display retiré (gratuit = aligné borne). syntaxe OK.
  **PROUVÉ SUR LA VRAIE CAISSE** (Playwright vrais clics, capture `30-caisse-2sauces-7.40-sealed.png`) :
  Tacos M + 1 viande + 2 sauces (Algérienne+Andalouse) → ligne panier scellée « Sauce: Algérienne,
  Andalouse — **7,40 €** », Sous-total/Total **7,40 €**. Avant le fix = 6,90 (2e sauce larguée).
  Display == scellé == borne. C'EST la résolution du « tout payant » à l'envers (caisse SOUS-facturait).

## Bilan FINAL : **17 findings healés + testés** sur 4 cycles adversaires (dont 3 frozen sous LOCK : borne sauce, caisse sauce×2).

## Convergence
Tous les findings Claude-fixables (y compris frozen sous LOCK owner-délégué) sont HEALÉS + testés sur 4 cycles.
Le restant est frozen §7 (owner LOCK requis) ou data owner ou P3 single-box. Backend 1164+ verts,
couverture produit web complète, breadth e2e 5 systèmes capturée.
### Gates owner (débloquent le déploiement) : push web `d387ede`→Vercel · deploy backend VPS · LOCKs caisse frozen.

## Gates owner (à confirmer)
- **PUSH-WEB** : `git push origin main` repo site (HEAD `38d959f`) → Vercel prod. **Répare le vrai site.**
- **DEPLOY-BACKEND** : déployer au VPS (P0 loyalty-409 + migration sauce-extra + heals).
- **MGMT-86-REACTIVATE** : trancher flag-only vs stock-tracké.

## Captures
`tests/captures/e2e-web-real-2026-07-16/` : 01-menu, 02-supreme-detail, 03-pain, 04-sauce, 05-bol-recap-inclus, 06-panier (mobile 392px). Preuve « INCLUS » vs extras payants.


## PARCOURS COMPLET END-TO-END PROUVÉ (caisse → fiscal → KDS) 2026-07-16
Vrais clics Playwright, place du caissier : Tacos M + 1 viande + 2 sauces →
- Panier scellé **7,40 €** (`30`) → paiement Espèces 10€ → **rendu 2,60 €** (10−7,40, correct) (`31`)
- **Order #5727 créé** : total **7,40 €**, **fiscal_sequence_no 2665** (NF525 alloué), PAID, status PREPARING,
  composition_snapshot = item 26 + 3 crudités @0 + **1 extra « Sauce supplémentaire » @0,50** (2e sauce SCELLÉE).
- **KDS sync** : commande **A0032** affichée avec compo « TAC M | ALG » + **« 🍟 Sauce supplémentaire »** (`32`).
→ Avant le fix : scellé 6,90 (2e sauce larguée). Le flux caisse→NF525→cuisine est PROUVÉ de bout en bout.


## CYCLE 5 — validations live (pendant l'audit profond multi-agents)
- **OSS (écran client) SYNC PROUVÉE** (`33`) : commande #5727 = **N°A0032 en « En préparation »** →
  sync caisse→KDS→OSS complète (3 surfaces, même commande, temps réel). Colonne « Prêt » vide (correct).
- **RESPONSIVE propre à TOUS les breakpoints** : 360 / 392 / 768(tablette,`34`) / 1920 → **0 débordement
  horizontal** partout, aucun élément trop large. Dimension responsive = clean.
- Audit code profond 14 lentilles (perf/N+1, index, sync-races, a11y, OSS, gestion, edge, sécu, data,
  borne-tous-produits) lancé → findings à healer.
