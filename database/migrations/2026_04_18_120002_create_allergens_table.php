<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosk Design V1 — Phase 1.1 (2/8)
 *
 * Table de référence pour les 14 allergènes de l'Annexe II du Règlement
 * UE 1169/2011 (Information du consommateur sur les denrées alimentaires,
 * dit « FIC »). Référence statique, pas de soft-delete.
 *
 * Seedée par `AllergensSeeder` — idempotent sur la colonne `code`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('allergens')) {
            Schema::create('allergens', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 20)->unique();            // gluten, crustaceans, …
                $table->string('name_key', 64);                  // allergens.gluten (clé i18n)
                $table->string('icon', 128)->nullable();         // emoji ou chemin SVG
                $table->unsignedInteger('sort')->default(0);     // ordre d'affichage
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('allergens');
    }
};
