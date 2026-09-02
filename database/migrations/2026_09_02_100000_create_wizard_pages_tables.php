<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [GOAL DASHBOARD-PILOTABLE 2026-09-02] Bibliothèque de pages de wizard réutilisables.
 *
 * Une page = une liste de choix AVEC prix (« Choisis ton pain » : Pain, Galette · « Suppléments » :
 * Cheddar 0,90 €…). Une catégorie compose son parcours en prenant des pages de la bibliothèque telles
 * quelles (liées) ou personnalisées (copie privée, `owner_category_id`). À la publication, les choix sont
 * matérialisés sur chaque produit (`item_variations` / `item_extras` / `item_addons`) — le contrat lu par
 * la caisse, la borne et PricingService (zones gelées) ne change pas d'un octet.
 *
 * Réversible : deux tables nouvelles + une colonne nullable sur `item_wizard_steps`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wizard_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120);
            $table->string('label', 191);
            $table->string('kind', 32)->default('generic');
            $table->string('source_type', 32)->default('item_attribute');
            $table->unsignedBigInteger('item_attribute_id')->nullable()->index();
            $table->string('extra_group_label', 50)->nullable();
            $table->string('addon_role', 32)->nullable();
            $table->unsignedInteger('min_select')->default(0);
            $table->unsignedInteger('max_select')->default(1);
            $table->boolean('allow_repeat')->default(false);
            $table->json('visible_on')->nullable();
            $table->boolean('stockable_choices')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('owner_category_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['key', 'owner_category_id']);
        });

        Schema::create('wizard_page_choices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('wizard_page_id')->index();
            $table->string('name', 191);
            $table->decimal('price', 19, 6)->default(0);
            $table->unsignedBigInteger('addon_item_id')->nullable()->index();
            $table->unsignedInteger('sort')->default(0);
            $table->tinyInteger('status')->default(5);
            $table->json('visible_on')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('item_wizard_steps', function (Blueprint $table): void {
            $table->unsignedBigInteger('wizard_page_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('item_wizard_steps', function (Blueprint $table): void {
            $table->dropColumn('wizard_page_id');
        });
        Schema::dropIfExists('wizard_page_choices');
        Schema::dropIfExists('wizard_pages');
    }
};
