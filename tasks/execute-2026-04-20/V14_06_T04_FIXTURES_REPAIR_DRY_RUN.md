# V14 #6 — T04 — `P14_VARIATION_FIXTURES_DATA_REPAIR` (DRY-RUN AUDIT)

## Header

```
TASK_ID: V14_06_T04_FIXTURES_REPAIR_DRY_RUN
WAVE: B — Data Repair Audit (NO DB MUTATION)
GATE_REFERENCE: aucun (mode dry-run par défaut, mutation = gate humain séparé futur)
PRIMARY_MODEL: Composer (foodking-routine-implementer)
RUNNER_MODE: single-session
PARALLEL_WITH: V14_04_T02_T20_*, V14_05_T06_*
DEPENDS_ON: V14 Vague A T01 (colonnes min_select / max_select / allow_repeat existent en DB)
SEVERITY: P1 (audit purement informatif, aucune mutation)
EFFORT_EST: 1.5h
```

## Contexte

T01 a ajouté `min_select`, `max_select`, `allow_repeat` sur `item_attributes` avec defaults backward-compat (`0, 1, false`). Ce qui veut dire : **toute la production existante est en mode single-select** (max_select=1, allow_repeat=false). Or des produits métier comme "Tacos 2 viandes", "Tacos 4 viandes mix" devraient avoir `max_select=2|4`, `allow_repeat=true`.

**T04 ne mute PAS la DB** (interdit sans gate humain). T04 produit :
1. Un audit DRY-RUN listant les attributs candidats (où `name` matche `viande|sauce|garniture|topping|extra|menu|formule|composant|mix|choix|option`).
2. Un seeder `RepairMultiVariationFixturesSeeder` invocable manuellement (`--force` requis) pour appliquer une politique externe.
3. Un fichier de politique `multi_variation_policy.json` documentant les règles métier (vide à livraison, à remplir par PO/dev).

## SUBSYSTEMS_TOUCHED

- `database/seeders/RepairMultiVariationFixturesSeeder.php` (CREATE — mode dry-run par défaut)
- `database/seeders/_data/multi_variation_policy.json` (CREATE — vide à livraison, schema documenté)
- `reports/data-repair/MULTI_VARIATION_AUDIT_2026-04-20.md` (CREATE — sortie de la commande dry-run)
- `tests/Feature/RepairMultiVariationFixturesSeederTest.php` (CREATE — assert dry-run ne touche RIEN)

## SUBSYSTEMS_OFF_LIMITS

- **TOUTE** mutation directe en `item_attributes`, `item_variations`, `items` (interdit dans ce cycle)
- `app/Services/**`, `app/Http/**`, `resources/js/**` (hors scope T04)
- `database/migrations/**` (territoire T01)
- Production DB live (jamais touchée)

## INVARIANTS_AT_RISK

1. **No mutation** : le seeder en mode `--dry-run` (par défaut) doit produire un rapport SANS écrire en DB. Test sentinel : compter les rows `item_attributes` AVANT et APRÈS, doit être identique.
2. **Idempotence** : exécuter 2 fois le seeder dry-run produit le même rapport.
3. **Pas de fuite secrets** : le rapport ne contient pas d'IDs sensibles (branch_id staging != prod).
4. **Politique externe** : le seeder ne contient AUCUNE règle métier hardcodée — tout vient de `multi_variation_policy.json`.

## TÂCHES À EXÉCUTER

### 1. Créer la politique JSON déclarative
`database/seeders/_data/multi_variation_policy.json` (vide à livraison, mais avec schéma commenté) :

```json
{
  "$schema": "Schema documentation: each rule matches an item_attribute by name regex (case-insensitive) and applies the multi_select fields.",
  "version": 1,
  "rules": []
}
```

Exemple commenté en haut du fichier :
```jsonc
// Example rule (NOT applied — empty rules[] by default):
// {
//   "match": { "name_regex": "^viande$", "item_id": null },
//   "apply": { "min_select": 1, "max_select": 4, "allow_repeat": true },
//   "scope": "all_branches",
//   "comment": "Tacos: 1 to 4 meats, mix authorised"
// }
```

### 2. Créer le seeder

`database/seeders/RepairMultiVariationFixturesSeeder.php` :

