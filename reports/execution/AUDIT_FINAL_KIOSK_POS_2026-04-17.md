# AUDIT FINAL — Borne + Caisse + décisions V1/V2 — 2026-04-17

Rapport récapitulatif orchestré autour des remarques utilisateur du 2026-04-17 et d’un audit court de parité borne/caisse + décisions d’architecture catalogue/stock.

## 1. Master task list (statut)

| # | Tâche | Statut |
|---|-------|--------|
| M1 | Borne — masquer « Boisson seule » sur sandwich/burger/tacos/assiette-menu | ✅ |
| M2 | Borne — upgrade frites (extras catalogue) avant boisson | ✅ |
| M3 | Borne — boisson incluse au menu complet (UI + i18n) | ✅ |
| M4 | Borne — sauces frites = catalogue complet + validation | ✅ |
| M5 | Borne — exclure upgrades menu/frites de l’étape Suppléments (doublon) | ✅ |
| M6 | Récap — libellés sauces frites `sauce-var-*` + « Sans sauce » | ✅ |
| M7 | i18n `fr` / `en` / `ar` — clés upgrade / hint / included | ✅ |
| M8 | Caisse — parité boisson seule sandwich/burger/tacos (pos-wizard.js) | ✅ |
| M9 | Décision CRUD produits/catégories dashboard vs caisse/borne | ✅ (voir §3) |
| M10 | Décision stock V1 vs V2 | ✅ (voir §4) |
| M11 | Tests Vitest — helpers (`kioskMenuBundledExtras`, `kioskSauceCatalog`) | ✅ |
| M12 | Rapport audit final (ce fichier) | ✅ |

## 2. Corrections appliquées

### Borne (Vue)

- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`  
  - `kioskShowBoissonOnlyMenuCard` (ne diffusé qu’à l’étape menu via `kioskMenuStepExtraProps`).  
  - `canAdvance` étape menu : exige `fritesSauceOrder` non vide uniquement si le catalogue expose des variations de sauce.  
  - `shouldShowStep('supplements')` : exclut les extras `kioskIsBundledFritesMenuUpgradeExtra`.  
  - `kioskFritesSauceDisplayName` : résolution `sauce-var-{id}` → nom catalogue, repli i18n.

- `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue`  
  - Masquage carte « + Boisson » si `showBoissonOnlyMenuCard === false`.  
  - Nouveau bloc **Upgrade frites** (radio : classique + extras catalogue) avant boisson.  
  - Bloc **Boissons** (« une boisson incluse » pour menu complet).  
  - Bloc **Sauces frites** basé sur `kioskSauceVariationRowsForItem` + « Sans sauce ».  
  - Hint de validation dédié (`kiosk-frites-sauce-validation-hint`) pour ne pas colliser avec boisson.  
  - Alias rétro-compatible `fritesSauceList` → `fritesSauceRows`.

- `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue`  
  - Filtre `kioskIsBundledFritesMenuUpgradeExtra` pour éviter doublon avec l’étape menu.

- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue`  
  - `fritesSauceRows` : retourne ligne « Sans sauce » seule si aucune sauce payante choisie.  
  - `fritesSauceLabel` : résolution `sauce-var-{id}` et IDs via catalogue.

### Caisse (JS natif)

- `public/js/pos-wizard.js`  
  - `buildSteps` : pour les catégories **sandwich / burger / tacos**, la carte formule « Boisson Seule » n’est plus poussée dans les étapes `supplements_menu` ni `menu_choice` (`_allowBoissonSeule = false`).  
  - Le rendu `renderMenuChoiceStep` ignore déjà `step.boissonSeule` quand `null` (if-guard existant).  
  - Liste des vraies boissons (`boisson_choice`) déjà filtrée (pré-existant).

### i18n

- `fr.json`, `en.json`, `ar.json` : clés `kiosk.wizard.menu.frites_upgrade_*`, `frites_sauce_hint`, `boisson_one_included`.

### Tests

- `tests/js/kioskMenuBundledExtras.spec.js` — 7 tests.  
- `tests/js/kioskSauceCatalog.spec.js` — 4 tests.  
- `tests/js/KioskWizard.spec.js` — 2 cas adaptés (`fritesSauceOrder: ['sans']` pour isoler le gating boisson).

Résultat : **126 / 128** passants. Les 2 échecs restants (`P5 detectTemplateFromName`) sont **antérieurs** à cette session (confirmé via `git stash`).

## 3. Décision architecture catalogue (M9)

### Contexte

Toutes les mutations catalogue passent aujourd’hui par les contrôleurs `Admin`:

- `Admin\ItemController` (produits)
- `Admin\ItemCategoryController` (catégories)
- `Admin\ItemVariationController` / `Admin\ItemAttributeController`
- `Admin\ItemExtraController` / `Admin\ItemAddonController`

Borne et caisse consomment :

- API `frontend/item` + `frontend/item-category` (vues allégées : `ItemResource`, `NormalItemResource`, `ItemAddonResource`, …).
- Disponibilité par branche via `AvailabilityService::toggle()` + table `item_branch_availability` + évènement `ItemAvailabilityChanged` + snapshot `MenuSnapshot`.

### Décision V1 — **Source unique = Admin/Dashboard**

Un seul endroit où l’on **crée, modifie, supprime** produits/catégories/variations : l’interface **admin/dashboard** (back-office). Borne et caisse sont des **clients en lecture**, plus l’action « rupture » déjà exposée via `AvailabilityService`.

**Justifications** :

