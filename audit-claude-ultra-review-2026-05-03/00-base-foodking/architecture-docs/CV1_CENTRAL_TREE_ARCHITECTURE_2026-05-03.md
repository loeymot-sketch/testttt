# CV1 — Architecture Centrale en Arbre — 2026-05-03

**Cycle :** `CV1-CENTRAL-TREE-ARCHITECTURE-001`
**Auteur :** Claude in-session orchestrator
**Demande user (2026-05-03 01:38) :** « centralisation comme une arbre qui donne synchronisation entre la borne, la caisse et toute la gestion de stock, ainsi que catégorie/produits/wizard. Liaison entre chaque chose. »

**Status :** Document de référence officiel pour V1 (consolide audits A.1 score 82/100 + A.3 score 84/100, 0 régression introduite par cycle wizard composable).

---

## §0 — Vision : la centralisation comme un arbre

```
                              ┌────────────────────────┐
                              │   SSOT BACKEND         │
                              │   (Laravel + MySQL)    │
                              │   Pricing • Stock      │
                              │   Catalog • Wizard     │
                              └──────────┬─────────────┘
                                         │
                       ┌─────────────────┼─────────────────┐
                       │   EVENTS (DispatchableAfterCommit) │
                       │   CatalogChanged                    │
                       │   ItemAvailabilityChanged           │
                       │   ComposerProfileChanged            │
                       │   StockLevelChanged                 │
                       │   OrderCreated                      │
                       └─────────────────┬─────────────────┘
                                         │
                       ┌─────────────────┴─────────────────┐
                       │   OUTBOX + ECHO BROADCAST          │
                       │   per branch.{id}                  │
                       └─────────────────┬─────────────────┘
                                         │
                ┌────────────┬───────────┼───────────┬────────────┐
                ▼            ▼           ▼           ▼            ▼
            ┌───────┐    ┌──────┐    ┌──────┐    ┌──────┐     ┌──────┐
            │ POS   │    │KIOSK │    │ KDS  │    │ OSS  │     │STOCK │
            │caisse │    │borne │    │cuisi.│    │écran │     │admin │
            │       │    │tactil│    │      │    │client│     │      │
            │PosSync│    │useCat│    │KdsSyn│    │      │     │      │
            │polling│    │Notifi│    │polling│   │polling│    │      │
            └───────┘    └──────┘    └──────┘    └──────┘     └──────┘
```

---

## §1 — Tableau SSOT (Source of Truth) par entité

| Entité métier | Table DB | Service "owner" | Events émis |
|---|---|---|---|
| **Item** (produit) | `items` | `ItemService` (`app/Services/ItemService.php:85+`) | `ItemCreated`, `ItemDeleted`, `ItemAvailabilityChanged` |
| **ItemCategory** (catégorie) | `item_categories` | `ItemCategoryService` (`app/Services/ItemCategoryService.php:111-187`) | `CategoryCreated`, `CategoryUpdated`, `CategoryDeleted` |
| **ItemVariation** (variation: taille S/M/L) | `item_variations` | `ItemVariationService` | `ItemAvailabilityChanged` |
| **ItemExtra** (extras: sauces gratuites) | `item_extras` | `ItemExtraService` | `ItemAvailabilityChanged` |
| **ItemAddon** (addons: boisson, dessert) | `item_addons` (FK `addon_item_id` → `items`) | `ItemAddonService` | `ItemAvailabilityChanged` |
| **ItemAttribute** (attributs partagés: viandes globales) | `item_attributes` | (admin direct) | — |
| **ItemBranchAvailability** (rupture/86 par branche) | `item_branch_availability` | `AvailabilityService` (`app/Services/Menu/AvailabilityService.php:34-154`) | `ItemAvailabilityChanged::forBranch` (after-commit ✅ T-CENT-AVAIL-DISPATCH-01) |
| **ItemWizardProfile** (wizard composer per produit) | `item_wizard_profiles` | `ComposerProfileService` (`app/Services/Composer/ComposerProfileService.php:82-164`) | `ComposerProfileChanged`, `ComposerProfilePublished` |
| **ItemWizardStep** (page personnalisable du wizard) | `item_wizard_steps` | `ComposerStepService` (`app/Services/Composer/ComposerStepService.php:15-37`) | `ComposerProfileChanged` (si profil publié) |
| **StockLevel** (niveaux stock par branche) | `stock_levels` (polymorphic stockable_type) | `StockService` (`app/Services/Stock/StockService.php:49-200`) | `StockLevelChanged`, `ItemAvailabilityChanged` (sync auto) |
| **StockMovement** (ledger mouvements) | `stock_movements` | `StockService` | — |
| **Order / OrderItem** | `orders`, `order_items` | `OrderService`, `FrontendOrderService` | `OrderCreated`, `OrderStatusChanged` |

---

