<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remplace dans order_items.allergens_snapshot les anciens codes anglais
 * par les codes FR (alignés sur 2026_04_18_140002_rename_allergen_codes_to_fr).
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private array $codeMap = [
        'gluten' => 'gluten',
        'crustaceans' => 'crustaces',
        'eggs' => 'oeufs',
        'fish' => 'poisson',
        'peanuts' => 'arachides',
        'soy' => 'soja',
        'milk' => 'lait',
        'tree_nuts' => 'fruits_a_coque',
        'celery' => 'celeri',
        'mustard' => 'moutarde',
        'sesame' => 'sesame',
        'sulphites' => 'sulfites',
        'lupin' => 'lupin',
        'molluscs' => 'mollusques',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('order_items') || !Schema::hasColumn('order_items', 'allergens_snapshot')) {
            return;
        }

        DB::table('order_items')
            ->whereNotNull('allergens_snapshot')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->allergens_snapshot, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    $remapped = $this->remapSnapshotCodes($decoded);
                    if ($remapped === $decoded) {
                        continue;
                    }
                    DB::table('order_items')->where('id', $row->id)->update([
                        'allergens_snapshot' => json_encode($remapped, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });
    }

    /**
     * @param  array<int, mixed>  $codes
     * @return array<int, string>
     */
    private function remapSnapshotCodes(array $codes): array
    {
        $out = [];
        $seen = [];
        foreach ($codes as $code) {
            if (!is_string($code) || $code === '') {
                continue;
            }
            $to = $this->codeMap[$code] ?? $code;
            if (isset($seen[$to])) {
                continue;
            }
            $seen[$to] = true;
            $out[] = $to;
        }

        return $out;
    }

    public function down(): void
    {
        // Backfill données : pas de rollback sûr (mélange possible FR natif / migré).
    }
};
