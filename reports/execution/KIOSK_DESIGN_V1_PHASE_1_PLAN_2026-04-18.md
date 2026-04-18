# KIOSK DESIGN V1 — Plan d'exécution Phase 1

- **Date** : 2026-04-18
- **Phase** : 1 — Prérequis backend (migrations + API kiosk)
- **Statut** : planning approuvé → implémentation
- **Auteur** : orchestration Claude basée sur :
  - Master prompt « MASTER PROMPT CURSOR — Intégration Design Kiosk FoodKing V1 »
  - Audit backend exhaustif (scan 10 sections, cf. session)
  - Invariants projet (`docs/BUSINESS_RULES.md`, `docs/ORDER_FLOW.md`, `docs/AUTHZ_MATRIX.md`, `docs/EVENT_CONTRACT.md`)

---

## 0. Découvertes critiques de l'audit backend

**La Phase 1 du master prompt part d'un postulat partiellement faux** : beaucoup
d'endpoints « à créer » existent déjà sous un autre nom / chemin, souvent
avec une implémentation SSOT mature :

| Endpoint demandé par prompt | Existant ? | Chemin actuel |
|---|---|---|
| `GET  /api/frontend/menu` | ❌ | absent — projection dual-channel uniquement admin |
| `POST /api/frontend/order` | ✅ **complet, SSOT, idempotent, outbox** | `Frontend\OrderController@store` via `OrderRequest` + `FrontendOrderService::myOrderStore` |
| `POST /api/frontend/pricing/preview` | ❌ | absent |
| `POST /api/frontend/promo/validate` | ~= | existant sous `/api/frontend/coupon/coupon-checking` |
| `GET  /api/frontend/upsell` | ~= | existant sous `/api/frontend/item/kiosk-upsell` |
| `POST /api/frontend/loyalty/opt-in` | ~= | existant sous `/api/frontend/loyalty/register` |
| `POST /api/frontend/kiosk/event` | ~= | existant sous `/api/frontend/kiosk-event` (tiret) |

### Décision stratégique Phase 1

Plutôt que **dupliquer**, la Phase 1 va :

1. **CRÉER** les endpoints réellement absents :
   - `GET /api/frontend/menu` — unified menu payload, dual-channel aware, 1 round-trip kiosk.
   - `POST /api/frontend/pricing/preview` — wrap `PricingService::calculateOrder(PricingRequest::forKiosk(...))` sans persistance, pour upsell + promo UI.

2. **ALIASER** (routes secondaires pointant le controller existant) les endpoints au bon nom pour cohérence master prompt :
   - `POST /api/frontend/promo/validate`  → `CouponController@couponChecking` (même behaviour, URL propre).
   - `GET  /api/frontend/upsell`          → `ItemController@kioskUpsell`.
   - `POST /api/frontend/loyalty/opt-in`  → `LoyaltyController@register` + consent RGPD explicite (ajout d'un champ `consent_accepted: required|accepted` côté FormRequest).
   - `POST /api/frontend/kiosk/event`     → `KioskEventController@store` (slash-form au lieu de tiret).
   
   Les URLs historiques restent actives → **aucune régression** côté composants Vue existants.

3. **NE PAS TOUCHER** `POST /api/frontend/order` : le controller est déjà SSOT
   strict (audit confirmé). Action limitée à ajouter des tests de régression
   ciblés sur les invariants (branch_id serveur-seul, total/subtotal/discount
   recalculés). **Zéro modification** du service.

4. **MIGRATIONS NET NEW** (5) :
   - `categories.parent_id` nullable FK self.
   - `allergens` (EU 14) + pivot `item_allergen` (coexiste avec `items.allergen_flags` JSON existant — le pivot est la source normalisée, le JSON devient cache projeté à terme).
   - `upsell_rules` (branch-scoped, 3 trigger types) — n'annule pas les flags `is_upsell` + `kiosk_upsell_include` existants, le service les combine.
   - `kiosk_promos` (branch-scoped) — **distinct** des `coupons` globaux existants (per master prompt §1.4).
   - `branches.available_locales` JSON (`["fr","en","ar"]`).

---

## 1. Séquence d'exécution (9 sous-phases, dépendances strictes)

L'ordre suit le graphe de dépendances (migration avant code, code avant test,
tests verts avant passage au suivant). Chaque sous-phase termine par un **gate
intermédiaire** vérifiable par test unitaire ou `php artisan migrate:fresh`.

