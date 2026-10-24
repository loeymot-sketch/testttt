<?php

namespace App\Console\Commands;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Compte de démonstration pour l'examinateur App Store / Google Play (décision propriétaire
 * 2026-10-15). Crée (ou met à jour) UN compte invité client, avec un téléphone déjà renseigné
 * pour que l'écran « numéro obligatoire » ne bloque pas l'examinateur. Le code fixe lui-même
 * vit dans le `.env` (APP_REVIEW_EMAIL / APP_REVIEW_OTP / APP_REVIEW_UNTIL), jamais ici.
 *
 *   php artisan foodking:review-account --email=revue@lecayenne.fr          # crée / met à jour
 *   php artisan foodking:review-account --email=revue@lecayenne.fr --remove  # supprime (après approbation)
 */
class ReviewAccountCommand extends Command
{
    protected $signature = 'foodking:review-account
                            {--email= : E-mail du compte de revue (celui de APP_REVIEW_EMAIL)}
                            {--phone=0600000042 : Téléphone français du compte}
                            {--remove : Supprimer le compte}';

    protected $description = 'Crée ou supprime le compte invité de démonstration pour la revue des stores';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->option('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('--email invalide ou manquant.');

            return 1;
        }

        $user = User::withoutGlobalScopes()->withTrashed()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($this->option('remove')) {
            if (! $user) {
                $this->info('Aucun compte à supprimer.');

                return 0;
            }
            if ((int) $user->is_guest !== Ask::YES) {
                $this->error('Refus : ce compte n\'est pas un compte invité (staff ?).');

                return 1;
            }
            $user->forceDelete();
            $this->info("Compte de revue {$email} supprimé. Retirez aussi APP_REVIEW_* du .env.");

            return 0;
        }

        if ($user && (int) $user->is_guest !== Ask::YES) {
            $this->error('Refus : cet e-mail appartient à un compte non invité (staff ?).');

            return 1;
        }

        $phone = (string) $this->option('phone');
        $clash = User::withoutGlobalScopes()->withTrashed()->where('phone', $phone)
            ->when($user, fn ($q) => $q->where('id', '!=', $user->id))->exists();
        if ($clash) {
            $this->error("Le téléphone {$phone} est déjà pris par un autre compte : passez --phone=…");

            return 1;
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
        } else {
            if ($user->trashed()) {
                $user->restore();
            }
            $user->forceFill(['phone' => $phone, 'status' => Status::ACTIVE])->save();
        }

        $this->info("Compte de revue prêt : {$email} (téléphone {$phone}).");
        $this->line('Reste à poser dans le .env du serveur : APP_REVIEW_EMAIL, APP_REVIEW_OTP (4 chiffres), APP_REVIEW_UNTIL (AAAA-MM-JJ), puis php artisan config:clear.');

        return 0;
    }
}
