# Catalogue Studio — design permission matrix → Spatie (FoodKing)

**Sources** :

- UI design matrix (colonnes × 9 actions) : `audit-claude-ultra-review-2026-05-03/01-design-claude-v2/studio-iter2.jsx` lignes ~455–465 (`Iter10Perms` : `roles`, `actions` avec vecteurs `[super_admin, branch_manager, kitchen_manager]`).
- Rôles effectivement invoqués côté branche dans `AdminController` : lignes ~22–23, ~34–35 (rôles `Admin`, `Tenant Admin` pour contourner la contrainte `branch_id` sur certains scopes).
- Fil d’permissions **composer/catalog** des routes HTTP : `routes/api.php` (groupe `/composer` : middleware `permission:catalog.compose` et `catalog.publish`).
- Contrôleur items : `app/Http/Controllers/Admin/ItemController.php` (middleware Spatie sur `items`, `items_create`, `items_edit`, `items_delete`, `items_show`).
- Contrôleur catégories : `app/Http/Controllers/Admin/ItemCategoryController.php` (middleware `permission:settings` pour le CRUD catégories côté admin item category).
- Disponibilité (toggle) : `app/Http/Controllers/Admin/AvailabilityController.php` (`permission:items_edit`).
- Seeds permissions composer minimales et rôles cibles : `database/seeders/ComposerPermissionsMinimalSeeder.php` (`catalog.compose`, `catalog.publish` pour `Admin`, `Branch Manager`, `Tenant Admin`, `Branch Admin`).
- **Référence demandée sur `CLAUDE.md:67`** : dans cette version du dépôt, la ligne ~67 est le début du § « Role Separation » (rôles **d’agents** Claude / Cursor / Playwright dans le flux de repo), pas une liste **métier** des rôles Spatie — les **rôles applicatifs** réels sont donc tirés des seeders (`database/seeders/RoleTableSeeder.php`), de `ComposerPermissionsMinimalSeeder` et des `hasRole` / `authorize*` ci-dessus.

---

## 1. Mapping rôles (design Claude v2 → rôles Spatie réels)

| Rôle désign prototype (`studio-iter2.jsx`) | Rôle Spatie / convention FoodKing réelle | Commentaire court |
|---|---|---|
| `super_admin` | **`Admin`** et **`Tenant Admin`** | Pas de slug `super_admin` en prod ; équivalent « plateau / tenant » = bypass branche lisible dans `AdminController::authorizeBranchScope` et `authorizeWritableBranchScope`. |
| `branch_manager` | **`Branch Manager`** | Rôle seedé dans `RoleTableSeeder`. |
| `kitchen_manager` | **Aucun rôle équivalent sous ce nom** | **Gate produit** : à trancher (création de rôle, ou mapping explicite vers `Chef` / autre sans casser isolation). Ne pas créer de rôle ici depuis ce document. |

**Rôles Spatie utiles ailleurs (hors lignes prototype mais réels)** :

- **`Chef`**, **`POS Operator`**, etc. (`RoleTableSeeder`) — non présents dans la matrice Studio v2 trois colonnes.
- **`Tenant Admin`** : utilisé comme `Admin` pour le scope dans `AdminController` ; existence du rôle vérifiable en base selon jeu de seeders projet.
- **`Branch Admin`** : cité comme bénéficiaire de `catalog.compose` / `catalog.publish` dans `ComposerPermissionsMinimalSeeder`, mais **pas** inséré dans l’extrait `RoleTableSeeder` livré avec le fichier standard — validation produit nécessaire (seed partiel ou custom).

---

## 2. Mapping actions (9 intents design → permissions Spatie réelles)

Les clés suivantes correspondent aux `actions[].k` de `studio-iter2.jsx` (~456–466). Les noms `catalog.*` ci-dessous sont une **traduction fonctionnelle** du design (_namespace logique Catalogue Studio_), pas nécessairement des permissions Laravel existantes.

| Intent design (`k`) | Canal API / comportement FOODKING | Permission(s) Spatie à utiliser (SSOT gates) |
|---|---|---|
| `create_category` | CRUD `ItemCategoryController` admin | **`settings`** (middleware groupe CRUD catégories) |
| `edit_category` | idem | **`settings`** |
| `delete_category` | idem | **`settings`** |
| `create_item` | `ItemController::store`, import, duplicate | **`items_create`** |
| `edit_item` | `ItemController::update`, `changeImage` | **`items_edit`** |
| `delete_item` | `ItemController::destroy` | **`items_delete`** |
| `edit_wizard` | Routes sous `/composer/...` (sauf `publish` isolé) | **`catalog.compose`** |
| `publish_wizard` | `POST …/profiles/{profile}/publish` | **`catalog.publish`** |
| `toggle_availability` | `AvailabilityController::toggle`, `setMaxDailyQty` | **`items_edit`** |

**Pas de préfixe générique `item_categories_*`** dans les seeders analysés (`PermissionTableSeeder`) pour ce périmètre : le CRUD catégorie admin repose sur **`settings`**.

**Pas de préfixe `composer_*`** : les gardes catalogue composer exposées utilisent **`catalog.compose`** et **`catalog.publish`** (`routes/api.php`).

---

## 3. Permissions / rôles à créér — décision humaine requise (ne pas auto-créér)

| Sujet | Statut |
|---|---|
| Rôle **`kitchen_manager`** aligné prototype | Absent du `RoleTableSeeder` ; matrice colonne 3 **non représentée** sans décision métier (**gate pending**). |
| Rôle **`Brand Manager`** | Non présent dans le seed canon listé ci-dessus — **gate pending** si le design l’emploie ultérieurement. |
| **`Branch Admin`** pour composer | Mentionné comme cible catalogue dans `ComposerPermissionsMinimalSeeder` — confirmer qu’il existe en base (**gate pending** sinon). |

---

## 4. Exemples `<v-can :permission="...">` pour un futur Vue Catalog Studio

> Le code admin historique utilise souvent `permissionChecker('…')`. Les exemples ci-dessous figent **la même valeur Spatie** dans la forme directives demandée pour le Studio redesign.

```vue
<v-can :permission="'settings'"><button type="button">Créer catégorie</button></v-can>
<v-can :permission="'items_create'"><button type="button">Nouveau produit</button></v-can>
<v-can :permission="'items_edit'"><button type="button">Modifier fiche produit</button></v-can>
<v-can :permission="'catalog.compose'"><button type="button">Éditer le wizard composer</button></v-can>
<v-can :permission="'catalog.publish'"><button type="button">Publier le wizard</button></v-can>
```

Réutiliser pour **suppression** :

```vue
<v-can :permission="'items_delete'"><button type="button">Supprimer ce produit</button></v-can>
```

Pour **dispo / rupture**, réutiliser l’édition item :

```vue
<v-can :permission="'items_edit'"><button type="button">Basculer disponibilité branche</button></v-can>
```

---

_Fin du mapping — mise à jour 2026-05-04._
