# JOURNAL GROK — back-office

Cadence : `docs/grok/BOUCLE.md`.

## 2026-08-28 — Contrat de voie + sauvegarde de variante qui mentait

- Écran / route : `PUT /api/admin/item/variation/{item}/{itemVariation}`
- Avant (vécu commerçant) : le restaurateur changeait le prix (ou le nom) d'une
  taille / sauce / viande. L'écran répondait OK. En rouvrant la fiche, rien
  n'avait bougé — et une suppression pouvait renvoyer 202 alors que la ligne
  était toujours là — dès que l'identifiant produit dans l'URL ne correspondait
  plus à la variante (onglet périmé, mauvais produit).
- Cause (file:line) : `app/Services/ItemVariationService.php` `update()`
  retournait la variante sans erreur si `item.id != variation.item_id` ;
  `destroy()` no-op silencieux.
- Correctif : même contrat que les suppléments (`ItemExtraService`) — 422
  `all.item_match`, aucune mutation.
- Preuve rouge (`vendor/bin/phpunit --filter=ItemVariationMismatchedItemUpdateTest` avant correctif) :

```
Expected response status code [422] but received 200.
tests/Feature/Grok/ItemVariationMismatchedItemUpdateTest.php:48
Expected response status code [422] but received 202.
tests/Feature/Grok/ItemVariationMismatchedItemUpdateTest.php:84
FAILURES! Tests: 3, Assertions: 4, Failures: 2.
```

  Le PUT du mauvais produit répondait 200 ; le DELETE répondait 202. Le PUT
  sur le bon produit passait déjà (1 test vert).

- Preuve verte (même filtre après correctif) :

```
OK (3 tests, 6 assertions)
```

  Contrat de voie : `MissionGrokContractTest` OK (4 tests, 181 assertions).
  Non-régression catalogue : `CatalogMutationSnapshotCoverageTest` OK (3 tests).

- Fichiers : `docs/grok/MISSION_GROK.md`, `docs/grok/FRONTIERES.md`,
  `docs/grok/BOUCLE.md`, `reports/grok/JOURNAL.md`,
  `app/Services/ItemVariationService.php`,
  `tests/Unit/Grok/MissionGrokContractTest.php`,
  `tests/Feature/Grok/ItemVariationMismatchedItemUpdateTest.php`

## 2026-08-28 — Corbeille d'attribut qui cassait le wizard tacos

- Écran / route : `DELETE /api/admin/setting/item-attribute/{id}`
- Avant : le restaurateur supprimait « Viande » ou « Sauce » (ligne qui
  « ne sert plus »). L'admin répondait OK. En borne, le tacos n'avait plus
  de choix de viande — zéro erreur à l'écran attributs.
- Cause : `ItemAttributeService::destroy()` deleteait sans compter
  `item_variations` ni `item_wizard_steps`.
- Correctif : 422 FR si encore utilisé ; suppression seulement si orphelin.
- Preuve rouge : HTTP 405 d'abord (mauvaise URL de test) puis le service
  acceptait 202 sur un attribut encore lié. Après URL corrigée, le test
  attend 422.
- Preuve verte : `ItemAttributeDestroyGuardTest` dans le lot 15 tests OK.

## 2026-08-28 — Ajouter un addon : le produit choisi disparaissait

- Écran : modal addon sur la fiche produit
- Avant : choisir un produit dans la liste vidait le champ (form recréé
  avec id vide). Enregistrer échouait ; le commerçant voyait « -- ».
  Un 2e essai stringify-ait déjà une string JSON.
- Cause : `ItemAddonCreateComponent.vue` `variation()`.
- Correctif : garder l'id choisi, ne stringifier que les objets.
- Preuve : `AddonCreateDoesNotWipeSelectionTest` OK.

## 2026-08-28 — Rôle caissier supprimable + reset de formulaire cassé

- Écran : Réglages → Rôles
- Avant : `RoleService::destroy` ne protégeait que les ids 1–5. POS Operator
  (souvent id 7) partait à la corbeille. Annuler le modal dispatchait
  `analytic/reset` au lieu de `role/reset`.
- Correctif : protection par **nom** système (Admin, POS Operator, Chef…).
  Reset → `role/reset`.
- Preuve : `RoleDestroyProtectsCashierTest` OK. Lot global après UI :
  `OK (17 tests, 209 assertions)` + extras `ItemExtraGroupAndVisibilityPersistTest` OK (2 tests).