```
1.0 ──► 1.1 ──► 1.2 ──► 1.3 ──┬─► 1.4  (menu)
                               ├─► 1.5  (pricing preview)
                               ├─► 1.6  (promo validate)
                               ├─► 1.7  (upsell)
                               ├─► 1.8  (loyalty opt-in)
                               └─► 1.9  (kiosk event)
                                     │
                                     └─► 1.10 tests + rapport
```

### 1.0 — Prep (10 min)
- Créer branche mentale `kiosk-design-v1/phase-1` (engagement commits atomiques `feat(kiosk/phase-1.X)`).
- Lecture `docs/BUSINESS_RULES.md`, `docs/ORDER_FLOW.md`, `docs/AUTHZ_MATRIX.md`,
  `docs/EVENT_CONTRACT.md`, `docs/API_KIOSK.md`.

### 1.1 — Migrations (ordre)
Toutes réversibles. Tables existantes : ALTER non-destructif. Tables nouvelles : timestamps + `softDeletes` quand cohérent (upsell_rules oui, kiosk_promos oui, allergens non).

1. `2026_04_18_xxxx01_add_parent_id_to_item_categories_table.php`
   - `unsignedBigInteger('parent_id')->nullable()->after('id')`.
   - FK `->constrained('item_categories')->nullOnDelete()`.
   - Index `['parent_id', 'status']`.
   - Enforcement profondeur ≤ 2 : **au niveau service** (pas de trigger SQL) — `ItemCategoryService::validateHierarchy($parentId)` jette `422` si le parent a déjà un parent (ligne unique, testée).

2. `2026_04_18_xxxx02_create_allergens_table.php`
   - Colonnes : `id`, `code` (unique, 20 chars — ex. `gluten`, `crustaceans`), `name_key` (i18n key ex. `allergens.gluten`), `icon` (emoji/SVG path, nullable), `sort` (int, default 0), `timestamps`. Pas de `softDeletes` (référence quasi-statique EU 14).

3. `2026_04_18_xxxx03_create_item_allergen_table.php`
   - Pivot : `item_id` FK cascade, `allergen_id` FK cascade, `is_trace` (bool, default false — indique trace vs ingrédient), `timestamps`.
   - `unique(['item_id', 'allergen_id'])`.

4. `2026_04_18_xxxx04_create_upsell_rules_table.php`
   - Colonnes : `id`, `branch_id` FK cascade, `trigger_type` enum string (`category_in_cart`, `item_in_cart`, `cart_total_gte`), `trigger_value` JSON (ex. `{"category_id":7}`, `{"item_id":42}`, `{"amount":15}`), `suggested_item_id` FK items cascade, `active` bool default true, `priority` int default 0 (tri décroissant), `starts_at` / `ends_at` datetime nullable, `timestamps`, `softDeletes`.
   - Index : `['branch_id', 'active']`, `['trigger_type']`.

5. `2026_04_18_xxxx05_create_kiosk_promos_table.php`
   - Colonnes : `id`, `branch_id` FK cascade, `code` (string 64), `type` enum (`percent`, `amount`), `value` decimal(10,2), `min_cart` decimal(10,2) default 0, `valid_from` / `valid_to` datetime nullable, `max_uses` int nullable, `uses_count` int default 0, `active` bool default true, `timestamps`, `softDeletes`.
   - `unique(['branch_id', 'code'])` → code unique par branche (multi-tenant).
   - Index : `['branch_id', 'active']`, `['valid_from', 'valid_to']`.

6. `2026_04_18_xxxx06_add_available_locales_to_branches_table.php`
   - `json('available_locales')->nullable()->after('zone')`.
   - Backfill `DB::update SET available_locales = JSON_ARRAY('fr','en','ar') WHERE available_locales IS NULL`.

**Gate 1.1** : `php artisan migrate:fresh --seed` vert + `Schema::hasColumn` asserts dans un test.

### 1.2 — Modèles Eloquent (alignement)