## §2 — Tableau liaisons SSOT → 4 surfaces

| Surface | Endpoint API entrant | Service backend lecteur | Composant Vue principal | Vuex store | Echo events écoutés |
|---|---|---|---|---|---|
| **POS (caisse)** | `GET /api/admin/item?surface=pos`, `GET /api/admin/pos-category`, `POST /api/admin/menu/availability/toggle`, `POST /api/admin/menu/availability/max-daily-qty` (NEW T-DEEP-AVAIL-API-01) | `ItemService::simpleList`, `MenuProjectionService::forChannel('pos')` (gated flag `unified_projection.enabled`), `AvailabilityService::toggle/setMaxDailyQty` | `PosComponent.vue`, `ItemComponent.vue` (modal wizard), `pos-wizard.js` (single-page composer-aware gated T-WC-POS-RUNTIME-01) | `item`, `posCart`, `posCategory`, `itemAvailability`, **`composer`** (NEW T-WC-MENU-CATALOG-01) | `CatalogChanged`, `ItemAvailabilityChanged`, `OrderCreated`, `OrderPaidAtCounter` + fallback polling `PosSyncService` (gated flag `pos_fallback_polling.enabled`) |
| **Kiosk (borne)** | `GET /api/frontend/menu` (cached `kiosk.menu.branch.{id}`), `GET /api/frontend/item/details/{item}?surface=kiosk` (avec `composer_profile`) | `KioskMenuService::build`, `ComposerProfileProjection::project($profile, $item, 'kiosk', $branchId)` | `KioskAppComponent.vue`, `KioskWizardComponent.vue` (multi-pages avec `STEP_KEY_REGISTRY` explicite NEW T-WC-KIOSK-REGISTRY-01), 8 step components spécialisés + `KioskStepGenericChoicesComponent` (fallback step_keys arbitraires) | `kioskMenu`, `kioskCart`, `kioskSettings` | `CatalogChanged`, `ItemAvailabilityChanged`, `ComposerProfileChanged` via composable `useCatalogChangeNotifier.js` (toast + prune + `wizard:invalidate-step`) |
| **KDS (cuisine)** | `GET /api/admin/kds-order` (commandes, **pas** menu) | `KitchenDisplaySystemOrderService` | `KitchenDisplaySystemComponent.vue` | `kds`, `kitchenDisplaySystemOrder` | `OrderStatusChanged`, `OrderCreated`, `OrderPaidAtCounter`, `OrderTableChanged`, `ItemAvailabilityChanged` (refresh sur rupture). Polling commandes via `KdsSyncService`. **Gap V2 :** pas de polling catalogue. |
| **OSS (écran client)** | `GET /api/admin/oss-order` (commandes seulement) | `OrderStatusScreenOrderService` | `PreparingAndReadyComponent.vue` | `orderStatusScreenOrder` | `OrderStatusChanged`, `OrderCreated` uniquement. **Pas de catalogue** (par design — OSS = flux commande, pas menu). |

---

## §3 — Liaison catégorie → produit → wizard (le cœur user-demanded)

### Modèle hiérarchique

```
ItemCategory (id, name, slug, channels[], thumb)
    ├── 1:N → Item (id, name, price, category_id FK, channels[], composer_profile)
    │           ├── 1:N → ItemVariation (id, item_id FK, item_attribute_id FK, name, convert_price)
    │           ├── 1:N → ItemExtra (id, item_id FK, group_label, name, convert_price)
    │           ├── 1:N → ItemAddon (id, item_id FK, addon_item_id FK→Item, role, qty_required)
    │           └── 1:1 → ItemWizardProfile (id, item_id FK, template, branch_id_scope, version, is_published)
    │                       └── 1:N → ItemWizardStep (id, profile_id FK, step_key, label, source_type, source_ref, position, min_select, max_select, visible_on[], addon_role)
    │
    └── ItemAttribute (id, name) ← partagé entre items via ItemVariation
```

### Personnalisation wizard per-product (user demand)

**Pour chaque produit**, l'admin peut configurer le wizard via `ProductComposerEditorComponent.vue` (refonte `T-WC-EDITOR-01`) :

1. **Choisir un template starter** (`ComposerTemplateService::TEMPLATES` : `simple/sandwich/tacos/assiette/snacking/menu/custom`)
2. **Drag & drop pages** (vue-draggable-next sur `position`)
3. **Ajouter / retirer une page** (POST/PUT/DELETE step API)
4. **Configurer chaque page** :
   - `label` (texte client)
   - `source_type` ∈ `item_attribute` / `extra_group` / `addon`
   - `source_ref` (picker labeled depuis `GET /api/admin/composer/items/{id}/available-sources` NEW T-WC-SOURCE-PICKER-01)
   - `min_select`, `max_select` (sliders)
   - `visible_on` checkboxes (POS / Kiosk)
   - `is_active` toggle
