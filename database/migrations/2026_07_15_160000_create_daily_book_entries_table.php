<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [GOAL RUPTURE-CARNET 2026-07-15 / W4] Carnet — registre interne jour-au-jour :
 * dépenses sorties, acomptes donnés aux travailleurs, notes libres. Photo de
 * facture via Spatie MediaLibrary (collection `invoice-photo` sur le modèle).
 * Table isolée : AUCUN trigger, AUCUN listener fiscal, hors chaîne NF525.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_book_entries', function (Blueprint $table) {
            $table->id();
            // expense | advance | note
            $table->string('type', 16)->index();
            $table->string('label', 190);
            // Rempli pour type=advance (à qui l'acompte a été donné).
            $table->string('worker_name', 120)->nullable()->index();
            // NULL pour type=note.
            $table->decimal('amount', 10, 2)->nullable();
            $table->date('entry_date')->index();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('branch_id')->default(1)->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_book_entries');
    }
};
