# Z1bis — Audit du système de TVA (regard commerçant), lecture seule

Vérifié 2026-08-27. Serveur audité : shared DB `foodking_e2e` via requêtes SELECT read-only.

## 1. Le modèle

- Table `taxes` : `database/migrations/2022_11_17_110459_create_taxes_table.php:16-28`
  colonnes `id, name, code, tax_rate decimal(13,6), type tinyint, status tinyint, creator_*, editor_*, timestamps`.
  Pas de `branch_id` (les taxes sont globales, pas scopées par succursale).
- Modèle `app/Models/Tax.php:9-27` : `$fillable = ['name','code','tax_rate','type','status']`,
  relation `items()` (hasMany Item, filtrée `status=ACTIVE`).
- Rattachement à l'article : `items.tax_id` — `database/migrations/2022_11_17_110514_create_items_table.php:22`
  `$table->foreignId('tax_id')->nullable()->constrained('taxes');` — **nullable dès la migration**, FK simple sans `onDelete()` (donc RESTRICT par défaut MySQL).
- `app/Models/Item.php:25` (`fillable`) et `:55` (`casts`) confirment `tax_id` en entier nullable côté modèle.

## 2. Valeur par défaut — POINT CRITIQUE

- Formulaire de validation `app/Http/Requests/ItemRequest.php:53` :
  `'tax_id' => ['nullable', 'numeric', 'not_in:0']` — **tax_id n'est PAS obligatoire**. Comparer avec `name`, `item_category_id`, `price` qui sont `required` juste au-dessus (lignes 44-46).
- Formulaire admin `resources/js/components/admin/items/ItemCreateComponent.vue:39-46` : le label du champ Tax n'a **pas** la classe CSS `required` (contrairement à `name` L13, `price` L20, `item_category_id` L27 qui l'ont). `data()` initialise `tax_id: null` (L339, L361, L417), placeholder `"--"`.
- Calcul du prix à la commande : `app/Services/Pricing/PricingService.php:241-245` :
  ```php
  $taxId = (int) ($dbItem->tax_id ?? 0);
  $taxObj = $taxes[$taxId] ?? null;
  $taxRate = $taxObj ? (float) $taxObj->tax_rate : 0.0;
  ```
  `(int) null = 0`, aucun id de taxe n'existe à 0 → `$taxObj = null` → **`$taxRate = 0.0` silencieusement, aucune erreur, aucun log**. Le résultat (`tax_name=null, tax_rate=0, tax_amount=0`) est ensuite persisté tel quel dans `order_items` (PricingService.php:275-278).
- **Preuve empirique dans la DB partagée** : `items.id=162 "Article Uber (non mappé)"` a `tax_id = NULL` (requête SQL directe, 2026-08-27). Le mécanisme n'est pas théorique.

→ Un commerçant qui laisse le champ Tax vide en créant un produit obtient un produit **vendu à 0 % de TVA sans aucun avertissement**, ni au formulaire ni à l'encaissement.

## 3. L'écran de gestion des taxes — INACCESSIBLE PAR LE MENU

- `resources/js/config/v1-hidden-modules.js:39` : `'settings.tax'` figure dans `V1_HIDDEN_MENU_MODULES`.
- `resources/js/components/admin/settings/MenuComponent.vue:103` :
  `<router-link v-if="!isSettingHidden('tax')" ...>` — la condition est fausse, **le lien n'est jamais rendu**, quel que soit l'onglet Réglages ouvert.
- La route existe bien (`resources/js/router/modules/settingRoutes.js:399-419`, `admin.settings.tax` → `admin.settings.tax.list`) mais n'est atteignable que si l'utilisateur tape l'URL directement. **Clics depuis le menu : impossible (0 chemin de navigation), il faut connaître l'URL.**

## 4. Modification d'un taux existant (10 % → 20 %) — historique protégé, MAIS pas par un mécanisme dédié à la taxe

- `app/Services/TaxService.php:67-75` (`update()`) : `tap($tax)->update($request->validated())` — modifie uniquement la ligne `taxes`, ne touche à aucune commande.
- Les commandes stockent leur taux au moment de la création, en colonnes dénormalisées sur `order_items` (pas de jointure live) : migration `database/migrations/2023_07_20_095843_add_tax_to_order_items_table.php:17-20` (`tax_name, tax_rate, tax_type, tax_amount`), remplies une seule fois dans `PricingService.php:275-278`.
- Protection directe trouvée : `app/Models/OrderItem.php:44-56` (hook `updating()`) + trigger DB `2026_05_24_040211_add_composition_snapshot_immutability_trigger` (référencé dans `app/Console/Commands/FiscalInstallImmutabilityTriggersCommand.php:168-183`) — **ces deux gardes protègent nommément la colonne `composition_snapshot`, pas `tax_rate`/`tax_amount` en tant que telles**.
- Aucun code applicatif ne réécrit `order_items.tax_rate`/`tax_amount` après création (grep exhaustif sur `app/Services`, `app/Http/Controllers`, `app/Console` : aucun `OrderItem::...->update()` touchant ces colonnes hors du bloc de création). L'historique est donc protégé **en pratique** (rien ne le modifie, colonnes figées dès l'insert), mais **pas par un garde explicite dédié au taux** comme il en existe un pour `composition_snapshot`.

