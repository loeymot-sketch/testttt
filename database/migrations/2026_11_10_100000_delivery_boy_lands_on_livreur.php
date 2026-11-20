<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [GOAL LIVRAISON-TICKETS 2026-11-10 · T-1.4.2] Un livreur qui se connecte arrive sur SON écran (`/livreur`),
 * pas sur la liste admin des livreurs (`delivery-boys`), qu'il n'a pas le droit d'ouvrir (le rôle n'a aucune
 * permission Spatie) : il tombait sur la page « exception ».
 *
 * Donnée, pas schéma : `roles.landing_url`. Même valeur que `LeCayenneRoleLandingUrlSeeder` ; la migration
 * l'applique aux bases déjà installées (le déploiement ne rejoue pas ce seeder). Ne touche que la valeur
 * historique, pour ne jamais écraser un choix fait depuis l'écran des rôles.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('name', 'Delivery Boy')
            ->where(fn ($q) => $q->whereNull('landing_url')->orWhereIn('landing_url', ['', 'delivery-boys']))
            ->update(['landing_url' => 'livreur', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'Delivery Boy')->where('landing_url', 'livreur')
            ->update(['landing_url' => 'delivery-boys', 'updated_at' => now()]);
    }
};