Modifications additives, `$fillable` étendu, casts alignés :
- `App\Models\ItemCategory` : `parent_id` fillable (+ cast int), relations `parent()` `BelongsTo self`, `children()` `HasMany self`. Helper `depth(): int` (0, 1 ou 2).
- `App\Models\Item` : relation `allergens() BelongsToMany Allergen via item_allergen withPivot('is_trace')`.
- Nouveau `App\Models\Allergen` : fillable `code`, `name_key`, `icon`, `sort` ; relation `items()` BelongsToMany.
- Nouveau `App\Models\UpsellRule` : fillable complet, casts `trigger_value → array`, `active → bool`, `starts_at/ends_at → datetime` ; scope `scopeActiveForBranch($q, $branchId)`.
- Nouveau `App\Models\KioskPromo` : fillable complet, casts `active → bool`, dates, `value → decimal:2` ; scope `scopeValidFor($branchId, Carbon $at, float $cart)`.
- `App\Models\Branch` : `available_locales → array` cast + fillable.

**Gate 1.2** : tests unitaires `tests/Unit/Models/*` vérifiant cast, relations et scopes.

### 1.3 — Seeder allergens EU 14

Fichier `database/seeders/AllergensSeeder.php` — **référence Annexe II Règlement
UE 1169/2011** (Information du consommateur sur les denrées alimentaires, FIC) :

```
1  gluten            (céréales contenant du gluten)
2  crustaceans       (crustacés)
3  eggs              (œufs)
4  fish              (poissons)
5  peanuts           (arachides)
6  soy               (soja)
7  milk              (lait, y compris lactose)
8  tree_nuts         (fruits à coque)
9  celery            (céleri)
10 mustard           (moutarde)
11 sesame            (graines de sésame)
12 sulphites         (anhydride sulfureux et sulfites > 10 mg/kg)
13 lupin             (lupin)
14 molluscs          (mollusques)
```

- Opération idempotente (`updateOrCreate` sur `code`).
- Clés `name_key` pointent `allergens.*` — traductions FR/EN/AR à ajouter en Phase 4 (`resources/js/languages/*.json`). Ne pas traduire ici.
- Intégré au `DatabaseSeeder::run()` en append — existe déjà un seeder principal, on ajoute un call `$this->call(AllergensSeeder::class)`.

**Gate 1.3** : `php artisan db:seed --class=AllergensSeeder` → 14 rows, idempotent sur 2e run.

### 1.4 — Endpoint `GET /api/frontend/menu` (création)

**Objectif** : 1 seul appel HTTP renvoie l'arbre complet nécessaire au kiosk,
pré-filtré pour la surface `kiosk` et la branche du token. Évite les 4 appels
actuels (categories + items + variations/extras + upsell).

**Route** :
```php
Route::middleware('auth:sanctum')
     ->get('frontend/menu', [Frontend\MenuController::class, 'kiosk'])
     ->name('frontend.menu.kiosk');
```

**Controller** `app/Http/Controllers/Frontend/MenuController.php` — nouveau.

**Réponse JSON (contrat)** :
```json
{
  "status": true,
  "data": {
    "branch": {
      "id": 3,
      "name": "Aubervilliers",
      "available_locales": ["fr","en","ar"],
      "currency": "€"
    },
    "categories": [
      {
        "id": 12, "parent_id": null, "slug": "burgers",
        "name": "Burgers", "kiosk_label": null, "sort": 1,
        "wizard_template": "burger", "has_menu": true,
        "children": [
          { "id": 13, "parent_id": 12, "slug": "signature", "name": "Signature" }
        ]
      }
    ],
    "items": [
      {
        "id": 42, "category_id": 12, "slug": "cayenne-xxl",
        "name": "Cayenne XXL", "price": 9.90, "tax_id": 1,
        "item_type": 1, "is_featured": 1, "is_upsell": 0,
        "is_chef_pick": false, "kiosk_emoji": "🍔",
        "is_available": true, "unavailable_reason": null,
        "channels": ["kiosk","pos"],
        "allergens": [
          { "code": "gluten", "name_key": "allergens.gluten", "is_trace": false },
          { "code": "milk",   "name_key": "allergens.milk",   "is_trace": false }
        ],
        "variations": [ { "id": 1, "attribute_id": 1, "name": "XL", "price": 1.50, "visible_on": ["kiosk"] } ],
        "extras": [ { "id": 5, "name": "Cheddar", "price": 1.00, "group_label": "supplements", "visible_on": ["kiosk"] } ]
      }
    ],
    "upsell_rules": [
      { "id": 7, "trigger_type": "cart_total_gte", "trigger_value": { "amount": 15 },
        "suggested_item_id": 88, "priority": 10 }
    ]
  }
}
```

