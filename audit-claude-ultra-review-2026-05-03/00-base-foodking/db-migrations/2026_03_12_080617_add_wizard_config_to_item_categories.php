<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    /**
     * Run the migrations.
     * [PLAN_11 ARCH-01] Ajouter config wizard aux catégories
     */
    public function up(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            $table->string('wizard_template', 20)->default('simple')
                  ->comment('tacos|sandwich|burger|assiette|salade|omelette|snacking|simple');
            $table->boolean('has_menu')->default(false)
                  ->comment('Cette catégorie propose un menu frites+boisson');
            $table->boolean('default_menu_kiosk')->default(false)
                  ->comment('En Kiosk, présélectionner menu par défaut');
            $table->boolean('sauce_included_menu')->default(false)
                  ->comment('La sauce frites est-elle incluse dans le menu ?');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            $table->dropColumn(['wizard_template', 'has_menu', 'default_menu_kiosk', 'sauce_included_menu']);
        });
    }
};
