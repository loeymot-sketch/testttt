<?php

namespace App\Services;

use Exception;
use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\ProfileRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\ChangeImageRequest;
use App\Http\Requests\ChangePasswordRequest;

class ProfileService
{

    /**
     * @param ProfileRequest $request
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     * @throws Exception
     */
    public function update(ProfileRequest $request)
    {
        // [W7-D D-003 2026-11-04] Un CLIENT ne change ni son e-mail ni son téléphone ici : `unique` empêchait de voler
        // un compte existant, mais rien n'empêchait de REVENDIQUER un contact pas encore inscrit — l'inscription
        // ultérieure de la vraie personne (par e-mail ou par téléphone, `GuestSignupController` restaure le compte
        // connu) tombait dans le compte de l'attaquant, qui gardait ses jetons. Le site et l'application affichent
        // déjà « Pour modifier ton nom, ton e-mail ou ton téléphone, demande en caisse ». Le personnel garde la route.
        // Hors du `try` : le message doit arriver tel quel au client (pas réécrit par QueryExceptionLibrary).
        $courant = auth()->user();
        if ($courant && $this->estUnClient($courant) && $this->contactChange($courant, $request)) {
            throw new Exception(
                'Pour modifier ton e-mail ou ton téléphone, demande en caisse : nous vérifions ton identité avant de changer un moyen de contact.',
                422
            );
        }

        try {
            $user               = User::find(auth()->user()->id);
            if (!blank($user)) {
                $user->name         = $request->first_name . ' ' . $request->last_name;
                $user->phone        = $request->get('phone');
                $user->email        = $request->get('email');
                $user->country_code = $request->get('country_code');
                $user->save();
            }

            return $user;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /** [W7-D D-003] Compte client = invité (`is_guest`) ou rôle Client ; le personnel n'est jamais concerné. */
    private function estUnClient($user): bool
    {
        if ((int) $user->is_guest === Ask::YES) {
            return true;
        }

        return $user->roles->contains('id', EnumRole::CUSTOMER);
    }

    /** [W7-D D-003] Comparaison tolérante (casse de l'e-mail, mise en forme du numéro) : seul un VRAI changement est refusé. */
    private function contactChange($user, ProfileRequest $request): bool
    {
        $emailDemande = mb_strtolower(trim((string) $request->get('email')));
        $emailActuel  = mb_strtolower(trim((string) $user->email));
        $telDemande   = preg_replace('/\D+/', '', (string) $request->get('phone'));
        $telActuel    = preg_replace('/\D+/', '', (string) $user->phone);

        return $emailDemande !== $emailActuel || $telDemande !== $telActuel;
    }

    /**
     * @param ChangePasswordRequest $request
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     * @throws Exception
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        try {
            $user           = User::find(auth()->user()->id);
            $user->password = bcrypt($request->get('password'));
            $user->save();

            // [MULTI-DEVICE 2026-08-07 — régression fermée]
            // Changer son mot de passe est le geste qu'on fait quand on pense
            // s'être fait voler son accès. Il DOIT donc couper toutes les
            // sessions existantes.
            //
            // Ce service ne l'a jamais fait explicitement : il en bénéficiait
            // par ricochet, parce que `LoginController` supprimait TOUS les
            // jetons du compte à la reconnexion suivante. En scopant cette
            // révocation à l'appareil (pour permettre le multi-terminaux), j'ai
            // supprimé ce garde-fou implicite sans le remplacer — un jeton
            // exfiltré survivait alors au changement de mot de passe, et pouvait
            // même se renouveler indéfiniment via /api/refresh-token.
            //
            // On révoque ici TOUS les jetons, y compris celui de la requête
            // courante : c'est le comportement attendu et déjà appliqué par la
            // réinitialisation par e-mail (ForgotPasswordController), qui réémet
            // ensuite un jeton neuf.
            $user->tokens()->delete();

            return $user;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function changeImage(ChangeImageRequest $request)
    {
        try {
            $user = User::find(auth()->user()->id);
            if ($request->image) {
                $user->clearMediaCollection('profile');
                $user->addMediaFromRequest('image')->toMediaCollection('profile');
            }
            $user->save();
            return $user;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}