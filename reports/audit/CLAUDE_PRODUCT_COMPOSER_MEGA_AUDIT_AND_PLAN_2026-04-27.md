# Claude Mega Audit + Plan — Product Composer / Catalogue / Stock / POS-Kiosk Sync — 2026-04-27

Date: 2026-04-27
Reviewer: Claude (orchestration brain)
Inputs read:
- `reports/audit/CLAUDE_HANDOFF_PRODUCT_COMPOSER_FINAL_AUDIT_2026-04-27.md`
- `reports/audit/PRODUCT_COMPOSER_SYNC_DEEP_AUDIT_ORCHESTRATION_2026-04-27.md`
- `reports/audit/PRODUCT_COMPOSER_SYNC_CONTINUATION_REPORT_2026-04-27.md`
- `plans/PLAN_PRODUCT_COMPOSER_SYNC_MASTER_2026-04-27.md`
- `missions/PRODUCT-COMPOSER-SYNC-01A-GATE-BRIEFS/`
- `missions/PRODUCT-COMPOSER-SYNC-02A-DASHBOARD-COMPOSER-LITE/`
- `missions/PRODUCT-COMPOSER-SYNC-03A-PROJECTION-CONSTRAINTS-LITE/`
- Code: `ProductComposerSummaryComponent.vue`, `ItemShowComponent.vue`, `KioskMenuService.php`, `MenuProjectionService.php`, `DeliveryFeeService.php`, `PricingService.php`, `PosOrderRequest.php`, `OrderRequest.php`, `FrontendOrderService.php`, `PosController.php`, `KioskWizardComponent.vue`, `kioskRoutes.js`, `KioskAdminComponent.vue`, `deliveryCharge.js`, `CheckoutComponent.vue`, `PosComponent.vue`, `MenuProjectionParitySentinelTest.php`, `productComposerSummary.spec.js`, `PosWalkInAndDeliveryFeeTest.php`, `DeliveryFeeServiceTest.php`
- Migrations: `database/migrations/`
- Listeners: `app/Listeners/`
- Gate briefs: `docs/gates/GATE_PRODUCT_COMPOSER_SCHEMA_2026-04-27.md`, `GATE_STOCK_STOCKABLE_SCOPE_2026-04-27.md`, `GATE_DASHBOARD_AUTHZ_CATALOG_OPS_2026-04-27.md`, `GATE_FROZEN_ORDERSERVICE_UNLOCK_PRODUCT_COMPOSER_STOCK_2026-04-27.md`, `GATE_E2E_HARDWARE_COMPOSER_SIGNOFF_2026-04-27.md`

---

## 0. AUDIT_VERDICT

```
AUDIT_VERDICT: REWORK
SEVERITY: HIGH (deux P0 nouveaux détectés en plus du REWORK admis par Codex)
CAN_CODEX_CONTINUE: PARTIAL — Codex peut exécuter B0 (P0 hotfix), B7 (kiosk lockdown release) et B3 (runtime wizard read-side) sans gates supplémentaires. Tout B1/B2/B4/B5/B6/B8 nécessite l'approbation des 5 gates humains existants.
```

Codex a livré 3 slices sûrs (composition tab read-only, projection itemAttributes, fix DeliveryFeeService backend) mais **n'a pas livré** le système central demandé par l'utilisateur (composer write, stock V2, runtime wizard sur profils, sync E2E, hardware signoff). En plus de ces gaps connus, **ce passage Claude détecte deux P0 que Codex a manqués**:

1. **P0-A** — La règle 5 €/5 km tranche n'est appliquée qu'au backend POS. Le helper frontend `deliveryCharge.js` garde l'ancien algorithme `Math.max(5, Math.ceil(distance))` (1 € par km après 5 km) — desync UI ↔ backend.
2. **P0-B** — Le path web client `OrderRequest` (livraison customer site/app) **ne recalcule pas** `delivery_charge` côté serveur. Un client peut envoyer `delivery_charge=0.01` et payer 1 cent de livraison. Violation directe de l'invariant pricing SSOT.

Aucun PASS global possible avant correction de P0-A + P0-B et approbation des gates pour le reste.

---

## 1. Défauts précis (file:line)

### 1.1 P0 — Pricing SSOT violations (NOUVEAU, manqués par Codex)

| ID | Fichier:ligne | Description | Impact |
|---|---|---|---|
| P0-A | `resources/js/helpers/deliveryCharge.js:9` | Frontend renvoie `Math.max(5, Math.ceil(distance))` → 5.01 km = 6 €, contredit la règle 5 € / 5 km. | UI customer/POS affiche un prix faux. Backend recalcule (POS), donc commande POS correcte mais incohérence visuelle. Sur le path web, l'erreur est aussi dans le total final (voir P0-B). |
| P0-B | `app/Http/Requests/OrderRequest.php:29-37` | `prepareForValidation()` ne merge PAS `delivery_charge` depuis `delivery_distance_km`. Contraste avec `PosOrderRequest:18-26` qui le fait correctement. | **Pricing SSOT violé sur web frontend.** `FrontendOrderService.php:228` et `:432` consomment `delivery_charge` brut du payload client. Un client malicieux peut envoyer `delivery_charge=0` ou `0.01`. |
| P0-B-bis | `app/Services/FrontendOrderService.php:228,432` | Total recalculé inclut `$this->frontendOrder->delivery_charge` directement de l'input. | Aggrave P0-B. Total final pollué par valeur cliente. |

### 1.2 P0 — Kiosk release hygiene (NOUVEAU, manqué par Codex)

| ID | Fichier:ligne | Description | Impact |
|---|---|---|---|
| P0-C | `public/js/kiosk-admin.js` (54 885 octets, 2026-04-26 23:34) + `public/js/kiosk-admin.js.LICENSE.txt` | Bundle webpack `kiosk-admin` toujours déployé alors qu'aucun import dans `resources/js` ne le référence. | **Risque P0** : un attaquant connaissant l'URL `/js/kiosk-admin.js` peut auditer le code admin compilé incluant le fallback PIN '1234'. Aucune route ne le charge actuellement, mais le binaire reste exposé publiquement. |
| P0-D | `resources/js/components/frontend/kiosk/KioskAdminComponent.vue:345-348` | `KioskAdminComponent.vue` reste dans la source avec `DEFAULT_PIN = '1234'` (ligne ~345). Aucun import depuis kiosk app, mais le fichier persiste. | Risque dormant : toute mission ultérieure pourrait ré-importer ce composant et réintroduire un escape path. À supprimer ou geler explicitement. |

Vérification : `grep -rn "KioskAdminComponent" resources/js` ne retourne **que** le fichier lui-même — confirme l'orphelinat. La route `/kiosk/admin` redirige bien vers `kiosk.idle` (`router/modules/kioskRoutes.js:222-227`).

### 1.3 P1 — Composer/composition (déjà connus de Codex)

