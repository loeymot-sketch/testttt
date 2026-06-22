# Claude — FINAL EXECUTION PLAN (Product Composer / Catalogue / Stock / POS-Kiosk Sync) — 2026-04-27

Ce document **supersède** `CLAUDE_PRODUCT_COMPOSER_MEGA_AUDIT_AND_PLAN_2026-04-27.md` pour la phase d'exécution.
Il intègre les 5 gates approuvées par l'humain, les décisions business arrêtées, les corrections d'incohérences signalées par Codex, et précise la sous-mission **B5b — Kiosk cash-at-counter lifecycle**.

Status: `READY_FOR_EXECUTION`
Codex peut lancer **B0** immédiatement, puis B2 → B3 → B4 → B5a/B5b/B6 (parallèle) → B7/B8 (parallèle dès B0) → B9.

---

## 1. Décisions ratifiées par l'humain (2026-04-27)

### 1.1 Gates (toutes APPROVED)

| Gate ID | Décision | Contraintes |
|---|---|---|
| HG-COMPOSER-SCHEMA-ADR | APPROVED | Schema thin (`item_wizard_profiles`+`item_wizard_steps`), aucun prix dans le profil. |
| HG-STOCK-STOCKABLE-SCOPE | APPROVED | Option B polymorphe (`stockable_type`+`stockable_id`) couvrant `item`/`variation`/`extra`. |
| HG-DASHBOARD-AUTHZ-CATALOG-OPS | APPROVED | **Permissions minimales uniquement** : ajouter `catalog.compose` + `catalog.publish` à `Branch Admin` et `Tenant Admin`. **Pas de refonte** des rôles existants. Pas de nouveau rôle dédié pour cette phase. |
| HG-FROZEN-ORDERSERVICE-UNLOCK | APPROVED **STRICT** | Patch limité à 2 hunks symétriques par service : `OrderService::posOrderStore` ET `FrontendOrderService::store`, **après PricingService**, dans la même transaction, appelant `StockService::decrementForOrder`. Symétrie obligatoire prouvée par diff side-by-side dans l'audit. |
| HG-E2E-HARDWARE-COMPOSER-SIGNOFF | APPROVED | Aucun PASS commercial sans E2E Playwright complet + checklist hardware UAT signée + simulation paiement documentée. |

### 1.2 Décisions business

| ID | Sujet | Décision |
|---|---|---|
| **D-DELIV-01** | 0–5 km | 5 EUR minimum. Confirmé. `DeliveryFeeService::fromDistanceKm(0)=5` reste valide. |
| **D-DELIV-02** | Geocode failure | **Bloquer**. Pas de fallback silencieux à 5 €. Si Google Maps refuse l'adresse → 422 avec message "Adresse invalide, veuillez en saisir une autre". Pas de distance estimée à 0. |
| **D-KIOSK-01** | Bouton `Retour` paiement borne | Recommandation Codex retenue : **actif avant lancement paiement**, **désactivé pendant paiement**, jamais d'escape admin/caisse. |
| **D-KIOSK-02** | Espèces sur borne | **Garder**. Parcours = "à régler au comptoir" (cash-at-counter, voir §4). |
| **D-KIOSK-03** | Composant kiosk admin | **Suppression approuvée**. Pas de kiosk admin côté client. Maintenance staff = caisse/admin uniquement. |
| **D-COMPOSER-01** | Addon roles | APPROVED enum : `drink|side|dessert|menu_component|upsell`. **Pas de prix dupliqué** ; PricingService reste autoritaire. |

### 1.3 Décision protocolaire

- Aucune mission ne peut auto-approuver un gate ni en créer un nouveau sans demande explicite humaine.
- Toute nouvelle décision business doit être archivée dans `docs/decisions/` avant exécution code.

---

## 2. P0 / P1 finalisés (reclassification après vérification source)

### 2.1 P0 — Pricing SSOT (path web client)

| ID | Fichier:ligne | Description | Mission |
|---|---|---|---|
| **P0-A** | `resources/js/helpers/deliveryCharge.js:9` | `Math.max(5, Math.ceil(distance))` ≠ règle 5 €/5 km. UI customer affiche valeur fausse. Backend POS recalcule (sécure) mais path web (B) trustait la valeur cliente. | B0 |
| **P0-B** | `app/Http/Requests/OrderRequest.php:29-37, 57, 67, 71` | `prepareForValidation()` ne recalcule pas `delivery_charge`. ET les comparaisons `=== OrderType::DELIVERY` aux lignes 57/67/71 sont **strictes** alors que `request->input('order_type')` est string ⇒ règles silencieusement `nullable`. Même bug-class que POS-9-H.1.5 corrigé sur `PosOrderRequest`. Le total dans `FrontendOrderService.php:228,432` consomme la valeur cliente brute. | B0 |

**Verdict pricing : 2 P0 confirmés (A+B).**

### 2.2 P1 — Lockdown release hygiene (reclassement vs audit précédent)

| ID | Fichier | Description | Mission |
|---|---|---|---|
| P1-LOCKDOWN-1 | `public/js/kiosk-admin.js` (~54 KB, 2026-04-26 23:34) + `kiosk-admin.js.LICENSE.txt` | Bundle webpack public résiduel. Source actuelle a `DEFAULT_PIN = ''` (ligne 302) — donc le bundle n'expose pas un PIN par défaut faible. Reste dead code public. **P1 release hygiene**, pas P0 secret. | B0 |
| P1-LOCKDOWN-2 | `resources/js/components/frontend/kiosk/KioskAdminComponent.vue` | Source orpheline (aucun import). Suppression approuvée par D-KIOSK-03. | B0 |

> Correction explicite : ma version précédente parlait d'un fallback `'1234'` — c'était une lecture inexacte. **Source actuelle = `DEFAULT_PIN = ''`**. Risque réel = nettoyage build + traçabilité. Pas un P0 fuite-de-secret.

### 2.3 P1 — Composer / Stock / Sync (déjà connus)

