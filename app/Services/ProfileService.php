<?php

namespace App\Services;

use Exception;
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