5. **Preview live** (POS + Kiosk côte-à-côte) via `ItemPreviewComponent.vue` (M2 1.2)
6. **Publier** (`ComposerProfileService::publish` → version++ + `ComposerProfileChanged` broadcast → Kiosk live update)

### Exemple user : "Assiette pas de crudités"

**Setup :** Admin crée produit "Assiette Mixte" dans catégorie "Assiettes" avec template `assiette` :
- Steps pré-remplis : `viande`, `sauce`, `garnitures`
- Admin retire la page `garnitures` → step `is_active=false` ou DELETE step
- Publish

**Runtime Kiosk :**
- `GET /api/frontend/item/details/{id}?surface=kiosk` retourne `composer_profile` avec **2 steps actifs seulement** (pas garnitures)
- `KioskWizardComponent` itère `composerActiveSteps` → 2 pages affichées (pas de page "crudités")
- `STEP_KEY_REGISTRY` mappe `viande`→`KioskStepViandeComponent`, `sauce`→`KioskStepSauceComponent`

**Preuve sentinels :**
- `tests/Feature/Composer/ComposerTemplateApplyTest.php` (template `assiette` ne crée pas step `menu` ni `pain`)
- `tests/js/kioskWizardStepRegistry.spec.js` (mapping explicite step_key → component)
- `tests/js/composerEditorV2.spec.js` (8 cas couvrent header, drag&drop, templates, source picker, publish)

---

## §4 — Synchronisation : 7 paths bout-en-bout (validés audit A.3)

| Path | Description | Statut | Évidence |
|---|---|---|---|
| **A** | Admin crée/modifie produit → propagation 4 surfaces | ✅ OK | `ItemService` after-commit → `ItemAvailabilityChanged` → outbox → broadcast → POS+Kiosk consumers |
| **B** | `setMaxDailyQty` admin → flip `is_available` immédiat | ✅ RESOLVED (T-DEEP-AVAIL-API-01) | `routes/api.php:252-253` + sentinel `SetMaxDailyQtyEndpointTest 5/5` |
| **C** | Stock zero pendant order → flip atomic exactly-once | ✅ OK (M2 1.9 atomic CAS) | `StockService::decrementForOrder` + sentinel `WizardOptionStockSyncTest 4/4` (T-WC-STOCK-PROPAGATION-01) |
| **D** | Composer profile changé → kiosk panier ouvert reçoit toast + prune | ✅ OK | `ComposerProfileChanged` → `useCatalogChangeNotifier.js:421-424` → toast + `wizard:invalidate-step` |
| **E** | POS Echo disconnect → fallback polling | ⚠️ CONDITIONAL (flag OFF par défaut) | `PosSyncService.js:79-84` early-return si flag false. **Roadmap ops** : activer prod après staging. |
| **F** | POS wizard composer-aware (NEW T-WC-POS-RUNTIME-01) | ✅ OK (gated par flag) | `pos-wizard.js:423+` early-return composer path quand flag ON + `composer_profile.steps` présent |
| **G** | Wizard kiosk personnalisable (admin retire page → kiosk masque) | ✅ OK | `ComposerProfileProjection::project` filtre `visible_on` côté serveur + `KioskWizardComponent` itère `composerActiveSteps` |

**Score global synchronisation V1 :** **84/100** (vs 70 baseline V1-CLOSEOUT).

---

## §5 — Stock central → propagation rupture options wizard

L'audit `T-WC-STOCK-PROPAGATION-01` (sentinel `WizardOptionStockSyncTest`) a documenté 4 chemins distincts :

| Path | `stockable_type` | IBA auto-sync ? | `ItemAvailabilityChanged` | `StockLevelChanged` |
|---|---|---|---|---|
| Variation (option wizard) | `ItemVariation` | NON (IBA keyée `item_id`, pas `variation_id`) | NON | **OUI** |
| Extra (option wizard) | `ItemExtra` | NON (idem) | NON | **OUI** |
| Addon item (composition) | `Item` | **OUI** | **OUI** | **OUI** |
| Item direct (ligne classique) | `Item` | OUI | OUI | NON |

**Implication runtime :**
- Variation/Extra rupture → `StockLevelChanged` → `CatalogChanged` (via listener) → invalidation cache kiosk + refetch complet → option grisée
- Addon item rupture → `ItemAvailabilityChanged` direct → patch granulaire `kioskMenu/UPDATE_ITEM`

**Pas de cassure** : tous les paths convergent vers une option correctement masquée client. Asymétrie acceptable V1 (refactor potentiel V2 : aligner toutes les paths sur même mécanisme).

---

## §6 — Forces / Fragilités identifiées

### ✅ Top 3 forces (maintenir)

