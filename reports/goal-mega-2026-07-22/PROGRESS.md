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