**Contraintes** :
- Auth : `auth:sanctum` + `$user->tokenCan('kiosk:order')` (middleware inline ou abort 403).
- Branch : lu depuis `KioskMachine::where('user_id', Auth::id())->firstOrFail()->branch_id`. Fallback 503 si machine non trouvée.
- Filtrage `channels` : WHERE `JSON_CONTAINS(items.channels, '"kiosk"')` ET `categories.channels` idem.
- `is_available` : jointure `item_branch_availability` sur `(item_id, branch_id)`, default true si ligne absente. Utiliser `AvailabilityService` existant.
- `is_chef_pick` : flag admin statique → à ajouter via migration séparée en fin de Phase 1 (§1.4 bis). Pour l'instant, renvoyer `false` par défaut. **Nouvelle micro-migration `add_is_chef_pick_to_items` nécessaire** — ajoutée à la liste 1.1 (bullet 7).

**Perf** :
- Utiliser `with(['variations', 'extras', 'allergens'])` (N+1 eager loading).
- Cache tag `kiosk.menu.branch.{id}` TTL 60 s via `Cache::remember` — invalidation par event `ItemAvailabilityChanged` listener (déjà présent).
- Gzip déjà actif côté reverse proxy.

**Tests** :
- `KioskMenuEndpointTest` : auth obligatoire, branch_id forcé, filtre channels, structure de réponse conforme au contrat, 404 si kiosk machine manquante, cache hit/miss.

### 1.4 bis — Micro-migration `is_chef_pick` (ajout tardif, identifié en planification)

```php
Schema::table('items', function (Blueprint $t) {
    $t->boolean('is_chef_pick')->default(false)->after('is_upsell');
    $t->index(['is_chef_pick']);
});
```
- **Flag admin statique uniquement**. Aucune écriture côté kiosk. Ajouté au fillable `Item`.
- Rappel invariant §1.5 : **jamais** basé sur des ventes / stats.

### 1.5 — Endpoint `POST /api/frontend/pricing/preview` (création)

**Objectif** : recalcul serveur sans persistance. Consommé par :
- UI wizard pour afficher le total en temps réel avant `POST /order`.
- UI upsell pour montrer l'impact d'un ajout.
- UI promo pour montrer le discount calculé.

**Route** :
```php
Route::middleware('auth:sanctum')
     ->post('frontend/pricing/preview', [Frontend\PricingPreviewController::class, 'preview']);
```

**Payload entrant** (FormRequest dédié) :
```json
{
  "items": [
    {
      "item_id": 42, "quantity": 1,
      "item_variations": [ { "id": 1 } ],
      "item_extras":     [ { "id": 5 } ],
      "instruction": "Sans oignon"
    }
  ],
  "coupon_code": "BIENVENUE10",
  "kiosk_promo_code": null
}
```

**Réponse** :
```json
{
  "status": true,
  "data": {
    "lines": [
      { "item_id": 42, "name": "Cayenne XXL", "quantity": 1,
        "unit_price": 9.90, "variations_total": 1.50, "extras_total": 1.00,
        "line_subtotal": 12.40, "tax": 1.24, "line_total": 13.64 }
    ],
    "subtotal": 12.40,
    "tax": 1.24,
    "discount": 1.24,
    "discount_source": "coupon",
    "total": 12.40,
    "currency": "€"
  }
}
```

**Contraintes** :
- `FrontendOrderService` **NON touché**.
- Nouveau `PricingPreviewService` qui appelle `PricingService::calculateOrder(PricingRequest::forPreview(...))` avec `persist=false`, `enforceCrossItemGuards=true`, `idempotency=null`.
- Pas d'`OrderCreated` dispatch.
- Rate limit dédié : `throttle:60,1` (1/s par user — preview fréquent côté UI).
- branch_id lu depuis `KioskMachine` comme `/order`.
- Coupon résolu via `CouponService::validateCode` existant ; promo kiosk via `KioskPromoService::validate`.

