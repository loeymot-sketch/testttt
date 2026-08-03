# GOAL — Viande nommée en cuisine · Proposition viande borne · Paiement web une-page
_Date : 2026-08-03 · Orchestrateur : session /goal · Pipeline par tâche : `ultra-audit-profond` (réf, non re-décrit)_

## §0 Préambule
- **Working tree** : branche `pos/category-first-caisse-2026-06-23`, nombreux untracked rapports/plans (état normal armada). Décision : ne pas commit les untracked hérités ; commits scope-minimal par volet.
- **Convergence** : Axis 6 — 2 cycles adversariaux consécutifs P0+P1=0 et findings identiques, par volet.
- **Frozen zones concernées** : `public/js/pos-wizard.js` (V1 LOCK requis), `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` + `KioskUpsellComponent.vue` (V2 — audit d'abord, LOCK seulement si patch nécessaire).
- **Test réel final** : navigateur réel sur www.lecayenne.fr (volet 3) + serveur local :8000 (volets 1-2), agents adversaires + raisonnement (dispute obligatoire avant verdict).

## §1 Volet 1 — CAISSE→CUISINE : nommer la viande supplémentaire (P1, racine PROUVÉE)
### Racine (vérifiée 2026-08-03)
- Le rendu **single-page ACTIF** soumet `buildTicketInstruction()` (`public/js/pos-wizard.js:4325`) qui inline les viandes suppl. dans « Viandes : X, +Y » (`:3745-3757`) mais **n'émet JAMAIS** la ligne dédiée « Viandes en plus : <noms> ».
- Seul `buildWizardInstruction()` (`:2508`, chemin récap/multi-étapes) l'émet — et le test `tests/js/posWizardViandeSupplementUnified.spec.js:82-96` valide le RÉCAP avec fallback mou → **test vert qui encode le bug**.
- Le ticket cuisine ne parse QUE « Viandes en plus : … » (`app/Services/Hardware/KitchenTicketSymbolicFormatter.php:338-348`, jamais la ligne « Viandes : ») → extra générique « + Viande supplémentaire » sans type.

### Tâches
- T-1.1 LOCK doc `plans/locks/LOCK_POS_WIZARD_TICKET_VIANDE_EN_PLUS_2026-08-03.md` (skill lock-plan ; précédents : LOCK_POS_WIZARD_VIANDE_SUPPL_UNIFIE 07-24, LOCK_POS_WIZARD_VIANDE_SUPPL_CHARGE 07-01).
- T-1.2 `pos-wizard.js buildTicketInstruction()` : pousser `'Viandes en plus : <noms>'` dans `extraLines` (miroir exact du bloc :2487-2508). Scope-minimal, 0 changement money-path.
- T-1.3 Anti-doublon notes : `KitchenTicketSymbolicFormatter::cleanInstruction` (`:602` compoRe) + JS `kdsSymbolic.js` sanitize : dropper les lignes `^(Viandes?|Sauces?)\s+en\s+plus\s*:` (compo déjà repliée). Vérifier ticket CLIENT (`OrderReceiptEscPosRenderer:448`) non régressé.
- T-1.4 TDD : (a) durcir `posWizardViandeSupplementUnified.spec.js` → asserter l'instruction SOUMISE/ticket (`.ticketContent`, pos-wizard.js:3684) SANS fallback mou ; (b) étendre `tests/Feature/Hardware/KitchenTicketViandeSupplNameTest.php` avec l'instruction single-page réelle ; (c) fixture parité `tests/fixtures/parity_php.json` régénérée si le contrat bouge.
### Acceptance
- `tests/js/posWizardViandeSupplementUnified.spec.js` PASS (assert durci) · `KitchenTicketViandeSupplNameTest` PASS · `MultiSauceTicketNamesTest` PASS (non-régression) · vitest kds/pos filtré 0 fail · frozen diff = LOCK-couvert uniquement.
- Preuve visuelle : ticket cuisine simulé (commande caisse viande différente au-delà du quota) montre « + Viande supplémentaire : <Type> ».

## §2 Volet 2 — BORNE : proposition viande supplémentaire bien positionnée
### Anchors (rapport éclaireur 2026-08-03 — SAIN)
- Proposition = dépassement de quota DANS l'étape Viande (`KioskStepViandeComponent.vue:119-130` CTA après quota, `:249-266` allocation inclus/suppl, `:237-240` prix depuis ItemExtra DB). Type NOMMÉ (tuiles réelles), instruction « Viandes en plus : <noms> » émise (`KioskWizardComponent.vue:2277-2285`), facturation via extra générique (`:2071-2095`, bloc LOCK 07-24). Upsell ne mentionne jamais viande (correct). Tests riches : `tests/js/kioskViandeSupplement.spec.js`.
### Verdict : positionnement DÉJÀ intelligent et en contexte. 4 points P2 :
- T-2.1 Affordance : CTA texte-seul après quota (choix owner 07-28, à conserver) — ne rien casser, prouver par capture.
- T-2.2 Plafond `maxViandes+4` silencieux (`KioskStepViandeComponent.vue:347-363`) — petit message au tap bloqué (non-frozen).
- T-2.3 Sandwichs viande FIXE (Cayenne/Suprême) : extra « Viande en plus » sans instruction nommée (`EnsureFixedMeatSupplementCommand.php:31-33`) — acceptable (la viande du produit est implicite) ; documenter, pas de patch V1.
- T-2.4 Preuve visuelle Playwright étape viande borne (quota atteint → tag +2,50 + CTA) analysée via Read.
### Acceptance
- `kioskViandeSupplement.spec.js` PASS (non-régression) · capture analysée : proposition visible, nommée, prix affiché, position logique.

## §3 Volet 3 — WEB : page de paiement UNIQUE (nom + email + téléphone + paiement en bas)
_Repo : `lecayenne-web-deploy/Site lecayenne` (SEUL déployé — jamais le miroir `Downloads/web`)._
### Anchors (éclaireur 2026-08-03) — repo `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne` HEAD `e9e263f`
- Parcours actuel : CartDrawer → upsell (0-3 sous-étapes) → **CheckoutPage « Étape 1/2 »** (`funnel.jsx:267-459` mode/jour/heure/promo/lieu) → **PaymentPage « Étape 2/2 »** (`funnel.jsx:460-984`) → confirmation. 2 vraies pages.
- Contact invité : prénom/nom/tél/email TOUS obligatoires (`funnel.jsx:687-704`) mais bloc EN BAS, révélé seulement après clic « Payer » (`funnel.jsx:661`, `:900-978`) — inverse de la demande owner.
- Inline OK : Mollie Components in-page (`funnel.jsx:536-570`), 0 window.open/_blank paiement ; seule redirection = 3DS même-onglet consentie (`:833-853`).
- Signaux : `index.html:29` mollie-profile-id VIDE (carte inline morte prod — gate owner Mollie) ; `funnel.jsx:751` libellé « parcours de test » relique ; `:648-651` catch silencieux (client "confirmé" sans savoir qu'il paiera au comptoir) ; `account-v2.jsx:50-54` nom de famille absent.
- Build : site statique Vercel, babel-standalone in-browser, bust manuel `?v=` (`index.html:84-118`) à incrémenter.
### Tâches
- T-3.1 **Fusion 1 page** : CheckoutPage + PaymentPage → une seule page scrollable « Commander » : retrait/heure/promo EN HAUT → bloc contact invité (prénom, nom, email, téléphone) VISIBLE D'EMBLÉE → méthode + carte Mollie + bouton Payer EN BAS. OTP inline (code sous le bloc contact après envoi). Stepper ajusté.
- T-3.2 Heals : catch Mollie silencieux → bandeau explicite « paiement au comptoir » ; retirer libellé test + state carte mort (`funnel.jsx:462`, sentinelle 4000…) ; nom de famille dans AccountFlow (`account-v2.jsx`).
- T-3.3 Backend testttt : confirmer 422 sans prénom+nom+email+tél sur le chemin OTP invité (tests Feature existants Auth/GuestSignup — non-régression).
- T-3.4 Bump `?v=` + push → Vercel ; vérifier servi (byte-check api/funnel).
### Acceptance
- Grep exhaustif 0 redirection/nouvel onglet paiement · specs web existants PASS · **test RÉEL navigateur sur www.lecayenne.fr** : parcours complet 1 page, commande visible en caisse avec nom+téléphone, adversaire indépendant re-déroule et dispute.

## §A Armée d'agents
- Éclaireurs Explore ×2 (borne, web) — lancés.
- Implémenteur : session principale (frozen LOCK discipline), jamais 2 implémenteurs parallèles.
- RED adversarial : 1 agent raisonnement par volet post-fix (refuter, preuve DB/DOM exigée, findings sur disque `reports/goal-viande-paiement-2026-08-03/`).
- QA Visual + RED Visual : captures Playwright/Chrome analysées indépendamment (volets 1-2 local, volet 3 live).

## §X Vagues
1. **W1 découverte** (en cours) : ancrages + rapports éclaireurs. Checkpoint : racines vérifiées file:line.
2. **W2 volet 1** (caisse→cuisine) : LOCK → TDD → fix → gates. Séquentiel.
3. **W3 volet 2** (borne) : audit → patch si besoin → gates. Séquentiel après W2 (fichiers kdsSymbolic partagés).
4. **W4 volet 3** (web) : repo séparé → parallèle possible avec W2/W3 mais implémenteur unique → séquentiel par défaut.
5. **W5 convergence** : RED ×2 cycles par volet, test réel live, BRAIN §2/§3, mémoire.
- Interrupt-resume : commit WIP + manifest `reports/goal-viande-paiement-2026-08-03/INTERRUPT_*.md` + BRAIN §2.

## §G Gates owner
| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G1 | LOCK pos-wizard.js (+ Kiosk si patché) | Orchestrateur (owner a mandaté le fix via /goal) | LOCK doc cité dans le commit | plans/locks/ + message de commit | AUTO (mandat /goal explicite) |
| G2 | Déploiement VPS backend + Vercel web | Orchestrateur (mandat « teste en réel sur le web ») | deploy logs + preuve live | BRAIN §2 | PENDING fin W4 |

## §F Règle finale
DONE = les 3 volets à P0+P1=0 sur 2 cycles adversariaux, ticket cuisine nommé prouvé, borne prouvée par capture, parcours paiement 1-page prouvé en RÉEL sur www.lecayenne.fr, frozen diff couvert par LOCK, chaîne NF525 OK, BRAIN + mémoire à jour.
