# PLAN AUDIT F-016a-BIS — Stock Orchestration Extras + Variations (REFINED Option 1bis)

**Date :** 2026-05-08
**Auteur :** Claude Orchestrator
**Origine :** Drift escalation par agent Wave 3 F-016a (close-by-investigation)
**Supersedes :** `PLAN_AUDIT_F016_STOCK_ORCHESTRATION_V1_2026-05-08.md` (rédigé sans connaissance des artefacts production)
**Décision orchestrateur :** Option 1bis — **réutiliser stock_levels polymorphique existant + extension légère**

---

## 1. Justification Drift / Verification Évidence

### 1.1 Artefacts production déjà en place (verified 2026-05-08)

| Artefact | Path | Évidence |
|---|---|---|
| Table polymorphique stock | `database/migrations/2026_04_27_143120_create_stock_levels_table.php` | `stockable_type`+`stockable_id`+`branch_id`+`on_hand`+`reserved`+`threshold_low` ; unique (branch_id, stockable_type, stockable_id) ; check constraints `on_hand >= 0`, `reserved >= 0`, `reserved <= on_hand` |
| Resolver availability | `app/Services/Stock/ChoiceAvailabilityResolver.php` | `snapshotForItems`/`snapshotForItem` retournent `is_available`+`unavailable_reason` per (variation, extra, addon) ; `assertSelectionsOrderable` lance `\InvalidArgumentException(422)` |
| Champs availability extras | `2026_05_05_000040_add_availability_to_item_extras_table.php` | `is_available BOOL`, `unavailable_reason VARCHAR(64)` (global, pas per-branche) |
| Champs availability variations parent | `2026_05_05_000030_add_availability_to_item_attributes_table.php` | idem (sur ItemAttribute) |
| Service Menu availability items | `app/Services/Menu/AvailabilityService.php` | items only — extras/variations non couverts |
| Enrichissement API kiosk | `app/Services/Kiosk/KioskMenuService.php:337-377` | injecte déjà `is_available`+`unavailable_reason` per extra/variation via resolver |
| Enrichissement API admin/POS | `app/Http/Resources/NormalItemResource.php:38-56` | idem via `ChoiceAvailabilityResolver::snapshotForItem` |

### 1.2 Conclusion

Le plan F-016 original (créer `item_extra_branch_availability` + `item_variation_branch_availability` tables séparées) violerait :
- CLAUDE.md §3 principe 2 (Architecture > local convenience)
- CLAUDE.md §6 (architecture coherence + dependency discipline)
- CLAUDE.md §10 (anti-drift : contradiction current plan vs stable code)

**Décision :** Option 1bis — étendre l'existant.

---

## 2. Vrai gap V1 (à combler par F-016a-BIS)

### 2.1 Sémantique manual reason absente
`stock_levels.on_hand=0` ne porte pas le **pourquoi** (rupture livreur vs saisonnier vs manuel manager).
→ Manager UI a besoin de raison persistante distinguable de `on_hand=0` automatique.

### 2.2 Endpoints admin manquants
`routes/api.php:252` : `POST /menu/availability/toggle` → ITEMS only.
**Manquants :**
- `POST /api/admin/menu/availability/extra/toggle`
- `POST /api/admin/menu/availability/variation/toggle`
- `GET /api/admin/menu/availability/branch/{branch_id}` (agrégat for StockManager UI)

### 2.3 Outbox events manquants pour push frontends
Manquants :
- `App\Events\Domain\ItemExtraAvailabilityChanged`
- `App\Events\Domain\ItemVariationAvailabilityChanged`

(Pour pattern existant voir `ItemAvailabilityChanged` ou équivalent — vérifier au build).

### 2.4 Decrement-on-order pour extras/variations
Items ont `decrementForOrder` (via `StockService`). Extras et variations probablement pas.
À vérifier au build dans `StockService::decrementForOrder` (`app/Services/Stock/StockService.php`).