**Tests** :
- Prix calculé ≠ prix client (envoyer un prix bidon côté client → ignoré).
- Variation/extra non lié à l'item → 422 (guard cross-item).
- Coupon expiré → discount=0.
- Kiosk promo + coupon simultanés → priorité master-prompt non spécifiée ; décision : premier arrivé (coupon_code en priorité). À tracer dans `discount_source`.

### 1.6 — Endpoint `POST /api/frontend/promo/validate` (alias + extension)

**Stratégie** : nouvelle route + nouveau controller **mince** qui délègue :
1. Essaye d'abord `KioskPromoService::validate($code, $branchId, $cart)` (table `kiosk_promos` branch-scopée).
2. Fallback sur `CouponService::couponChecking($code)` (coupons globaux).

**Route** :
```php
Route::middleware('auth:sanctum')
     ->post('frontend/promo/validate', [Frontend\PromoController::class, 'validate']);
```

**Payload** :
```json
{ "code": "BIENVENUE10", "cart_total": 15.50 }
```

**Réponse** :
```json
{
  "status": true,
  "data": {
    "code": "BIENVENUE10",
    "source": "kiosk_promo",      // ou "coupon"
    "type": "percent",
    "value": 10.00,
    "discount_amount": 1.55,
    "valid": true,
    "message": null
  }
}
```

**Note** : la validation coupon existante (`/coupon/coupon-checking`) reste
active — zéro break. Cette nouvelle route est la référence future.

### 1.7 — `GET /api/frontend/upsell` (alias + intégration upsell_rules)

**Stratégie** : nouvelle route qui pointe sur un **nouveau controller mince**
qui :
1. Lit le panier depuis le payload (query string : `cart_item_ids=42,88&cart_total=23.40`).
2. Interroge `UpsellRuleService::suggestFor($branchId, $cartItemIds, $cartTotal)` qui :
   - Matche les règles `upsell_rules` `active=true AND branch_id=$bid AND now() ∈ [starts_at, ends_at]`.
   - Évalue le `trigger_type` :
     - `category_in_cart` : any `cart_item.category_id === trigger_value.category_id`.
     - `item_in_cart` : any `cart_item_id === trigger_value.item_id`.
     - `cart_total_gte` : `cart_total >= trigger_value.amount`.
   - Retourne top N (default 4) par `priority DESC`.
3. Fallback si 0 règles : délègue à `ItemController::kioskUpsell` (legacy) pour ne pas casser le comportement actuel.

**Tests** : combinaisons trigger, branch isolation, fallback legacy.

### 1.8 — `POST /api/frontend/loyalty/opt-in` (alias RGPD-explicit)

**Différence vs `/loyalty/register`** : exige un champ **`consent_accepted: required|accepted`** (RGPD §1.6 invariant — checkbox non pré-cochée côté client).

**Route** :
```php
Route::middleware(['throttle:5,1'])
     ->post('frontend/loyalty/opt-in', [Frontend\LoyaltyController::class, 'optIn']);
```