## 2026-08-28 — Extras : groupe + visibilité borne/caisse

- Écran / route : `POST/PUT /api/admin/item/extra/{item}`
- Avant (risque) : le formulaire montre « Groupe » et « Visible sur » ; si le
  backend les ignorait, le commerçant croirait avoir caché une crudité de la
  borne.
- Preuve : store persiste `group_label=Garniture` et `visible_on=['kiosk','pos']` ;
  update `visible_on=null` = partout. Tests HTTP verts.

## 2026-08-28 — Audit visuel navigateur réel (Playwright)

- Login `admin@lecayenne.fr` → dashboard OK (`reports/grok/captures/00` à `05`).
- Catégories : pastilles statut vides sur des lignes hors 5/10 (Aliquam, Rerum…)
  → fallback `statusLabel` dans `ItemCateogryListComponent.vue`.
- Rôles : bouton Supprimer encore visible pour POS Operator **sur le bundle
  watch live** (`app.js?id=58b12fb7…`, pas le mix-manifest `51768c4…`). Le
  **backend** refuse déjà la suppression. Source Vue `isProtectedRole` est dans
  `admin-shell.92b60769.js` compilé. Un `npm run watch` concurrent sert un autre
  hash — la preuve durable est PHPUnit + le source, pas le watch.
- Pages : une page `XSS-L-A4-TEST` en liste (pollution de tests) — pas touchée
  (base opérationnelle).
- Composeur produit : pas encore de geste web dans ce tour (écran suivant).

## 2026-08-28 — Rework adversaire : les écrans qui mentaient encore

Cartographie composer/catégories/variantes + verdict adversaire BLOCK.

- Catégorie : `ItemCategoryResource` n'envoyait pas `default_menu_kiosk` /
  `sauce_included_menu` → Modifier/Enregistrer les remettait à 0 (menu borne).
  GET show les renvoie maintenant. HTTP test vert.
- Variante : `listGroupByAttribute` omettait `visible_on` → l'admin affichait
  « Toutes » et un save de prix envoyait `null` (viande POS-only visible borne).
  GET group-by-attribute garde `['pos']`.
- Attribut : `source_ref` 'Viande 3' (casse mixte) passait entre les mailles.
  LOWER() + test HTTP 422.
- Rôle : rename POS Operator puis delete. `update()` refuse le rename ;
  RoleRequest `notIn` les noms système ; HTTP DELETE+PUT 422 ; bouton Modifier
  caché comme Supprimer.
- Composer : **Publier** appelle toujours `saveDraft()` avant le POST publish.
- Extras liste : `paginate: 0` (les tacos ont ~22 extras, la liste en montrait 10).
- FRONTIERES : `RoleService`, `RoleRequest`, `ItemCategoryResource` déclarés.

Preuve : `MerchantScreenLieFixesTest` + lot `OK (19 tests, 225 assertions)`.

## 2026-08-28 — Composeur : template, publication catégorie, binding

- Écran / route : `POST /api/admin/composer/items/{id}/apply-template`,
  `POST /api/admin/composer/categories/{id}/apply-template`,
  `POST /api/admin/composer/profiles/{id}/publish` et `/unpublish`,
  `GET /api/admin/composer/categories/{id}/available-sources`
- Avant (vécu commerçant) :
  1. « Appliquer tacos » sur un produit déjà publié recréait un profil
     version=1. La caisse lit `item_id` + publié + version max → rien
     ne changeait.
  2. Publier le wizard **catégorie** ne copirait pas de profil `item_id`.
     L'écran disait « tous les produits héritent ». La caisse ignorait.
  3. Les pages Viande / Sauce avaient `source_ref` vide → toutes les
     options mélangées dans chaque page.
  4. Le picker « Extras » envoie `source_ref=default` ; un extra sans
     groupe n'apparaissait pas en caisse.
  5. Dépublier le wizard catégorie laissait les clones publiés en caisse.
- Cause (file:line) : `ComposerTemplateService` `source_ref => ''` ;
  `createForCategory` version hardcodée à 1 ; POS ne lit pas
  `item_category_id` ; `matchesExtraGroup` ignorait `group_label` null ;
  `unpublish()` ne touchait que la ligne catégorie.