| ID | Fichier | Description |
|---|---|---|
| P1-A | `resources/js/components/admin/items/ProductComposerSummaryComponent.vue` | Read-only summary tab. Pas de création/édition de profil composer, pas d'ordre d'étapes, pas de surfaces, pas de versionning. |
| P1-B | `database/migrations/` | **Aucune** migration `item_wizard_profiles` ou `item_wizard_steps`. Schema composer absent. |
| P1-C | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:548-595` | `effectiveWizardTemplate()` + `detectTemplateFromName()` continuent d'inférer `tacos`/`burger`/`assiette`/`omelette`/`salade`/`snacking` à partir du nom/catégorie. **Aucune consommation** de profils composer. |
| P1-D | `app/Services/Pricing/PricingService.php` | PricingService consomme `items/variations/extras/addons` directement (pas de `composition_snapshot` à partir de profils composer). À adapter quand profils existeront, sans dupliquer les prix. |

### 1.4 P1 — Stock V2 (déjà connus de Codex)

| ID | Description |
|---|---|
| P1-E | Aucune table `stock_levels`, aucune `stock_movements`, aucun modèle `Stockable` polymorphe. Seul `item_branch_availability` (qty journalière + disponibilité) existe (`database/migrations/2026_04_15_230100_create_item_branch_availability_table.php`). |
| P1-F | Aucun décrément stock atomique dans `OrderService.php` ou `FrontendOrderService.php`. |
| P1-G | Aucun release stock idempotent sur cancel/refund (le release existe pour `item_branch_availability.daily_consumed_qty` via `ReleaseAvailabilityOnOrderCanceled`/`ReleaseAvailabilityOnRefundCreated` mais pas pour stock quantitatif réel des choix/extras). |
| P1-H | Pas d'UI rupture sur kiosk/POS pour rupture quantitative produit/choix. |

### 1.5 P1 — Sync catalogue/photo end-to-end (partiel)

| ID | Fichier:ligne | Description |
|---|---|---|
| P1-I | `app/Providers/EventServiceProvider.php:126-147` | `ItemAvailabilityChanged` + `InvalidateKioskMenuCacheOnCatalogChange` sont câblés. `ItemService::changeImage()` (`app/Services/ItemService.php:380`) dispatch `ItemAvailabilityChanged::fromItem($refreshed, 'full')`. **Bonne foundation** mais pas de contrat unifié `CatalogChanged`. |
| P1-J | Aucun test E2E (Playwright/feature) prouvant : admin upload photo → kiosk affiche tile mise à jour < N s. Codex n'a livré aucune evidence. |
| P1-K | Photos catégorie vs produit : pas d'audit prouvant que les deux invalident bien le cache kiosk. |
| P1-L | Pas de "dashboard connected device version status" (snapshot version visible dans le dashboard). |

### 1.6 P1 — Addon semantic roles

| ID | Description |
|---|---|
| P1-M | `item_addons` actuel (`item_id`, `addon_item_id`, `addon_item_variation`) n'a pas de rôle (`drink`/`side`/`dessert`/`menu_component`/`upsell`). UX "menu/offre" ne peut pas s'appuyer dessus. |

### 1.7 P1 — Runtime wizard

| ID | Description |
|---|---|
| P1-N | `MenuProjectionService.php:28-30` (commentaire) confirme : "Consumers today (POS / Kiosk controllers) are NOT yet plugged into this service". POS et kiosk gardent leurs paths legacy + heuristiques. La projection itemAttributes est exposée mais n'est pas consommée. |

### 1.8 P2 — Décisions business à formaliser

| ID | Sujet | Action requise |
|---|---|---|
| P2-A | DeliveryFeeService `0 km → 5 €` (ligne 14 : `max(5, ...)`) | Confirmer business : 0 km = livraison à pied autorisée à 5 € minimum, ou doit retourner 0 ? |
| P2-B | Geocode failure fallback distance=0 → fee=5 € | Politique : bloquer la commande, demander distance manuelle, ou accepter min fee ? |
| P2-C | Bouton `Retour` sur kiosk payment | Acceptable UX customer ou casse le lockdown ? |
| P2-D | `Paiement à la caisse` sur kiosk | Acceptable selon déploiement (multi-tenant) ou interdire par feature flag branche ? |

---

## 2. Plan technique complet

### 2.1 Vision cible (rappel)

Un **control plane catalogue unique** dans le dashboard admin :
1. CRUD catégories / produits / photos / prix / variations / extras / addons / disponibilité / stock.
2. **Product Composer** type Shopify : profils par produit, étapes ordonnées (`pain` / `viandes` / `crudités` / `sauces` / `suppléments` / `boisson` / `addons`), activables/désactivables, héritage catégorie + override produit.
3. **POS et kiosk** consomment **la même projection** (pas le même design).
4. **Stock V2** atomique générique (item / variation / extra) avec décrément/release transactionnel et rupture propagée.
5. **PricingService** seul autorité prix final.
6. **Photos** modifiables depuis dashboard, propagation kiosk/POS observable.
7. **Offres hebdo/mensuelles** = produits compose-able commercialisables.

### 2.2 Architecture cible (synthèse)

```
Dashboard Composer (write)
   │
   ├─► item_wizard_profiles / item_wizard_steps  ──┐
   │                                                │
   ├─► items / categories / attributes /            │   schema thin :
   │   variations / extras / addons (existants)     │   profils référencent
   │                                                │   les choix existants
   ├─► stock_levels (stockable polymorphe)          │   sans dupliquer le prix
   │   stock_movements (append-only)                │
   │                                                │
   └─► CatalogChanged + StockLevelChanged          ◄┘
            │ (outbox after commit)
            ▼
   MenuProjectionService V2 (canonical) ─► snapshot_version bump
            │
   ┌────────┴────────────┐
   ▼                     ▼
 POS adapter         Kiosk adapter
   │                     │
   └────► PricingService ◄──── (quote backend, jamais front)
                │
                ▼
       OrderService / FrontendOrderService
                │
   ┌────────────┴────────────┐
   ▼                         ▼
  POS live / KDS         OSS / Customer
```

### 2.3 Invariants intangibles

1. Backend = SSOT prix (PricingService + OrderQuote + DeliveryFeeService).
2. Frontend = uniquement preview/UX, **jamais** autoritaire.
3. Branch isolation stricte (lecture/écriture, listeners, jobs).
4. Dispatch events après DB commit.
5. Pas de duplication prix dans `item_wizard_steps` (les steps référencent variations/extras existants).
6. Pas de patch `OrderService` ou `FrontendOrderService` sans `HG-FROZEN-ORDERSERVICE-UNLOCK`.
7. Pas de migration sans gate humain explicite.
8. Symétrie obligatoire `OrderService` ↔ `FrontendOrderService` quand la logique touche la commande.
9. `OrderStatus` enum uniquement, pas de strings magiques.
10. Tous les events catalogue passent par outbox pour audit/reprise.

### 2.4 Data contract Composer (rappel structure cible)

```json
{
  "item_id": 123,
  "profile": { "template": "assiette", "version": 7, "published": true, "branch_id_scope": null },
  "steps": [
    {
      "step_key": "viandes",
      "label": "Viandes",
      "source_type": "item_attribute",
      "source_ref": "<item_attribute_id>",
      "min_select": 1,
      "max_select": 3,
      "allow_repeat": true,
      "visible_on": ["pos","kiosk"],
      "stockable_choices": true
    },
    {
      "step_key": "crudites",
      "label": "Crudités",
      "source_type": "extra_group",
      "source_ref": "garniture",
      "min_select": 0,
      "max_select": 6,
      "allow_repeat": false,
      "visible_on": ["pos","kiosk"]
    }
  ]
}
```

`choices` sont projetés depuis `item_variations` / `item_extras` / `item_addons`. **Aucun prix dans le profil.**

### 2.5 Data contract Stock V2 (recommendation Option B polymorphe)

```sql
CREATE TABLE stock_levels (
  id BIGSERIAL PK,
  branch_id BIGINT NOT NULL,
  stockable_type VARCHAR(64) NOT NULL,  -- 'item','variation','extra'
  stockable_id   BIGINT NOT NULL,
  on_hand INT NOT NULL DEFAULT 0,
  reserved INT NOT NULL DEFAULT 0,
  threshold_low INT NULL,
  unique (branch_id, stockable_type, stockable_id)
);