1. **Projection unifiée documentée comme SSOT** : `MenuProjectionService::forChannel($surface, $branchId)` + `KioskMenuService::build` cohérents (channels POS/kiosk/web explicites).
2. **Chaîne événements industrielle** : `DispatchableAfterCommit` trait + outbox `PersistCatalogChangedToOutbox` + invalidation cache ciblée + Echo broadcast per branch — robuste.
3. **Wizard/composer extension du même graphe** : `ComposerProfileProjection` réutilisée menu kiosk + détail item + preview admin (1 source = 3 consumers).

### ⚠️ Top 3 fragilités (surveillance, pas cleanup immédiat)

1. **Double chemin liste POS** : legacy `PosCategoryController` + `ItemService::simpleList` vs convergence `PosMenuProjection` encore conditionnée flags. Risque de dérive selon environnement.
2. **OSS découplé du graphe catalogue** (par design) : seuls `OrderCreated`/`OrderStatusChanged` souscrits. Cohérent UX (écran préparation) mais arbre incomplet si fraîcheur menu ultra requise.
3. **POS fallback polling défaut OFF** : flag opt-in `pos_fallback_polling.enabled`. Risque catalogue stale si Echo down 5+ min sans activation. Roadmap ops : flip prod après staging.

---

## §7 — Cleanup chirurgical effectué (Phase C)

Suite à l'audit A.2 (1 DEAD CONFIRMED + 6-10 DEAD SUSPECTED) — **strict principe prudence MAX user-demanded** :

| Cible | Verdict A.2 | Action | Justification |
|---|---|---|---|
| `resources/js/store/modules/profile.js` | 🟢 DEAD CONFIRMED (triple-check : 0 import, 0 store registration, contenu = duplicate de `employee.js`) | **DELETED** | Aucun usage, aucun risque. Économie code mort 162 lignes. |
| `posCustomer` Vuex (NFC lookup) | 🟡 DEAD SUSPECTED (déclaré + registré mais 0 dispatch hors store) | **KEEP par prudence** | Backend endpoint `CustomerNfcLookupTest.php` existe → intégration future probable. |
| `posNfc.js`, `posCentsArith.js`, `posFormatCents.js`, `MetricsBatcher`, `posA11y.js` | 🟡 DEAD SUSPECTED (tests présents mais pas import app) | **KEEP par prudence** | Tests verrouillent comportement futur ; pas de coût maintenance. |
| `StockRuptureDashboardComponent.vue` | 🟡 DEAD SUSPECTED (0 match routeur mais API M2 2.1 backend complet) | **KEEP par prudence** | Lien depuis dashboard widget V1 (StockLowAlertsWidget M2 V2 Lot C) attend cette page. Routeur à câbler en cycle V2. |
| `pos-wizard.js` (5832 lignes vanilla) | 🔴 VIVANT critique | **PRESERVE** | Asset frozen, chargé via Blade `master.blade.php`. T-WC-POS-RUNTIME-01 a ajouté composer-aware path SANS toucher legacy. |
| `AvailabilityController` vs `AvailabilityService` doublure | (déjà fixé) | — | Commit 35c9d1e13 (T-CENT-DEDUP-AVAIL-01) : controller délègue désormais service unique. |

**Résultat cleanup chirurgical :** 1 fichier supprimé (162 lignes), 0 régression introduite, prudence MAX respectée.

---

## §8 — Roadmap V2 (hors scope V1, documenté pour suite)

| ID | Titre | Effort | Risque |
|---|---|---|---|
| `CV1-WC-T-WC-SOURCE-FK-01` | Migration DB FK `source_ref` + `stock_levels` polymorphic FK | M | Gate humain (DDL) |
| `T-OPS-POS-POLLING-01` | Activer flag `pos_fallback_polling.enabled` en prod | S | Ops |
| `T-OPS-POS-WIZARD-COMPOSER-01` | Activer flag `pos_wizard_composer_aware.enabled` en prod | S | Ops (staging 7 jours) |
| `V2-KDS-OSS-CATALOG-POLLING` | Polling catalogue KDS+OSS (parité PosSyncService) | M | V2 |
| `V2-POS-CATEGORY-CONVERGE` | Brancher `PosCategoryController::index` sur `PosMenuProjection::forBranch` | L | Cycle séparé |
| `V2-WIZARD-RT-REFACTOR-XL` | Refactor moteur partagé POS+Kiosk wizard runtime (Famille D ULTRA PLAN) | XL | Plusieurs cycles |
| `CV1-DASHBOARD-CLEANUP-2` | Suite cleanup G3 modules (delivery/table-service/online — 3 gates DROP TABLE écrits) | L | 3 gates pending |

---

**Statut :** Document de référence officiel V1. Score architecture global = **(82+84)/2 = 83/100**. Verdict cycle CV1-CENTRAL-TREE-ARCHITECTURE-001 : **🟢 GO**.