### 2.5 AvailabilityService façade
Wrappers délégant à `ChoiceAvailabilityResolver` pour API publique simple :
- `toggleExtra(int $extraId, int $branchId, bool $available, ?string $reason): void`
- `toggleVariation(int $variationId, int $branchId, bool $available, ?string $reason): void`
- `isExtraAvailable(int $extraId, int $branchId): bool`
- `isVariationAvailable(int $variationId, int $branchId): bool`
- `getUnavailableExtraIdsForBranch(int $branchId): array`
- `getUnavailableVariationIdsForBranch(int $branchId): array`

(Pas de `decrementExtraStock/decrementVariationStock` séparés — décrément reste dans `StockService` qui est polymorphique nativement.)

---

## 3. Architecture cible Option 1bis

### 3.1 Migration GATED OWNER (1 fichier)

```php
// database/migrations/2026_05_08_HHMMSS_add_manual_unavailable_to_stock_levels.php
Schema::table('stock_levels', function (Blueprint $table) {
    $table->string('manual_unavailable_reason', 32)->nullable()->after('threshold_low');
    $table->timestamp('manual_unavailable_since')->nullable()->after('manual_unavailable_reason');
    $table->index('manual_unavailable_reason', 'stock_levels_manual_reason_idx');
});
```

**Sémantique manual_unavailable_reason :** enum string libre mais valeurs recommandées : `out_of_stock_manual`, `seasonal`, `recipe_change`, `supplier_issue`, `quality_issue`. Whitelist côté validator endpoint admin.

**Règle priorité dans resolver :** `manual_unavailable_reason` IS NOT NULL ⇒ unavailable, même si `on_hand > 0`. Le manuel override le stock automatique.

### 3.2 Extension ChoiceAvailabilityResolver (additif, pas de cassure contrat)

```php
private function availabilityFromLevel(?StockLevel $level): array
{
    if (! $level) {
        return ['is_available' => true, 'unavailable_reason' => null];
    }

    // [F-016a-BIS] Manual override prioritaire sur stock automatique
    if (! empty($level->manual_unavailable_reason)) {
        return ['is_available' => false, 'unavailable_reason' => $level->manual_unavailable_reason];
    }

    return (int) $level->on_hand > 0
        ? ['is_available' => true, 'unavailable_reason' => null]
        : ['is_available' => false, 'unavailable_reason' => 'stock_rupture'];
}
```

### 3.3 Extension AvailabilityService (Menu) avec wrappers

```php
public function toggleExtra(int $extraId, int $branchId, bool $available, ?string $reason = null): StockLevel
{
    return $this->toggleStockable(ItemExtra::class, $extraId, $branchId, $available, $reason);
}

public function toggleVariation(int $variationId, int $branchId, bool $available, ?string $reason = null): StockLevel
{
    return $this->toggleStockable(ItemVariation::class, $variationId, $branchId, $available, $reason);
}

public function isExtraAvailable(int $extraId, int $branchId): bool { ... }
public function isVariationAvailable(int $variationId, int $branchId): bool { ... }
public function getUnavailableExtraIdsForBranch(int $branchId): array { ... }
public function getUnavailableVariationIdsForBranch(int $branchId): array { ... }

private function toggleStockable(string $type, int $id, int $branchId, bool $available, ?string $reason): StockLevel
{
    // upsert stock_levels, set/clear manual_unavailable_reason + manual_unavailable_since
    // dispatch event approprié (ItemExtraAvailabilityChanged ou ItemVariationAvailabilityChanged)
    // wrap DB::transaction pour garantir atomicité
}
```

### 3.4 Endpoints admin (2 nouveaux + 1 GET)

Routes ajoutées dans `routes/api.php` group `admin/menu` (ou équivalent) :

```php
Route::post('/menu/availability/extra/toggle', [MenuAvailabilityController::class, 'toggleExtra']);
Route::post('/menu/availability/variation/toggle', [MenuAvailabilityController::class, 'toggleVariation']);
Route::get('/menu/availability/branch/{branch}', [MenuAvailabilityController::class, 'showBranchAvailability']);
```

Permissions middleware : `permission:pos-orders` ou `menu-availability-manage` selon convention existante (vérifier au build).

