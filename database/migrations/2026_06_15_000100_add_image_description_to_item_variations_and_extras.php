<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [WIZARD-STUDIO W4 2026-06-15] Additive, non-frozen: per-option image + description for the
 * visual wizard builder. Today ItemVariation/ItemExtra images come only from a config name-match
 * accessor (config/menu_images.php) and there is no description at all. These nullable columns let
 * the Studio attach a real image + description per option; the projection prefers them and falls
 * back to the existing config thumb, so the FROZEN kiosk wizard (reads choices[].thumb) is unchanged.
 * NF525-neutral: no price/fiscal field touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_variations', function (Blueprint $table) {
            if (! Schema::hasColumn('item_variations', 'image_path')) {
                $table->string('image_path')->nullable()->after('name')
                    ->comment('Wizard Studio: per-option image (storage path); falls back to config thumb');
            }
            if (! Schema::hasColumn('item_variations', 'description')) {
                $table->string('description', 255)->nullable()->after('image_path');
            }
        });

        Schema::table('item_extras', function (Blueprint $table) {
            if (! Schema::hasColumn('item_extras', 'image_path')) {
                $table->string('image_path')->nullable()->after('name')
                    ->comment('Wizard Studio: per-option image (storage path); falls back to config thumb');
            }
            if (! Schema::hasColumn('item_extras', 'description')) {
                $table->string('description', 255)->nullable()->after('image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('item_variations', function (Blueprint $table) {
            foreach (['image_path', 'description'] as $col) {
                if (Schema::hasColumn('item_variations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('item_extras', function (Blueprint $table) {
            foreach (['image_path', 'description'] as $col) {
                if (Schema::hasColumn('item_extras', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