```php
namespace Database\Seeders;

use App\Models\ItemAttribute;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class RepairMultiVariationFixturesSeeder extends Seeder
{
    public function run(bool $force = false): void
    {
        $policyPath = database_path('seeders/_data/multi_variation_policy.json');
        $policy = json_decode(File::get($policyPath), true);

        $candidatesRegex = '/viande|sauce|garniture|topping|extra|menu|formule|composant|mix|choix|option/i';
        $allAttributes = ItemAttribute::query()->get();

        $candidates = $allAttributes->filter(fn ($a) => preg_match($candidatesRegex, $a->name));
        $report = [];

        foreach ($candidates as $attr) {
            $matched = null;
            foreach ($policy['rules'] ?? [] as $rule) {
                if (preg_match('/' . $rule['match']['name_regex'] . '/i', $attr->name)) {
                    $matched = $rule;
                    break;
                }
            }
            $report[] = [
                'id' => $attr->id,
                'name' => $attr->name,
                'current' => [
                    'min_select' => $attr->min_select,
                    'max_select' => $attr->max_select,
                    'allow_repeat' => $attr->allow_repeat,
                ],
                'recommended' => $matched['apply'] ?? null,
                'will_apply' => $force && $matched !== null,
            ];

            if ($force && $matched !== null) {
                $attr->update($matched['apply']);
                $this->command->info("APPLIED → attribute #{$attr->id} '{$attr->name}'");
            }
        }

        // Write audit report (always, even in dry-run)
        $reportPath = base_path('reports/data-repair/MULTI_VARIATION_AUDIT_' . now()->toDateString() . '.md');
        File::ensureDirectoryExists(dirname($reportPath));
        $md = "# Multi-Variation Fixtures Audit — " . now()->toDateTimeString() . "\n\n";
        $md .= "Mode: " . ($force ? '**FORCED (DB MUTATED)**' : 'DRY-RUN (no mutation)') . "\n\n";
        $md .= "Total candidates: " . count($report) . "\n\n";
        $md .= "| ID | Name | Current (min/max/repeat) | Recommended | Will apply? |\n|---|---|---|---|---|\n";
        foreach ($report as $r) {
            $cur = "{$r['current']['min_select']}/{$r['current']['max_select']}/" . ($r['current']['allow_repeat'] ? 'true' : 'false');
            $rec = $r['recommended'] ? json_encode($r['recommended']) : '_no rule matched_';
            $md .= "| {$r['id']} | {$r['name']} | {$cur} | {$rec} | " . ($r['will_apply'] ? '✅' : '⏸') . " |\n";
        }
        File::put($reportPath, $md);

        $this->command->info("Report written: {$reportPath}");
        if (! $force) {
            $this->command->warn('DRY-RUN mode. No DB mutation. Pass --force to apply matched rules.');
        }
    }
}
```

### 3. Test sentinel no-mutation

`tests/Feature/RepairMultiVariationFixturesSeederTest.php` :

```php
namespace Tests\Feature;

use Database\Seeders\RepairMultiVariationFixturesSeeder;
use App\Models\ItemAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairMultiVariationFixturesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_mutate_db(): void
    {
        // Seed 3 candidate attributes
        ItemAttribute::factory()->create(['name' => 'Viande', 'max_select' => 1]);
        ItemAttribute::factory()->create(['name' => 'Sauce', 'max_select' => 1]);
        ItemAttribute::factory()->create(['name' => 'Couleur', 'max_select' => 1]);

        $before = ItemAttribute::all()->map->only(['id', 'name', 'min_select', 'max_select', 'allow_repeat'])->toArray();

        $seeder = new RepairMultiVariationFixturesSeeder();
        $seeder->setCommand($this->createCommandMock());
        $seeder->run(force: false);

        $after = ItemAttribute::all()->map->only(['id', 'name', 'min_select', 'max_select', 'allow_repeat'])->toArray();
        $this->assertEquals($before, $after, 'DRY-RUN must not mutate any item_attribute row.');
    }

    public function test_dry_run_writes_audit_report(): void
    {
        ItemAttribute::factory()->create(['name' => 'Viande', 'max_select' => 1]);

        $seeder = new RepairMultiVariationFixturesSeeder();
        $seeder->setCommand($this->createCommandMock());
        $seeder->run(force: false);

        $reportPath = base_path('reports/data-repair/MULTI_VARIATION_AUDIT_' . now()->toDateString() . '.md');
        $this->assertFileExists($reportPath);
        $content = file_get_contents($reportPath);
        $this->assertStringContainsString('DRY-RUN', $content);
        $this->assertStringContainsString('Viande', $content);
    }

    private function createCommandMock()
    {
        return new class {
            public function info($msg) {}
            public function warn($msg) {}
        };
    }
}
```

### 4. Run et valider

```bash
php artisan test --filter='RepairMultiVariation' 
```

→ 2/2 verts.

Lancer manuellement le dry-run sur la DB de dev (si possible) :
```bash
php artisan db:seed --class=RepairMultiVariationFixturesSeeder
```
→ doit produire `reports/data-repair/MULTI_VARIATION_AUDIT_2026-04-20.md` listant les attributs candidats RÉELS sans muter la DB.

## ACCEPTANCE

- [ ] `database/seeders/RepairMultiVariationFixturesSeeder.php` créé
- [ ] `database/seeders/_data/multi_variation_policy.json` créé avec schéma documenté + `rules: []` vide
- [ ] `tests/Feature/RepairMultiVariationFixturesSeederTest.php` créé, 2/2 verts
- [ ] Run manuel dry-run produit un rapport `MULTI_VARIATION_AUDIT_*.md` SANS muter `item_attributes`
- [ ] Aucune mutation DB (test sentinel le prouve)
- [ ] Le seeder accepte `force: true` mais reste no-op tant que `policy.rules` est vide

## RUN_REPORT

`reports/execution/RUN_V14_T04_FIXTURES_REPAIR_DRY_RUN_2026-04-20.md`

Doit contenir : count attributs candidats trouvés en dev DB, exemple de ligne du rapport audit, confirmation "no mutation" (avant/après count rows).

## NOTES AUDITEUR

- Cette tâche est purement préparatoire. Le PO ou le dev seniors devra ensuite remplir `multi_variation_policy.json` avec les vraies règles métier (ex: tacos = max 4 viandes), puis lancer le seeder en `--force` après validation humaine (gate à part).
- Si `ItemAttribute::factory()` n'existe pas, le créer minimal (`name`, `status`, defaults T01).
