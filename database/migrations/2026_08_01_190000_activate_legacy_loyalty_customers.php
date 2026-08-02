<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [AUDIT FIDÉLITÉ 2026-08-01 · P1-2] Débloque les comptes fidélité legacy.
 *
 * `LoyaltyController::check` créait les clients inscrits AU COMPTOIR avec
 * `status=1` (convention interne de ce contrôleur, cf. son `isCustomerActive()`
 * qui accepte 1 OU 5). Mais `EnsureUserStatusActive` n'accepte QUE ACTIVE(5) et
 * révoque le token présenté : ces clients pouvaient se connecter sur le site
 * (verify → 200 + token) puis recevaient 401 sur TOUT appel authentifié — solde
 * invisible, QR fidélité impossible, commande web impossible, boucle de login
 * sans fin. La création est corrigée à la source (ACTIVE) ; cette migration
 * rattrape les comptes déjà en base.
 *
 * PÉRIMÈTRE STRICT — uniquement des CLIENTS : porteur d'un `loyalty_code`, sans
 * aucun rôle attribué (les clients n'en ont pas via ce chemin) et `branch_id=0`
 * (client agnostique de branche). Aucun compte staff/admin ne peut être activé
 * par cette migration : un compte désactivé volontairement côté personnel a un
 * rôle et/ou une branche, donc il est exclu.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('users')
            ->where('status', 1)
            ->whereNotNull('loyalty_code')
            ->where('branch_id', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', \App\Models\User::class);
            })
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('users')->whereIn('id', $ids)->update([
            'status'     => \App\Enums\Status::ACTIVE,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Pas de rollback : re-désactiver des comptes clients les renverrait dans
        // la boucle de login 401. L'état ACTIVE est l'état correct et attendu.
    }
};
