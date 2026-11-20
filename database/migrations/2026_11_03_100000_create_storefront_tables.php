<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [APP HYBRIDE W2a · 2026-11-03] Vitrine distante (bandeaux, offres flash, couleurs) — voir config/storefront.php.
 *
 *   · storefront_settings  — BROUILLON des réglages, une ligne par branche, + version publiée courante (qui / quand) ;
 *   · storefront_banners   — BROUILLON des bandeaux (suppression douce) ;
 *   · storefront_versions  — instantanés PUBLIÉS, ajout seul : la lecture publique ne lit QUE cette table, et
 *                            « retour en arrière » republie un ancien instantané comme une nouvelle version.
 *
 * Pourquoi une table d'historique plutôt qu'une colonne « instantané » : une colonne ne garde que la DERNIÈRE version,
 * donc un seul retour possible et aucune trace de ce qui a été montré aux clients le 24 décembre. La table coûte une
 * ligne par publication (quelques Ko) et répond à « qu'affichait l'application tel jour ».
 *
 * Purement additive : aucune table existante n'est modifiée. Aucun lien avec les commandes, les prix ou la chaîne NF525.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('storefront_settings')) {
            Schema::create('storefront_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->unique();
                $table->unsignedInteger('version')->default(0);           // dernière version PUBLIÉE (0 = jamais publié)
                $table->boolean('enabled')->default(false);
                $table->json('theme')->nullable();                         // jetons #RRGGBB (liste blanche), null = couleurs embarquées
                $table->string('delivery_on_message', 140)->nullable();
                $table->string('delivery_off_message', 140)->nullable();
                $table->unsignedBigInteger('published_by')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('storefront_banners')) {
            Schema::create('storefront_banners', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('type', 8)->default('info');               // info | flash
                $table->string('title', 80);
                $table->string('body', 240)->nullable();
                $table->string('button_label', 30)->nullable();
                $table->string('button_link', 120)->nullable();           // route interne de l'application (#menu, #loyalty, #menu/<produit>)
                $table->string('coupon_code', 40)->nullable();
                $table->dateTime('starts_at')->nullable();
                $table->dateTime('ends_at')->nullable();
                $table->smallInteger('priority')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('storefront_versions')) {
            Schema::create('storefront_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id');
                $table->unsignedInteger('version');
                $table->string('action', 16)->default('publish');         // publish | rollback
                $table->unsignedInteger('source_version')->nullable();    // pour un retour : la version republiée
                $table->json('snapshot');
                $table->unsignedBigInteger('published_by')->nullable();
                $table->timestamp('published_at');
                $table->timestamps();
                $table->unique(['branch_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_versions');
        Schema::dropIfExists('storefront_banners');
        Schema::dropIfExists('storefront_settings');
    }
};
