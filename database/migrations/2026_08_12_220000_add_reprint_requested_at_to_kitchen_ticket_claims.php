<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [RÉIMPRESSION 2026-08-12 · owner « pour réimprimer »] Une DEMANDE HUMAINE de réimpression.
 *
 * POURQUOI UNE COLONNE PLUTÔT QUE SUPPRIMER LA RÉCLAMATION
 * --------------------------------------------------------
 * Effacer la réclamation remettrait bien la commande dans la file… mais la file automatique ne
 * regarde que les commandes de MOINS DE 30 MINUTES encore présentes en cuisine (fenêtre voulue :
 * elle empêche un pont qui redémarre de recracher tout le service). Or on réimprime justement
 * quand le papier s'est perdu, bourré ou taché — souvent bien après. La suppression seule aurait
 * donc promis un ticket sans jamais le sortir : le pire des deux mondes.
 *
 * Cette colonne porte une demande EXPLICITE d'un humain. Le pont la sert par un chemin distinct,
 * qui ignore la fenêtre et le statut — parce qu'un humain a regardé et décidé, et que cette
 * décision vaut mieux qu'une heuristique de fraîcheur.
 *
 * Le chemin automatique n'est PAS modifié : il vient tout juste de fonctionner en production
 * (10 tickets réels servis), et on n'y touche pas pour ajouter un confort.
 *
 * Domaine NEUF, ADDITIF, HORS NF525 : aucune séquence fiscale, aucune chaîne d'audit approchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kitchen_ticket_claims')) {
            return;
        }

        Schema::table('kitchen_ticket_claims', function (Blueprint $table) {
            if (! Schema::hasColumn('kitchen_ticket_claims', 'reprint_requested_at')) {
                // NULL = rien de demandé. Une date = un humain réclame ce papier, tout de suite.
                $table->timestamp('reprint_requested_at')->nullable()->after('error');
                $table->index('reprint_requested_at', 'kitchen_ticket_claims_reprint_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kitchen_ticket_claims')) {
            return;
        }

        Schema::table('kitchen_ticket_claims', function (Blueprint $table) {
            if (Schema::hasColumn('kitchen_ticket_claims', 'reprint_requested_at')) {
                $table->dropIndex('kitchen_ticket_claims_reprint_idx');
                $table->dropColumn('reprint_requested_at');
            }
        });
    }
};
