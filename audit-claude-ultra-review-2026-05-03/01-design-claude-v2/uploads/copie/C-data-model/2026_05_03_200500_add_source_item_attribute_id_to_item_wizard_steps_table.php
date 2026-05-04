<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_wizard_steps', function (Blueprint $table): void {
            if (! Schema::hasColumn('item_wizard_steps', 'source_item_attribute_id')) {
                $table->foreignId('source_item_attribute_id')
                    ->nullable()
                    ->after('source_ref')
                    ->constrained('item_attributes')
                    ->nullOnDelete();
            }
        });

        // Backfill deterministic rows only:
        // source_type=item_attribute AND source_ref is numeric and existing.
        DB::table('item_wizard_steps')
            ->select(['id', 'source_type', 'source_ref'])
            ->orderBy('id')
            ->chunkById(250, function ($rows): void {
                $candidateByRowId = [];
                $candidateAttributeIds = [];

                foreach ($rows as $row) {
                    if (($row->source_type ?? null) !== 'item_attribute') {
                        continue;
                    }
                    $raw = trim((string) ($row->source_ref ?? ''));
                    if ($raw === '' || ! ctype_digit($raw)) {
                        continue;
                    }

                    $attrId = (int) $raw;
                    if ($attrId <= 0) {
                        continue;
                    }
                    $candidateByRowId[(int) $row->id] = $attrId;
                    $candidateAttributeIds[$attrId] = true;
                }

                if ($candidateByRowId === []) {
                    return;
                }

                $existing = DB::table('item_attributes')
                    ->whereIn('id', array_keys($candidateAttributeIds))
                    ->pluck('id')
                    ->map(static fn ($id) => (int) $id)
                    ->all();
                $existingSet = array_fill_keys($existing, true);

                foreach ($candidateByRowId as $rowId => $attrId) {
                    if (! isset($existingSet[$attrId])) {
                        continue;
                    }
                    DB::table('item_wizard_steps')
                        ->where('id', $rowId)
                        ->update(['source_item_attribute_id' => $attrId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('item_wizard_steps', function (Blueprint $table): void {
            if (Schema::hasColumn('item_wizard_steps', 'source_item_attribute_id')) {
                $table->dropForeign(['source_item_attribute_id']);
                $table->dropColumn('source_item_attribute_id');
            }
        });
    }
};
