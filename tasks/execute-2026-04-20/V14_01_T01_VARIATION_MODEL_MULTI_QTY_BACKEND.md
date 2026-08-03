# V14 #1 — T01 — `P14_VARIATION_MODEL_MULTI_QTY_BACKEND`

## Header

```
TASK_ID: V14_01_T01_VARIATION_MODEL_MULTI_QTY_BACKEND
WAVE: A — Backend Foundation
GATE_REFERENCE: docs/gates/GATE_G14A_VARIATION_MULTI_QTY_CONSOLIDATED_2026-04-20.md (G14-A)
PRIMARY_MODEL: GPT-5.4 (foodking-complex-implementer)
RUNNER_MODE: single-session
PARALLEL_WITH: V14_02_T05_*, V14_03_T07_*  (3 subagents simultanés, vague A)
DEPENDS_ON: nothing — foundation cycle
BLOCKS: T02, T03, T04, T05 (pricing), T06, T07 (snapshot), T08
SEVERITY: P0
EFFORT_EST: 1.5h
```

## SUBSYSTEMS_TOUCHED

- `database/migrations/` (CREATE 1 migration)
- `app/Models/ItemAttribute.php` (EDIT — fillable + casts)
- `app/Http/Requests/ItemAttribute/*.php` (EDIT — validation create/update si existant)

## SUBSYSTEMS_OFF_LIMITS (interdits)

- `app/Services/OrderService.php` (T05 territoire)
- `app/Services/FrontendOrderService.php` (T05 territoire)
- `app/Services/Pricing/**` (T05 territoire)
- `app/Models/OrderItem.php` (T07 territoire)
- `app/Http/Resources/OrderItemResource.php` (T07 territoire)
- `resources/js/**` (vague B)
- Tout fichier sous `tasks/phase9-sync/` (LOCK files — read-only)

## INVARIANTS_AT_RISK

- Schéma DB : la migration DOIT être additive nullable, idempotente up/down, **zéro backfill destructif**
- Modèle Eloquent : ne casser aucun consumer existant (status, name reads/writes inchangés)
- Pas de nouvelle règle métier dans cette task ; seulement **schéma + sérialisation**

---

## PLAN (lecture obligatoire avant EXECUTE)

### Contexte recon

`item_attributes` actuellement (cf. `database/migrations/2022_11_17_110541_create_item_attributes_table.php`) :
- `id`, `name`, `status`, `creator_*`, `editor_*`, timestamps. **Aucune contrainte multi-select.**

Modèle `App\Models\ItemAttribute` (cf. `app/Models/ItemAttribute.php`) :
- `fillable = ['name', 'status']`
- `casts = ['id'=>'integer', 'name'=>'string', 'status'=>'integer']`

Bug user-reported (cf. Gate G14-A §2) : impossible d'exprimer "tacos 4 viandes mixables" car aucune contrainte côté attribut + UI POS single-select strict.

### Décisions techniques (alignées Gate G14-A §5)

1. **3 colonnes nullable defaultées rétro-compat** :
   - `min_select` UNSIGNED INT DEFAULT 0 (0 = optionnel)
   - `max_select` UNSIGNED INT DEFAULT 1 (1 = single-select, comportement actuel)
   - `allow_repeat` BOOLEAN DEFAULT FALSE (FALSE = pas de duplication, comportement actuel)
2. **Aucun backfill** : defaults reflètent le comportement actuel.
3. **Modèle** : ajouter aux fillable + casts (int / int / boolean).
4. **Validation FormRequest** : si une `Http/Requests/ItemAttribute/{Create,Update}*Request.php` existe, ajouter règles ; sinon ne rien créer (out of scope T01).

---

## EXECUTE

### Étape 1 — Créer migration additive

Fichier : `database/migrations/2026_04_22_000010_add_min_max_repeat_to_item_attributes.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('item_attributes', function (Blueprint $table) {
            if (! Schema::hasColumn('item_attributes', 'min_select')) {
                $table->unsignedInteger('min_select')->default(0)->after('name');
            }
            if (! Schema::hasColumn('item_attributes', 'max_select')) {
                $table->unsignedInteger('max_select')->default(1)->after('min_select');
            }
            if (! Schema::hasColumn('item_attributes', 'allow_repeat')) {
                $table->boolean('allow_repeat')->default(false)->after('max_select');
            }
        });
    }

    public function down(): void
    {
        Schema::table('item_attributes', function (Blueprint $table) {
            foreach (['allow_repeat', 'max_select', 'min_select'] as $col) {
                if (Schema::hasColumn('item_attributes', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
```

### Étape 2 — Étendre modèle Eloquent

Fichier : `app/Models/ItemAttribute.php`

Modifier UNIQUEMENT `$fillable` et `$casts` (ne pas toucher relations/accessors si présents) :