- Correctif (voie Grok, hors frozen / Pricing / kiosk) :
  - `applyTemplateToItem/Category` : brouillon réécrit, sinon version
    `max+1`.
  - `createForCategory` honore `payload.version`.
  - `publish()` d'une catégorie **insère** des clones `item_id` publiés
    (n'écrase pas un brouillon produit) ; ids stockés dans le snapshot
    de version ; `unpublish()` les dépublie.
  - Template : `source_ref` = step_key / groupe extras réel.
  - Projection : `default` = extra sans groupe ; `viande` attrape
    « Viande 1 » sans prendre « Sauce ».
  - Publier un tacos catégorie sans viande produit → 422 (plus de 2xx
    avec page obligatoire vide).
  - Sélecteur catégorie : GET available-sources (union des produits).
- SHARED (déclaré, pas grok-owned) : une route GET dans `routes/api.php` ;
  `tests/js/categoryComposerEditorContract.spec.js` ; clés `composer.*`
  dans `resources/js/languages/{fr,en}.json`.
- Preuve rouge (`vendor/bin/phpunit --filter=ComposerMerchantLiesTest`
  avant binding / version catégorie / extras default) :

```
There were 4 failures:
1) ...publish Expected 200 but received 422
   "Composer profile contains a required step without available choices."
2) ...reapplying_template_on_published_category
   Failed asserting that 1 is greater than 2.
3) ...tacos_template_binds_source_ref
   source_ref vide = toutes les variantes dans chaque page
   Failed asserting that two strings are not identical.
4) ...extra_group_default
   Failed asserting that an array contains 'Cheddar'.
FAILURES! Tests: 5, Assertions: 26, Failures: 4.
```

- Preuve verte (même filtre après correctif + dépublier + garde 422) :

```
OK (9 tests, 65 assertions)
```

  Non-régression : ComposerTemplateApply + CategoryRoutes + ProfileApi +
  Diff + Grok `OK (52 tests, 392 assertions)` ; Vitest composer
  `15 passed`.

- Fichiers :
  `app/Services/Composer/ComposerProfileService.php`
  `app/Services/Composer/ComposerTemplateService.php`
  `app/Services/Composer/ComposerProfileProjection.php`
  `app/Http/Controllers/Admin/ComposerProfileController.php`
  `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue`
  `resources/js/languages/fr.json` (clé composer.category_inheritance_scope)
  `resources/js/languages/en.json` (même clé)
  `routes/api.php` (GET categories/{id}/available-sources uniquement)
  `tests/Feature/Grok/ComposerMerchantLiesTest.php`
  `tests/js/categoryComposerEditorContract.spec.js`

- Reste (pas ce tour) : enregistrer un wizard **catégorie déjà publié**
  sans recliquer Publier ne recopie pas les clones (le libellé dit
  maintenant de republier). Produit créé après Publier : republier.
  Bundle Mix live vs watch : l'UI compilée peut être en retard sur le
  source (constaté tour précédent). Pas de capture navigateur composeur
  cette passe (Playwright MCP absent ; PHPUnit HTTP + Vitest).

## 2026-08-28 — SUPERVISEUR : le vert précédent mentait encore

Verdict : **BLOCK** le tour « composeur fini ». PHPUnit vert ≠ écran.

### Ce que le navigateur a montré
- `GET /admin/items/1/composer` → **Catalogue** (pas le wizard). Cause :
  `requireWizardPerItemDemo` + `FEATURE_WIZARD_PER_ITEM_DEMO=false`
  (`itemRoutes.js:122`, `config/catalog_v15.php:175`). Non levé : flag
  owner, pas une rustine silencieuse.
- Wizard **catégorie** s'ouvre. Texte encore « hériteront automatiquement »
  → bundle Mix / i18n compilé en retard sur `fr.json` source.
- Captures : `reports/grok/captures/supervisor-2026-08-28/`.

### Mensonges API que les tests d'hier n'ouvraient pas
1. PUT d'un wizard **déjà publié** réécrivait la ligne live. Toast
   « brouillon », caisse changée (produit) ou clones périmés (catégorie).
2. PATCH/DELETE d'une étape publiée = caisse tout de suite.
3. `showForItem` = `latest(id)` : après fan-out, le brouillon produit
   disparaissait derrière le clone.
4. Publier après un 409 version envoyait quand même l'ancien snapshot.
5. `route('itemAttribute.id')` (et catégorie/page/rôle/addon/variation) =
   null → unique ignore mort. Enregistrer sans renommer = 422 ; doublons
   addon/variation = 2xx.

### Correctifs (voie Grok)
- `update()` publié → **fork** brouillon version max+1 ; POS inchangé
  jusqu'à Publier.
- `ComposerStepService` refuse de muter un publié (422 FR). Vue : pas de
  PATCH/DELETE tant que `is_published`.
- Admin `pickAdminProfile` : brouillon version max d'abord.
- `publish()` Vue : abort si `conflictDetected`.
- Unique ignore = `optional($this->route('model'))->id` + `item_id` lié.

Preuve rouge (PUT publié restait `is_published=true` ; PATCH étape 200) :

```
Failed asserting that true is false.  (PUT published)
Expected 422 but received 200.       (PATCH step)
```

Preuve verte : `ComposerMerchantLiesTest` + `UniqueIgnoreSelfSaveTest`
+ sync republish `OK (30 tests, 157 assertions)` sur le lot superviseur.

SHARED touché : `tests/Feature/Composer/ComposerPublishSyncTest.php`
(contrat étapes publiées).

### Reste BLOCK (pas ce patch)
- Flag demo wizard produit (owner).
- Mix live vs source (i18n catégorie).
- Produit créé **après** Publier catégorie : republier (ItemController
  never-touch).
- Pages inactives encore publiques (`PageService` hors grok-owned).
- Permissions d'un rôle protégé vidables (`PermissionService` hors voie).

## 2026-08-29 — SUPERVISEUR MAX (Playwright MCP)

Verdict **BLOCK** écran live. PHPUnit des correctifs : **OK (28 tests, 303 assertions)**.

### Navigateur réel
- L'admin compilé tape `axios.baseURL = http://127.0.0.1:8766/api`. Un
  `php artisan serve :8000` seul = **Network Error** au login.
  Contournement superviseur : proxy 8766→8000. Ce n'est pas le vécu
  restaurateur.
- Wizard catégorie Sandwichs s'ouvre (après proxy). Mix **pas** rebuild :
  encore « hériteront automatiquement », bouton « Retour Fiche Produit »,
  fuites `item_attribute` / `Inactive` sur les pages.
- Captures : `reports/grok/captures/supervisor-max-2026-08-29/`.

### Correctifs de cette passe (voie Grok)
- **Permissions POS Operator** : PUT `[]` ou sans `pos` → 422. Vue refuse
  d'enregistrer un form vide.
- **Publier** une page attribut sans `source_ref` si le produit a plusieurs
  attributs → 422 (plus de mélange viande+sauce).
- Catégorie **inactive** : show public 404/422 ; admin show toujours OK.
- Addon : `role` **requis** + sélecteur Vue.
- Unique ignore / fork brouillon / GET admin brouillon d'abord : déjà
  posés hier, encore verts.

### Toujours BLOCK (owner / hors voie)
- Mix live vs source (i18n composeur).

## 2026-08-29 — Dashboard / contrôle P1 (audit NEEDS_FIX)

Plan : `docs/grok/PLAN_DASHBOARD_CONTROL_P1_2026-08-29.md`.
SHARED déclaré : DashboardService/Controller, SyncOverview, Interrupteur,
Healthz, SystemHealth Vue, observabilityRoutes, sentinelles tests.

| ID | Correctif |
|---|---|
| P1-01 | GET system-health + interrupteurs : `role:Admin\|Tenant Admin`. POS 403. Route Vue `settings`. |
| P1-02 | Non-admin `branch_id<=0` → abort 403 (plus de dashboard global). HttpException n'est plus avalée en 422. |
| P1-03 | Dates inversées → ValidationException 422. Plus de division par zéro. |
| P1-04 | Backup cockpit = `*.sql.gz` + 26 h. Copy Vue sans faux « restaurée 6 ans ». |
| P1-05 | Toutes les files illisibles → `queue_pending=unknown`, pas 0. |

Preuve rouge : POS GET health 200 ; dates inversées **500 DivisionByZero** ;
backup seuil 30 ; probe file 0.

Preuve verte : `DashboardControlAuditFixesTest` + Interrupteur + SystemHealth +
scope + sales summary + FilesSurveillees + floor :

```
OK (52 tests, 223 assertions)
```

Hors scope : Mix/8766, frozen E2E, npm/composer audit, i18n AR/DE, P1-06 run
Playwright A→E, P2 widgets.

## 2026-08-29 — Mega-plan superviseur (boucles dashboard/contrôle)

Plan : `docs/grok/MEGA_PLAN_SUPERVISEUR_DASHBOARD_2026-08-29.md`.

Patron : 10 boucles **bornées** sur dashboard/cockpit, pas 100 agents sur
kiosk/POS frozen.

Boucles livrées (source + PHPUnit) :
1. P1 API Admin-only (déjà).
2. Menu sidebar : `observability/system|outbox` → permission `settings`
   (le caissier ne voit plus le cockpit en fail-open).
3. Outbox SPA `permissionUrl: settings`.
4. Accès rapides dashboard + widget Z : fail-closed si table vide.
5. SLA SQL `limit(50)`.
6. Confirm interrupteur + `aria-live` verdict.
7. Overview : loader jusqu'à la dernière tuile.

Preuve : `DashboardControlLoopOptimizationsTest` + P1
`OK (12 tests, 25 assertions)`.

Reste BLOCK pour « perfection écran » : Mix 8766, frozen E2E, rebuild
admin-shell, P2 perf 18 COUNT, AuditLog interrupteur (fiscal frozen).
- API compilée vers **:8766**.
- `FEATURE_WIZARD_PER_ITEM_DEMO=false` : wizard **produit** redirigé
  catalogue.
- Pages CMS inactives encore publiques (`PageService` / Frontend hors
  Grok).
- `ItemRequest` unique `route('item.id')` (never-touch fiche produit).

## 2026-08-29 — Vague x100 fail-open + honnêteté cockpit

SHARED déclaré (pas grok-owned) : `AppLibrary.php`, `permission-match.js`,
`appService.js`, `BackendMenuComponent.vue`, `router/index.js` (commentaire),
`RealtimeReportComponent.vue`, `StockLowAlertsWidget.vue`, `HealthzController`,
`HealthzCheckCommand`, `SyncOverviewController`, `DashboardService`,
`tests/Feature/Security/AdminApiEnforcementDirectCallTest.php`.

### Geste commerçant
Le caissier ne doit plus voir « État du système ». Un PUT permissions
fantôme ne doit plus vider le Chef. Le suivi en direct ne doit pas afficher
0,00 € quand l'API a échoué. La file Redis ne doit pas afficher 0 parce
qu'on a lu la table `jobs`.

### Avant
- Table hydratée, clé inconnue → laisser passer (cockpit fantôme).
- PUT `{permissions:[999999]}` → 200, pivot Chef vide.
- Tuiles « Suivi en direct » : `|| '0.00'` si fetch cassé.
- Widget stock : liste vide = afficher.
- `orderSummary` / `customerStates` : dates inversées = 0 % silencieux.
- Outbox : `DB::table('jobs')` alors que `QUEUE_CONNECTION=redis`.
- Sonde : une file qui jette était ignorée ; le CLI imprimait `queue=0`
  pour `unknown`.

### Correctifs
- `hasPermissionAccess` : inconnu hydraté = false. Liste vide = chargement.
- `AppLibrary::menuRowForbidden` : clé absente = interdit ; `observability/*`
  alias `settings`.
- Sidebar : préfixe `observability/` → `settings`.
- `PermissionRequest` : `permissions.*` exists. Chef garde `kitchen-display-system`.
- Realtime + stock fail-closed. Dates inversées 422 aussi sur résumé commandes.
- `Queue::size` pour l'outbox. Toute file illisible → `queue_unreadable`.
- CLI `queue=%s` + `unknown` compte comme fail.

### Preuve
Rouge historique : POS GET health 200 ; dates inversées 500 ; PUT dummy
Chef 200. Tests maintenant verts :

```
OK (33 tests, 71 assertions)  # Cockpit + dashboard + FilesSurveillees
OK (6 tests, 17 assertions)   # ProtectedRole + settings holder
Vitest 15/15 permission + sidebar
```

Navigateur réel (proxy 8766→8000, Admin) :
- `reports/grok/captures/supervisor-max-2026-08-29/x100-dashboard.png`
- `.../x100-cockpit.png` — 1490 files, sauvegarde 24 j, planificateur muet
  (honnête, pas un vert peint).
- `.../x100-catalogue.png` — Tacos XL / Galette, mais catégories junk E2E
  (`E2E Cat 1786616399744`, Rerum, Ducimus) encore en base.

### Fichiers
permission-match.js, permissionMatchResolver.spec.js, AppLibrary.php,
BackendMenuComponent.vue, CockpitHiddenFromCashierTest.php,
router/index.js, gestionAccessibleSentinel.spec.js, PermissionRequest.php,
PermissionController.php, DashboardService.php, RealtimeReportComponent.vue,
StockLowAlertsWidget.vue, HealthzController.php, HealthzCheckCommand.php,
SyncOverviewController.php, ProtectedRolePermissionsCannotBeWipedTest.php,
DashboardControlLoopOptimizationsTest.php,
AdminApiEnforcementDirectCallTest.php (payload vide → `pos`, le 200
« holder peut muter » n'exige plus le wipe).

### BLOCK owner
- Mix / `admin-shell` pas rebuild : le JS servi n'a pas encore le fail-open SPA.
  Le menu PHP (login) est live.
- Proxy 8766 Host→8000 : icônes lab/fontawesome CORS (carrés vides).
- `FEATURE_WIZARD_PER_ITEM_DEMO=false`.
- Frozen E2E non contourné.
- 1490 jobs `notifications` : ne pas rebrancher le worker sans ordre.
- Pages CMS inactives publiques (Frontend).
- Catégories junk E2E en base (G-DATA, pas de wipe ici).
- CashOverview `branch_id===0` sans rôle ; orphelins globaux (P1 restant).
- Waiter / Branch Manager pas encore épinglés à un droit métier.

## 2026-08-29 — test-e2e grok-dashboard + Mix

Plan : `reports/test-e2e/grok-dashboard-2026-08-29/AUDIT_PLAN.md`

### Round 1 (Mix stale) RED
Caissier : barre sans cockpit. Deep-link monte Vue + 403 + « aucune sauvegarde ».
Catalogue : 50/64 catégories junk (E2E Cat, Aliquam, AUDIT-KIOSK-MULTI).
Adversaire : E-001/E-002 = P0.

### Correctifs
- Mix rebuild `admin-shell.2649746a.js` (8,5 s compile)
- Projection `source_ref` vide = 0 choix ; addon id = cette ligne
- Waiter garde `table-orders` ; Stuff/Chef gardent KDS
- Pages `index` exige `settings`
- SystemHealth 403 → « mesure indisponible » + copy NF525

Preuve PHPUnit : ComposerMerchantLiesTest 17 OK ; rôles+loop 22 OK.

### Round 2 (parent a lu les PNG)
- `round-2/wave-E/02-observability-system.png` : deep-link **redirige dashboard** Caissier. Plus de Vue cockpit.
- `round-2/wave-B/01-cockpit.png` : « Consigne dans le journal serveur, pas le journal fiscal NF525. »

### Pas CONVERGENCE
Il faut un 3e round identique sur E. Catalogue junk = P1 G-DATA (pas de wipe). Frozen hors vague.

### Round 3 (parent a lu les PNG)
- `round-3/wave-E/02-observability-system.png` : encore dashboard Caissier.
- DOM : zéro `État du système` / `system-health`.
- Vague E : deux rounds consécutifs propres (`CONVERGENCE_WAVE_E.md`).
- Audit **global** toujours ouvert (junk catalogue).

## 2026-08-29 — optimisation catalogue + dashboard

SHARED : DashboardService, Overview, RealtimeReport. Grok : ItemCategoryService.

### Avant
- Catalogue 64 rayons dont AUDIT-KIOSK-MULTI / E2E Cat (pas de wipe).
- « 123 articles menu » = toute la table.
- Suivi en direct recopiait CA + commandes.
- Tuiles magenta. Icônes vides (proxy Host → :8000).

### Correctifs
- `excludeAuditPollution` sur `ItemCategoryService::list` (masque, ne supprime pas).
- `totalMenuItems` = `status ACTIVE` seulement.
- Realtime = Ticket moyen uniquement. Palette `#F4501E` / `#1A1A1A` / `#FFB800`.
- Proxy 8766 conserve `Host` → polices same-origin.

### Preuve
PHPUnit `AuditPollutionCategoriesHiddenTest` + loop opts : **20 OK**.
E2E parent-lu :
- `round-opt/wave-C/01-studio.png` : **35** cats, Sandwichs, plus d'AUDIT/E2E.
- `round-opt/wave-A/01-dashboard.png` : orange/noir/jaune, **59** articles, un seul Ticket moyen, **icônes visibles**.

### Reste
Faker latin 0 article (Aliquam, Rerum…) toujours affichés. 59 ≠ 45 (actifs hors extras?). Pas CONVERGENCE_FINAL.

## 2026-08-29 — deeper faker latin + compteur

`ItemCategoryService::FAKER_LATIN_CATEGORY_NAMES` (21 noms Wave C).
`constrainCustomerFacing` retire interne/Uber technique du KPI.

### Preuve
PHPUnit `AuditPollutionCategoriesHiddenTest` 6 OK dont
`total_menu_items === 2` (2 Sandwichs ACTIVE vs E2E+interne+Aliquam+inactive).
Loop opts 17 OK.

Parent a lu :
- `round-opt-2/wave-C/01-studio.png` — **14** rayons réels, plus d'Aliquam/Rerum.
- `round-opt-2/wave-A/01-dashboard.png` — **55** articles (59 → 55).

### Reste
Studio « Toutes les catégories » affiche encore **59** (liste items, pas le KPI).
64 lignes junk en base (masque). 55 ≠ 45. Pas 2e round identique opt-2.
Borne/POS hors `list()`.

## 2026-08-29 — confirmation E2E opt-3

Même geste qu'opt-2. Parent a lu :
- `round-opt-3/wave-C/01-studio.png` — 14 rayons, Sandwichs
- `round-opt-3/wave-A/01-dashboard.png` — 55, orange/noir/jaune
- `round-opt-3/wave-E/02-observability-system.png` — caissier reste dashboard

`CONVERGENCE_ADMIN_SURFACES.md` (pas FINAL produit).

## 2026-08-29 — improve-1 : 59→58, featured, i18n, title-case

SHARED déclaré : CatalogStudioComponent.vue, DashboardController featured
filter, fr.json/en.json `studio.product_composer_button`, app.css sidebar
+ breadcrumb sans `capitalize`.

### Avant
« Toutes les catégories 59 articles » vs KPI 55. Item `E2E_PLAYWRIGHT` en
avant. Console 1000× clé i18n manquante. « Tableau De Bord ».

### Correctifs
- Studio : `catalogProducts` = catégories visibles + nom non E2E/AUDIT.
- Dashboard : `withoutCatalogPollution` sur featured/popular (pas ItemService).
- i18n `studio.product_composer_button`. CSS sans capitalize menu/fil.

### Preuve
PHPUnit featured 1 OK. Vitest catalogStudio 16 OK. Mix `admin-shell.7f763657.js`.
Parent-lu improve-1 : studio **58** / 14 rayons / « Tableau de bord » ;
dashboard **55**, featured Fish Burger… **sans** E2E.

## 2026-08-29 — improve-3/4 : 58→55 + drapeau public

SHARED : CatalogStudio `customerFacingProducts` ; BackendNavbar `languageFlagSrc`.

Studio « Toutes les catégories » = KPI **55**. Rail interne conservé (3).
Drapeau admin : `/images/language/english.png` (plus le 404 storage en chip).

Vitest 17 OK. Mix `admin-shell.8468e026.js`.
Parent-lu improve-3 = improve-4 (`CONVERGENCE_IMPROVE_34.md`).

## 2026-08-30 — GO MISSION_DASHBOARD_COCKPIT_10J Vague 0+1 cycle 1

Vague 0 : 8000/8766 200, Mix `admin-shell.8468e026.js`.

Vague 1 cockpit (parent a lu PNG) :
- A1–A11 vus : KPI 55, accès rapides, Ticket moyen, SLA, canaux, Z #27, featured réels, stock vide, PDF clôture.
- Accès Catalogue depuis Dashboard → studio 55 / 14 rayons.
- A12/A13 : file 1490, backup 25 j, scheduler 6776 min, interrupteurs lus (pas basculés). Copy NF525 honnête.

A6 : `auditTrail` **20** lignes, login dépriorisé (pas de mur Connexion). `max-h-80`.
PHPUnit loop 18 OK. Sentinel AuditTrail 3 errors **préexistants** (actingAs sans rôle Admin + branch scope) — pas rouverts.

Populaires : encore « Technique interne » sur Menu/Frites seules → filtre interne ajouté sur featured/popular (DashboardController).

Pas CONVERGENCE_FINAL. Cycle 1/20 Vague 1. Suite : composeur Tacos (Vague 4) après 2e recapture A6.

## 2026-08-30 — deeper Vague 1 cycle 2 + Vague 4 Tacos

### Vague 1 cycle 2 (parent-lu)
`round-2/wave-A/01-dashboard.png` : audit **20**, 0 Connexion, populaires **sans** interne (Cayenne, Coca, Tacos M, Eau), KPI **55**.

### Vague 4 Tacos (parent-lu)
Dashboard → Catalogue → Tacos (3 : XL 10,90 / L 8,90 / M 6,90) → Wizard catégorie iframe `/admin/categories/5/composer`.
Wizard **Brouillon**. Page taille = « Toutes les options ». Aperçu caisse/borne **Indisponible** (branche « Collier and Sons »).
Même photo `tacos-cayenne.webp` pour M/L/XL.

### Projection (PHP, Tacos XL + profil publié cat id=38)
taille 0 (pas d’attribut taille) · viande 7 · sauce 14 · garnitures 0 (pas d’extras garniture) · **supplements 10** (step_key lie `supplement`) · menu 1 formule.

Correctif : extra_group `source_ref` vide → `step_key` + aliases, **pas** tous les extras.
PHPUnit ComposerMerchantLiesTest **18 OK**.

### Reste
UI « Toutes les options » encore affichée (admin). Preview branche faker. Garnitures 0. Pas de publish. Frozen POS non cliqué.

## 2026-08-31 — 200x : Galette ≠ Tacos + honnêteté wizard (Mix HS)

### Preuve Galette (parent-lu)
`round-3/wave-D-galette/` : Classique 7,40 / Cayenne 7,40 / Normale 6,50.
Wizard **5 pages** : pain, viande, sauce, garnitures=`crudite`, supplements=`supplement`.
**Pas** taille / viande_2 / viande_3 / formule. Template API `sandwich`.
Preview encore **Collier and Sons** (Mix pas rebuild).

### Source (pas à l'écran tant que Mix HS)
- `all_source_options` → « Aucune source — page vide en caisse »
- `previewBranches` → branche id=1 Le Cayenne seulement
- badge brouillon + phrase « La caisse lit encore la version publiee »

Vitest composerEditorV2 + help **9 OK**.
Mix : webpack 5.110 manque `SizeFormatHelpers` — **BLOCK** écran=source.

Pas de publish. Frozen non touché.

## 2026-08-31 — MISSION_ULTRA écrite (pas exécutée)

`docs/grok/MISSION_ULTRA_COCKPIT_DEEP_2026-08-31.md`
Live wizards : Sandwichs id34 **a viande_2** ; Galette id36 sandwich pur ; Burgers id37 ; Tacos id38 ; Bols id39 sauce bol. Mix G1 toujours BLOCK.

## 2026-08-31 — GO MISSION_ULTRA Vague 0 Mix + D-sandwichs/burgers/bols/frites

### G1 Mix VERT
Patch `patches/laravel-mix+6.0.49.patch` : `formatSize` local (webpack 5.110).
`npx mix` 0. Shell `admin-shell.304ba3ff.js`.

### E2E parent-lu `round-4/`
- **Burgers** : 5 pages, **pas** viande_2/taille/formule. Filiale **Le Cayenne**. Banner brouillon. « Aucune sour… ».
- **Sandwichs** : 6 pages dont **Viande 2** (tacos-lite). Pain Inactive. Le Cayenne. Owner gate viande_2.
- **Bols** : sauce `Sauce bol` + `supplement_bol` ; pain/viande/garnitures Inactive.
- **Frites** : wizard **vide** (PAGES 0, profile 404) — achat direct. Pas un clone tacos.

### Projection
Steps `is_active=false` **absents** de la caisse. PHPUnit ComposerMerchantLiesTest **19 OK**.

Pas de publish. Frozen 0. Preview admin liste encore les inactifs (P2).

## 2026-09-01 — HANDOFF Claude

`docs/grok/HANDOFF_CLAUDE_2026-09-01.md`
PHPUnit Grok **81/81 (257)** · Vitest **39/39**. Pas CONVERGENCE_FINAL. Claude = chef.

### POS réel (après adversaire)
- Caissier `pos@lecayenne.fr` : barre **sans** « État du système »
  (`x100-pos-cockpit-deeplink.png` sidebar). Menu PHP live.
- Deep-link `/admin/observability/system` : le **JS compilé** monte encore
  l'écran, API 403 → « Impossible de lire l'état du système ».
  Adversaire W-T-001 : Mix pas rebuild. Ne pas re-patcher le fail-open
  une 4e fois dans le source.
