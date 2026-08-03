<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [PROMO-DASH-2026-05-06] — Étend la table `coupons` avec les attributs de
 * scoping avancés exigés par le Dashboard FoodKing :
 *  - validité jour-de-semaine / plage horaire (happy-hour),
 *  - portée multi-branches,
 *  - portée canal (POS / kiosk / web),
 *  - quota d'utilisation global + tracking,
 *  - status ACTIVE/INACTIVE (5/10) pour aligner sur App\Enums\Status.
 *
 * Tous les champs sont additifs / nullables / défauts neutres : les codes promo
 * existants restent utilisables (status par défaut = 5 = ACTIVE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (!Schema::hasColumn('coupons', 'valid_days_of_week')) {
                $table->json('valid_days_of_week')->nullable()->after('end_date');
            }
            if (!Schema::hasColumn('coupons', 'valid_hours_start')) {
                $table->time('valid_hours_start')->nullable()->after('valid_days_of_week');
            }
            if (!Schema::hasColumn('coupons', 'valid_hours_end')) {
                $table->time('valid_hours_end')->nullable()->after('valid_hours_start');
            }
            if (!Schema::hasColumn('coupons', 'branch_scope')) {
                $table->json('branch_scope')->nullable()->after('valid_hours_end');
            }
            if (!Schema::hasColumn('coupons', 'surfaces')) {
                $table->json('surfaces')->nullable()->after('branch_scope');
            }
            if (!Schema::hasColumn('coupons', 'max_uses_global')) {
                $table->unsignedInteger('max_uses_global')->nullable()->after('surfaces');
            }
            if (!Schema::hasColumn('coupons', 'usage_count')) {
                $table->unsignedInteger('usage_count')->default(0)->after('max_uses_global');
            }
            if (!Schema::hasColumn('coupons', 'status')) {
                // 5 = ACTIVE, 10 = INACTIVE (cf. App\Enums\Status). Default ACTIVE
                // pour ne pas casser les codes promo existants.
                $table->unsignedTinyInteger('status')->default(5)->after('usage_count');
            }
        });

        // Index séparés — driver-portable (MySQL + SQLite). On utilise le
        // SchemaManager Doctrine pour interroger l'état réel des index.
        $existing = $this->existingIndexes();
        Schema::table('coupons', function (Blueprint $table) use ($existing) {
            if (!in_array('coupons_status_index', $existing, true)) {
                $table->index('status', 'coupons_status_index');
            }
            if (!in_array('coupons_code_index', $existing, true)) {
                $table->index('code', 'coupons_code_index');
            }
            if (!in_array('coupons_start_date_index', $existing, true)) {
                $table->index('start_date', 'coupons_start_date_index');
            }
            if (!in_array('coupons_end_date_index', $existing, true)) {
                $table->index('end_date', 'coupons_end_date_index');
            }
        });
    }

    public function down(): void
    {
        $existing = $this->existingIndexes();
        Schema::table('coupons', function (Blueprint $table) use ($existing) {
            foreach ([
                'coupons_status_index',
                'coupons_code_index',
                'coupons_start_date_index',
                'coupons_end_date_index',
            ] as $idx) {
                if (in_array($idx, $existing, true)) {
                    $table->dropIndex($idx);
                }
            }
        });

        Schema::table('coupons', function (Blueprint $table) {
            foreach ([
                'valid_days_of_week',
                'valid_hours_start',
                'valid_hours_end',
                'branch_scope',
                'surfaces',
                'max_uses_global',
                'usage_count',
                'status',
            ] as $col) {
                if (Schema::hasColumn('coupons', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    /**
     * Liste des index existants sur la table `coupons`, driver-portable.
     * Utilise le SchemaManager Doctrine pour rester compatible MySQL + SQLite.
     */
    private function existingIndexes(): array
    {
        try {
            $manager = Schema::getConnection()->getDoctrineSchemaManager();
            return array_keys($manager->listTableIndexes('coupons'));
        } catch (\Throwable $e) {
            return [];
        }
    }
};
