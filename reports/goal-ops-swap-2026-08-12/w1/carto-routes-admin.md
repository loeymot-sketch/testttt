# Cartographie des routes admin — W1

**2026-08-12 · lecture seule · agent cartographe + vérification en base par l'orchestrateur**

## Volumétrie

| Mesure | Valeur |
|---|---:|
| Routes nommées `admin.*` | **140** (+3 redirections) = 143 URL admin |
| Modules de routeur | 41 |
| Imports dynamiques de vues | 154 — **0 non résolu** |
| Contrôleurs `Admin/*.php` | 97 |
| Endpoints `GET` admin sans paramètre sondés | 175 → **166 en 200** |

## Orphelins (route vivante, aucun lien depuis la barre latérale)

**A. Absolus — aucun lien nulle part**
1. `/admin/observability`, `/admin/observability/system`, `/admin/observability/outbox` (`observabilityRoutes.js:22,26-28,37-39`) — ni barre latérale, ni sous-menu réglages, ni raccourci du tableau de bord.
2. `/admin/ingredients/:type(attribute|extra|addon)` (`ingredientRoutes.js:16-18`)
3. `/admin/items/:id/composer` (`itemRoutes.js:109`), `/admin/categories/:id/composer` (`:139`), redirect `adminRoutes.js:3`

**B. Hors barre latérale mais atteignables ailleurs** — `/admin/pos-orders-tracker` (raccourci tableau de bord + PosComponent) · `/admin/pos/floorplan` · `/admin/cash-sessions-report` (alors que son jumeau `/admin/cash-overview` **est** en barre latérale) · `/admin/stock/rupture` · les 3 écrans de profil (barre de navigation) · `/admin/demo/wizard-launcher`

**C. Écrans de détail sans entrée** — 27, comportement normal.

**D. Masqués volontairement V1** — 29 écrans via `resources/js/config/v1-hidden-modules.js:11-55`. Routes toujours joignables par URL directe. **À trancher avec l'owner** : choix assumé ou oubli ?

## CONSTAT P0 — `/admin/uber-photo` : un écran en barre latérale sans aucune API

- L'entrée est en barre latérale : `BackendMenuComponent.vue:139`
- Les 4 routes `uber/photo/*` ont été **retirées** de `routes/api.php` (bloc explicatif `:398-410`, commit `590e1cc62`)
- Le contrôleur, le modèle, le fournisseur, la migration, le composant et le module de routes sont **non suivis par git**

**Ce n'est pas mon lot** : c'est l'écart arbre-de-travail / dépôt de la session Uber-photo, déjà décrit dans le message du commit `590e1cc62`. Signalé, non touché.

## CONSTAT P1 — `PERMISSION-URL-DESACCORDEE` : le menu promet ce que le serveur refuse

Le garde-fou du routeur (`router/index.js:82-83`) et celui de la barre latérale (`BackendMenuComponent.vue:293-296`) **laissent passer** quand aucune permission ne correspond — choix **délibéré et documenté** (« le backend reste l'autorité finale via 403 sur l'API »). Le repli n'est donc pas le défaut.

Le défaut est le **désaccord** entre ce que le routeur interroge et ce que la table stocke. **Vérifié en base réelle** (pas déduit des seeders) :

```
name=ingredients_manage   url=[NULL]
name=catalog.compose      url=[NULL]
name=items_create         url=[items/create]     ← le routeur demande "items_create"
url=ingredients_manage -> 0 ligne(s)
url=items_create       -> 0 ligne(s)
url=catalog.compose    -> 0 ligne(s)
```

**Conséquence prouvée par appel réel** (`/api/admin/ingredients`, jeton réel) :

| Compte | `admin/ingredients` |
|---|---|
| `pos@lecayenne.fr` (opérateur caisse) | **HTTP 403** |
| `chef@lecayenne.fr` (chef) | **HTTP 403** |

Or l'entrée « Ingrédients » est en barre latérale (`BackendMenuComponent.vue:114`, mappée `ingredients_manage` `:144`) et s'affiche **pour tous les rôles**, faute de correspondance. Idem « Scan facture » (`:116`, mappée `items_create`).

**C'est exactement la plainte owner** : un onglet visible, cliquable, qui ne mène à rien.

### Ancres du désaccord
- `database/seeders/IngredientPermissionSeeder.php:19-22` — `firstOrCreate` sans `url`
- `database/seeders/ComposerPermissionsMinimalSeeder.php:17-20` — idem
- `database/seeders/PermissionTableSeeder.php:37,39` — `items_create` porte `url='items/create'`
- Demandeurs : `ingredientRoutes.js:11,23` · `purchasingRoutes.js:15` · `itemRoutes.js:74,116,131,149`

## CONSTAT P2 — Rôles fantômes

**Rôles réels en base** : `Admin`, `Branch Manager` (sanctum **et** web), `Chef`, `Customer`, `Delivery Boy`, `POS Operator`, `Stuff`, `Waiter`, et **un rôle nommé littéralement « 3 »**.

- **`Tenant Admin` n'existe pas** — pourtant invoqué dans 12+ contrôleurs : `AdminController.php:22,34` · `ItemController.php:88,191` · `ItemPhotoController.php:28` · `ItemVariationController.php:122` · `ItemExtraController.php:111` · `ComposerProfileController.php:30,54` · `MenuProjectionController.php:56` · `Pos/CashDrawerSessionController.php:106` · `Observability/SyncOverviewController.php:62`. Toutes ces branches sont mortes.
- `ComposerPermissionsMinimalSeeder.php:12` et `IngredientPermissionSeeder.php:24` distribuent à `Tenant Admin` / `Branch Admin` / `Manager` — **trois rôles inexistants**.
- Le rôle **« 3 »** est du bruit de données à qualifier.

## CONSTAT P2 — Outbox : deux autorités qui ne s'accordent pas

`observabilityRoutes.js:43` exige `dashboard` (permission détenue par 5 rôles) ; `SyncOverviewController.php:62` exige `role:Admin|Tenant Admin`. Un Branch Manager franchit la porte du routeur et prend un 403. L'écran n'est de toute façon lié depuis nulle part (orphelin absolu A.1).

## Incertitudes restantes

1. Le contenu réel de la table `menus` n'a pas été lu (déduit de `MenuTableSeeder.php`).
2. `settings/MenuComponent.vue` lignes 150-190 non inspectées.
3. L'inventaire exhaustif des endpoints de `/admin/pos` n'est pas dans ce livrable (hors voie — session parallèle active).