Inchangés vs audit précédent : composer write absent, schema absent, runtime wizard sur heuristiques, stock V2 absent, addon roles absents, sync photo non prouvée E2E, OrderService non intégré stock. Couverts par B2..B9.

### 2.4 Décompte global

```
P0 pricing : 2 (A+B)            → B0
P1 lockdown : 2                  → B0
P1 composer/stock/sync : 10      → B2..B9
P2 (résolus par décisions) : 0   → archivés docs/decisions/
```

---

## 3. Plan révisé B0..B9

### Mission B0 — P0 hotfix (pricing SSOT + lockdown release) — RÉVISÉE

**Pas de gate. Exécutable immédiatement.**

#### B0.1 — Path web client : SSOT delivery + cast strict

Modifier `app/Http/Requests/OrderRequest.php` :

1. `prepareForValidation()` étendu :
   - Garder le merge `branch_id` kiosk existant.
   - Ajouter merge `delivery_charge` :
     ```php
     $orderTypeInt = (int) $this->input('order_type', 0);
     if ($orderTypeInt === OrderType::DELIVERY) {
         if ($this->filled('delivery_distance_km')) {
             $this->merge([
                 'delivery_charge' => app(DeliveryFeeService::class)
                     ->fromDistanceKm($this->input('delivery_distance_km')),
             ]);
         }
         // Si distance absente, ne rien merger : la règle delivery_distance_km required ci-dessous échouera.
     }
     ```
2. `rules()` :
   - Remplacer **toutes** les comparaisons `=== OrderType::DELIVERY` par `(int) $this->input('order_type') === OrderType::DELIVERY` (lignes 50, 57, 67, 71).
   - Ajouter `'delivery_distance_km' => $orderTypeInt === OrderType::DELIVERY ? ['required','numeric','min:0'] : ['nullable','numeric','min:0']` (D-DELIV-02 : pas de fallback silencieux).
