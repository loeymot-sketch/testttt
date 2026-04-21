# V14 #8 — T10 — `P14_POS_SEARCH_BARCODE_FAST`

## Header

```
TASK_ID: V14_08_T10_POS_SEARCH_BARCODE
WAVE: C-α — Finalisation caisse opérateur (sub-vague α)
GATE_REFERENCE: aucun (UI + colonne barcode legacy-safe)
PRIMARY_MODEL: Composer (foodking-routine-implementer)
RUNNER_MODE: single-session
PARALLEL_WITH: V14_07_T11_POS_AVAILABILITY_LIVE_GUARD, V14_09_T08_POS_PARK_HOLD_RECALL
DEPENDS_ON: aucun
SEVERITY: P1
EFFORT_EST: 3-4h
```

## Contexte

POS actuel : recherche par nom existante (`v-model="props.search.name"`) mais **non debouncée** (re-render à chaque touche). Pas de barcode, pas de raccourcis clavier. Une caisse pro doit :
1. Avoir une recherche **fuzzy + debouncée** (debounce 100-150ms).
2. Supporter les **scanners barcode HID** (mode "wedge" : scanner émule le clavier, frappe rapide + Enter).
3. Supporter des **raccourcis clavier** F1-F12 = catégories favorites (configurable plus tard, hardcoder 1-12 = 12 premières catégories en V1).

T10 ajoute ces 3 capacités sans casser la recherche actuelle.

## SUBSYSTEMS_TOUCHED

- `database/migrations/2026_04_20_xxxxxx_add_barcode_index_to_items.php` (CREATE — colonne `barcode` VARCHAR(64) NULLABLE + INDEX, idempotent)
- `app/Models/Item.php` (EDIT minimal — ajouter `barcode` à `$fillable`)
- `app/Http/Controllers/Admin/Items/ItemController.php` ou équivalent (EDIT léger — recherche par barcode si endpoint search existe ; sinon créer une route minimale)
- `routes/api.php` ou `routes/web.php` admin POS (EDIT — route `GET /admin/pos/items/lookup-barcode/{code}` si pas déjà couvert)
- `resources/js/helpers/posBarcode.js` (CREATE — détecteur scanner HID : si N caractères tapés en <50ms et terminés par Enter, considérer comme barcode)
- `resources/js/components/admin/pos/PosComponent.vue` (EDIT — debounce input recherche + listener global keydown F1-F12 + intégration helper barcode)
- `resources/js/store/modules/item.js` ou équivalent (EDIT — méthode `lookupByBarcode` si pas existante)
- `tests/js/posBarcode.spec.js` (CREATE — 6 cas : detection vitesse, ignore frappe lente, F-keys mappées catégories)

## SUBSYSTEMS_OFF_LIMITS

- `app/Services/Pricing/PricingService.php` (frozen)
- `app/Services/OrderService.php`, `FrontendOrderService.php` (frozen)
- `resources/js/components/admin/pos/PaymentComponent.vue` (frozen — fix B-6 récent)
- `resources/js/components/admin/pos/ItemComponent.vue` (T11 territoire en parallèle)
- Tout schéma `order_*` (out of scope)

## INVARIANTS_AT_RISK

1. **Migration idempotente** : `ALTER TABLE items ADD COLUMN IF NOT EXISTS barcode` ou check `Schema::hasColumn` avant ajout. Index conditionnel si MySQL 8+ sinon `try/catch`.
2. **Backward compat recherche** : la recherche par nom doit rester fonctionnelle ; le debounce ne doit pas changer l'UX perçue (≤ 150ms).
3. **Barcode lookup safe** : si plusieurs items ont le même barcode (data corrompue), retourner le premier + warning console.
4. **Raccourcis clavier non bloquants** : ne pas intercepter F1-F12 si focus dans un input texte (laisse comportement natif).
5. **Helper barcode propre** : pas de side-effect global (cleanup du listener au unmount du composant).
6. **Tests Vitest doivent simuler `KeyboardEvent`** correctement (utiliser `dispatchEvent`).

## TÂCHES À EXÉCUTER

### 1. Migration `add_barcode_index_to_items`

```php
public function up(): void
{
    Schema::table('items', function (Blueprint $table) {
        if (! Schema::hasColumn('items', 'barcode')) {
            $table->string('barcode', 64)->nullable()->after(/* champ stable existant */);
            $table->index('barcode', 'items_barcode_idx');
        }
    });
}

public function down(): void
{
    Schema::table('items', function (Blueprint $table) {
        if (Schema::hasColumn('items', 'barcode')) {
            $table->dropIndex('items_barcode_idx');
            $table->dropColumn('barcode');
        }
    });
}
```

Mettre à jour `Item::$fillable` pour inclure `barcode`.

### 2. Endpoint lookup barcode

Vérifier si une route admin POS expose déjà `items/search`. Si oui, étendre :
```php
public function lookupBarcode(string $code) {
    $item = Item::query()->where('barcode', $code)->where('is_available', true)->first();
    return $item ? new ItemResource($item) : response()->json(['error' => 'not_found'], 404);
}
```
Sinon créer route et controller minimal sous `routes/api.php` (auth POS middleware).

### 3. Helper `posBarcode.js`

