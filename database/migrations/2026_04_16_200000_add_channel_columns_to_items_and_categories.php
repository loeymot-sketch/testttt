<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V1 section 5 — Menu Borne ↔ Menu POS dual-channel SSOT.
 *
 * Schema additions only. Null defaults preserve V1 behaviour:
 *   - NULL `channels`      → item visible on every surface (back-compat)
 *   - NULL `kiosk_sort`    → fallback to `sort` on kiosk projection
 *   - NULL `pos_sort`      → fallback to `sort` on POS projection
 *   - NULL `kiosk_label`   → fallback to `name` on kiosk projection
 *
 * These columns are **read by `MenuProjectionService::forChannel()`**; existing
 * controllers (Admin\ItemController, Admin\ItemCategoryController, frontend
 * menu endpoints) continue to work unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            if (!Schema::hasColumn('items', 'channels')) {
                $table->json('channels')->nullable()->after('status');
            }
            if (!Schema::hasColumn('items', 'allergen_flags')) {
                $table->json('allergen_flags')->nullable()->after('channels');
            }
            if (!Schema::hasColumn('items', 'kiosk_emoji')) {
                $table->string('kiosk_emoji', 8)->nullable()->after('allergen_flags');
            }
        });

        Schema::table('item_categories', function (Blueprint $table): void {
            if (!Schema::hasColumn('item_categories', 'channels')) {
                $table->json('channels')->nullable()->after('status');
            }
            if (!Schema::hasColumn('item_categories', 'kiosk_sort')) {
                $table->unsignedInteger('kiosk_sort')->nullable()->after('sort');
            }
            if (!Schema::hasColumn('item_categories', 'pos_sort')) {
                $table->unsignedInteger('pos_sort')->nullable()->after('kiosk_sort');
            }
            if (!Schema::hasColumn('item_categories', 'kiosk_label')) {
                $table->string('kiosk_label', 255)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            foreach (['kiosk_emoji', 'allergen_flags', 'channels'] as $col) {
                if (Schema::hasColumn('items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('item_categories', function (Blueprint $table): void {
            foreach (['kiosk_label', 'pos_sort', 'kiosk_sort', 'channels'] as $col) {
                if (Schema::hasColumn('item_categories', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