Nouvelle méthode `LoyaltyController::optIn(LoyaltyOptInRequest $request)` :
- Validation : `name`, `phone`, `email`, `consent_accepted: required|accepted`, `privacy_notice_version: required|string|max:20`.
- Log RGPD : persister `loyalty_consents` (nouvelle table — **ajoutée à §1.1 comme bullet 8**) : `user_id`, `consent_accepted`, `privacy_notice_version`, `ip_hash` (sha256, pas l'IP brute), `user_agent_hash`, `occurred_at`.
- Delegate à `LoyaltyController::register()` (réutilise la logique existante).

**Nouvelle micro-migration §1.1 bullet 8** : `create_loyalty_consents_table`.
```
id, user_id FK cascade, consent_accepted bool, privacy_notice_version varchar(20),
ip_hash char(64), user_agent_hash char(64), occurred_at timestamp, timestamps.
Index (user_id, occurred_at).
```

### 1.9 — `POST /api/frontend/kiosk/event` (alias slash)

Route alias pure, pointe `KioskEventController@store` :
```php
Route::middleware(['auth:sanctum', 'throttle:30,1'])
     ->post('frontend/kiosk/event', [Frontend\KioskEventController::class, 'store']);
```

URL historique `/kiosk-event` **garde son support**. Permet aux restyles
Phase 2+ d'utiliser le nommage propre imposé par le prompt.

### 1.10 — Tests PHPUnit (obligatoires avant clôture Phase 1)

Suite ciblée, exécutable isolément :

```bash
php artisan test --filter Kiosk
php artisan test --filter Pricing
php artisan test --filter BranchScope
```

Couverture minimale attendue :

| Fichier | Cas couverts |
|---|---|
| `tests/Feature/Phase1/KioskMenuEndpointTest.php` | auth requise, branch_id forcé, filtre channels, structure JSON, cache, 403 autre branch |
| `tests/Feature/Phase1/PricingPreviewEndpointTest.php` | SSOT (prix client ignoré), guards cross-item, coupon, kiosk_promo, total=final |
| `tests/Feature/Phase1/PromoValidateEndpointTest.php` | kiosk_promo prio, fallback coupon, code invalide, branch isolation kiosk_promo |
| `tests/Feature/Phase1/UpsellEndpointTest.php` | 3 trigger types, priority sort, fallback legacy, branch isolation |
| `tests/Feature/Phase1/LoyaltyOptInEndpointTest.php` | consent requis, version RGPD loggée, ip/ua hashés (pas bruts), throttle |
| `tests/Feature/Phase1/KioskEventAliasTest.php` | alias slash fonctionne, tiret historique aussi |
| `tests/Feature/Phase1/FrontendOrderSsotRegressionTest.php` | **régression SSOT** : envoie `total=0.01`, `branch_id=autre`, vérifie serveur a recalculé et scopé correctement |
| `tests/Unit/Models/ItemCategoryHierarchyTest.php` | depth max 2, parent nullable, children relation |
| `tests/Unit/Models/UpsellRuleTest.php` | scope ActiveForBranch, cast trigger_value |
| `tests/Unit/Models/KioskPromoTest.php` | scope ValidFor, computation discount |
| `tests/Unit/Models/BranchAvailableLocalesTest.php` | cast array, default ["fr","en","ar"] |
| `tests/Unit/Services/AllergensSeederTest.php` | 14 rows, idempotent, codes attendus |

**Pattern auth kiosk dans les tests** :
```php
$user = User::factory()->create(['branch_id' => $branch->id]);
KioskMachine::factory()->create(['user_id' => $user->id, 'branch_id' => $branch->id]);
Sanctum::actingAs($user, ['kiosk:order']);
$this->withHeaders(['x-api-key' => config('app.api_key')]);
```

---

## 2. Gates (à tous les checkpoints)

Tout PR / push sur cette phase doit satisfaire :

1. `php artisan migrate:fresh --seed --env=testing` → 0 erreur.
2. `php artisan test --filter Kiosk` → vert.
3. `php artisan test --filter Pricing` → vert (régression non-négociable).
4. Aucune route `/api/admin/*` modifiée.
5. Aucun composant Vue modifié (Phase 2).
6. Zero nouvelle dépendance `composer.json`.
7. Migrations réversibles (testées `migrate:rollback` jusqu'à initial state).
8. Commits atomiques `feat(kiosk/phase-1.X): <résumé>`.

---

## 3. Risques identifiés + mitigations

| # | Risque | Impact | Mitigation |
|---|---|---|---|
| R1 | `items.allergen_flags` JSON **coexiste** avec nouveau pivot `item_allergen` → désynchro | Badges erronés UI | Service `AllergenService::projectFlags($item)` qui synchronise le JSON au save/update du pivot. Non bloquant Phase 1 (le pivot est la source de vérité). |
| R2 | `categories.parent_id` + tests kiosk existants qui supposent plat | Tests rouges | Depth max 2 enforcée en service ; 0 catégorie actuelle n'a de parent → backfill null. Tests legacy lisent `ItemCategory::all()` → pas impactés. |
| R3 | `upsell_rules` + legacy `ItemController::kioskUpsell` → double source | Suggestions incohérentes | Règle : `upsell_rules` prioritaire ; si 0 match → fallback legacy. Documenté. |
| R4 | `kiosk_promos` + `coupons` globaux → ambigüité UX | Utilisateur confus | `promo/validate` teste kiosk_promo d'abord, `discount_source` renvoyé → UI peut afficher la source. |
| R5 | `GET /menu` gros payload (menu complet branche) | Latence mobile | Eager load, cache 60 s, gzip. Si >500 kb, paginer par catégorie en Phase 2 (hors scope). |
| R6 | `PricingService` appelé en preview très fréquent (à chaque tap wizard) | Charge DB | Rate limit 60/min + cache par hash du payload 5 s (TTL court, ID stable via `sha1(json_encode)`). |
| R7 | Champ `is_chef_pick` ajouté → index & back-compat | Déploiement | `default(false)` + migration réversible. Aucune UI existante ne lit ce champ. |
| R8 | `loyalty_consents` stocke ip_hash/ua_hash — RGPD | Compliance | ip_hash = `sha256(ip . app_key_salt)`, non-réversible, anonymisation OK (CNIL). |

---

## 4. Livrables (après 1.10)

Fichiers créés (estimation) :

**Migrations (8)** :
- `add_parent_id_to_item_categories_table.php`
- `create_allergens_table.php`
- `create_item_allergen_table.php`
- `create_upsell_rules_table.php`
- `create_kiosk_promos_table.php`
- `add_available_locales_to_branches_table.php`
- `add_is_chef_pick_to_items_table.php`
- `create_loyalty_consents_table.php`

**Modèles (4 nouveaux + 3 étendus)** :
- `Allergen.php`, `UpsellRule.php`, `KioskPromo.php`, `LoyaltyConsent.php`
- Extensions : `Item.php`, `ItemCategory.php`, `Branch.php`

**Seeders (1)** :
- `AllergensSeeder.php`

**Services (4 nouveaux)** :
- `Services/PricingPreviewService.php`
- `Services/UpsellRuleService.php`
- `Services/KioskPromoService.php`
- `Services/AllergenService.php`

**Controllers (3 nouveaux + 1 méthode ajoutée)** :
- `Http/Controllers/Frontend/MenuController.php` (new)
- `Http/Controllers/Frontend/PricingPreviewController.php` (new)
- `Http/Controllers/Frontend/PromoController.php` (new)
- `Http/Controllers/Frontend/LoyaltyController.php` → `optIn()` ajouté

**Form Requests (3)** :
- `Http/Requests/Kiosk/PricingPreviewRequest.php`
- `Http/Requests/Kiosk/PromoValidateRequest.php`
- `Http/Requests/Kiosk/LoyaltyOptInRequest.php`

**Routes** : 7 nouvelles lignes dans `routes/api.php` (groupe existant `/api/frontend/*`).

**Tests PHPUnit (12 fichiers)** : cf. §1.10.

**Rapport** : `reports/execution/KIOSK_DESIGN_V1_PHASE_1_2026-04-18.md` avec evidence (sortie tests + curl exemples).

---

## 5. Politique de modification

- `POST /api/frontend/order` : **aucune ligne modifiée** (service déjà SSOT-conforme). Seule action : **ajouter** un test de régression dédié.
- Routes existantes (`/coupon/coupon-checking`, `/kiosk-event`, `/item/kiosk-upsell`, `/loyalty/register`) : **inchangées**. Les nouvelles routes vivent en parallèle.
- Composants Vue : **aucune modification** (Phase 2).
- `config/` : ajout éventuel de `config/kiosk.php` → clés `pricing_preview_cache_ttl`, `upsell_default_limit`. Non-breaking.

---

## 6. Ce qui est volontairement HORS SCOPE Phase 1

- Migration des anciens écrans vers le DS Phase 0 → Phase 2.
- i18n EN / AR complet + clés allergens traduites → Phase 4.
- Échantillons / fixtures de données `upsell_rules` et `kiosk_promos` → ajoutés en dev seed Phase 2 quand l'UI admin sera en place.
- UI admin pour CRUD `upsell_rules` / `kiosk_promos` / allergens → Phase 2 (panneau admin).

---

**Fin du plan Phase 1.**
Implémentation démarre immédiatement après ce document, sous-phase 1.1 (migrations).