## 5. Suppression d'une taxe référencée par des articles

- `app/Services/TaxService.php:80-95` (`destroy()`) — logique inversée mais résultat correct :
  ```php
  $checkItem = $tax->items->whereNull('deleted_at');
  if (!blank($checkItem)) {          // des articles actifs référencent la taxe
      $tax->delete();                 // suppression tentée SANS désactiver les FK
  } else {                            // aucun article
      DB::statement('SET FOREIGN_KEY_CHECKS=0');
      $tax->delete();
      DB::statement('SET FOREIGN_KEY_CHECKS=1');
  }
  ```
  `Tax` n'a pas de `SoftDeletes` (`app/Models/Tax.php` — pas de trait) → `delete()` est un **DELETE physique**. Quand des articles référencent la taxe, la contrainte FK `items.tax_id → taxes.id` (RESTRICT par défaut, pas de `onDelete cascade`) fait échouer le DELETE ; l'exception est catchée et renvoyée en 422 (`TaxService.php:91-94`). **Suppression bloquée pour les taxes utilisées — mais par un accident de FK, pas par un check métier explicite** (le code, lu littéralement, tente activement la suppression dans ce cas).

## 6. Taux pré-remplis à l'installation

- `database/seeders/TaxTableSeeder.php:22-58` : 5 lignes — No-VAT 0 % (`VAT-0`), VAT 5 % (`VAT-5%`), VAT 10 % (`VAT-10%`), GST 5 %, GST 10 %. **Aucune ligne à 20 % dans ce seeder.**
- Confirmé en DB partagée : `id=1 No-VAT 0%`, `id=2 VAT 5%`, `id=3 VAT 10%`, `id=4 GST 5%`, `id=5 GST 10%`, et une 6e ligne ajoutée plus tard `id=7 "VAT 5.5" / VAT-5.5% / 5.5%` (hors seeder de base, origine non tracée dans ce périmètre).
- Correspondance France : le 10 % (sur place/à emporter) et le 5,5 % existent (id 3, id 7). **Le taux alcool 20 % n'est PRÉ-REMPLI NULLE PART** — un commerçant devrait le créer lui-même, sur un écran qui n'est pas dans le menu (§3).
- `config/menu.php:80-85` documente `default_tax_id = 3` (VAT 10 %) comme **fallback pour le seeder de menu Le Cayenne** (`MenuSeeder`), pas comme valeur par défaut appliquée à la création d'un article via l'admin (§2 le prouve : le formulaire admin n'assigne aucune valeur, `tax_id` reste `null` si non choisi).

## Synthèse (25 lignes max)

**Comportement par défaut** : `items.tax_id` est nullable partout (migration, modèle, validation `ItemRequest.php:53`, formulaire Vue sans astérisque `required`). Si le commerçant ne choisit rien, l'article se crée avec `tax_id = NULL`.

**Risque fiscal le plus grave, avec preuve** : `PricingService.php:241-245` — `$taxId = (int)($dbItem->tax_id ?? 0)` puis `$taxes[$taxId] ?? null` → objet taxe introuvable → `$taxRate = 0.0`. Aucune erreur, aucun log, aucun blocage : la commande se crée et se facture à 0 % de TVA, silencieusement. Preuve empirique en DB partagée : `items.id=162` a déjà `tax_id = NULL`. Aggravant : l'écran `/admin/settings/taxes` qui permettrait de créer/consulter les taux est **retiré du menu** (`v1-hidden-modules.js:39` + `MenuComponent.vue:103`), donc un commerçant qui découvre le problème après-coup ne peut même pas y accéder en cliquant — il doit connaître l'URL.

**Historique protégé ou non** : PROTÉGÉ dans les faits — `order_items.tax_rate/tax_amount/tax_name/tax_type` sont écrits une seule fois à la création (`PricingService.php:275-278`) et aucun code ne les réécrit ensuite (grep exhaustif négatif). Mais ce n'est pas un garde dédié : le hook `OrderItem.php:44-56` et le trigger DB `2026_05_24_040211_...` protègent explicitement `composition_snapshot`, pas les colonnes de taxe elles-mêmes — la protection du taux tient à l'absence de code qui le modifierait, pas à une contrainte qui l'interdirait.

**Suppression d'une taxe utilisée** : bloquée en pratique par la contrainte FK `items.tax_id → taxes.id` (RESTRICT, pas de cascade) quand `TaxService::destroy()` (`TaxService.php:80-95`) tente le DELETE physique sans désactiver les FK — mais la logique du code est inversée (elle désactive les FK dans le cas SANS articles, où ce n'est pas nécessaire) : le blocage est un effet de bord de la contrainte DB, pas un check métier volontaire.

**Seeder France** : 0 %, 5 %, 5,5 %, 10 % présents ; **20 % (alcool) absent du seeder**, à créer manuellement sur un écran retiré du menu.