3. `withValidator()` (déjà cast `(int) request('order_type')` à la ligne 101 — vérifier symétrie ; aucune autre modification.

**Pas de modification** de `FrontendOrderService.php` (frozen). Le merge `OrderRequest::prepareForValidation` modifie le payload **avant** que le service le lise — l'authority est restaurée sans toucher au service.

#### B0.2 — Frontend helper alignement

Modifier `resources/js/helpers/deliveryCharge.js` :

```js
// FoodKing V1 : 5 € par tranche de 5 km. Preview UI uniquement.
// Backend `DeliveryFeeService::fromDistanceKm` reste autoritatif (POS et web).
export function calculateDeliveryChargeFromDistance(distanceKm) {
    const distance = Number(distanceKm);
    if (!Number.isFinite(distance) || distance < 0) {
        return 0;
    }
    return Math.max(5, Math.ceil(distance / 5) * 5);
}
```

Ajouter `tests/js/deliveryCharge.spec.js` couvrant `0, 5, 5.01, 10, 10.01, -1, 'x', null, NaN, Infinity`.

#### B0.3 — Lockdown release : suppression bundle + source

1. Supprimer :
   - `public/js/kiosk-admin.js`
   - `public/js/kiosk-admin.js.LICENSE.txt`
   - `resources/js/components/frontend/kiosk/KioskAdminComponent.vue`
2. Ajouter garde CI `tools/lint/forbidden_bundles.sh` :
   ```bash
   #!/usr/bin/env bash
   set -euo pipefail
   if ls public/js/kiosk-admin*.js 2>/dev/null; then
       echo "❌ kiosk-admin bundle is forbidden in public/js/" >&2
       exit 1
   fi
   if [ -f resources/js/components/frontend/kiosk/KioskAdminComponent.vue ]; then
       echo "❌ KioskAdminComponent.vue source must remain deleted (D-KIOSK-03)" >&2
       exit 1
   fi
   echo "✅ kiosk-admin lockdown OK"
   ```
3. Tests :
   - **Vue Router** (`tests/js/kioskRouterLockdown.spec.js`) : import du module `kioskRoutes.js`, assert qu'il existe une entry `name: 'kiosk.admin'` avec `redirect: { name: 'kiosk.idle' }` ET aucun `component:` admin associé. Empêche un futur dev de transformer le redirect en route active.
   - **Feature Laravel** (`tests/Feature/KioskBundleLockdownTest.php`) : `$this->assertFalse(file_exists(public_path('js/kiosk-admin.js')))` + `$this->assertFalse(file_exists(resource_path('js/components/frontend/kiosk/KioskAdminComponent.vue')))`. **Pas** de `Route::has` (ce n'est pas une route Laravel).

#### B0.4 — Forbidden

- Pas d'édition `OrderService.php` ni `FrontendOrderService.php` (frozen).
- Pas de migration.
- Pas d'édition Composer summary tab existant.
- Pas d'édition runtime wizard.
- Pas d'édition `DeliveryFeeService` backend (déjà OK).

#### Allowlist B0 (final)

```
app/Http/Requests/OrderRequest.php
resources/js/helpers/deliveryCharge.js
tests/js/deliveryCharge.spec.js
tests/js/kioskRouterLockdown.spec.js
tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php
tests/Feature/KioskBundleLockdownTest.php
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

| Test | Cas couverts |
|---|---|
| `tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php` | 1) POST `/api/frontend/order` `delivery_distance_km=5.01, delivery_charge=999, order_type=DELIVERY` → ordre persiste avec `delivery_charge=10`. 2) `delivery_distance_km=0` → 5. 3) Pas de `delivery_distance_km` pour DELIVERY → 422. 4) `order_type` envoyé en string `"5"` (DELIVERY=5) → règle delivery_charge appliquée (preuve cast). 5) `order_type=TAKEAWAY` → règles delivery passent en nullable, pas de 422. |
| `tests/js/deliveryCharge.spec.js` | `0→5, 5→5, 5.01→10, 10→10, 10.01→15, -1→0, 'x'→0, null→0, NaN→0, Infinity→0`. |
| `tests/js/kioskRouterLockdown.spec.js` | Module exporte un array contenant entry `kiosk.admin` avec `redirect: {name:'kiosk.idle'}` et **sans** `component`. |
| `tests/Feature/KioskBundleLockdownTest.php` | bundle absent, source absente. |
| Régression existante | `php artisan test tests/Feature/PosWalkInAndDeliveryFeeTest.php`, `tests/Unit/Services/DeliveryFeeServiceTest.php`, `tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php`, `npx vitest run tests/js/productComposerSummary.spec.js` doivent rester verts. |
| Build | `npm run production` ne reproduit pas `kiosk-admin*.js` (vérifié par `tools/lint/forbidden_bundles.sh` post-build). |

#### PASS B0 ⇔ AUTRES MISSIONS DÉBLOQUÉES

- B0 PASS débloque B2 (parallèle B7/B8).
- B7/B8 peuvent commencer dès B0 PASS sans gate humain.

---

### Mission B1 — DONE (gates approuvés)

Plus rien à faire. Les 5 gates sont approved.
Codex doit dans son `execute_brief.md` de B2/B3/B5a/B5b citer le numéro de gate approuvé.

---

### Mission B2 — Schema ADR (Composer + Stock V2 + Addon roles)

**Précondition** : B0 PASS + gates HG-COMPOSER-SCHEMA-ADR + HG-STOCK-STOCKABLE-SCOPE approved.

#### Scope (mis à jour avec D-COMPOSER-01)

1. Migrations :
   - `*_create_item_wizard_profiles_table.php`
     - cols : `id`, `item_id` FK, `template` enum, `version` int, `is_published` bool, `published_at` ts, `branch_id_scope` FK nullable, `created_at/updated_at`.
     - index : `(item_id, branch_id_scope)`, `is_published`.
   - `*_create_item_wizard_steps_table.php`
     - cols : `id`, `profile_id` FK cascade, `step_key` string, `label` string, `source_type` enum `item_attribute|extra_group|addon|fixed`, `source_ref` string, `min_select` int, `max_select` int, `allow_repeat` bool, `visible_on` json, `stockable_choices` bool, `position` int, `is_active` bool, `addon_role` enum nullable `drink|side|dessert|menu_component|upsell` (utilisé quand `source_type=addon`).
     - check : `min_select <= max_select`, `position >= 0`.
   - `*_create_stock_levels_table.php`
     - cols : `id`, `branch_id` FK, `stockable_type` string, `stockable_id` int, `on_hand` int default 0, `reserved` int default 0, `threshold_low` int nullable.
     - unique : `(branch_id, stockable_type, stockable_id)`.
     - check : `on_hand >= 0`, `reserved >= 0`, `reserved <= on_hand`.
   - `*_create_stock_movements_table.php`
     - cols : `id`, `stock_level_id` FK, `branch_id` int, `delta` int, `reason` enum `order_created|order_canceled|refund|manual_in|manual_out|rupture_set`, `reference_type` string nullable, `reference_id` int nullable, `idempotency_key` string nullable, `created_at` ts.
     - unique : `idempotency_key` (where not null).
   - `*_add_role_to_item_addons_table.php`
     - col : `role` enum `drink|side|dessert|menu_component|upsell` nullable. **Pas de prix.**
2. Modèles + factories.
3. ADR `docs/architecture/ADR-COMPOSER-STOCK-2026-04-27.md` (référence gates approuvées + D-COMPOSER-01).

#### Forbidden B2
Aucune édition `OrderService`, `FrontendOrderService`, `PricingService`, controllers POS/Kiosk, runtime wizard, UI.

#### Tests B2
- `tests/Feature/Catalog/ComposerSchemaTest.php` — création profil → steps ordonnés → version bump → publish → unpublish.
- `tests/Feature/Catalog/AddonRolePersistenceTest.php` — persistance role enum, refus role hors enum.
- `tests/Feature/Stock/StockLevelSchemaTest.php` — invariants check constraints, unique tuple.
- `tests/Feature/Stock/StockBranchIsolationTest.php` — scope branch.
- `tests/Feature/Stock/StockMovementsAppendOnlyTest.php` — refus update/delete sur stock_movements (via observer ou trigger DB).

#### Allowlist B2 → voir §5.

---

### Mission B3 — Dashboard Composer Write (authz minimaliste)

**Précondition** : B2 PASS + HG-DASHBOARD-AUTHZ-CATALOG-OPS approved (minimal).

#### Authz minimaliste (D-AUTHZ minimal)

Ajouter **uniquement** :
- 2 permissions Spatie : `catalog.compose`, `catalog.publish`.
- Attribuer ces 2 permissions aux rôles existants `Branch Admin` et `Tenant Admin` via seeder idempotent.
- **Pas** de nouveau rôle.
- **Pas** de modification permissions existantes.
- **Pas** de migration permissions schema.

Tout autre rôle (POS Operator, Delivery Boy, etc.) reste **inchangé** et n'a **pas** accès composer.

#### Scope UI/API

Inchangé vs plan précédent (un écran composer write, CRUD profile/steps, refus payload contenant `price`).

#### Tests B3
- `ComposerProfileApiTest` (CRUD + publish dispatch event after commit).
- `ComposerAuthzMinimalTest` :
  - `Branch Admin` peut compose+publish dans son scope.
  - `Tenant Admin` peut compose+publish toutes branches.
  - `POS Operator` → 403 sur toutes les routes composer.
  - `Delivery Boy` → 403.
  - **Pas** de test "Catalog Manager" (rôle non créé).
- `productComposerEditor.spec.js`.

#### Allowlist B3 → voir §5 (suppression `database/seeders/ComposerPermissionsSeeder.php` étendu, remplacé par `database/seeders/ComposerPermissionsMinimalSeeder.php`).

---

### Mission B4 — Runtime wizard migration

Inchangée vs plan précédent. Précondition B3 livré.

---

### Mission B5a — StockService + symétrie OrderService/FrontendOrderService (HUNKS STRICTS)

**Précondition** : B2 + B4 PASS + HG-FROZEN-ORDERSERVICE-UNLOCK approved (strict).

#### Limites strictes du gate

Patch limité à **2 hunks par service** :
1. Hunk #1 (`OrderService::posOrderStore`) : injection `StockService::decrementForOrder($this->order, $idempotencyKey)` immédiatement après `PricingService::calculateOrder` et avant `FiscalSequenceService::next()`.
2. Hunk #2 (`OrderService::posOrderStore`) : aucune autre. Si compensation via `try/catch` est nécessaire, elle est dans `StockService` lui-même, pas dans OrderService.
3. Mêmes 2 hunks dans `FrontendOrderService::store` (ou méthode équivalente) — diff side-by-side fourni en self-audit.

Hors de ces hunks, **aucune** autre modification de ces deux fichiers.

#### Scope StockService

`app/Services/Stock/StockService.php` :
- `decrementForOrder(Order $order, string $idempotencyKey): void`
  - Lock pessimiste `lockForUpdate()` par `(branch, stockable_type, stockable_id)`.
  - Refus si `on_hand < requested` (throw `StockUnavailableException` qui rollback la transaction).
  - Insert `stock_movements` row avec `idempotency_key=hash($order->id|$line_uid)`.
  - Update `stock_levels.on_hand -= delta`.
- `releaseForOrder(Order $order, string $reason): void` (idempotent).
- Listeners `DecrementStockOnOrderCreated`/`ReleaseStockOnOrderCanceled`/`ReleaseStockOnRefundCreated` (mais le décrément principal est inline dans la transaction order, les listeners gèrent les événements après-commit pour broadcast realtime).

#### Tests B5a
- `StockDecrementOrderServiceTest` + `StockDecrementFrontendOrderServiceTest` (parité).
- `StockSymmetryDiffTest` — outil node `tools/audit/order-service-symmetry.mjs` qui compare les hunks dans les deux services et échoue si divergent.
- `StockReleaseOnCancelTest`, `StockReleaseOnRefundTest`, `StockConcurrentDecrementTest`.
- Régression : pas de régression sur `tests/Feature/PosWalkInAndDeliveryFeeTest.php`, `OrderQuoteService` tests existants.

---

### Mission B5b — Kiosk cash-at-counter lifecycle (NOUVELLE)

**Précondition** : B5a PASS (StockService livré). HG-FROZEN-ORDERSERVICE-UNLOCK couvre déjà cette extension via "lifecycle paiement comptoir" mais reste limitée à hunks stricts.

Cette mission existe parce que D-KIOSK-02 (espèces sur borne = paiement au comptoir) impose un nouveau cycle de vie payment côté order, KDS, et POS, avec contrainte NF525 (fiscalisation = au moment réel du paiement, pas à la création de la commande borne).

#### Spec lifecycle

##### Enums

`app/Enums/PaymentStatus.php` étendu :

```php
interface PaymentStatus
{
    const PAID            = 5;   // Existing : encaissé
    const UNPAID          = 10;  // Existing : pas encore réglé (legacy paths web)
    const PENDING_COUNTER = 15;  // NEW : commande borne en attente de paiement comptoir
    const REFUNDED        = 20;  // NEW : remboursé (utile B5a release)
}
```

`app/Enums/PosPaymentMethod.php` étendu :

```php
const COUNTER_DEFERRED = 6;  // NEW : "à régler au comptoir" — kiosk only
```

`app/Enums/Source.php` (vérifier — existe déjà avec source 1=POS, 2=customer site, 3=customer app, 4=KIOSK ; ajuster si besoin).

##### Transitions autorisées

Machine d'états payment_status (gérée dans `app/Domain/Order/PaymentStateMachine.php`, **nouveau** module isolé pour ne pas polluer `OrderStateMachine`) :

```
        ┌──────────────┐
        │ UNPAID       │ (web/POS card path inchangé)
        └──┬───────────┘
           │ (POS card success)
           ▼
        ┌──────────────┐
        │ PAID         │
        └──────────────┘

  KIOSK CASH AT COUNTER FLOW :
        ┌─────────────────────┐
        │ PENDING_COUNTER     │ (kiosk submit + payment_method=COUNTER_DEFERRED)
        └────┬────────┬───────┘
             │        │
   (POS confirm)  (POS reject / customer no-show)
             │        │
             ▼        ▼
       ┌────────┐  ┌──────────┐
       │ PAID   │  │ REFUNDED │ (no actual money taken, but order cancelled + status REFUNDED for audit)
       └────────┘  └──────────┘
```

Transitions interdites (test de garde) : `PENDING_COUNTER → UNPAID`, `PAID → PENDING_COUNTER`, `REFUNDED → *`.

##### Règles fiscales NF525

- À la création de l'ordre kiosk avec `payment_method=COUNTER_DEFERRED` : **`fiscal_sequence_no = NULL`** (pas d'allocation FiscalSequenceService).
- Au moment où POS confirme l'encaissement (transition `PENDING_COUNTER → PAID`) :
  - dans la même transaction DB, appeler `FiscalSequenceService::next($branch_id)` et persister `fiscal_sequence_no` avant le commit.
  - dispatch `OrderPaidAtCounter` event après commit.
- Si transition `PENDING_COUNTER → REFUNDED` : pas d'allocation fiscal_sequence_no. Order canceled, lié à `OrderCanceled` event qui déclenche `ReleaseStockOnOrderCanceled` (B5a).

##### Impressions

- Borne kiosk : ticket d'attente **non fiscal** (badge "Bon de commande – À régler au comptoir – Numéro X"). Imprimante kiosk continue d'imprimer.
- POS au moment de l'encaissement : ticket fiscal NF525 émis (path POS existant).
- Re-imprimer le bon kiosk depuis POS : route `/api/admin/orders/{id}/reprint-counter-slip` (read-only, pas de modif fiscale).

##### KDS

- Ticket KDS reçu **dès la création de la commande borne** (pas d'attente du paiement).
- Badge visible "PAIEMENT COMPTOIR – NON RÉGLÉ" en rouge sur le ticket KDS tant que `payment_status=PENDING_COUNTER`.
- Quand POS confirme paiement → broadcast WS `order.payment.confirmed` → KDS retire le badge automatiquement.
- Si `PENDING_COUNTER → REFUNDED` → broadcast `order.canceled` → KDS retire le ticket (path déjà existant).

##### POS écran "Encaissement borne"

Nouvelle vue `resources/js/components/admin/pos/PosCounterCollectComponent.vue` :
- Liste filtrée des commandes `payment_status=PENDING_COUNTER` de la branche, triée par création.
- Chaque ligne : numéro, total backend, items résumé, age depuis création, badge "À encaisser".
- Action `[Encaisser]` → modal :
  - Sélection mode paiement (CASH/CARD/MOBILE_BANKING/TICKET_RESTAURANT/OTHER).
  - Saisie reçu si CASH (champ `pos_received_amount`).
  - Validation côté serveur via `PaymentService::confirmCounterPayment(Order, mode, received)`.
  - Sur succès : transition `PENDING_COUNTER → PAID`, fiscal_sequence_no alloué, ticket fiscal imprimé.
- Action `[Annuler commande]` → confirmation → transition `PENDING_COUNTER → REFUNDED`, stock libéré (B5a).

##### Routes API

- `POST /api/admin/pos/counter-collect/{order}/confirm` — body `{ mode, received, note }` — middleware `permission:pos`.
- `POST /api/admin/pos/counter-collect/{order}/cancel` — body `{ reason }` — middleware `permission:pos`.
- Les deux retournent `OrderResource` mis à jour.

##### Borne kiosk — flow customer

`KioskPaymentComponent.vue` :
- Section "Espèces" déclenche modal "Vous serez invité à payer au comptoir lors du retrait de votre commande. Votre numéro de commande sera affiché sur l'écran de retrait."
- Submit → `POST /api/frontend/order` avec `payment_method=COUNTER_DEFERRED`, ordre créé `payment_status=PENDING_COUNTER`.
- Page `kiosk.confirmation` mentionne explicitement "À régler au comptoir – Numéro X".

#### Forbidden B5b
- Pas d'impression de ticket fiscal côté borne.
- Pas de modification de `FiscalSequenceService`.
- Pas d'auto-allocation fiscal_sequence_no à la création kiosk.
- Pas de broadcast WS qui exposerait des champs payment cross-branche.

#### Allowlist B5b → voir §5.

#### Tests B5b

| Test | Cas |
|---|---|
| `PaymentStateMachineTransitionsTest` | Transitions valides/invalides exhaustives ; no-magic-strings (assertions sur enum int). |
| `KioskCounterDeferredOrderCreationTest` | Submit borne CASH → ordre créé `payment_status=PENDING_COUNTER`, `payment_method=COUNTER_DEFERRED`, `fiscal_sequence_no=NULL`. KDS event dispatched (post-commit) avec `payment_pending_counter=true`. |
| `PosCounterConfirmTest` | POST confirm → transition PAID + `fiscal_sequence_no` alloué dans **la même transaction**. WS broadcast `order.payment.confirmed`. |
| `PosCounterCancelTest` | POST cancel → transition REFUNDED, stock release (B5a), aucune allocation fiscal_sequence_no. |
| `KioskCounterAuthzTest` | POS Operator peut confirm/cancel ; non-POS users → 403. Branch isolation respectée. |
| `KdsBadgePendingCounterTest` | Test JS : composant KDS affiche badge si `payment_status=PENDING_COUNTER`, retire le badge sur event WS. |
| `KioskConfirmationCounterMessageTest` | spec : page confirmation kiosk affiche "À régler au comptoir" + numéro. |
| `KioskPaymentRetourBehaviorTest` | spec : bouton Retour visible AVANT submit, **disabled** pendant `submit-pending` (D-KIOSK-01). |
| Régression | aucun changement payment_status sur path POS/web non-kiosk. |

---

### Mission B6 — Catalog eventing unifié + photo E2E

Inchangée vs plan précédent. Peut tourner en parallèle de B5a/B5b.

---

### Mission B7 — Kiosk lockdown release audit (étendue)

**Pas de gate. Peut tourner dès B0 PASS.**

Ajouts vs plan précédent :

1. Test E2E Playwright `tests/e2e/kiosk-lockdown.spec.js` :
   - Visit `/kiosk/admin` → redirected to `/kiosk/idle`.
   - Visit `/js/kiosk-admin.js` → 404.
   - Inspect kiosk payment screen DOM → bouton `Retour` actif **avant** submit, `disabled` après click submit (D-KIOSK-01).
   - Aucun lien admin/caisse visible dans payment, cart, cash-instruction, idle.
2. Test feature `tests/Feature/KioskBundleLockdownTest.php` étendu :
   - Bundle absent (post-build).
   - Source absente.
3. CI scan `tools/lint/scan_kiosk_bundles.mjs` : scan `public/js/*.js` pour patterns `KioskAdmin`, `kiosk_admin_pin`, `'1234'`, `kiosk-admin-overlay`. Si match → fail.
4. Documenter `docs/kiosk/LOCKDOWN_POLICY_2026-04-27.md` avec D-KIOSK-01/02/03.

**Note** : la sous-feature "cash at counter" est livrée par B5b, pas B7. B7 reste lockdown release.

---

### Mission B8 — Delivery / Maps hardening (geocode block)

**Pas de gate. Peut tourner dès B0 PASS.**

#### Mise à jour D-DELIV-02

Geocode failure = **block**. Pas de fallback silencieux à 5 €.

`app/Services/Delivery/DeliveryQuoteService.php` :
- `quoteForAddress(int $branchId, array $address): array` — orchestre geocode → distance → fee.
- Si geocode fail (status non-OK, latitude/longitude absentes, distance NaN) : `throw GeocodeUnavailableException` → 422 `{ "code":"GEOCODE_FAILED", "message":"Adresse invalide. Veuillez en saisir une autre." }`.
- Logger telemetry `delivery.geocode.fail`.

`OrderRequest::prepareForValidation` (étendu B0) : si `delivery_distance_km` absent et `order_type=DELIVERY` → 422 (déjà couvert B0). Si présent → recompute.

Frontend : intercepter 422 GEOCODE_FAILED dans `CheckoutComponent.vue` et `PosComponent.vue` → bannière rouge "Adresse non reconnue" + champ adresse re-focus.

#### Tests B8
- `DeliveryFeeForgePosTest` (forge `delivery_charge` → backend recompute).
- `DeliveryFeeForgeWebTest` (forge web).
- `GeocodeFailureBlocksOrderTest` (mock Maps fail → 422).
- `GeocodeFailureFrontendBannerTest` (spec UI).
- `tests/js/checkoutGeocodeError.spec.js`.

---

### Mission B9 — E2E + hardware signoff

Inchangée vs plan précédent. **Précondition** : B0..B8 PASS + HG-E2E-HARDWARE-COMPOSER-SIGNOFF approved (déjà OK).

Ajouter scénario E2E spécifique cash-at-counter :
- Customer borne paie en espèces → ordre PENDING_COUNTER → KDS reçoit ticket avec badge → POS confirme paiement → fiscal_sequence_no assigné → ticket fiscal imprimé → KDS badge retiré.
- Cas annulation : POS cancel `PENDING_COUNTER` → stock release, KDS ticket retiré, aucun fiscal_sequence_no consommé.

---

## 4. Cash-at-counter spec consolidée (référence)

### 4.1 Enums (B5b)

```
PaymentStatus :  PAID=5, UNPAID=10, PENDING_COUNTER=15 (NEW), REFUNDED=20 (NEW)
PosPaymentMethod : CASH=1, CARD=2, MOBILE_BANKING=3, OTHER=4, TICKET_RESTAURANT=5, COUNTER_DEFERRED=6 (NEW)
```

### 4.2 Transitions

```
UNPAID → PAID                       (POS card / web checkout)
PAID → REFUNDED                     (refund manual)
PENDING_COUNTER → PAID              (POS counter confirm) ⇒ alloue fiscal_sequence_no
PENDING_COUNTER → REFUNDED          (POS counter cancel) ⇒ stock release, pas d'alloc fiscal
TOUTES AUTRES                       → 422 InvalidPaymentTransition
```

### 4.3 Écrans concernés

| Écran | Composant | Comportement |
|---|---|---|
| Borne paiement | `KioskPaymentComponent.vue` | option Espèces → modal "À régler au comptoir" → submit `payment_method=COUNTER_DEFERRED`. Bouton Retour : actif avant submit, désactivé pendant. |
| Borne confirmation | `KioskConfirmationComponent.vue` | message "À régler au comptoir – Numéro X" si `payment_status=PENDING_COUNTER`. |
| Borne ticket | `KioskCashInstructionComponent.vue` | re-purposé : ticket non-fiscal "Bon de commande". |
| KDS | `KdsTicketCardComponent.vue` (existant) | badge rouge "PAIEMENT COMPTOIR" tant que `payment_status=PENDING_COUNTER`. |
| POS encaissement | `PosCounterCollectComponent.vue` (NEW) | liste pending counter, action confirm/cancel. |
| POS dashboard | `PosLiveBoardComponent.vue` (existant) | colonne `payment_status` ajoutée si pertinent. |

### 4.4 Routes API (B5b)

```
POST /api/frontend/order                         (existant, étendu pour COUNTER_DEFERRED)
POST /api/admin/pos/counter-collect/{order}/confirm
POST /api/admin/pos/counter-collect/{order}/cancel
GET  /api/admin/pos/counter-collect/pending     (filtered by branch)
POST /api/admin/orders/{order}/reprint-counter-slip   (read-only re-print)
```

### 4.5 Garde NF525

- `fiscal_sequence_no` reste `NULL` jusqu'à transition vers PAID.
- Allocation atomique dans `PaymentService::confirmCounterPayment` (DB transaction + `FiscalSequenceService::next`).
- Test `NF525CounterDeferredFiscalGuardTest` : essai de fiscalisation au stade `PENDING_COUNTER` → exception.

---

## 5. Allowlists et tests par mission (vue compacte)

### B0
Voir §3 B0. 14 entrées allowlist (incl. 3 suppressions, 1 script lint).
Tests : 4 nouveaux + régression existante + build.

### B2
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
app/Models/ItemAddon.php                                    # add role cast only
docs/architecture/ADR-COMPOSER-STOCK-2026-04-27.md
tests/Feature/Catalog/ComposerSchemaTest.php
tests/Feature/Catalog/AddonRolePersistenceTest.php
tests/Feature/Stock/StockLevelSchemaTest.php
tests/Feature/Stock/StockBranchIsolationTest.php
tests/Feature/Stock/StockMovementsAppendOnlyTest.php
missions/PRODUCT-COMPOSER-SYNC-B2-SCHEMA-ADR/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B2-SCHEMA-ADR.md
reports/post_execute_latest.log
```

### B3 (authz minimaliste)
```
resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue
resources/js/components/admin/items/composer/StepEditorComponent.vue
resources/js/components/admin/items/composer/StepPreviewComponent.vue
resources/js/store/modules/composer.js
resources/js/router/modules/adminRoutes.js
app/Http/Controllers/Admin/ComposerProfileController.php
app/Http/Controllers/Admin/ComposerStepController.php
app/Http/Requests/ComposerProfileRequest.php
app/Http/Requests/ComposerStepRequest.php
app/Http/Resources/ComposerProfileResource.php
app/Http/Resources/ComposerStepResource.php
app/Services/Composer/ComposerProfileService.php
app/Services/Composer/ComposerStepService.php
app/Events/ComposerProfilePublished.php
routes/api.php                                              # ajouter routes scoped middleware permission:catalog.compose / publish
database/seeders/ComposerPermissionsMinimalSeeder.php       # ajout 2 permissions, attribution Branch Admin + Tenant Admin
tests/Feature/Composer/ComposerProfileApiTest.php
tests/Feature/Composer/ComposerAuthzMinimalTest.php
tests/js/productComposerEditor.spec.js
missions/PRODUCT-COMPOSER-SYNC-B3-DASHBOARD-COMPOSER-WRITE/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B3-DASHBOARD-COMPOSER-WRITE.md
reports/post_execute_latest.log
```

### B4
Inchangée vs plan précédent.

### B5a
```
app/Services/Stock/StockService.php
app/Exceptions/Stock/StockUnavailableException.php
app/Services/OrderService.php                               # 2 hunks stricts (HG)
app/Services/FrontendOrderService.php                       # 2 hunks stricts symétriques (HG)
app/Listeners/DecrementStockOnOrderCreated.php
app/Listeners/ReleaseStockOnOrderCanceled.php
app/Listeners/ReleaseStockOnRefundCreated.php
app/Events/StockLevelChanged.php
app/Providers/EventServiceProvider.php                      # wiring
app/Http/Resources/MenuItemResource.php                     # expose stock per choice si pertinent
resources/js/components/frontend/kiosk/KioskWizardComponent.vue   # badge rupture
resources/js/components/admin/pos/PosComponent.vue                # badge rupture
resources/js/store/modules/stock.js                               # echo channel branch.{id}.stock
tools/audit/order-service-symmetry.mjs
tests/Feature/Stock/StockDecrementOrderServiceTest.php
tests/Feature/Stock/StockDecrementFrontendOrderServiceTest.php
tests/Feature/Stock/StockReleaseOnCancelTest.php
tests/Feature/Stock/StockReleaseOnRefundTest.php
tests/Feature/Stock/StockConcurrentDecrementTest.php
tests/Feature/Stock/StockSymmetryDiffTest.php
tests/js/kioskRuptureUx.spec.js
tests/js/posRuptureUx.spec.js
missions/PRODUCT-COMPOSER-SYNC-B5A-STOCK-V2-CORE/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B5A-STOCK-V2-CORE.md
reports/post_execute_latest.log
```

### B5b — Cash-at-counter lifecycle (NOUVELLE)
```
app/Enums/PaymentStatus.php                                 # add PENDING_COUNTER=15, REFUNDED=20
app/Enums/PosPaymentMethod.php                              # add COUNTER_DEFERRED=6
app/Domain/Order/PaymentStateMachine.php                    # NEW
app/Services/PaymentService.php                             # add confirmCounterPayment + cancelCounterPayment
app/Services/OrderService.php                               # NF525 guard : skip fiscal alloc if payment_method=COUNTER_DEFERRED at create (1 hunk strict)
app/Services/FrontendOrderService.php                       # symétrie, même skip if COUNTER_DEFERRED (1 hunk strict)
app/Http/Controllers/Admin/PosCounterCollectController.php  # NEW
app/Http/Requests/PosCounterCollectConfirmRequest.php       # NEW
app/Http/Requests/PosCounterCollectCancelRequest.php        # NEW
app/Http/Resources/PendingCounterOrderResource.php          # NEW
app/Events/OrderPaidAtCounter.php                           # NEW
app/Events/OrderCounterCanceled.php                         # NEW
app/Listeners/BroadcastOrderPaymentConfirmedToKds.php       # NEW
routes/api.php                                              # 4 routes (voir §4.4)
resources/js/components/admin/pos/PosCounterCollectComponent.vue   # NEW
resources/js/components/frontend/kiosk/KioskPaymentComponent.vue   # update Retour disable + COUNTER_DEFERRED submit
resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue   # message PENDING_COUNTER
resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue   # ticket non-fiscal text
resources/js/components/admin/kds/KdsTicketCardComponent.vue       # badge "PAIEMENT COMPTOIR"
resources/js/store/modules/posCounter.js                    # NEW
docs/payment/CASH_AT_COUNTER_LIFECYCLE_2026-04-27.md        # spec
tests/Feature/Payment/PaymentStateMachineTransitionsTest.php
tests/Feature/Payment/KioskCounterDeferredOrderCreationTest.php
tests/Feature/Payment/PosCounterConfirmTest.php
tests/Feature/Payment/PosCounterCancelTest.php
tests/Feature/Payment/KioskCounterAuthzTest.php
tests/Feature/Payment/NF525CounterDeferredFiscalGuardTest.php
tests/js/kdsBadgePendingCounter.spec.js
tests/js/kioskConfirmationCounterMessage.spec.js
tests/js/kioskPaymentRetourBehavior.spec.js
tests/js/posCounterCollect.spec.js
missions/PRODUCT-COMPOSER-SYNC-B5B-CASH-AT-COUNTER-LIFECYCLE/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B5B-CASH-AT-COUNTER-LIFECYCLE.md
reports/post_execute_latest.log
```

### B6
Inchangée vs plan précédent.

### B7
```
tools/lint/scan_kiosk_bundles.mjs
tests/e2e/kiosk-lockdown.spec.js
tests/Feature/KioskBundleLockdownTest.php                   # extension
config/kiosk.php                                            # NO new flags pour cash (D-KIOSK-02 = comportement default)
resources/js/components/frontend/kiosk/KioskPaymentComponent.vue   # patch Retour disable (cohérent B5b)
docs/kiosk/LOCKDOWN_POLICY_2026-04-27.md
missions/PRODUCT-COMPOSER-SYNC-B7-KIOSK-LOCKDOWN-RELEASE-AUDIT/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B7-KIOSK-LOCKDOWN-RELEASE-AUDIT.md
reports/post_execute_latest.log
```

### B8
```
app/Services/Delivery/DeliveryQuoteService.php              # NEW
app/Exceptions/Delivery/GeocodeUnavailableException.php     # NEW
app/Http/Requests/OrderRequest.php                          # extension B0 si nécessaire
app/Http/Controllers/Frontend/OrderController.php           # injection DeliveryQuoteService
resources/js/components/frontend/checkout/CheckoutComponent.vue   # bannière 422 GEOCODE_FAILED
resources/js/components/admin/pos/PosComponent.vue                # bannière 422 GEOCODE_FAILED
tests/Feature/Delivery/DeliveryFeeForgePosTest.php
tests/Feature/Delivery/DeliveryFeeForgeWebTest.php
tests/Feature/Delivery/GeocodeFailureBlocksOrderTest.php
tests/js/checkoutGeocodeError.spec.js
docs/delivery/DELIVERY_FEE_POLICY_2026-04-27.md
missions/PRODUCT-COMPOSER-SYNC-B8-DELIVERY-MAPS-HARDENING/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B8-DELIVERY-MAPS-HARDENING.md
reports/post_execute_latest.log
```

### B9
Inchangée vs plan précédent + scénario E2E cash-at-counter.

---

## 6. Critères d'exécution Codex (par mission, exhaustif)

Pour chaque mission B<n>, Codex doit produire :

1. **Mission folder** `missions/PRODUCT-COMPOSER-SYNC-B<n>-<NAME>/` avec `execute_brief.md` (intent, scope, forbidden, validation, exit criteria), `allowlist.txt` (un fichier par ligne), `input.json` (`task_id`, `mode`, `objective`, `allowlist_file`, `forbidden`, `invariants_considered`, `expected_outputs`, `gates_referenced`).
2. Implémentation strictement dans l'allowlist.
3. Tests obligatoires verts (matrice §3 et §5).
4. `git diff --check` propre.
5. `npm run production` PASS si frontend modifié.
6. **Symétrie OrderService↔FrontendOrderService** documentée par diff side-by-side dans le self-audit pour B5a et B5b.
7. **Self-audit** `reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B<n>-<NAME>.md` qui :
   - liste fichiers créés/modifiés/supprimés ;
   - confirme aucun écart d'allowlist ;
   - liste les invariants vérifiés (pricing-backend-ssot, branch-isolation, dispatch-after-commit, frozen-zone-respected, NF525-respected, no-magic-strings) ;
   - liste les tests passés ;
   - liste les risques résiduels.
8. **Audit Claude post-mission** `reports/audit/CLAUDE_REVIEW_PRODUCT-COMPOSER-SYNC-B<n>-<NAME>.md` avant d'enchaîner B<n+1>.

### Règles d'arrêt strictes (inchangées)

- 2 cycles healing même mission sans PASS → escalade humaine.
- Édition hors allowlist → REWORK forcé.
- Pricing frontend autoritaire détecté (op arithmétique sur `price`/`total` sans `// preview-only`) → REWORK forcé.
- Migration sans rollback testé → REWORK forcé.
- Édition `OrderService` ou `FrontendOrderService` au-delà des hunks autorisés (B5a = 2 hunks max, B5b = 1 hunk strict NF525-skip) → REWORK forcé + escalade.
- Allocation `fiscal_sequence_no` à la création kiosk-cash-at-counter → REWORK forcé (NF525 violation).

---

## 7. Ordre d'exécution recommandé

```
[NOW]    B0 (P0 hotfix)           ──────────────► PASS débloque B2/B7/B8
[+1]     B7 (lockdown)            ── parallèle B0
         B8 (delivery hardening)  ── parallèle B0
[+2]     B2 (schema ADR)          ◄── HG-COMPOSER-SCHEMA-ADR + HG-STOCK-STOCKABLE-SCOPE
[+3]     B3 (composer write)      ◄── HG-DASHBOARD-AUTHZ-CATALOG-OPS
[+4]     B4 (runtime wizard)      ◄── après B3
[+5]     B5a (stock V2 core)      ◄── HG-FROZEN-ORDERSERVICE-UNLOCK strict
         B6 (catalog eventing)    ── parallèle B5a (pas de gate pour B6)
[+6]     B5b (cash-at-counter)    ◄── après B5a (étend HG-FROZEN-ORDERSERVICE-UNLOCK)
[+7]     B9 (E2E + hardware)      ◄── après tous, HG-E2E-HARDWARE-COMPOSER-SIGNOFF
```

Aucun PASS commercial avant B9.

---

## 8. Note de couverture des incohérences signalées

| Signalement Codex | Correction appliquée |
|---|---|
| "deux P0" vs liste P0-A/B/C/D | §2 : 2 P0 pricing (A+B), 2 P1 lockdown (C→P1-LOCKDOWN-1, D→P1-LOCKDOWN-2). Décompte explicite §2.4. |
| `DEFAULT_PIN = ''` actuel, pas `'1234'` | §2.2 corrigé. Bundle reste à supprimer pour release hygiene (P1, pas P0). |
| `Route::has('kiosk.admin')` invalide | §3 B0.3 : remplacé par test Vue Router (`tests/js/kioskRouterLockdown.spec.js`) + assertion fichiers absents (`tests/Feature/KioskBundleLockdownTest.php`). Jamais `Route::has` sur cette ligne. |
| Cast `order_type` dans OrderRequest | §3 B0.1 : `(int) $this->input('order_type')` ajouté + remplacement de tous les `===` stricts. Test "string `\"5\"`" exigé. |
| B3 dépend de B2 + authz | §3 B3 : précondition explicite "B2 PASS + HG-DASHBOARD-AUTHZ-CATALOG-OPS approved". Authz minimaliste détaillée (2 permissions Spatie attribuées à Branch Admin + Tenant Admin uniquement). |
| Cash-at-counter lifecycle | §4 + Mission B5b nouvelle, enums + transitions + écrans + NF525 guard explicite. |
| Geocode policy | §3 B8 : block, pas de fallback silencieux à 5 €. |
| Bouton Retour | §3 B7 + §4.3 : actif avant submit, disabled pendant submit, jamais d'escape admin. |

---

Fin de document. Codex peut exécuter B0 maintenant.
