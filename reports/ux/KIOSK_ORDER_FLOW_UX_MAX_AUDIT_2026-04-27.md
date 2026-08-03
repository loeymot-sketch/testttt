# KIOSK_ORDER_FLOW_UX_MAX_AUDIT_2026-04-27

Date: 2026-04-27T01:43:10+02:00  
TASK_ID: KIOSK-ORDER-FLOW-UX-MAX-2026-04-27  
EXECUTE_DELEGATION: codex-extension  
Scope: borne active Vue, parcours commande depuis categories jusqu'a ajout panier.

## Verdict

UX_VERDICT: PASS_WITH_EXTERNAL_SAFETY_BLOCKER

Le parcours borne critique est fonctionnel et plus fluide jusqu'a l'ajout panier:

- La page categories a maintenant une navigation rapide horizontale type prototype, en plus de la colonne categorie.
- Le choix viande ne demande plus de viser le bouton `+` pour la premiere selection: toucher la carte selectionne l'option.
- Le bouton `+` apparait et sert uniquement apres selection, pour repeter la meme viande ou le meme supplement.
- Les supplements suivent la meme logique: carte = premiere selection, controle `- 1 +` = ajustement.
- Les totaux live restent coherents sur sauces, supplements x2, menu complet, boisson et recap.
- Aucun fichier backend, paiement, migration, D-M13, branch isolation ou pricing SSOT n'a ete modifie dans cette passe.

Blocage externe non cree par cette mission:

- `.cursor/hooks/safety-check.sh` reste en HALT sur `app/Services/OrderService.php` deja staged en frozen zone. Cette mission ne touche pas ce fichier.

## Audit Avant

### Page categories

Probleme utilisateur:

- L'ecran actif etait fonctionnel, mais la navigation haute ne portait pas encore l'intention visuelle du prototype valide: categories plus grandes, plus tactiles, plus rapides a scanner.
- Le client devait surtout se reperer par la colonne gauche, utile mais moins naturelle quand l'oeil est deja dans la zone produit.

Decision:

- Ajouter une bande horizontale de categories dans la zone produit, avec image, label court, et etat actif fort.
- Garder la sidebar existante pour ne pas casser le flow et les tests.
- Corriger l'accessibilite: le bandeau rapide a un label landmark different de la sidebar.

### Etape viande

Probleme utilisateur:

- Le premier choix demandait de cliquer sur `+`, ce qui force une precision inutile sur borne tactile.
- Pour un tacos 2 viandes, le comportement mental attendu est: je touche Merguez, puis si je veux deux fois Merguez je touche `+`.

Decision:

- Carte non selectionnee = surface tactile de selection.
- Carte selectionnee = affichage du compteur `- 1 +`.
- Deuxieme tap sur la carte ne duplique pas silencieusement; seul le bouton `+` repete la meme viande.
- Le quota inclus reste bloque par le meta `_tailleMeta.viandeCount`, sans heuristique locale nouvelle.

### Etape sauces

Etat:

- La selection carte etait deja naturelle.
- Le probleme principal signale precedemment etait le total live; il reste couvert par les tests de running total.

Correction de qualite:

- Suppression des transitions CSS `all` pour eviter des effets non maitrises.

### Etape crudites

Etat:

- Le modele est coherent pour le client: tout est inclus, toucher une carte retire/remet, et le trait visuel exprime "sans".
- Pas de changement logique dans cette passe.

### Etape supplements

Probleme utilisateur:

- Meme friction que viande: viser `+` pour le premier choix n'est pas optimal.
- Besoin explicite: pouvoir prendre 2 fois le meme supplement apres l'avoir choisi.

Decision:

- Carte non selectionnee = premiere selection.
- Carte selectionnee = compteur `- 1 +`.
- Fallback visuel generique des supplements remplace par une icone nourriture, pas par un `+`, pour eviter la confusion.

### Etape menu / boisson

Etat:

- Les choix `Menu complet`, `+ Frites`, `Boisson seule`, `Sans menu` sont presents.
- La liste boisson est alimentee par les donnees existantes du catalogue/central, pas par un faux choix statique.
- La selection sauce frites bloque correctement `Suivant` tant qu'aucun choix n'est pris.

Correction de qualite:

- Suppression des transitions CSS `all` sur cartes menu et boisson.

