# Page 4 — POS Cart (post add-to-cart attempt) — Evidence

**Verdict** : RED
**Finding** : PG4-P0-001 (silent_error, P0)
**State** : `04-pos-cart-after-add`

## Capture quartet

- PNG : `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/04-pos-cart-after-add.png`
- DOM : `.dom.html`
- Console : `.console.json` (2 error entries)
- Network : `.network.json` (1 entry, status=404)

## Visual analysis

Top-right of POS shell : **red toast "Une erreur est survenue. Réessayez."** (icon ⚠ + message in red pill). Cart panel right rail unchanged from page-2 default state — "Aucun article. Sélectionnez un produit dans la grille." + Sous-total 0,00 € + Total 0,00 €.

The user clicked "Frites Seules" (item id=361) tile expecting an auto-add (simple wizard_template), but the backend returned 404 and the click produced only the generic error toast. No item was added to the cart.

## Technical analysis

### Network (4xx/5xx)
```
{
  "url": "http://localhost:8000/api/admin/item/details/361?surface=pos&branch_id=1",
  "method": "GET",
  "status": 404,
  "duration_ms": null
}
```

### Console (errors)
```
{ "level": "error", "text": "Failed to load resource: the server responded with a status of 404 (Not Found)",
  "location": { "url": ".../api/admin/item/details/361?surface=pos&branch_id=1" } }
{ "level": "error", "text": "[POS] item/details failed AxiosError",
  "location": { "url": "http://localhost:8000/js/pos-shell.js", "lineNumber": 1501 } }
```

### Root-cause (verified via tinker)

| ID | Name | Category | item.isVisibleOn(pos) | category.isVisibleOn(pos) | Result |
|---|---|---|---|---|---|
| 361 | Frites Seules | 315 (Frites & Accompagnements) | YES | **NO** | 404 |
| 362 | Boisson Seule | 315 (Frites & Accompagnements) | YES | **NO** | 404 |
| 375 | Chicken Burger | 349 (Burgers) | YES | YES | OK |

```
$ php artisan tinker --execute="echo json_encode(DB::table('item_categories')->where('id',315)->value('channels'));"
"[]"

$ php artisan tinker --execute="echo json_encode(DB::table('item_categories')->where('id',317)->value('channels'));"
null

$ php artisan tinker --execute="echo DB::table('items')->where('item_category_id',315)->where('status',5)->count();"
5
```

Category 315 has `channels="[]"` (empty array). Per `app/Models/ItemCategory.php:55-58` :

```php
public function isVisibleOn(string $channel): bool
{
    return $this->channels === null || in_array($channel, (array) $this->channels, true);
}
```

`null` = legacy default, visible everywhere. `[]` = explicitly visible nowhere. All 12 working categories use `null`. Only cat 315 has `[]`.

`ItemController::itemDetails` at `app/Http/Controllers/Admin/ItemController.php:207-208` aborts 404 when `! $item->category || $item->category->isVisibleOn($surface)` is false — so click → silent 404 → generic toast.

The POS grid query (PosCategoryController / catalog feed) is more permissive and shows the items despite the category's visibility flag. **The grid and details endpoint disagree**, producing tiles that exist but cannot be sold.

### 5 items affected (cat 315)

Verified via `php artisan tinker --execute="DB::table('items')->where('item_category_id',315)->where('status',5)->get(['id','name']);"` :
- 361 Frites Seules (€2.00)
- 362 Boisson Seule (€2.00)
- 3 others (Menu Frites + Boisson, Petite Frites, Tiramisu confirmed in capture 10 via past order #1405261491 detail)

## Heal applied

**NONE** — owner gate required. Two-option remediation (see wave-findings.json fix_hint).

- Option A (data) : `UPDATE item_categories SET channels=NULL WHERE id=315` — 1 line, restores legacy default.
- Option B (architectural) : align grid query with details visibility, or interpret `channels=[]` as legacy compat.

Owner decision blocks heal because `channels=[]` may be intentional (V1 dual-channel projection per ItemCategory.php:44 comment).

## Verdict

RED. **Blocks pages 5, 6, 7, 8** (all depend on a non-empty cart). 0 frozen-zone touch. Reproducible via `tests/e2e/_pageby-pos-2026-05-18.spec.js` first describe block.
