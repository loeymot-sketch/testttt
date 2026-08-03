<?php

use App\Enums\Ask;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [REGISTRE finding P2-v 2026-07-18] Débloque le login web des téléphones enregistrés en
 * fidélité AVANT le fix de LoyaltyController::register().
 *
 * Ces comptes ont été créés fidélité-seuls avec is_guest au défaut colonne Ask::NO(10) →
 * numéro verrouillé (guest-signup/signup/Signup(Phone)Request les refusent). On les repasse
 * en Ask::YES(5) — statut « invité en attente d'un vrai login » — pour qu'ils redeviennent
 * revendicables par les portillons existants (déjà audités), SANS toucher un vrai compte.
 *
 * Signature airtight du stub fidélité (ne peut PAS matcher un compte staff/web/machine) :
 *   - username LIKE 'kiosk\_%'  → SEUL LoyaltyController::register() génère `uniqid('kiosk_')`
 *                                 (underscore) ; les users KioskMachine utilisent 'kiosk-...'
 *                                 (tiret) donc ne matchent pas ce LIKE échappé ;
 *   - is_guest = Ask::NO        → cible exactement le défaut fuité ;
 *   - AUCUN rôle (model_has_roles) → un vrai compte staff/web a toujours ≥1 rôle ;
 *   - AUCUNE ligne kiosk_machines → ceinture+bretelles (redeem() traite la borne en privilégié).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $query = DB::table('users')
            ->where('is_guest', Ask::NO)
            ->where('username', 'like', 'kiosk\_%')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->whereColumn('model_has_roles.model_id', 'users.id');
            });

        if (Schema::hasTable('kiosk_machines')) {
            $query->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('kiosk_machines')
                    ->whereColumn('kiosk_machines.user_id', 'users.id');
            });
        }

        // branch_id NULL → 0 pour ces mêmes stubs : LoyaltyController::register n'a jamais posé
        // branch_id, laissant NULL en base. Un NULL casse la finalisation du login web par
        // guest-signup (DefaultAccessService écrit default_id = branch_id = NULL → NOT NULL).
        // On aligne sur le pattern client (branch_id=0) — même clause CASE que le is_guest.
        $query->update([
            'is_guest'   => Ask::YES,
            'branch_id'  => DB::raw('COALESCE(branch_id, 0)'),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Non réversible en toute sûreté : after up() ces comptes peuvent avoir été
        // légitimement revendiqués (upgrade en compte web is_guest=NO). Un down naïf
        // rétrograderait des comptes pleins → volontairement no-op.
    }
};
