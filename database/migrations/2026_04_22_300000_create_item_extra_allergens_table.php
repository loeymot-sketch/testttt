<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [W8.5 HOTFIX] Pivot table item_extras <-> allergens.
 *
 * Auparavant : OrderAllergenSnapshotComposedTest::setUp() créait la table à
 * la volée via Schema::create() pour pouvoir tester le chemin
 * "extras avec allergènes". Sous MySQL 8.0 ce DDL runtime déclenche un
 * commit implicite qui casse la transaction d'enveloppe RefreshDatabase :
 * toutes les fixtures du test (items "Tacos bœuf sentinel", catégorie
 * "Tacos Test", allergène "lait") survivaient au rollback et polluaient
 * la suite — provoquant 11 échecs en cascade dans
 * MenuProjectionServiceTest sur le runner CI MySQL.
 *
 * Voir reports/ci/REPORT_PHPUNIT_MYSQL_ISOLATION_ROOT_CAUSE_2026-04-21.md
 *
 * Solution : matérialiser la table en migration permanente (utilisée
 * également par OrderItemAllergenSnapshot::resolveExtraAllergens() qui
 * supportait déjà l'absence via Schema::hasTable()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_extra_allergens')) {
            return;
        }

        Schema::create('item_extra_allergens', function (Blueprint $table): void {
            $table->foreignId('item_extra_id')
                ->constrained('item_extras')
                ->cascadeOnDelete();
            $table->foreignId('allergen_id')
                ->constrained('allergens')
                ->cascadeOnDelete();
            $table->primary(['item_extra_id', 'allergen_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_extra_allergens');
    }
};