### Recap / ajout panier

Verification browser:

- Recap d'un Tacos L: Merguez x2, sauces Ketchup + Mayonnaise, supplements Jambon de dinde x2, menu complet, Capri-Sun, sauce frites Ketchup.
- Total final verifie dans le navigateur: `€14,00`.
- Ajout panier effectif: bottom bar passe a `1 article`, total `€14,00`.

## Fichiers Modifies

Code source Vue:

- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue`

I18n:

- `resources/js/languages/fr.json`
- `resources/js/languages/en.json`

Tests:

- `tests/js/KioskWizard.spec.js`
- `tests/js/KioskCategoriesRestyle.spec.js`

Build assets generes par `npm run production`:

- `public/js/kiosk-shell.js`
- `public/js/kiosk-wizard-step.js`
- `public/js/kiosk-wizard.js`
- `public/mix-manifest.json`

Preuves navigateur:

- `reports/ux/screenshots/kiosk_wizard_viande_card_first_2026-04-27.png`
- `reports/ux/screenshots/kiosk_categories_after_add_to_cart_2026-04-27.png`

## Validation

Commandes executees:

- `npx vitest run tests/js/KioskWizard.spec.js tests/js/KioskCategoriesRestyle.spec.js`  
  Resultat: 2 files passed, 104 tests passed.
- `npx vitest run tests/js/Kiosk*.spec.js tests/js/kiosk*.spec.js`  
  Resultat final: 64 files passed, 560 tests passed.
- `npx vitest run tests/js/KioskWizard.spec.js tests/js/KioskCategoriesRestyle.spec.js tests/js/kioskA11yAxe.spec.js`  
  Resultat: 3 files passed, 109 tests passed.
- `npm run production`  
  Resultat: compiled successfully.
- `git diff --check -- <fichiers touches>`  
  Resultat: PASS.
- `.cursor/hooks/safety-check.sh`  
  Resultat: HALT externe sur `app/Services/OrderService.php` deja staged/frozen.

Notes de bruit non bloquantes observees pendant Vitest:

- `baseline-browser-mapping` ancien.
- `vuex unknown action/getter` dans certains tests isoles.
- `axios unavailable` dans tests de preview pricing.
- `ECONNREFUSED ::1:3000` dans un test axe qui mocke partiellement l'app. Les tests passent.

## Audit Apres

### Invariants FoodKing

- Pricing SSOT: PASS. Aucune nouvelle logique metier de prix ajoutee; les totaux utilisent les helpers existants et le preview serveur quand present.
- branch_id isolation: NOT_TOUCHED.
- OrderStatus enum: NOT_TOUCHED.
- Dispatch after commit: NOT_TOUCHED.
- OrderService / FrontendOrderService symmetry: NOT_TOUCHED.
- D-M13 / queue_number: NOT_TOUCHED.
- Paiement: NOT_TOUCHED.

### UX Psychologie Client

- Categories: l'utilisateur voit immediatement la categorie active et peut changer sans quitter la zone produit.
- Viande: l'action primaire est maintenant le geste naturel "je touche ce que je veux". La repetition est volontaire via `+`, donc moins d'erreur.
- Sauce: le client voit vite les choix, le total live monte quand une deuxieme sauce payante est ajoutee.
- Crudites: le modele "inclus par defaut, je retire ce que je ne veux pas" reste le plus rapide pour borne.
- Supplements: la premiere selection est plus rapide; la repetition est visible et reversible.
- Menu: la boisson n'est pas un faux produit "boisson seule"; les boissons reelles apparaissent ensuite.
- Recap: les quantites multiples sont lisibles (`x2`) et le total final correspond au panier.

## Reste A Traiter Plus Tard

Hors scope volontaire de cette passe:

- Cart page et paiement complet: phase suivante.
- Images catalogue placeholder: plusieurs produits affichent encore des visuels generiques ou anciens. Il faut corriger les assets depuis le back-office/seed central, pas coder des prix ou listes en dur.
- Etat offline local: le navigateur live affichait `Connexion perdue — hors ligne`; le flow reste utilisable, mais le serveur/API local devrait etre controle pendant UAT.
- Phase hardware: imprimante, tiroir, TPE/paiement simule puis reel.

FINAL_VERDICT: PASS_WITH_EXTERNAL_SAFETY_BLOCKER