### 3.5 Outbox events

```php
class ItemExtraAvailabilityChanged extends DomainEvent { ... }
class ItemVariationAvailabilityChanged extends DomainEvent { ... }
```

Payload : `{ extra_id|variation_id, branch_id, is_available, reason, changed_at }`.
Dispatch via `event(new ItemExtraAvailabilityChanged(...))` dans `toggleExtra/toggleVariation`.

### 3.6 Decrement-on-order

Vérifier `app/Services/Stock/StockService.php` :
- Si `decrementForOrder` existe et est polymorphique → confirmer qu'il décrémente aussi extras/variations sélectionnés dans `OrderItem` (probable car `stockable_type` polymorphique).
- Si manquant pour extras/variations → ajouter logique itération `OrderItem.extras` + `OrderItem.variations` avec `lockForUpdate`.
- Tests : `OrderDecrementsExtrasAndVariationsStockTest`.

### 3.7 Filtering API frontend

**STATUS RÉEL :** déjà fait via `ChoiceAvailabilityResolver` (résolu lors enrichissement Resource/MenuService).
Action : aucune si `manual_unavailable_reason` est correctement remonté.
Vérifier sentinel : test e2e ou Feature qui assert que API frontend `/item/details/{slug}` ne retourne pas `is_available=true` pour extra manuel rupture.

---

## 4. Scope F-016a-BIS (BACKEND UNIQUEMENT)

### Fichiers à créer
- `database/migrations/2026_05_08_HHMMSS_add_manual_unavailable_to_stock_levels.php` (GATED OWNER)
- `app/Events/Domain/ItemExtraAvailabilityChanged.php`
- `app/Events/Domain/ItemVariationAvailabilityChanged.php`
- `app/Http/Controllers/Admin/Menu/MenuAvailabilityController.php` (ou étendre existant — vérifier au build)
- `app/Http/Requests/Admin/Menu/ToggleExtraAvailabilityRequest.php`
- `app/Http/Requests/Admin/Menu/ToggleVariationAvailabilityRequest.php`
- `tests/Feature/Menu/AvailabilityServiceExtrasVariationsTest.php`
- `tests/Feature/Menu/MenuAvailabilityToggleEndpointsTest.php`
- `tests/Feature/Stock/OrderDecrementsExtrasAndVariationsStockTest.php`
- `tests/Feature/Sentinels/StockManualReasonSurfacingSentinelTest.php`

### Fichiers à modifier
- `app/Models/StockLevel.php` : ajouter casts `manual_unavailable_reason`, `manual_unavailable_since`
- `app/Services/Stock/ChoiceAvailabilityResolver.php` : `availabilityFromLevel` priorité manual
- `app/Services/Menu/AvailabilityService.php` : ajouter 6 wrappers + private `toggleStockable`
- `routes/api.php` : 3 routes admin/menu
- `app/Services/Stock/StockService.php` : confirmer/ajouter decrement extras+variations dans `decrementForOrder`

### Fichiers FROZEN (ne PAS toucher)
- `public/js/pos-app.js`, `resources/js/components/admin/pos/PosComponent.vue`, `resources/js/components/frontend/kiosk/Kiosk*.vue` (8 composants)
- `app/Services/OrderStateMachine.php`, `app/Services/Fiscal/*`, `app/Services/Payment/Gateways/*`

---

## 5. TDD obligatoire

### 5.1 AvailabilityServiceExtrasVariationsTest (10+ cases)
1. `toggleExtra(extraId=1, branchId=A, available=false, reason='seasonal')` → stock_levels row created with manual_unavailable_reason='seasonal' + manual_unavailable_since≈now
2. `toggleExtra(...)` again with `available=true` → manual_unavailable_reason=null + manual_unavailable_since=null
3. `isExtraAvailable(extraId=1, branchId=A)` returns false after toggle false ; true after toggle true
4. `isExtraAvailable` honors `extra.is_available=false` ingredient global → false même si pas de manual override
5. `getUnavailableExtraIdsForBranch(A)` returns [1] when extra 1 toggled unavailable on A only
6. Cross-branch isolation : toggle on A ne change pas availability on B
7. Idem pour `toggleVariation/isVariationAvailable/getUnavailableVariationIdsForBranch`
8. Manual reason whitelist enforced (invalid reason → 422 validation)
9. Concurrency : 2 toggles simultanés sur même extra/branch → no race (Cache::lock ou DB row lock)
10. Event ItemExtraAvailabilityChanged dispatched after toggleExtra
11. Event ItemVariationAvailabilityChanged dispatched after toggleVariation