```php
protected $fillable = ['name', 'status', 'min_select', 'max_select', 'allow_repeat'];

protected $casts = [
    'id'           => 'integer',
    'name'         => 'string',
    'status'       => 'integer',
    'min_select'   => 'integer',
    'max_select'   => 'integer',
    'allow_repeat' => 'boolean',
];
```

### Étape 3 — Validation FormRequest (conditionnel)

```bash
ls app/Http/Requests/ItemAttribute/ 2>/dev/null
```

- Si dossier existe et contient des classes Request : ajouter règles aux 2 fichiers (Create + Update) — chacun :
  ```php
  'min_select'   => ['nullable', 'integer', 'min:0'],
  'max_select'   => ['nullable', 'integer', 'min:0', 'gte:min_select'],
  'allow_repeat' => ['nullable', 'boolean'],
  ```
- Si dossier inexistant ou vide : SKIP étape 3 (laisser remediation à T03).
- Documenter le choix dans le RUN_REPORT.

### Étape 4 — Tests Feature minimaux

Fichier : `tests/Feature/ItemAttributeMultiSelectMigrationTest.php` (CREATE)

Couvrir :
1. `migrate:fresh` : les 3 colonnes existent avec defaults attendus
2. `ItemAttribute::create(['name'=>'Viande'])` : `min_select=0, max_select=1, allow_repeat=false`
3. `ItemAttribute::create(['name'=>'Viande', 'min_select'=>1, 'max_select'=>4, 'allow_repeat'=>true])` : casts corrects (int, int, bool)
4. `migrate:rollback --step=1` : les 3 colonnes disparaissent ; legacy attributes restent intacts (`name`, `status`)
5. `migrate` à nouveau : restauration sans données perdues sur `name` / `status`

```php
<?php
namespace Tests\Feature;

use App\Models\ItemAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ItemAttributeMultiSelectMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_exist_after_migration(): void
    {
        $this->assertTrue(Schema::hasColumns('item_attributes', ['min_select', 'max_select', 'allow_repeat']));
    }

    public function test_defaults_preserve_legacy_single_select(): void
    {
        $a = ItemAttribute::create(['name' => 'Viande', 'status' => 1]);
        $a->refresh();
        $this->assertSame(0, $a->min_select);
        $this->assertSame(1, $a->max_select);
        $this->assertFalse($a->allow_repeat);
    }

    public function test_multi_select_values_persist_with_correct_casts(): void
    {
        $a = ItemAttribute::create([
            'name' => 'Viande',
            'status' => 1,
            'min_select' => 1,
            'max_select' => 4,
            'allow_repeat' => true,
        ]);
        $a->refresh();
        $this->assertSame(1, $a->min_select);
        $this->assertSame(4, $a->max_select);
        $this->assertTrue($a->allow_repeat);
    }
}
```

---

## VALIDATE

```bash
php artisan migrate --pretend           # dry-run
php artisan migrate                      # up
php artisan migrate:rollback --step=1    # down
php artisan migrate                      # up à nouveau

php artisan test --filter=ItemAttributeMultiSelectMigrationTest
php artisan test tests/Feature/PricingIntegrityTest.php           # régression
php artisan test tests/Feature/Services/Pricing/PricingServiceTest.php  # régression

bash scripts/check-invariants.sh
```

Tous doivent passer.

---

## AUDIT (autodiagnostic obligatoire avant CLOSE)

- ☐ Migration up/down idempotente (testée 2 fois consécutives avec `Schema::hasColumn` guards)
- ☐ Modèle : aucun champ retiré, seulement ajoutés
- ☐ FormRequest : étape 3 documentée (skip ou applied)
- ☐ 3 tests verts dans `ItemAttributeMultiSelectMigrationTest`
- ☐ `PricingServiceTest` + `PricingIntegrityTest` 100% verts inchangés
- ☐ `scripts/check-invariants.sh` 6/6 verts
- ☐ Aucun fichier OFF-LIMITS modifié (vérifier `git status` strict)

---

## CLOSE

Append au fichier `reports/execution/RUN_V14_01_T01_VARIATION_MODEL_MULTI_QTY_2026-04-20.md` :

```markdown
# RUN_V14_01_T01_VARIATION_MODEL_MULTI_QTY_2026-04-20

## Final report
- task_id: V14_01_T01_VARIATION_MODEL_MULTI_QTY_BACKEND
- status: CLOSED
- attempts: <N>
- artifacts: [migration path, modèle path, test path]
- form_request_action: applied | skipped (justification)
- regression_tests_status: PricingIntegrityTest ✓ / PricingServiceTest ✓
- invariants_check: 6/6
- next_dependent: T05 (peut démarrer en // si pas déjà actif)
```

Update `.cursor/ACTIVE_CYCLE.md` : marquer T01 CLOSED, vérifier T05/T07 status.
