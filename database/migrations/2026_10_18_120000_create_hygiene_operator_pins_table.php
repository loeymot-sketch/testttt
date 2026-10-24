<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P1 « Qui ? sans code »] Un code personnel (4 à 6 chiffres) protège le nom d'un employé :
 * sur la tablette partagée, personne ne signe un relevé au nom d'un collègue sans connaître son code. Facultatif par personne (adoption
 * progressive) : un employé SANS code reste sélectionnable librement, comme avant. Le code n'est jamais stocké en clair (hachage).
 * Additif : une table neuve, aucune donnée existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hygiene_operator_pins')) {
            return;
        }
        Schema::create('hygiene_operator_pins', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('user_id')->unique();
            $t->string('pin_hash');
            $t->unsignedTinyInteger('failed_attempts')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->unsignedBigInteger('set_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hygiene_operator_pins');
    }
};