```js
const BARCODE_THRESHOLD_MS = 50;     // entre 2 keypresses
const BARCODE_MIN_LENGTH = 6;
const ENTER_KEY = 'Enter';

export function createBarcodeDetector(onBarcode) {
    let buffer = '';
    let lastKeyAt = 0;

    function handler(event) {
        const now = performance.now();
        const delta = now - lastKeyAt;
        lastKeyAt = now;

        if (event.key === ENTER_KEY) {
            if (buffer.length >= BARCODE_MIN_LENGTH) {
                const code = buffer;
                buffer = '';
                onBarcode(code);
                event.preventDefault();
            } else {
                buffer = '';
            }
            return;
        }

        if (event.key.length === 1 && /[\w-]/.test(event.key)) {
            // Reset buffer si frappe trop lente (humain)
            if (delta > BARCODE_THRESHOLD_MS && buffer.length > 0) {
                buffer = '';
            }
            buffer += event.key;
        }
    }

    window.addEventListener('keydown', handler, true);
    return () => window.removeEventListener('keydown', handler, true);
}
```

Helper de raccourcis F-keys :
```js
export function createFKeyShortcuts(onShortcut) {
    function handler(event) {
        // ne pas intercepter si focus dans input/textarea/contenteditable
        const target = event.target;
        if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) {
            return;
        }
        const m = /^F(\d{1,2})$/.exec(event.key);
        if (m) {
            const idx = parseInt(m[1], 10);
            if (idx >= 1 && idx <= 12) {
                event.preventDefault();
                onShortcut(idx);
            }
        }
    }
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
}
```

### 4. Intégration `PosComponent.vue`

```js
import { createBarcodeDetector, createFKeyShortcuts } from "../../../helpers/posBarcode";
import _ from "lodash";

mounted() {
    this._stopBarcode = createBarcodeDetector((code) => this.onBarcodeScanned(code));
    this._stopFKeys = createFKeyShortcuts((idx) => this.onFKeyShortcut(idx));
    this._debouncedSearch = _.debounce((val) => { this.applySearch(val); }, 150);
},
beforeUnmount() {
    if (this._stopBarcode) this._stopBarcode();
    if (this._stopFKeys) this._stopFKeys();
},
methods: {
    onBarcodeScanned(code) {
        // Lookup via store ; si trouvé, ouvrir modal
        this.$store.dispatch('item/lookupByBarcode', code).then((item) => {
            if (item) this.openItemModal(item);
            else this.$toast?.error?.(this.$t('pos.barcode_not_found', { code }));
        });
    },
    onFKeyShortcut(idx) {
        const cat = this.categories?.[idx - 1];
        if (cat) this.selectCategory(cat);
    },
    onSearchInput(event) { this._debouncedSearch(event.target.value); },
}
```

Brancher `onSearchInput` sur l'input recherche (remplacer `v-model` direct par `:value` + `@input`).

### 5. i18n

`fr.json` : `"pos.barcode_not_found": "Code-barres non reconnu : {code}"`
`en.json` : `"pos.barcode_not_found": "Unknown barcode: {code}"`
`ar.json` : `"pos.barcode_not_found": "رمز شريطي غير معروف: {code}"`

### 6. Tests Vitest

CREATE `tests/js/posBarcode.spec.js` (6 cas) :
1. Frappe rapide >6 chars + Enter → callback déclenché avec le code.
2. Frappe lente (gap > 50ms) → buffer reset, pas de callback.
3. Frappe < 6 chars + Enter → ignoré.
4. F1 hors input → callback `(1)`.
5. F1 dans `<input>` focused → pas de callback (laisse natif).
6. F13+ → ignoré.

### 7. Régression

```bash
npx vitest run tests/js/PosComponent.spec.js tests/js/posCart.spec.js tests/js/posBarcode.spec.js
```
→ Tous verts.

```bash
php artisan migrate --pretend
```
→ Migration idempotente, pas d'erreur.

## ACCEPTANCE

- [ ] Migration `add_barcode_index_to_items` créée et idempotente
- [ ] `Item::$fillable` inclut `barcode`
- [ ] Endpoint lookup barcode ou méthode store dispo
- [ ] Helper `posBarcode.js` exporte `createBarcodeDetector` + `createFKeyShortcuts`
- [ ] PosComponent intègre debounce search + barcode + F-keys (cleanup au unmount)
- [ ] i18n FR + EN + AR pour `pos.barcode_not_found`
- [ ] Sentinel `posBarcode.spec.js` : 6/6 verts
- [ ] Régression POS : 0
- [ ] Aucun listener global laissé après unmount du composant

## RUN_REPORT

`reports/execution/RUN_V14_T10_POS_SEARCH_BARCODE_2026-04-20.md`

Doit contenir : diff migration + helper + integration + Vitest + cleanup proof (test ou audit manuel).

## NOTES AUDITEUR

- Si la stack Vue est en Options API, garder Options API (pas de mix).
- Si `lodash` n'est pas importé globalement, importer juste `debounce` : `import debounce from 'lodash/debounce'`.
- La détection HID barcode est heuristique ; documenter dans le helper que des scanners très lents peuvent ne pas être détectés (TODO : config UI plus tard).
- Si plusieurs `keydown` listeners sont déjà attachés (ex: navigation livraison existante), bien isoler avec `event.stopPropagation` UNIQUEMENT si nécessaire pour barcode (pas pour F-keys).
