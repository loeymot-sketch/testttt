<?php

namespace Database\Seeders;

use App\Models\ItemAttribute;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class RepairMultiVariationFixturesSeeder extends Seeder
{
    /**
     * @param  bool|null  $force  null = derive from artisan --force; explicit bool for tests
     */
    public function run(?bool $force = null): void
    {
        $force = $force ?? (bool) ($this->command?->option('force') ?? false);

        $policyPath = database_path('seeders/_data/multi_variation_policy.json');
        $policy = json_decode(File::get($policyPath), true, 512, JSON_THROW_ON_ERROR);

        $candidatesRegex = '/viande|sauce|garniture|topping|extra|menu|formule|composant|mix|choix|option/i';
        $allAttributes = ItemAttribute::query()->get();

        $candidates = $allAttributes->filter(fn ($a) => preg_match($candidatesRegex, (string) $a->name));
        $report = [];

        foreach ($candidates as $attr) {
            $matched = null;
            foreach ($policy['rules'] ?? [] as $rule) {
                if (! isset($rule['match']['name_regex'])) {
                    continue;
                }
                $pattern = '/' . str_replace('/', '\/', $rule['match']['name_regex']) . '/i';
                if (@preg_match($pattern, (string) $attr->name)) {
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
                $this->command?->info("APPLIED → attribute #{$attr->id} '{$attr->name}'");
            }
        }

        // [SUPERVISION 2026-08-22] En `testing`, l'audit atterrit sous storage/. Ce seeder est
        // appelé par la suite de tests, qui déposait donc dans le dépôt un fichier NEUF par
        // jour calendaire, en-tête « **Mode: FORCED (DB MUTATED)** » — relu plus tard, cela se
        // lit comme la preuve qu'on a forcé une mutation de la base opérationnelle.
        // Voir App\Support\GeneratedReportPath.
        $reportPath = \App\Support\GeneratedReportPath::resolve(
            'reports/data-repair/MULTI_VARIATION_AUDIT_' . now()->toDateString() . '.md'
        );
        $dir = dirname($reportPath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $md = "# Multi-Variation Fixtures Audit — " . now()->toDateTimeString() . "\n\n";
        $md .= 'Mode: ' . ($force ? '**FORCED (DB MUTATED)**' : 'DRY-RUN (no mutation)') . "\n\n";
        $md .= 'Total candidates: ' . count($report) . "\n\n";
        $md .= "| ID | Name | Current (min/max/repeat) | Recommended | Will apply? |\n|---|---|---|---|---|\n";
        foreach ($report as $r) {
            $cur = "{$r['current']['min_select']}/{$r['current']['max_select']}/" . ($r['current']['allow_repeat'] ? 'true' : 'false');
            $rec = $r['recommended'] ? json_encode($r['recommended']) : '_no rule matched_';
            $md .= "| {$r['id']} | {$r['name']} | {$cur} | {$rec} | " . ($r['will_apply'] ? '✅' : '⏸') . " |\n";
        }
        File::put($reportPath, $md);

        $this->command?->info("Report written: {$reportPath}");
        if (! $force) {
            $this->command?->warn('DRY-RUN mode. No DB mutation. Pass --force to apply matched rules.');
        }
    }
}
