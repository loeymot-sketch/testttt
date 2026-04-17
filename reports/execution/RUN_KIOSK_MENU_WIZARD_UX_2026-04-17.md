# RUN — Kiosk menu wizard UX (borne) — 2026-04-17

## Objectif

Corriger le flux « Formule » sur la borne : ne plus proposer **Boisson seule** à côté de Menu complet / Frites pour les repas type sandwich-burger-tacos ; regrouper **upgrade frites** (extras catalogue) puis **choix boisson** puis **sauces frites** alignées sur le **catalogue** (variations sauce), avec validation et récap cohérents.

## Changements techniques

### `KioskWizardComponent.vue`

- Prop conditionnelle `showBoissonOnlyMenuCard` : calcul `kioskShowBoissonOnlyMenuCard` — `false` pour templates `sandwich`, `burger`, `tacos`, et `assiette` avec `has_menu` ; passée à l’étape menu uniquement via `kioskMenuStepExtraProps` (évite les props inconnues sur les autres étapes).
- `canAdvance` (étape `menu`) : si `menuChoice` est `full` ou `frites`, exiger au moins une entrée dans `fritesSauceOrder` (y compris `sans`).
- `shouldShowStep('supplements')` : exclure les extras identifiés comme **upgrade menu/frites** (`kioskIsBundledFritesMenuUpgradeExtra`) pour ne pas afficher l’étape suppléments si seuls ces extras payants restent.
- `kioskFritesSauceDisplayName` : résolution des clés `sauce-var-{id}` et des IDs via `kioskFindSauceVariation`, repli sur les clés i18n historiques.

### `KioskStepMenuComponent.vue`

- Carte **+ Boisson** masquée lorsque `showBoissonOnlyMenuCard === false`.
- Ordre des blocs : cartes formule → **upgrade frites** (radio + option « Frites classiques ») → boisson → sauces frites.
- Sauces frites : `kioskSauceVariationRowsForItem` + ligne **Sans sauce** ; repli sur la liste courte historique si l’item n’a pas d’attribut sauce.
- Upgrades : écriture dans `selections.supplements` (IDs exclus de l’étape suppléments) ; reset des upgrades si passage à **Sans menu** ou **Boisson seule** (quand visible).
- Texte « une boisson incluse » pour menu complet (i18n).

### `KioskStepSupplementsComponent.vue`

- Filtre des extras **upgrade menu/frites** (déjà présent en session) pour éviter le doublon avec l’étape menu.

### `KioskOrderSummaryComponent.vue`

- Libellés sauces frites : même logique que le wizard pour `sauce-var-*` et IDs ; affichage de **Sans sauce** si seule sélection `sans`.

### i18n

- `fr.json`, `en.json`, `ar.json` : clés `frites_upgrade_*`, `frites_sauce_hint`, `boisson_one_included`.

### Fichiers existants réutilisés

- `resources/js/helpers/kioskSauceCatalog.js` — catalogue sauce complet.
- `resources/js/helpers/kioskMenuBundledExtras.js` — heuristique upgrade frites/menu (noms type cheddar / crispy / frites / supplément ; `group_label` menu/frite).

## Caisse (POS)

Revue ciblée de `public/js/pos-wizard.js` : le POS filtre déjà les addons « Boisson seule » (formule) des vrais articles boisson et gère menu complet / frites / boisson. **Aucune modification POS** requise pour ce ticket ; la borne se rapproche de cette logique UX.

## Gestion produits / catégories (Dashboard vs borne vs caisse)

**Décision V1 (stabilité)** : une **seule source de vérité** catalogue côté **admin / dashboard** (API menu + migrations disponibilité). La borne et la caisse **consomment** la même API ; pas de double CRUD sur les deux terminaux dans cette itération (risque de divergence et de conflits de cache).

**V2 (si besoin métier)** : éventuellement écran « catalogue léger » sur caisse ou borne (sync serveur, rôles, audit), ou wizard unifié — à cadrer hors de ce correctif.

**Stock** : laisser pour une phase dédiée (V2) pour ne pas mélanger avec la stabilisation du parcours commande.

## Vérifications recommandées (manuel)

1. Article sandwich avec `has_menu` + addons boisson : pas de carte « Boisson seule » ; menu complet → upgrade (si extras catalogue) → une boisson → au moins une sauce frites.
2. Item avec attribut **Sauce** riche : la grille frites reflète toutes les variations actives (hors `status === 10`).
3. Récap : noms de sauces frites lisibles (pas seulement `sauce-var-…`).
4. Si tous les suppléments payants sont des upgrades menu : l’étape **Suppléments** disparaît ; prix upgrade toujours dans le total via `supplements`.

## Fichiers touchés (résumé)

- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue`
- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue`
- `resources/js/languages/fr.json`, `en.json`, `ar.json`
- `reports/execution/RUN_KIOSK_MENU_WIZARD_UX_2026-04-17.md` (ce fichier)
