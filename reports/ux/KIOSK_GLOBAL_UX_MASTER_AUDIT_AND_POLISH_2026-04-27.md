# Kiosk Global UX Master Audit + Polish

Date: 2026-04-27  
Task: KIOSK-GLOBAL-UX-POLISH-ORDER-VALIDATION-2026-04-27  
Scope: borne client, parcours accueil -> catalogue -> wizard -> recap -> panier -> upsell -> paiement cash simule -> waiting.

## Verdict

UX_VERDICT: PASS_WITH_P0_FUNCTIONAL_FINDING

Le polish visuel demande a ete applique sur les zones les plus visibles du parcours borne sans toucher au backend:

- statut reseau transforme en pastille compacte au lieu d'un bandeau rouge dominant;
- pastille de statut deplacee sur le wizard pour ne plus masquer le nom produit;
- focus visible sur les choix de demarrage;
- bouton Payer vide rendu clairement inactif;
- chiffres de prix stabilises avec `tabular-nums`;
- recap produit renforce: contraste, total, quantites supplement, sections plus lisibles.

Le parcours complet fonctionne jusqu'a l'ecran `Votre commande est en preparation`, avec paiement `Espèces` simule. Un point P0 hors polish UX a ete decouvert: le total frontend avant paiement est `€11,70`, mais la navigation waiting finale contient `total=9.5`. Cela indique un risque pricing SSOT sur la quote/commit pour les extras menu/boisson/sauces, ou au minimum un parametre waiting obsolète. Ne pas declarer release-ready tant que ce point n'est pas traite.

## Methodologie

- In-app browser: inspection manuelle de l'ecran idle et categories.
- Playwright Chromium local: replay complet du parcours borne avec captures.
- Vitest cible: wizard/catalogue/panier/paiement/a11y/boissons.
- Build production Laravel Mix apres modifications CSS.
- Reference UX: principes Web Interface Guidelines, focus visible, etats actifs/desactives explicites, affordances claires, textes lisibles.

Source externe consultee: https://raw.githubusercontent.com/vercel-labs/web-interface-guidelines/main/command.md

## Changements appliques

### 1. Statut reseau

Fichier: `resources/js/components/frontend/kiosk/KioskAppComponent.vue`

- `ConnectionStatusBanner` scupe sous `.kiosk-app` en pastille compacte.
- Couleurs distinctes dark/light via variables `--kiosk-status-*`.
- Position wizard speciale avec `.kiosk-app:has(.kiosk-wizard)` pour eviter le chevauchement du titre produit.
- Bouton fermer du statut reduit et lisible.

Impact UX: le client voit l'etat reseau sans que l'alerte prenne le role principal de l'ecran.

### 2. Accueil

Fichier: `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue`

- Focus visible sur les cartes `Sur place` / `À emporter`.
- Transitions explicites au lieu de `transition: all` sur les petits controles.

Impact UX: meilleur confort tactile/clavier et moins d'animations globales non maitrisees.

### 3. Catalogue

Fichier: `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`

- Prix en chiffres tabulaires.
- Total bas de page en chiffres tabulaires.
- `Payer` vide passe sur surface neutre/desactivee au lieu de rester rouge.

Impact UX: le CTA rouge reste reserve a une action possible; le client comprend mieux qu'il faut ajouter un article.

### 4. Recap wizard

Fichier: `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue`

- Sections recap plus contrastees.
- Total recap en bloc rouge plein.
- Supplements avec quantite `xN` et prix de ligne.
- Boisson affichee depuis `_boissonMeta` quand disponible.

Impact UX: le client relit sa commande plus vite avant ajout panier.

## Audit page par page

| Page | Etat apres polish | Validation |
| --- | --- | --- |
| Idle | Fond rouge/dark fort, deux choix clairs, focus visible, statut reseau compact. | PASS |
| Categories | Cartes produits lisibles, prix stables, categories rapides visibles, Payer vide neutre. | PASS |
| Wizard viande | Selection par carte fonctionne, compteur `2 / 2`, message "Complet", + disponible pour repetition. | PASS |
| Wizard sauce | 1 sauce gratuite, supplements affiches, total live passe de `€8,50` a `€9,50` apres 2 sauces payantes. | PASS |
| Wizard crudites | Inclusion par defaut comprehensible, cartes cochees, instruction claire. | PASS |
| Wizard supplements | Cartes payantes lisibles, Boursin ajoute au total local. | PASS |
| Wizard menu | `Boisson seule` existe et ouvre une liste de 8 boissons; selection Capri-Sun testee. | PASS |
| Recap | Total local `€11,70`, detail "Avec boisson (Capri-Sun)", sauces payantes et supplement visibles. | PASS |
| Panier | Ligne commande detaillee, total `€11,70`, CTA `Valider ma commande`. | PASS |
| Upsell | Etape intermediaire logique; `Non merci` permet de continuer. | PASS |
| Paiement | Total affiche `€11,70`, cash selectionne, confirm visible. | PASS visuel |
| Waiting | Commande creee, numero `A0012`, attente lisible. | PASS visuel, P0 total query mismatch |

