<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [KITCHEN-AUTOPRINT 2026-08-07 owner] Marqueur d'impression AUTOMATIQUE du ticket cuisine.
 *
 * L'owner veut que toute commande qui entre sur l'écran de cuisine s'imprime seule, quelle
 * qu'en soit la source — borne, caisse ou site. Or plusieurs chemins peuvent mener au même
 * résultat (création de commande, passage au statut ACCEPTÉ, rejeu d'un job en file). Sans
 * marqueur DURABLE, la même commande sortirait deux ou trois fois du rouleau.
 *
 * Un verrou en cache ne suffit pas : il expire, et il ne survit pas à un redémarrage. La
 * colonne est le seul endroit où « cette commande a déjà été imprimée » reste vrai.
 *
 * Additive et nullable : aucune commande existante n'est modifiée, et le champ n'entre pas
 * dans la chaîne fiscale (c'est un fait d'exploitation, pas un fait comptable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'kitchen_ticket_printed_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('kitchen_ticket_printed_at')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'kitchen_ticket_printed_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('kitchen_ticket_printed_at');
        });
    }
};
