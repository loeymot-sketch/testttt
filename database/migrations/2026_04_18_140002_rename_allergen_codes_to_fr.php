<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
        if (Schema::hasTable('allergens')) {
            $this->renameAllergenRows($this->codeMap);
        }

        $this->remapLegacyFlags($this->codeMap);
    }

    public function down(): void
    {
        $reverseMap = array_flip($this->codeMap);

        if (Schema::hasTable('allergens')) {
            $this->renameAllergenRows($reverseMap);
        }

        $this->remapLegacyFlags($reverseMap);
    }

    private function renameAllergenRows(array $map): void
    {
        foreach ($map as $from => $to) {
            DB::table('allergens')
                ->where('code', $from)
                ->update([
                    'code' => $to,
                    'name_key' => 'allergens.' . $to,
                ]);
        }
    }

    private function remapLegacyFlags(array $map): void
    {
        if (!Schema::hasTable('items') || !Schema::hasColumn('items', 'allergen_flags')) {
            return;
        }

        DB::table('items')
            ->select(['id', 'allergen_flags'])
            ->orderBy('id')
            ->get()
            ->each(function (object $row) use ($map): void {
                if ($row->allergen_flags === null) {
                    return;
                }

                $decoded = json_decode((string) $row->allergen_flags, true);
                if (!is_array($decoded)) {
                    return;
                }

                $remapped = $this->remapFlagsValue($decoded, $map);
                if ($remapped === $decoded) {
                    return;
                }

                DB::table('items')
                    ->where('id', $row->id)
                    ->update([
                        'allergen_flags' => json_encode($remapped, JSON_UNESCAPED_UNICODE),
                    ]);
            });
    }

    private function remapFlagsValue(array $value, array $map): array
    {
        $isSequential = array_keys($value) === range(0, count($value) - 1);

        if ($isSequential) {
            return array_values(array_map(
                fn ($code) => is_string($code) && array_key_exists($code, $map) ? $map[$code] : $code,
                $value
            ));
        }

        $remapped = [];
        foreach ($value as $code => $flag) {
            $remappedCode = is_string($code) && array_key_exists($code, $map) ? $map[$code] : $code;
            $remapped[$remappedCode] = $flag;
        }

        return $remapped;
    }
};