## Captures clefs

- `reports/ux/screenshots/global-ux-pw2-idle-2026-04-27.png`
- `reports/ux/screenshots/global-ux-pw2-categories-2026-04-27.png`
- `reports/ux/screenshots/global-ux-pw2-viande-complete-2026-04-27.png`
- `reports/ux/screenshots/global-ux-pw2-sauce-selected-2026-04-27.png`
- `reports/ux/screenshots/global-ux-pw2-menu-drinks-2026-04-27.png`
- `reports/ux/screenshots/global-ux-pw2-menu-drink-selected-2026-04-27.png`
- `reports/ux/screenshots/global-ux-pw2-recap-2026-04-27.png`
- `reports/ux/screenshots/global-ux-pw5-payment-cash-selected-2026-04-27.png`
- `reports/ux/screenshots/global-ux-pw5-after-cash-confirm-2026-04-27.png`

## Validation technique

Build:

```bash
npm run production
```

Resultat: PASS, Laravel Mix compiled successfully.

Vitest cible:

```bash
npx vitest run tests/js/KioskWizard.spec.js tests/js/KioskCategoriesRestyle.spec.js tests/js/KioskCartRestyle.spec.js tests/js/KioskPaymentRestyle.spec.js tests/js/kioskA11yAxe.spec.js tests/js/kioskDrinkAddons.spec.js tests/js/kioskWizardNavigation.spec.js
```

Resultat: PASS, 7 files / 133 tests.

Playwright local:

- idle -> Sur place
- catalogue Tacos
- Tacos L
- Merguez + Kefta
- Ketchup + Mayonnaise + Algerienne
- Crudites par defaut
- Boursin
- Boisson seule + Capri-Sun
- recap
- panier
- upsell skip
- paiement cash
- waiting

Resultat: PASS jusqu'a waiting, avec warning fonctionnel ci-dessous.

PHP cible quote/kiosk:

```bash
php artisan test --filter='KioskQuoteIntegrityTest|KioskQuoteTokenRequiredOnCommitTest|KioskFullFlowE2ETest'
```

Resultat: PASS, 7 tests. Les tests existants ne couvrent pas encore le cas compose `Boisson seule + sauces payantes + supplement`, d'ou le sentinel recommande plus bas.

Console attendue en environnement local:

- CSP report-only via meta ignore par Chromium.
- WebSocket `127.0.0.1:6001` refuse, donc pastille "Reconnexion en cours" visible. Le flow HTTP reste fonctionnel.

## Finding P0 hors polish UX

ID: KIOSK-P0-QUOTE-MENU-ADDONS-MISMATCH-2026-04-27  
Severite: P0 pricing / SSOT  
Repro Playwright: `global-ux-pw5-*`

Observation:

- Panier et paiement affichent `€11,70`.
- Paiement cash confirme avec CTA `Confirmer — €11,70`.
- Apres confirmation, URL finale:

```text
http://127.0.0.1:8000/kiosk/waiting/13?queue=A0012&total=9.5
```

Interpretation probable:

- Le total local inclut base `€8,50` + deux sauces payantes `€1,00` + Boursin `€1,00` + boisson seule `€1,20` = `€11,70`.
- Le total quote/commit utilise pour la navigation waiting semble ne garder que `€9,50`, probablement base + Boursin, sans les supplements de sauce et menu/boisson.
- Le code frontend ne doit pas envoyer un prix calcule client pour corriger cela: le backend doit rester SSOT. Il faut transmettre des choix structurants non financiers, ou faire calculer ces selections par `OrderQuoteService` depuis les IDs/metadonnees.

Allowlist recommandee pour mission suivante:

- `resources/js/store/modules/kioskCart.js`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `app/Services/Order/OrderQuoteService.php`
- `app/Services/FrontendOrderService.php`
- `tests/Feature/KioskQuoteIntegrityTest.php`
- `tests/Feature/KioskQuoteTokenRequiredOnCommitTest.php`
- `tests/js/KioskWizard.spec.js`
- nouveau test sentinel: `tests/Feature/Sentinels/KioskMenuAddonQuoteParitySentinelTest.php`

Critere de sortie attendu:

- Meme parcours `Boisson seule + sauces payantes + supplement` donne le meme total sur recap, panier, paiement, quote backend, order final et waiting.
- Aucun prix frontend n'est accepte comme source de verite.

## Decision release UX

L'amelioration UX peut rester: elle est frontend-only et les tests ciblés passent.

La release commerciale ne doit pas etre declaree complete tant que le finding P0 ci-dessus n'est pas ferme, car il touche la confiance prix/commande. C'est un probleme plus important que les derniers details de couleur.

FINAL_VERDICT: UX_POLISH_PASS__RELEASE_BLOCKED_BY_PRICING_SSOT_P0
