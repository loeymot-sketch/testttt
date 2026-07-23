# GOAL Méga 2026-07-22 — Progression (resume manifest)

Plan : `plans/GOAL_MEGA_BORNE_TICKET_STOCK_MOBILE_2026-07-22.md`

## ✅ W1 — Ticket + écran cuisine (S2) — DONE + déployé
- Commit `a3b91303e` (backend, poussé + deploy VPS).
- Sauce par catégorie : produit (1re+en plus) ligne 1 « FRO MAY » ; menu ligne 2 « MENU : KTP ».
- Tacos : plus de [Taille] ; viandes symboles « K P ». Ordre produit→viandes→sauce→suppléments.
- Parité PHP (KitchenTicketSymbolicFormatter) ↔ JS (kdsSymbolic.js). Reçu fiscal client INCHANGÉ.
- Preuves : JS 32/32, PHP 37/110 assert, parité fixture 220 lignes, frozen 0.
- Reste W1 : capture visuelle KDS écran (bloquée : creds VPS non seedés + rebuild local) → owner à confirmer OU rebuild local + login admin@lecayenne.fr/123456 (sélecteurs accessibles: getByRole textbox "Email" + button "Connexion").

## ✅ W2 — Borne tacos crudités + prix formule (S1 partiel) — DONE + déployé
- Commit `27797eff7` (poussé + deploy VPS).
- **Tacos crudités : premisse RÉFUTÉE** — tacos vendables (M #26, L #97) affichent DÉJÀ crudités (prouvé vs KioskMenuService réel). #27 Big Tacos INACTIF (non servi). Sentinel `kioskTacosCruditesStep.spec.js` verrouille. → si owner voit encore tacos sans crudités = **bundle borne périmé** (hard-refresh) OU testait Big Tacos.
- **Prix formule borne** : affichés par carte (KioskStepMenuComponent non-frozen, SSOT getKioskMenuAddonPrice). Web + suppléments déjà OK.
- ⚠️ **G-PRIX ouvert** : owner veut boisson seule 1,90 / frites seules 1,90 ; système = boisson +1,00 / frites +1,50. = CHANGEMENT PRIX RÉEL → confirmation owner requise avant de toucher.
- Reste W2 (déféré, FROZEN) : **formule split 3 pages** (formule→boissons→sauces) = touche `computeActiveSteps` dans KioskWizardComponent FROZEN → LOCK gate OU approche profil-composer. À traiter en vague dédiée.

## ⏳ W3 prix SSOT (résolu si G-PRIX) · W4 stock rupture KDS/caisse/admin+sync · W5 mobile admin (G-MOBILE) · W6 convergence

## (ex-W2 next, voir ci-dessus)
- Anchor : `resources/js/components/frontend/kiosk/steps/*.vue`, `KioskWizardComponent.vue:1060 (template!=='tacos')`, `:922 tacos detect`, `computeActiveSteps`, profil composer tacos DB.
- Tacos crudités : trouver pourquoi tacos saute l'étape garnitures/crudités → ajouter via data/profil/computeActiveSteps (non-frozen).
- Formule split : page1 formule(menu/boisson-seule/frites-seule) → page2 boissons → page3 sauces (KioskStepMenuComponent + computeActiveSteps).
- Prix affichés : 2,50/1,90/1,90 + suppléments.

## ⏳ W3 prix formule SSOT (gate G-PRIX) · W4 stock rupture KDS/caisse/admin+sync · W5 mobile admin (gate G-MOBILE) · W6 convergence test-e2e + deploy

## Gates owner PENDING
- G-PRIX : confirmer menu 2,50 / boisson seule 1,90 / frites seules 1,90 (vs ancien +1,00/+1,50).
- G-MOBILE : URL/domaine + auth accès admin mobile.
- G-MOLLIE : clé Mollie VPS. G-PUSH : « deploy » par vague.

## ✅ W4 — Stock rupture KDS+caisse+admin+sync — DÉJÀ FAIT (vérifié, 0 code)
- Livré par RUPTURE-CARNET 2026-07-15 (W2=POS, W3=KDS). SSOT unique `menu/availability/toggle`
  → AvailabilityService → ItemAvailabilityChanged → Echo `private-branch.{id}`. KDS panel
  (KitchenDisplaySystemComponent), caisse panel (PosComponent non-frozen), admin dashboard =
  même endpoint. Chef+POS Operator autorisés (availability_toggle). Anti-doublage PASS.
- Preuves : PHPUnit 181/850 assert, Vitest 34, frozen 0. Construire un 2e bouton = doublage interdit.
- Si owner veut un UX différent (86 inline sur chaque carte KDS) = nouvelle décision de scope.

## État global : W1✅ W2✅(prix) W4✅(déjà) déployés. Reste :
- **G-PRIX** (owner) : boisson/frites seules 1,90 vs 1,00/1,50 → changement prix réel, attente confirmation.
- **Formule split 3 pages** : FROZEN KioskWizardComponent (computeActiveSteps) → LOCK gate OU profil-composer, décision requise.
- **W5 mobile admin** : admin déjà déployé (VPS) + accessible URL ; besoin **creds admin VPS valides** (non seedés) + vérif responsive mobile → G-MOBILE owner.
- **W6 convergence test-e2e** : validation adversariale finale (partiellement bloquée sur creds VPS pour surfaces login-gated).

## ✅ G-PRIX RÉSOLU (owner confirme 2026-07-22) — commit f3ed08761 + web 63f934d, déployés
- frites seules / boisson seule = **1,90 €** (menu complet 2,50 inchangé). SSOT = config
  `kiosk.menu_pricing` ratios 0.6/0.4 → **0.76** (×2,50=1,90 exact). ZÉRO frozen touché
  (PricingService lit la config ; pos-wizard.js n'a pas de prix formule hardcodés).
- Miroirs : kioskPricing.js défauts 0.76 ; web menu.js f-frites/f-boisson 1,90 + wizard-v2 options.
- Tests màj : MenuRoleAdjusted (5.91/2.28), sentinel addon-role 2,28€, KioskWizard 97 (previews
  13.28/11.28), kioskFormulePrices 1,90. Sweep 124 JS + 130 PHP verts.

## 🔓 Autorisation FROZEN owner (verbatim) → plans/LOCK_KIOSK_FORMULE_SPLIT_2026-07-22.md
## 🚀 3 agents parallèles lancés (partitions disjointes)
1. FORMULE-SPLIT : KioskWizardComponent (LOCK) + steps → 3 pages (formule/boissons/sauce-frites).
2. MOBILE-PIN : /m + code 2580 (pattern carnet, fail-closed, throttle) → stock mobile + toggle rupture SSOT.
3. WEB-RESPONSIVE : audit+fix mobile Pixel 7 (nav burger cassée + boutons) sur le site Vercel.
## ⏭️ Ensuite : deploy des 3 · test massif STOCK cross-surfaces (téléphone/caisse/KDS/admin) ·
##    audit LOGIQUE caisse (contrôle commandes, annuler/valider, sync KDS, commandes web) en boucle.

## ✅ FORMULE SPLIT 3 PAGES — LOCK exécuté, commits 7157208f5+8b4c5b887, deploy VPS en cours
- Frozen : KioskWizardComponent SEUL (+70/−19, hook LOCK-citation). Menu monolithique → 3 étapes
  gatées : QUEL MENU ?(4 cartes, prix 2,50/1,90/1,90) → QUELLE BOISSON ?(pleine page, 15 photos,
  CTA gaté) → SAUCE POUR LES FRITES ?. Bols intacts, payload identique.
- Preuves : 757 sweep + 12 spec + 112 re-vérif ; e2e réel local 3 pages capturées + LUES (0 erreur JS).
## ✅ 4/4 chantiers owner du jour LIVRÉS (prix 1,90 · mobile /m 2580 · nav mobile web · split formule)
## ⏭️ NEXT : test massif STOCK cross-surfaces (/m→borne/caisse/KDS/admin) + audit LOGIQUE caisse en boucle

## ⚠️ INTERRUPT 2026-07-22 — limite de session (reset 20h10 Paris) pendant les 2 audits
- STOCK MASSIF (a12c…) : coupé à « 9/10 verts — 1 échec format date (cast) à corriger dans l'assertion »
  → tests locaux quasi finis ; volet live VPS /m→web à re-dérouler. Reprendre : re-lancer l'agent
  stock avec le même brief + corriger l'assertion date, puis registre STOCK_MASSIF_FINDINGS.md.
- CAISSE LOGIQUE (a0cd…) : coupé à « tracker path confirmé, seed 3e clone (gate annulation payée),
  driver Playwright à écrire » → reprendre le même brief ; les seeds tinker sont peut-être en DB locale
  (commandes clones source=5 à nettoyer/réutiliser).
- TOUT LE RESTE EST LIVRÉ + DÉPLOYÉ : prix 1,90 · /m PIN 2580 · nav mobile web · formule 3 pages (LOCK).

## ✅ VAGUE 2 COMPLÈTE (16/16 findings traités) — commits 12a6bc016 (MP) + 28ec6ce8f (stock+sync+bundles)
- **Money-path 4/4** : remboursement espèces sans double avoir (renverse contrat gated, sanctionné registre) ·
  tiroir jamais fantôme (hasPaymentTranches) · ticket split = 1 ligne/tranche · reçu REMBOURSEMENT.
- **Stock 5/6** : filet poll 86 (worker down) · quota lazy reconcile (box éteinte) · reasons documentées+sentinel ·
  photo gaté rôle+403 propre · badge « Désactivé globalement » · sidebar→?tab=stock. **1 escaladé owner** :
  StockService restock physique réactive un 86 manuel (décision A/B posée).
- **Sync/KDS 6/6** : unbind ciblé · cadence WS-sain=60s (renverse GOAL-HEAL-SYNC-001, sanctionné) · coalesce OSS ·
  tracker paginate+lean · KDS grace floor 2h no-show · carte programmée ancre timer + badge 🕐.
- Preuves : vitest 2508+ (fraîcheur 9/9 post-build) · PHPUnit 364/1208 + MP 347+663 · frozen 0 · chaîne OK ×4.
- **STOCK MASSIF CLOS** : preuve LIVE /m→web 153 ms + local 10/10 (STOCK_MASSIF_FINDINGS.md). 0 P0/P1 produit.
## ⏭️ Reste : deploy 28ec6ce8f (en cours) · e2e final caisse/KDS · décisions owner (A/B restock, frozen v2 items 🧊, perf bundle dédiée)

## ✅ 2026-07-23 — BOM stock intelligent P1+P2 LIVRÉ + réconciliation vague-2
- **P1** (`10c241d88`+`15206aa89`) : 12 matières + 104 recettes + fiche owner (Suprême corrigé = hachée 75g + cordon bleu).
- **P2a moteur conso** (`fd1ee8b40`) : OrderCreated→consume idempotent. Preuve réelle Cayenne→5 matières.
- **P2b coût+rejeu** (deploy en cours) : replay 2659 cmd→1248 lignes/12 matières (dry-run ACID), FoodCostService
  NULL-safe (« ? prix non saisi »), FOOD_COST_REPORT.md 55 produits.
- **⚠️ RÉCONCILIATION** (`23979a484`) : source vague-2 (MP+SYNC+stock) testée verte mais JAMAIS commitée
  (commits partiels 12a6bc016/28ec6ce8f = tests seulement) → scellée + déployée. VPS était en retard.
- **REVALIDATION WEB 10/10** (`REVALIDATION_WEB_2026-07-23.md`) : commande réelle #230726193 10,40€ scellée.
- Preuves globales : vitest 2511/2514, phpunit 754/2531, chaîne NF525 OK ×4, frozen 0.
## RESTE : P2c (/m stock théorique + à-acheter) · P3 facture photo IA (clé ChatGPT) · prix d'achat matières
##   (owner qq valeurs OU factures) · Mollie clé VPS · capital mentions · commande test #230726193 à encaisser.