CREATE TABLE stock_movements (
  id BIGSERIAL PK,
  stock_level_id BIGINT NOT NULL,
  branch_id BIGINT NOT NULL,
  delta INT NOT NULL,                   -- négatif = décrément, positif = release/refill
  reason VARCHAR(40) NOT NULL,          -- 'order_created','order_canceled','refund','manual','rupture'
  reference_type VARCHAR(64) NULL,
  reference_id   BIGINT NULL,
  idempotency_key VARCHAR(64) NULL,
  created_at TIMESTAMPTZ NOT NULL,
  unique (idempotency_key)
);
```

**Décrément atomique** dans `OrderService::posOrderStore` et `FrontendOrderService::store` à l'intérieur de la même transaction que l'order, après PricingService et avant dispatch events. **Release** sur `OrderCanceled` / `RefundCreated` via listeners idempotents (mêmes patterns que `ReleaseAvailabilityOnOrderCanceled`).

---

## 3. Missions d'implémentation ordonnées

> Le plan B0..B9 remplace les trains 00..05 du master plan en y ajoutant les correctifs P0 détectés par Claude.

### Ordre obligatoire et raison

```
B0  P0 HOTFIX (delivery SSOT + frontend helper + dead bundle)   ── pas de gate
B1  GATES APPROVAL (humain : 5 gates pendantes)                 ── humain seulement
B2  SCHEMA ADR (composer + stock)                               ── après B1
B3  DASHBOARD COMPOSER WRITE                                    ── après B2
B4  RUNTIME WIZARD MIGRATION (POS+Kiosk consomment profils)     ── après B3
B5  STOCK V2 (table, décrément/release, rupture UI)             ── après B4
B6  CATALOG EVENTING UNIFIÉ + photo E2E                         ── après B3 (// avec B4)
B7  KIOSK LOCKDOWN RELEASE AUDIT (cleanup admin bundle/source)  ── // dès B0 (pas de gate)
B8  DELIVERY/MAPS HARDENING (geocode policy, tests forge)       ── // dès B0 (pas de gate)
B9  E2E + HARDWARE SIGNOFF                                      ── après B5/B6
```

Inverser B5 avant B2/B3 = stock découplé du composer (incohérent demande utilisateur).
Inverser B4 avant B3 = wizard sans payload composer écrit (= heuristiques bouclées).

---

### Mission B0 — P0 HOTFIX (Delivery SSOT + frontend helper + dead bundle)

**Pas de gate. Exécutable immédiatement.**

#### Intent
Aligner le path web client sur le même pattern d'authority que POS, désynchroniser totalement le helper frontend de la décision prix finale, et nettoyer le bundle dead admin kiosk.

#### Scope

**B0.1 — Web frontend OrderRequest authority**
- Modifier `app/Http/Requests/OrderRequest.php::prepareForValidation()` pour merger `delivery_charge = DeliveryFeeService::fromDistanceKm($input)` quand `delivery_distance_km` est fourni ET `order_type === DELIVERY`.
- Ajouter une *règle de cohérence* : si `delivery_distance_km` est manquant pour DELIVERY, refuser le payload avec une erreur explicite OU forcer un fallback documenté (à choisir avec B8).
- Ne PAS toucher `FrontendOrderService.php` (frozen) sauf à substituer la lecture par `app(DeliveryFeeService::class)->fromDistanceKm($order->delivery_distance_km)` si `delivery_distance_km` est tracké côté order. Si pas trackable sans gate, se contenter de B0.1 path-1 (re-merge en request) → l'`OrderRequest` modifie le payload **avant** que `FrontendOrderService` lise.

**B0.2 — Frontend helper align**
- Modifier `resources/js/helpers/deliveryCharge.js` pour appliquer `Math.max(5, Math.ceil(distance / 5) * 5)` (parité backend).
- Ajouter `tests/js/deliveryCharge.spec.js` couvrant 0, 5, 5.01, 10, 10.01, -1, NaN, null.
- Ajouter un commentaire explicite : "Preview UI uniquement — backend `DeliveryFeeService::fromDistanceKm` reste autoritatif".

**B0.3 — Dead admin bundle removal**
- Supprimer `public/js/kiosk-admin.js` et `public/js/kiosk-admin.js.LICENSE.txt`.
- Ajouter une garde CI `tools/lint/forbidden_bundles.sh` qui échoue si `kiosk-admin*.js` réapparaît dans `public/js/` après build, sauf gate explicite.
- Décision sur le source `KioskAdminComponent.vue` : **option A (recommandée)** suppression et garde de regression test `tests/Feature/KioskLockdownTest.php` qui assert `Route::has('kiosk.admin')` = redirect to idle ET file_exists ce composant = false ; **option B** (si maintenance staff envisagée plus tard) : déplacer dans `resources/js/components/admin/maintenance/` derrière feature flag + gate. **B0 livre option A**.

#### Forbidden
- Pas d'édition `OrderService.php` ni `FrontendOrderService.php` (gate frozen).
- Pas de migration.
- Pas de modification du Composer summary tab.
- Pas de modification kiosk wizard.

#### Allowlist B0
```
app/Http/Requests/OrderRequest.php
resources/js/helpers/deliveryCharge.js
tests/js/deliveryCharge.spec.js
tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php
tests/Feature/KioskLockdownTest.php
public/js/kiosk-admin.js                       # delete
public/js/kiosk-admin.js.LICENSE.txt           # delete
resources/js/components/frontend/kiosk/KioskAdminComponent.vue   # delete
tools/lint/forbidden_bundles.sh
missions/PRODUCT-COMPOSER-SYNC-B0-P0-HOTFIX/allowlist.txt
missions/PRODUCT-COMPOSER-SYNC-B0-P0-HOTFIX/execute_brief.md
missions/PRODUCT-COMPOSER-SYNC-B0-P0-HOTFIX/input.json
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B0-P0-HOTFIX.md
reports/post_execute_latest.log
```

#### Tests obligatoires B0
- `tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php` :
  - POST `/api/frontend/order` avec `delivery_distance_km=5.01, delivery_charge=999` → ordre persiste avec `delivery_charge=10`.
  - POST avec `delivery_distance_km=0` → `delivery_charge=5` (ou comportement gelé en B8 si décision business).
  - POST sans `delivery_distance_km` pour DELIVERY → 422.
- `tests/js/deliveryCharge.spec.js` couvre matrice : `0,5,5.01,10,10.01,-1,'x',null,Infinity,NaN`.
- `tests/Feature/KioskLockdownTest.php` :
  - `Route::has('kiosk.admin')` ET redirect → idle.
  - `assertFileDoesNotExist(public_path('js/kiosk-admin.js'))`.
  - `assertFileDoesNotExist(resource_path('js/components/frontend/kiosk/KioskAdminComponent.vue'))`.
- Régression : `npx vitest run tests/js/productComposerSummary.spec.js` reste PASS, `php artisan test tests/Feature/PosWalkInAndDeliveryFeeTest.php` reste PASS.
- Build : `npm run production` doit produire un bundle sans `kiosk-admin*.js`.

#### Critères PASS B0
- 4/4 nouveaux tests verts.
- Bundle build ne contient plus `kiosk-admin*`.
- `git diff --check` propre sur l'allowlist.
- Self-audit Codex confirme aucune édition hors allowlist.

#### Critères REWORK B0
- Tout test rouge.
- Bundle reproduit `kiosk-admin*.js`.
- Édition non autorisée de `OrderService` / `FrontendOrderService`.

---

### Mission B1 — Approbation gates (HUMAIN)

Pas une mission Codex. Action **humaine** sur les 5 fichiers existants :

```
docs/gates/GATE_PRODUCT_COMPOSER_SCHEMA_2026-04-27.md            (HG-COMPOSER-SCHEMA-ADR)
docs/gates/GATE_STOCK_STOCKABLE_SCOPE_2026-04-27.md              (HG-STOCK-STOCKABLE-SCOPE)
docs/gates/GATE_DASHBOARD_AUTHZ_CATALOG_OPS_2026-04-27.md        (HG-DASHBOARD-AUTHZ-CATALOG-OPS)
docs/gates/GATE_FROZEN_ORDERSERVICE_UNLOCK_PRODUCT_COMPOSER_STOCK_2026-04-27.md  (HG-FROZEN-ORDERSERVICE-UNLOCK)
docs/gates/GATE_E2E_HARDWARE_COMPOSER_SIGNOFF_2026-04-27.md      (HG-E2E-HARDWARE-COMPOSER-SIGNOFF)
```

**Décisions à acter avant de débloquer B2..B9** :
- B2 ←─ `HG-COMPOSER-SCHEMA-ADR` + `HG-STOCK-STOCKABLE-SCOPE` (option B polymorphe recommandée).
- B3 ←─ `HG-DASHBOARD-AUTHZ-CATALOG-OPS`.
- B5 ←─ `HG-FROZEN-ORDERSERVICE-UNLOCK` + `HG-STOCK-STOCKABLE-SCOPE`.
- B9 ←─ `HG-E2E-HARDWARE-COMPOSER-SIGNOFF`.

**Décisions business additionnelles** (à acter dans `docs/decisions/` si pas déjà fait) :
- D-DELIV-01 : politique `0 km → 5 €` confirmée ou amendée.
- D-DELIV-02 : politique geocode failure (block / manual / min fee).
- D-KIOSK-01 : bouton `Retour` sur kiosk payment (keep / hide / require staff PIN).
- D-KIOSK-02 : `Paiement à la caisse` sur kiosk (per-branch flag).
- D-COMPOSER-01 : addon roles enum (`drink|side|dessert|menu_component|upsell`).

---

### Mission B2 — Schema ADR (Composer + Stock V2)

**Précondition B1** : `HG-COMPOSER-SCHEMA-ADR` + `HG-STOCK-STOCKABLE-SCOPE` approuvés.

#### Intent
Livrer le schema thin Composer + le schema Stock V2 polymorphe avec migrations, modèles, factories, contrats et tests fail-first.

#### Scope
1. Migrations :
   - `*_create_item_wizard_profiles_table.php` (`item_id` FK, `template`, `version`, `published_at`, `is_published`, `branch_id_scope NULL`).
   - `*_create_item_wizard_steps_table.php` (`profile_id` FK, `step_key`, `label`, `source_type` enum `item_attribute|extra_group|addon|fixed`, `source_ref`, `min_select`, `max_select`, `allow_repeat`, `visible_on` JSON, `stockable_choices`, `position`, `is_active`).
   - `*_create_stock_levels_table.php` polymorphe + index unique `(branch_id, stockable_type, stockable_id)`.
   - `*_create_stock_movements_table.php` append-only, `idempotency_key` unique.
   - `*_add_role_to_item_addons_table.php` (`role` enum nullable : `drink|side|dessert|menu_component|upsell`).
2. Modèles `App\Models\ItemWizardProfile`, `ItemWizardStep`, `StockLevel`, `StockMovement`. Relations + scopes branche.
3. Factories + seeders dev pour assiette/tacos/sandwich.
4. Tests fail-first qui prouvent le contrat (lecture/écriture, branch isolation, idempotence stock).
5. ADR markdown `docs/architecture/ADR-COMPOSER-STOCK-2026-04-27.md`.

#### Forbidden B2
- **Aucune** édition `OrderService.php`, `FrontendOrderService.php`, `PricingService.php`, controllers POS/Kiosk, runtime wizard.
- Aucune surface UI.

#### Allowlist B2
```
database/migrations/*create_item_wizard_profiles_table.php
database/migrations/*create_item_wizard_steps_table.php
database/migrations/*create_stock_levels_table.php
database/migrations/*create_stock_movements_table.php
database/migrations/*add_role_to_item_addons_table.php
database/factories/ItemWizardProfileFactory.php
database/factories/ItemWizardStepFactory.php
database/factories/StockLevelFactory.php
database/seeders/ComposerSeeder.php
app/Models/ItemWizardProfile.php
app/Models/ItemWizardStep.php
app/Models/StockLevel.php
app/Models/StockMovement.php
docs/architecture/ADR-COMPOSER-STOCK-2026-04-27.md
tests/Feature/Catalog/ComposerSchemaTest.php
tests/Feature/Stock/StockLevelSchemaTest.php
tests/Feature/Stock/StockBranchIsolationTest.php
missions/PRODUCT-COMPOSER-SYNC-B2-SCHEMA-ADR/*
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B2-SCHEMA-ADR.md
reports/post_execute_latest.log
```

#### Tests B2
- `ComposerSchemaTest`: profil → steps ordonnés → relation correcte → version bump → `branch_id_scope=null` global vs override branche.
- `StockLevelSchemaTest`: insert/update → contrainte unique respectée → `on_hand >= reserved` invariant DB-level (check constraint).
- `StockBranchIsolationTest`: branche A ne voit pas branche B (scopes Eloquent + raw query test).

#### Critères PASS B2
- Migrations forward + rollback OK.
- 100% tests verts.
- ADR validé par humain (référence ticket).

---

### Mission B3 — Dashboard Composer Write Flow

**Précondition** : B2 + `HG-DASHBOARD-AUTHZ-CATALOG-OPS`.

#### Intent
Construire l'UI dashboard composer (un seul écran consolidé) qui permet à un manager de créer/modifier un produit composé entièrement (catégorie → photo → preset → étapes activables → choix → publish + version).

#### Scope
1. Vue principale `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` :
   - Header : nom produit, catégorie, photo, status, type.
   - Section "Préset" (template assiette/tacos/sandwich/burger/salade/snacking/omelette/simple/menu).
   - Section "Étapes" : timeline drag-and-drop, chaque étape activable avec source attribut/extra/addon, contraintes min/max/repeat, surfaces (POS/kiosk).
   - Section "Aperçu POS" + "Aperçu kiosk" (read-only render des steps avec données mockées).
   - Section "Stock & disponibilité" : on_hand, threshold_low par branche (lecture seule en B3, write en B5).
   - Section "Publier" : bouton publish → version bump + dispatch `CatalogChanged`.
2. Backend API `POST/PUT/DELETE /api/admin/composer/profile/{item}` + `POST/PUT/DELETE /api/admin/composer/step/{step}` derrière middleware `permission:catalog.compose`.
3. Authz Spatie role `Catalog Manager` ajouté avec permissions `catalog.read`, `catalog.compose`, `catalog.publish`.
4. Validations server-side strictes : pas de prix dans le payload composer, refus si payload contient `price`.
5. Photo upload reste path existant (`/api/admin/item/change-image/{item}`) — composer affiche l'image, UI single-screen mais action déléguée.

#### Forbidden B3
- Pas d'édition runtime wizard.
- Pas de décrément stock.
- Pas de modification PricingService.

#### Allowlist B3
```
resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue
resources/js/components/admin/items/composer/StepEditorComponent.vue
resources/js/components/admin/items/composer/StepPreviewComponent.vue
resources/js/store/modules/composer.js
resources/js/router/modules/adminRoutes.js                 # ajouter /admin/items/{id}/composer
app/Http/Controllers/Admin/ComposerProfileController.php
app/Http/Controllers/Admin/ComposerStepController.php
app/Http/Requests/ComposerProfileRequest.php
app/Http/Requests/ComposerStepRequest.php
app/Http/Resources/ComposerProfileResource.php
app/Http/Resources/ComposerStepResource.php
app/Services/Composer/ComposerProfileService.php
app/Services/Composer/ComposerStepService.php
app/Events/ComposerProfilePublished.php
routes/api.php
database/seeders/ComposerPermissionsSeeder.php
tests/Feature/Composer/ComposerProfileApiTest.php
tests/Feature/Composer/ComposerAuthzTest.php
tests/js/productComposerEditor.spec.js
missions/PRODUCT-COMPOSER-SYNC-B3-DASHBOARD-COMPOSER-WRITE/*
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B3-DASHBOARD-COMPOSER-WRITE.md
reports/post_execute_latest.log
```

#### Tests B3
- `ComposerProfileApiTest` : CRUD profile, version bump, publish dispatch event after commit.
- `ComposerAuthzTest` : `Catalog Manager` peut éditer ; `POS Operator` ne peut pas ; `Branch Admin` peut éditer dans son scope.
- `productComposerEditor.spec.js` : drag-drop ordre, activer/désactiver step, refus client si payload contient `price`, bouton publish appelle action store correcte.
- Régression : composer summary read-only existant continue de PASS.

#### Critères PASS B3
- Manager peut créer un produit "Assiette test" avec étapes crudités/sauces/suppléments/boisson activables, sans toucher au code, en restant 100% backend pour le prix.
- Photo upload depuis composer fonctionne et est visible kiosk après bump version.

---

### Mission B4 — Runtime Wizard Migration (POS + Kiosk)

**Précondition** : B3 livré (profils peuvent être lus).

#### Intent
Faire consommer aux wizards POS et kiosk les profils composer via la projection canonique. Garder les heuristiques **uniquement** comme fallback quand aucun profil n'existe.

#### Scope
1. Étendre `MenuProjectionService::projectItems()` pour inclure `composer_profile` (profil publié + steps) si présent. Pas de prix dans le profil.
2. Étendre `KioskMenuService::projectItems()` symétrie.
3. `KioskWizardComponent.vue` + `KioskPosWizardComponent.vue` :
   - Lire `item.composer_profile.steps` en priorité.
   - Si absent, fallback `effectiveWizardTemplate()` actuel.
   - Logger via analytics (`kioskAnalytics.trackHeuristicFallback`) chaque fois que le fallback est utilisé.
4. `PosComponent.vue` (admin POS wizard) : même priorité profil → fallback.
5. Tests parité POS↔kiosk consommation profil identique pour shared variations/extras (conserve sentinelle existante + ajoute `test_pos_and_kiosk_consume_published_composer_profile`).
6. Test "no fallback" : si profil publié pour `assiette test`, pas un seul appel à `detectTemplateFromName`.

#### Forbidden B4
- Pas d'édition `OrderService` / `FrontendOrderService`.
- Pas d'édition `PricingService`.
- Pas de migration.

#### Allowlist B4
```
app/Services/Menu/MenuProjectionService.php
app/Services/Kiosk/KioskMenuService.php
app/Http/Resources/ItemResource.php           # ajouter composer_profile
app/Http/Resources/NormalItemResource.php     # ajouter composer_profile
resources/js/components/frontend/kiosk/KioskWizardComponent.vue
resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue
resources/js/components/admin/pos/PosComponent.vue
resources/js/helpers/kioskAnalytics.js
tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php
tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php   # extension
tests/js/kioskWizardComposerProfile.spec.js
tests/js/posWizardComposerProfile.spec.js
missions/PRODUCT-COMPOSER-SYNC-B4-RUNTIME-WIZARD-MIGRATION/*
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B4-RUNTIME-WIZARD-MIGRATION.md
```

#### Tests B4
- `MenuProjectionComposerProfileTest` : profil publié exposé sur POS et kiosk avec mêmes ids steps, mais filtré par `visible_on` et channel.
- Sentinel parity étendue : `composer_profile.steps[*].id` identiques POS/kiosk pour la part `visible_on` shared.
- `kioskWizardComposerProfile.spec.js` : profil publié → wizard suit steps composer ; pas de profil → heuristique loguée.
- Test régression tacos/sandwich/burger/assiette : flow customer ne change pas si pas de profil publié.

#### Critères PASS B4
- Test "no fallback when profile published" vert.
- Pricing toujours backend (audit grep `calculateDeliveryChargeFromDistance|priceCalculation` côté wizard = 0 nouveaux appels).
- `npm run production` PASS.

---

### Mission B5 — Stock V2 (décrément/release/rupture)

**Précondition** : B2 + B3 + `HG-FROZEN-ORDERSERVICE-UNLOCK`.

#### Intent
Brancher le stock atomique sur OrderService et FrontendOrderService avec parité, ajouter UI rupture POS/kiosk, et propager rupture en realtime.

#### Scope
1. Service `app/Services/Stock/StockService.php` :
   - `decrementForOrder(Order $order)` à l'intérieur de la transaction order, après PricingService et avant dispatch outbox events. Idempotency par `(order_id, line_uid)`.
   - `releaseForOrder(Order $order, string $reason)` listener-friendly, idempotent.
   - Lock pessimiste `lockForUpdate()` par `stock_level_id`.
2. Patch **symétrique** `OrderService::posOrderStore()` ET `FrontendOrderService::store()` : appel `StockService::decrementForOrder` avant `DB::commit`.
3. Listeners `DecrementStockOnOrderCreated` (idempotent), `ReleaseStockOnOrderCanceled`, `ReleaseStockOnRefundCreated`.
4. Event `StockLevelChanged` (after-commit), broadcast Echo channel `branch.{id}.stock`.
5. UI rupture :
   - Kiosk : si `composer_profile.steps[].choices[].stock <= 0`, le choix s'affiche grisé, click bloqué, message "Indisponible aujourd'hui".
   - POS : badge rouge sur choix, refus envoi commande si stock épuisé.
6. Concurrence : test `tests/Feature/Stock/StockConcurrentDecrementTest.php` simule 5 commandes parallèles sur stock=3 → 3 succès, 2 rupture.

#### Forbidden B5
- Pas de duplication prix.
- Pas de modification PricingService.
- Pas de modification UI composer write.

#### Allowlist B5
```
app/Services/Stock/StockService.php
app/Services/OrderService.php                    # patch symétrie (après HG-FROZEN-ORDERSERVICE-UNLOCK)
app/Services/FrontendOrderService.php            # patch symétrie
app/Listeners/DecrementStockOnOrderCreated.php
app/Listeners/ReleaseStockOnOrderCanceled.php
app/Listeners/ReleaseStockOnRefundCreated.php
app/Events/StockLevelChanged.php
app/Providers/EventServiceProvider.php           # wiring
app/Http/Resources/MenuItemResource.php          # expose stock per choice (si pertinent)
resources/js/components/frontend/kiosk/KioskWizardComponent.vue   # badge rupture
resources/js/components/admin/pos/PosComponent.vue                # badge rupture
resources/js/store/modules/stock.js                               # echo channel branch.{id}.stock
tests/Feature/Stock/StockDecrementOrderServiceTest.php
tests/Feature/Stock/StockDecrementFrontendOrderServiceTest.php
tests/Feature/Stock/StockReleaseOnCancelTest.php
tests/Feature/Stock/StockReleaseOnRefundTest.php
tests/Feature/Stock/StockConcurrentDecrementTest.php
tests/js/kioskRuptureUx.spec.js
tests/js/posRuptureUx.spec.js
missions/PRODUCT-COMPOSER-SYNC-B5-STOCK-V2/*
```

#### Tests B5
- 5 tests Feature ci-dessus + 2 specs JS rupture UX + 2 tests parité OrderService↔FrontendOrderService (mêmes décréments pour mêmes payloads).
- Régression : `MenuProjectionParitySentinelTest` reste vert.

#### Critères PASS B5
- Concurrence : 100% des runs le test parallel passe (≥ 50 itérations).
- Symétrie OrderService ↔ FrontendOrderService prouvée par diff de code review (note explicite du reviewer).
- Aucune fuite branche-cross dans les tests d'isolation.

---

### Mission B6 — Catalog Eventing unifié + Photo E2E

**Précondition** : B3 livré.

#### Intent
Unifier les événements catalogue sous un contrat `CatalogChanged` versionné et prouver la propagation photo end-to-end.

#### Scope
1. Event `CatalogChanged` (`scope`, `entity_type`, `entity_id`, `branch_id_scope` nullable, `version`).
2. Adapter listeners existants pour dispatch `CatalogChanged` en plus de `ItemAvailabilityChanged` (pas de breaking change).
3. Outbox unifié : `catalog_outbox` table + worker.
4. Test E2E photo : upload via API admin → assert `kiosk-cache:menu:{branch}` invalidé → assert `MenuSnapshot::current({branch})` incrémenté → assert kiosk Echo reçoit message → snapshot retourné par `GET /api/frontend/menu` reflète nouvelle URL.
5. Dashboard widget "Connected devices snapshot version" lisant `MenuSnapshot::current` par device.

#### Forbidden B6
- Pas d'édition stock.
- Pas d'édition runtime wizard.

#### Allowlist B6
```
app/Events/CatalogChanged.php
app/Listeners/PersistCatalogChangedToOutbox.php
app/Providers/EventServiceProvider.php
app/Services/ItemService.php                       # dispatch CatalogChanged on changeImage
app/Services/ItemCategoryService.php
app/Services/ItemVariationService.php
app/Services/ItemExtraService.php
app/Services/ItemAddonService.php
database/migrations/*create_catalog_outbox_table.php
resources/js/components/admin/dashboard/ConnectedDevicesSnapshotComponent.vue
tests/Feature/Catalog/CatalogChangedDispatchTest.php
tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php
tests/Feature/Catalog/CatalogOutboxIdempotencyTest.php
tests/e2e/catalog-photo-propagation.spec.js
missions/PRODUCT-COMPOSER-SYNC-B6-CATALOG-EVENTING/*
```

#### Tests B6
- E2E Playwright : flow complet upload → snapshot bump → kiosk poll voit nouvelle URL en < 5 s.
- Outbox idempotency : double dispatch même event → un seul row outbox.

---

### Mission B7 — Kiosk Lockdown Release Audit

**Pas de gate. Exécutable en parallèle de B0..B6. Recommandé après B0 (qui fait déjà le cleanup principal).**

#### Intent
Auditer les bundles compilés en prod et le source pour détecter tout escape path admin/maintenance résiduel et formaliser les décisions D-KIOSK-01/02 en feature flags branche.

#### Scope
1. Script CI `tools/lint/scan_kiosk_bundles.mjs` qui :
   - Scan `public/js/*.js` pour patterns admin (`KioskAdmin`, `kiosk_admin_pin`, `DEFAULT_PIN`, `'1234'`).
   - Échoue si match.
2. Test Playwright `tests/e2e/kiosk-lockdown.spec.js` :
   - Visit `/kiosk/admin` → redirected to idle.
   - Visit `/js/kiosk-admin.js` → 404.
   - Inspect kiosk payment screen DOM → no admin/caisse link visible.
3. Feature flag `kiosk.allow_cash_at_counter` (per-branch) qui contrôle l'affichage de "Paiement à la caisse" sur le kiosk.
4. Feature flag `kiosk.allow_back_button_on_payment` (per-branch) idem pour bouton `Retour`.
5. Documenter `docs/kiosk/LOCKDOWN_POLICY_2026-04-27.md`.

#### Allowlist B7
```
tools/lint/scan_kiosk_bundles.mjs
tests/e2e/kiosk-lockdown.spec.js
config/kiosk.php                                  # ajouter allow_cash_at_counter, allow_back_button_on_payment
resources/js/components/frontend/kiosk/KioskPaymentComponent.vue
docs/kiosk/LOCKDOWN_POLICY_2026-04-27.md
missions/PRODUCT-COMPOSER-SYNC-B7-KIOSK-LOCKDOWN-RELEASE-AUDIT/*
```

#### Tests B7
- Playwright lockdown vert.
- CI scan bundles vert.

---

### Mission B8 — Delivery / Maps Hardening

**Pas de gate. Exécutable en parallèle de B0..B6.**

#### Intent
Verrouiller la politique de geocode failure, ajouter tests de forge, et garantir que tous les paths order trustent le backend.

#### Scope
1. `app/Services/Delivery/DeliveryQuoteService.php` qui orchestre : geocode (Google Maps API) → distance → DeliveryFeeService.
   - Si geocode fail : appliquer décision business D-DELIV-02 (block/manual/min).
   - Logger telemetry.
2. Patcher `OrderRequest` pour exiger `delivery_distance_km` côté DELIVERY (déjà en B0).
3. Tests forge :
   - POST `delivery_charge=0` avec `delivery_distance_km=5.01` → backend = 10.
   - POST `delivery_charge=999` avec `delivery_distance_km=5.01` → backend = 10.
   - POST sans `delivery_distance_km` pour DELIVERY → 422.
   - Geocode mock fail → comportement D-DELIV-02 honoré.
4. Documenter `docs/delivery/DELIVERY_FEE_POLICY_2026-04-27.md`.

#### Allowlist B8
```
app/Services/Delivery/DeliveryQuoteService.php
app/Services/Delivery/DeliveryFeeService.php          # noop si déjà OK
app/Http/Requests/OrderRequest.php                    # déjà patché en B0, peut s'étendre
app/Http/Controllers/Frontend/OrderController.php     # injection DeliveryQuoteService
tests/Feature/Delivery/DeliveryFeeForgePosTest.php
tests/Feature/Delivery/DeliveryFeeForgeWebTest.php
tests/Feature/Delivery/GeocodeFailurePolicyTest.php
docs/delivery/DELIVERY_FEE_POLICY_2026-04-27.md
missions/PRODUCT-COMPOSER-SYNC-B8-DELIVERY-MAPS-HARDENING/*
```

---

### Mission B9 — E2E + Hardware Signoff

**Précondition** : B0..B8 verts + `HG-E2E-HARDWARE-COMPOSER-SIGNOFF`.

#### Intent
Prouver le parcours complet utilisateur et signer le déploiement hardware.

#### Scope
1. Playwright E2E `tests/e2e/composer-mega-flow.spec.js` :
   - Manager crée catégorie + produit composé "Assiette Test".
   - Upload photo → kiosk voit tile dans 5 s.
   - Activate steps crudités/sauces/suppléments/boisson.
   - Add prix supplémentaires sans toucher au code.
   - POS commande "Assiette Test" → wizard composer suivi → quote backend.
   - Kiosk commande "Assiette Test" → wizard composer suivi → simulation paiement.
   - Stock atteint 0 → POS et kiosk affichent rupture.
   - KDS reçoit ticket avec composition.
   - OSS suit statut.
   - Queue number unique cross-channel.
2. Hardware UAT checklist `docs/hardware/UAT_COMPOSER_2026-04-27.md` :
   - Borne physique, TPE, imprimante, KDS écran, branch config réelle.
3. Final Claude verdict + final Codex audit après Claude.

#### Allowlist B9
```
tests/e2e/composer-mega-flow.spec.js
docs/hardware/UAT_COMPOSER_2026-04-27.md
reports/audit/CLAUDE_FINAL_COMPOSER_VERDICT_2026-XX-XX.md
reports/audit/CODEX_FINAL_COMPOSER_AUDIT_2026-XX-XX.md
missions/PRODUCT-COMPOSER-SYNC-B9-E2E-HARDWARE-SIGNOFF/*
```

---

## 4. Allowlists agrégées (vue d'ensemble)

> Pour chaque mission, l'allowlist exhaustive figure dans la section dédiée. Les fichiers `missions/<MISSION>/{allowlist.txt,execute_brief.md,input.json}` + `reports/audit/GPT_SELF_AUDIT_<MISSION>.md` + `reports/post_execute_latest.log` doivent **toujours** être inclus.

Règle de blast radius :
- B0 : 9 fichiers code/tests + suppression de 3 artefacts + 1 script lint.
- B2 : 5 migrations + 4 modèles + factories + ADR + 3 tests.
- B3 : 4 composants Vue + 1 store + 5 backend + 2 resources + 3 tests.
- B4 : 3 services + 2 resources + 3 vues frontend + 4 tests.
- B5 : 1 service + 2 patches symétriques + 3 listeners + 1 event + 2 vues + 5 tests.
- B6 : 1 event + 1 listener + 4 services patch + 1 migration + 1 vue dashboard + 4 tests.
- B7 : 1 lint + 1 e2e + 1 config + 1 vue + 1 doc.
- B8 : 1 service + 1 patch request + 1 controller + 3 tests + 1 doc.
- B9 : 1 e2e + 1 doc UAT + 2 verdicts.

---

## 5. Matrice de tests obligatoires

| Domaine | Test | Mission |
|---|---|---|
| Delivery fee parité backend | `DeliveryFeeServiceTest` (existant) | B0 (ne pas casser) |
| Delivery fee POS forge | `PosWalkInAndDeliveryFeeTest` (existant) | B0 (ne pas casser) |
| Delivery fee web frontend forge | `tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php` | B0 |
| Delivery fee frontend helper parité | `tests/js/deliveryCharge.spec.js` | B0 |
| Kiosk lockdown source + bundle | `tests/Feature/KioskLockdownTest.php` | B0 |
| Composer schema | `ComposerSchemaTest` | B2 |
| Stock schema + isolation | `StockLevelSchemaTest`, `StockBranchIsolationTest` | B2 |
| Composer API CRUD + authz | `ComposerProfileApiTest`, `ComposerAuthzTest` | B3 |
| Composer editor UI | `productComposerEditor.spec.js` | B3 |
| Projection composer profile parité | `MenuProjectionComposerProfileTest`, `MenuProjectionParitySentinelTest` (étendu) | B4 |
| Kiosk wizard consume profile | `kioskWizardComposerProfile.spec.js` | B4 |
| POS wizard consume profile | `posWizardComposerProfile.spec.js` | B4 |
| Stock decrement OrderService | `StockDecrementOrderServiceTest` | B5 |
| Stock decrement FrontendOrderService | `StockDecrementFrontendOrderServiceTest` | B5 |
| Stock release cancel/refund | `StockReleaseOnCancelTest`, `StockReleaseOnRefundTest` | B5 |
| Stock concurrence | `StockConcurrentDecrementTest` | B5 |
| Rupture UX kiosk/POS | `kioskRuptureUx.spec.js`, `posRuptureUx.spec.js` | B5 |
| CatalogChanged dispatch | `CatalogChangedDispatchTest` | B6 |
| Photo end-to-end kiosk | `PhotoEndToEndKioskInvalidationTest` | B6 |
| Catalog outbox idempotence | `CatalogOutboxIdempotencyTest` | B6 |
| Photo E2E Playwright | `tests/e2e/catalog-photo-propagation.spec.js` | B6 |
| Kiosk lockdown E2E | `tests/e2e/kiosk-lockdown.spec.js` | B7 |
| Delivery forge POS/Web | `DeliveryFeeForgePosTest`, `DeliveryFeeForgeWebTest` | B8 |
| Geocode failure policy | `GeocodeFailurePolicyTest` | B8 |
| Mega flow E2E | `tests/e2e/composer-mega-flow.spec.js` | B9 |

---

## 6. Gates humains (statuts requis)

| Gate ID | Fichier | Mission débloquée | Statut actuel |
|---|---|---|---|
| HG-COMPOSER-SCHEMA-ADR | `docs/gates/GATE_PRODUCT_COMPOSER_SCHEMA_2026-04-27.md` | B2 | PENDING_HUMAN_GATE |
| HG-STOCK-STOCKABLE-SCOPE | `docs/gates/GATE_STOCK_STOCKABLE_SCOPE_2026-04-27.md` | B2 + B5 | PENDING_HUMAN_GATE |
| HG-DASHBOARD-AUTHZ-CATALOG-OPS | `docs/gates/GATE_DASHBOARD_AUTHZ_CATALOG_OPS_2026-04-27.md` | B3 | PENDING_HUMAN_GATE |
| HG-FROZEN-ORDERSERVICE-UNLOCK | `docs/gates/GATE_FROZEN_ORDERSERVICE_UNLOCK_PRODUCT_COMPOSER_STOCK_2026-04-27.md` | B5 | PENDING_HUMAN_GATE |
| HG-E2E-HARDWARE-COMPOSER-SIGNOFF | `docs/gates/GATE_E2E_HARDWARE_COMPOSER_SIGNOFF_2026-04-27.md` | B9 | PENDING_HUMAN_GATE |

**Décisions business additionnelles à formaliser dans `docs/decisions/` (avant ou pendant B0..B8) :**
- D-DELIV-01 (0 km policy)
- D-DELIV-02 (geocode failure policy)
- D-KIOSK-01 (Retour button)
- D-KIOSK-02 (Cash at counter)
- D-COMPOSER-01 (Addon role enum)

---

## 7. Design dashboard / composer attendu

### 7.1 Architecture UI

```
┌── DASHBOARD ADMIN ─────────────────────────────────────────────┐
│                                                                │
│  Sidebar : Catalogue ─► Catégories / Produits / Composer      │
│                                                                │
│  ┌── Item Index ──────────────────────────────────────┐       │
│  │ [+ Nouveau produit]   [Filtres]   [Recherche]      │       │
│  │ Nom | Cat | Photo | Prix | Stock | Actions         │       │
│  │ ...                              [✏ Composer]      │       │
│  └────────────────────────────────────────────────────┘       │
│                                                                │
│  ┌── Item Show (existant + nouveau) ──────────────────┐       │
│  │ Tabs : [Info][Image][Variations][Extras][Addons]   │       │
│  │        [Composition (read-only summary - actuel)]   │       │
│  │        [Composer Editor (B3 nouveau)]               │       │
│  └────────────────────────────────────────────────────┘       │
└────────────────────────────────────────────────────────────────┘
```

### 7.2 Écran Composer Editor (B3)

```
┌─ Product Composer : Assiette Test ─────────────────────────────┐
│                                                                │
│ ┌─ Product header ──────────────────────────────────────┐     │
│ │ [photo] Assiette Test • Catégorie: Assiettes • 12.90€ │     │
│ │ Type: NON_VEG • Status: ACTIVE • Branch scope: global │     │
│ │ [Modifier infos] [Changer photo] [Pricing -> backend] │     │
│ └───────────────────────────────────────────────────────┘     │
│                                                                │
│ ┌─ Préset ──────────────────────────────────────────────┐     │
│ │ Template hérité catégorie : assiette                  │     │
│ │ Override produit : [ assiette ▾ ]   [Reset hérité]    │     │
│ └───────────────────────────────────────────────────────┘     │
│                                                                │
│ ┌─ Étapes (drag & drop) ────────────────────────────────┐     │
│ │ #1 ☑ Viandes        source: item_attribute "Viandes"  │     │
│ │     min 1 / max 3 / repeat ☑    surfaces : POS Kiosk  │     │
│ │     [choices : Boeuf 2€, Poulet 2€, Cordon 2.5€]      │     │
│ │ #2 ☑ Crudités       source: extra_group "garniture"   │     │
│ │     min 0 / max 6 / repeat ☐    surfaces : POS Kiosk  │     │
│ │ #3 ☐ Sauces         (désactivé)                       │     │
│ │ #4 ☑ Suppléments    source: extra_group "supplément"  │     │
│ │ #5 ☑ Boisson        source: addon role "drink"        │     │
│ │ #6 ☐ Menu/Frites    (désactivé)                       │     │
│ │     [+ Ajouter étape]                                  │     │
│ └───────────────────────────────────────────────────────┘     │
│                                                                │
│ ┌─ Aperçu POS ──────┐  ┌─ Aperçu kiosk ───────────────┐      │
│ │ (render mock)     │  │ (render mock)                 │      │
│ └───────────────────┘  └───────────────────────────────┘      │
│                                                                │
│ ┌─ Stock & disponibilité (read in B3, write in B5) ─────┐     │
│ │ Branche : Paris-Centre • on_hand: 23 • threshold: 5    │     │
│ │ Branche : Paris-Sud    • on_hand: 0  • RUPTURE        │     │
│ └───────────────────────────────────────────────────────┘     │
│                                                                │
│  [Brouillon] [Aperçu] [Publier (version 8 → 9)]  [Annuler]    │
└────────────────────────────────────────────────────────────────┘
```

### 7.3 Écran rupture POS/Kiosk (B5)

```
Kiosk:
  Choix grisé + badge "Indisponible aujourd'hui"
  Si user clique → toast "Désolé, ce choix est en rupture. Choisissez un autre"

POS:
  Pastille rouge sur le bouton choix
  Tooltip "Rupture branche - décrémenté à 12:34"
  Refus envoi commande si choix rupture sélectionné (validation pré-quote)
```

### 7.4 Widget connected devices snapshot (B6)

```
┌─ Connected devices snapshot ───────────────────────────────────┐
│ Branche      Device           Snapshot   Last seen             │
│ Paris-Centre POS (mac:abcdef) v=42       2 min ago             │
│ Paris-Centre Kiosk #1         v=42       1 min ago             │
│ Paris-Centre Kiosk #2         v=41 ⚠     8 min ago             │
│ Paris-Centre KDS              v=42       1 min ago             │
└────────────────────────────────────────────────────────────────┘
```

---

## 8. Critères d'exécution Codex (par mission)

Pour chaque mission B*, Codex doit produire dans cet ordre :

1. **Mission folder** `missions/PRODUCT-COMPOSER-SYNC-B<n>-<NAME>/` contenant :
   - `execute_brief.md` (intent, scope, forbidden, validation, exit criteria — utiliser le template existant 02A/03A).
   - `allowlist.txt` (exact, fichiers seulement, un par ligne).
   - `input.json` avec `task_id`, `mode`, `objective`, `allowlist_file`, `forbidden`, `invariants_considered`, `expected_outputs`.
2. **Implémentation** strictement dans l'allowlist.
3. **Tests verts** documentés dans `reports/post_execute_latest.log`.
4. **Self-audit** `reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B<n>-<NAME>.md` qui :
   - liste chaque fichier modifié/créé/supprimé,
   - confirme aucun écart d'allowlist,
   - confirme tests obligatoires verts,
   - liste les invariants vérifiés,
   - explicite les **risques résiduels** (si humains doivent décider).
5. **Aucun gate auto-approuvé**. Aucun gate édité (sauf B1 humain).
6. **Diff-check** `git diff --check` propre sur tous les fichiers de l'allowlist.
7. **Build** `npm run production` PASS si frontend modifié.
8. **Symétrie OrderService↔FrontendOrderService** documentée pour toute mission qui touche order (B5).
9. **Chaque PASS Codex** doit être suivi d'un **audit Claude court** (`reports/audit/CLAUDE_REVIEW_PRODUCT-COMPOSER-SYNC-B<n>-<NAME>.md`) avant d'enchaîner B<n+1>.

### Règles d'arrêt strictes

- Si **2 cycles de healing consécutifs** sur la même mission B sans PASS → escalade humaine (rule CLAUDE.md §8 healing).
- Si une mission tente d'éditer un fichier hors allowlist → REWORK forcé.
- Si le pricing frontend devient autoritaire (présence d'opérations arithmétiques sur `price`/`total` dans wizard ou checkout sans `// preview-only` explicite) → REWORK forcé.
- Si une migration n'est pas réversible (`down()` vide ou cassé) → REWORK forcé.
- Si un test E2E nécessite plus de 30 s sans evidence vidéo → réécrire ou marquer flaky.

---

## 9. Statut Codex actuel — résumé non-ambigu

| Item utilisateur | Etat réel | Action requise |
|---|---|---|
| Audit produits/cat/var/extras/addons | Partiel (composer summary read-only) | B3 |
| Dashboard central CRUD catégorie/produit/prix/photo/dispo/stock | Partiel (CRUD existent éclatés, composer absent, stock absent) | B3 + B5 |
| Stock partagé caisse + borne | **Absent** | B5 |
| POS et borne même catalogue | OK pour items de base, **partial** pour composer | B4 |
| Wizard POS conservé | OK (heuristique inchangée) | B4 garde fallback |
| Composer Shopify-style | **Absent** | B3 + B4 |
| Composer par type produit (assiette/sandwich/tacos/burger/salade/simple/menu) | Heuristique seulement | B3 + B4 |
| Étapes activables (pain/viandes/crudités/sauces/suppléments/menu/boisson/addons) | **Absent en write** | B3 + B4 |
| Override produit vs catégorie | **Absent** | B3 |
| Choix réutilisables sans code | Partiel (existent, pas exposés via composer) | B3 |
| Photos modifiables borne | OK upload, propagation à prouver E2E | B6 |
| Offres hebdo/mensuelles | **Absent en composer** | B3 + B6 |
| Queue number sans doublons | OK (migration `add_unique_branch_queue_number_to_orders`) | (déjà OK) |
| POS live board POS+kiosk | OK (déjà déployé W*) | (déjà OK) |
| KDS/OSS suivent cycle | OK (déjà déployé) | (déjà OK) |
| Paiement simulation | OK | (déjà OK) |
| Cleanup FR/demo/Bangladesh sans casser historique | Hors scope ici | (mission séparée déjà) |
| Audit avant/après chaque mission | OK pattern Codex | (continuer) |
| Livraison 5€/5km | Backend OK, **frontend cassé** | B0 |
| Lockdown kiosk admin | Source orpheline + bundle dead | B0 + B7 |
| Pricing 100% backend | Violé sur path web client | B0 |

---

## 10. Final Claude statement

Codex a produit du travail **honnête et tracé** sur les 3 slices livrés (Composition tab, projection itemAttributes, fix DeliveryFeeService backend) avec validations qui passent. L'auto-évaluation Codex `REWORK_REQUIRED_BEFORE_GLOBAL_PASS` est **correcte mais incomplète** : Codex a manqué deux P0 (helper frontend desync, OrderRequest web frontend non-authoritaire) et un risque release (bundle kiosk-admin compilé persisté).

**Prochaine étape immédiate (sans gate)** : exécuter B0 (P0 hotfix) et B7 (lockdown release audit) en parallèle. Ces deux missions sont sûres, ne touchent ni les zones gelées ni la base de données, et stabilisent la production avant que les missions de schéma/composer/stock soient débloquées par l'humain.

**Ensuite** : action humaine sur les 5 gates (B1) puis B2 → B3 → B4 → B5/B6 (parallèle) → B8 → B9.

**Rappel CLAUDE.md** : aucun PASS global sans E2E hardware sign-off (B9).

---

Document généré par Claude le 2026-04-27.
Trois rapports déjà existants (Codex final handoff, deep audit orchestration, continuation report) ont été lus et confrontés au code. Ce document **les complète** avec deux P0 nouveaux et un plan d'exécution Codex sans ambiguïté.
