<?php

namespace App\Services\Auth;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Compte invité de démonstration pour l'examinateur App Store / Google Play (décision propriétaire 2026-10-15).
 *
 * UNE seule règle de création, partagée par la commande `foodking:review-account` et par la connexion e-mail
 * (`GuestSignupController::emailLogin`).
 *
 * [AUDIT FINAL 2026-11-05 · D-P1-1] Pourquoi la connexion en a besoin : l'examinateur Apple teste presque toujours
 * la SUPPRESSION DE COMPTE (règle 5.1.1(v)) avec le compte de démonstration. La suppression efface l'e-mail du
 * compte (`DeactivateController`) — à raison : un compte supprimé ne doit jamais renaître. Mais le code fixe de revue
 * n'était accordé qu'à un compte EXISTANT : après ce test, un second examinateur (ou la soumission suivante) ne pouvait
 * plus se connecter, et l'application était rejetée pour « identifiants de démonstration invalides » (2.1). Tant que
 * la porte de revue est ouverte (e-mail exact + code + date de fin, voir GuestSignupController::porteDeRevue), la
 * connexion recrée donc un compte de démonstration NEUF — jamais l'ancien, qui reste anonymisé.
 */
final class ReviewAccountService
{
    public const TELEPHONE_PAR_DEFAUT = '0600000042';

    /**
     * Crée (ou réactive) le compte invité de revue. Renvoie null — sans rien écrire — si l'e-mail appartient à un compte
     * non invité (staff) ou si le téléphone est déjà pris par un autre compte.
     */
    public function assurer(string $email, string $phone = self::TELEPHONE_PAR_DEFAUT): ?User
    {
        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $user = $this->compteParEmail($email);
        if ($user && (int) $user->is_guest !== Ask::YES) {
            return null; // jamais un compte staff
        }

        $clash = User::withoutGlobalScope(BranchScope::class)->withTrashed()->where('phone', $phone)
            ->when($user, fn ($q) => $q->where('id', '!=', $user->id))->exists();
        if ($clash) {
            return null;
        }

        if (! $user) {
            $user = User::create([
                'name'         => 'Revue Store',
                'username'     => 'revue-'.Str::lower(Str::random(8)),
                'email'        => $email,
                'phone'        => $phone,
                'country_code' => '+33',
                'branch_id'    => 0,
                'is_guest'     => Ask::YES,
                'status'       => Status::ACTIVE,
                'password'     => Hash::make(Str::random(40)),
            ]);
            $user->assignRole(EnumRole::CUSTOMER);

            return $user;
        }

        if ($user->trashed()) {
            $user->restore();
        }
        $user->forceFill(['phone' => $phone, 'status' => Status::ACTIVE])->save();

        return $user;
    }

    public function compteParEmail(string $email): ?User
    {
        return User::withoutGlobalScope(BranchScope::class)->withTrashed()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();
    }
}
