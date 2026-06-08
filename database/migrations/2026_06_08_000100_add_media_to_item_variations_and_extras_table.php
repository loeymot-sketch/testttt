<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 1] Per-option media on catalog constructs.
 *
 * The wizard builder edits photo + description PER OPTION; per the source-binding
 * contract these persist to the CONSTRUCT (ItemVariation / ItemExtra) where they
 * legally live — NOT on the wizard step (price stays NF525 SSOT, prohibited on
 * steps). PRICE already exists on both constructs; this adds the two missing
 * non-fiscal metadata columns. Additive + nullable = zero impact on the 45
 * legacy items (their images keep resolving via config/menu_images.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_variations', function (Blueprint $table): void {
            if (! Schema::hasColumn('item_variations', 'description')) {
                $table->text('description')->nullable()->after('caution');
            }
            if (! Schema::hasColumn('item_variations', 'image_path')) {
                $table->string('image_path', 2048)->nullable()->after('description');
            }
        });

        Schema::table('item_extras', function (Blueprint $table): void {
            if (! Schema::hasColumn('item_extras', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
            if (! Schema::hasColumn('item_extras', 'image_path')) {
                $table->string('image_path', 2048)->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('item_variations', function (Blueprint $table): void {
            if (Schema::hasColumn('item_variations', 'image_path')) {
                $table->dropColumn('image_path');
            }
            if (Schema::hasColumn('item_variations', 'description')) {
                $table->dropColumn('description');
            }
        });

        Schema::table('item_extras', function (Blueprint $table): void {
            if (Schema::hasColumn('item_extras', 'image_path')) {
                $table->dropColumn('image_path');
            }
            if (Schema::hasColumn('item_extras', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