1. **Une seule source de vérité** : évite les divergences de schéma et de prix à travers 3 surfaces.  
2. **Audit / traçabilité** : les mutations catalogue (prix, structure) sont déjà journalisées côté admin ; dupliquer l’UI dupliquerait les chemins d’audit.  
3. **Snapshot/projections** : `ItemAvailabilityChanged` + `MenuSnapshot` supposent un chemin d’écriture canonique.  
4. **Sécurité** : les caisses/bornes doivent rester en **droits restreints** (ne pas permettre la suppression d’une catégorie entière depuis une caisse publique).  
5. **Simplicité** : au lieu d’un CRUD cross-surface, la caisse et la borne reçoivent les mises à jour via les **projections** déjà en place.

### Scénarios autorisés en V1 depuis la caisse ou la borne

- **Activer / désactiver un produit** (rupture) → `AvailabilityService::toggle`, déjà présent, déjà émis en broadcast. À confirmer côté UI caisse (fonction existante dans le POS pour 86 produit).
- **Rien d’autre** sur le catalogue depuis ces surfaces.

### Roadmap V2 (hors scope immédiat)

- Écran « mini-catalogue » sur caisse : créer un produit « flash » (promo du jour), **écriture serveur**, rôle dédié, synchro via le même évènementiel menu (`MenuProjectionService`).  
- Wizard unifié caisse/borne : possible si on extrait la logique UI en composant partagé (Vue pour la borne, bridge JS pour la caisse actuelle ou réécriture Vue POS).

## 4. Décision stock (M10)

### Contexte

La gestion « stock » actuelle est en réalité un **toggle de disponibilité** (`ItemBranchAvailability` : `is_available` + auto-86 sur compteur daily). Pas de **décrément à la vente**, pas d’inventaire physique par article.

### Décision — **Reporter un vrai stock quantitatif en V2**

V1 conserve le modèle **rupture/disponibilité** (déjà stable et observé via `BumpMenuSnapshotOnItemAvailabilityChanged`).

**Justifications** :

1. Le stock **quantitatif réel** implique décrément atomique par ligne de commande (race conditions), rollback sur annulation/refund, réconciliation journalière, UI d’inventaire par branche, alertes de seuil, import/export — chacune est une tâche de taille.  
2. Le risque de régression sur **Order flow / Kitchen / Kiosk** est élevé ; stabiliser d’abord le parcours commande (objectif actuel : caisse + borne cohérentes).  
3. Le toggle manuel + auto-86 couvre ~80 % du besoin fonctionnel (un produit en rupture est basculé en un tap, tout le monde le voit).  
4. Ajouter un stock maintenant obligerait à retoucher `OrderStateMachine`, l’outbox domain events, les projections menu — toutes en V1 fraîches.

### Ce qui reste disponible en V1

- Toggle rupture depuis dashboard + POS (existant).  
- `ItemAvailabilityChanged` broadcast branch channel.  
- `MenuProjectionService` / `MenuSnapshot` mis à jour via `BumpMenuSnapshotOnItemAvailabilityChanged`.

### V2 — livrables à prévoir

1. Schéma `item_branch_stock` (quantité, low_threshold, reorder_point).  
2. Décrément transactionnel dans `OrderStateMachine` à la confirmation, réversible sur cancel/refund.  
3. UI admin : saisie de stock, historique mouvements, alertes.  
4. Surface caisse : réception manuelle (entrée stock) hors commande.  
5. Tests feature dédiés.

## 5. Santé du système (rapide)

Observations courantes en inspectant le working tree :

- **Outbox + Event contract** déjà en place (`app/Domain/Events/EventContract.php`, `DispatchDomainEventsJob`, tests `EventContractTest`).  
- **Menu projection** fraîchement introduite (`MenuProjectionService`, `MenuSnapshot`, `BumpMenuSnapshotOnItemAvailabilityChanged`) — cohérent avec la décision §3.  
- **Staff-only routing** couvert par `tests/Feature/StaffOnlyRoutingTest.php` + e2e `tests/e2e/06-staff-only-routing.spec.js`.  
- **Sécurité** : tests existants (`CorsTest`, `RateLimiterConfigTest`, `VHtmlStaticGuardTest`, `safeHtml.spec.js`) — confort V1.  
- **Tests JS** : 126/128 passants après cette session ; 2 échecs P5 pré-existants (template `detectTemplateFromName` burger) — à traiter indépendamment, non liés aux remarques UX.

## 6. Recommandations hors scope immédiat

1. **Fixer les 2 tests P5** (`KioskWizard.spec.js`, l. 1510 / 1525) : vérifier que `shouldShowStep('sauce')` ne dépend pas uniquement de `item.itemAttributes` (fallback template-based manquant).  
2. **Unifier borne et caisse** : extraction V2 d’un composant Vue partagé côté borne + bridge POS.  
3. **Audit prix** : un `PricingService` (`tests/Feature/Services/Pricing/PricingServiceTest.php` récent) — verrouiller par un invariant test « total récap borne == total kiosk cart == total commande persistée ».  
4. **Observabilité** : compléter le dashboard Grafana/Datadog si présent pour suivre `MenuSnapshot` bumps et latence `ItemAvailabilityChanged`.

## 7. Fichiers touchés (résumé session)

```
public/js/pos-wizard.js
resources/js/components/frontend/kiosk/KioskWizardComponent.vue
resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue
resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue
resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue
resources/js/helpers/kioskMenuBundledExtras.js  (ajouté en session précédente)
resources/js/helpers/kioskSauceCatalog.js       (ajouté en session précédente)
resources/js/languages/{fr,en,ar}.json
tests/js/KioskWizard.spec.js
tests/js/kioskMenuBundledExtras.spec.js         (NEW)
tests/js/kioskSauceCatalog.spec.js              (NEW)
reports/execution/RUN_KIOSK_MENU_WIZARD_UX_2026-04-17.md
reports/execution/AUDIT_FINAL_KIOSK_POS_2026-04-17.md   (ce fichier)
```