### 5.2 MenuAvailabilityToggleEndpointsTest (8+ cases)
1. POST extra toggle returns 200 + JSON `{status:true, data:{extra_id, branch_id, is_available, reason}}`
2. POST variation toggle idem
3. Auth required (sanctum) → 401 sans token
4. Permission required → 403 si user sans permission
5. Cross-branch isolation : user branch B ne peut pas toggle extra branch A → 403
6. Validation : missing reason quand available=false → 422 (force motivation)
7. Validation : invalid reason hors whitelist → 422
8. GET branch availability returns aggregate {items, extras, variations} indisponibles

### 5.3 OrderDecrementsExtrasAndVariationsStockTest (4+ cases)
1. Order with extra qty=2 → stock_levels.on_hand decremented by 2 for that extra+branch
2. Order with variation → idem
3. Order failure (rollback) → no decrement persisted
4. Insufficient stock (on_hand < qty) → 422 throw + no decrement
   (réutiliser `assertSelectionsOrderable` pattern existant)

### 5.4 StockManualReasonSurfacingSentinelTest (3 cases)
1. Toggle extra unavailable manual → API `/api/frontend/item/details/{slug}` returns extra avec `is_available=false` + `unavailable_reason='seasonal'`
2. Toggle variation unavailable manual → idem
3. Manual reason ne masque pas stock auto : si on_hand=0 ET pas de manual → reason='stock_rupture' (pas null)

---

## 6. STOP checklist 6Q (rappel agent)

1. Ai-je lu le plan F-016a-BIS intégralement ? **OUI obligatoire**
2. Ai-je vérifié `StockLevel` model + `ChoiceAvailabilityResolver::availabilityFromLevel` actuels ? **OUI**
3. Tests rouges AVANT impl ? **OUI strict**
4. Aucune frozen-zone touchée ? **VÉRIFIER** (POS Vanilla, Kiosk Vue 8, OSM, Fiscal*, Payment Gateways)
5. Migration GATED OWNER (créée mais pas migrate prod) ? **OUI 1 fichier `add_manual_unavailable_to_stock_levels`**
6. Commit format `audit(F-016a-BIS): ...` ? **OUI**

## 7. Anti-drift checklist 12 cases (rappel agent)

À cocher EXPLICITEMENT dans REPORT après commit. Voir Wave 3 brief F-015 pour contenu.

## 8. Decision orchestrateur attendue après build

`continue` si tous les tests verts + Anti-drift propre + Frozen-zones intactes + 1 migration GATED OWNER documentée.

## 9. Hand-off F-016b UI (post-merge backend)

(Voir REPORT F-016a agent original — section "Hand-off F-016b UI" déjà détaillée). Composant `StockManagerComponent.vue` 3 onglets, modal "Rupture rapide" avec dropdown raisons, Echo handlers `ItemExtraAvailabilityChanged` + `ItemVariationAvailabilityChanged` pour invalidation cache cross-surface.

---

## 10. Estimation

- Migration : 0.5h
- Resolver patch : 0.5h
- AvailabilityService wrappers : 2h
- Controller + 2 FormRequests + routes : 2h
- Events + dispatch wiring : 1h
- StockService decrement vérif/extension : 1h
- Tests (4 fichiers, ~25 cases) : 4h
- REPORT durable : 0.5h

**Total estimé : 11.5h-agent (vs 8-10 jours-agent du plan original).**

ROI Option 1bis vs littéral : **x10-15** (réutilisation maximale, zéro parallel SoT).
