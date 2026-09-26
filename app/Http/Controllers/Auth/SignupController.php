<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\SignupPhoneRequest;
use App\Http\Requests\SignupRequest;
use App\Http\Requests\VerifyPhoneRequest;
use App\Libraries\AppLibrary;
use App\Models\User;
use App\Services\OtpManagerService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Smartisan\Settings\Facades\Settings;

class SignupController extends Controller
{
    private OtpManagerService $otpManagerService;

    public function __construct(OtpManagerService $otpManagerService)
    {
        $this->otpManagerService = $otpManagerService;
    }

    public function otp(
        SignupPhoneRequest $request
    ): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->otpManagerService->otp($request);

            return response(['status' => true, 'message' => trans('all.message.check_your_phone_for_code')]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function verify(
        VerifyPhoneRequest $request
    ): \Illuminate\Http\Response|array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->otpManagerService->verify($request);

            return response(['status' => true, 'message' => trans('all.message.otp_verify_success')], 201);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function register(
        SignupRequest $request
    ): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        // [SELF-AUDIT R5 P1 SÉCURITÉ 2026-07-05 — hijack de compte invité] Ancienne logique VULNÉRABLE :
        //   (1) portillon OTP INVERSÉ `if (!$otp->exists()) $flag = true` → autorisait quand AUCUN OTP
        //       n'existait ; l'OTP étant supprimé au succès de verify(), « pas d'OTP » = vérifié OU jamais
        //       demandé → un attaquant ne demandant JAMAIS d'OTP passait le portillon ;
        //   (2) fusion dans un compte INVITÉ existant par simple correspondance de téléphone, SANS aucune
        //       preuve de possession → écrasait email/mot de passe → vol de la fidélité + de l'historique,
        //       et verrouillait la victime.
        // Fix : exiger une PREUVE positive de vérification (marqueur one-time posé par OtpManagerService::
        // verify(), consommé ici) pour écraser un compte existant. Un numéro déjà pris (invité non prouvé
        // ou compte plein) → refus (pas d'écrasement). Création d'un compte NEUF inchangée.
        $phone = (string) $request->post('phone');
        $verificationEnabled = ! env('DEMO')
            && Settings::group('site')->get('site_phone_verification') != Activity::DISABLE;
        // Consomme (one-time) le marqueur de vérification. DEMO = toujours vérifié (fixtures).
        $phoneVerified = (bool) env('DEMO') || Cache::pull('phone_verified:'.$phone) === true;

        // Vérification activée mais téléphone NON prouvé → refus (l'attaquant qui ne vérifie pas est bloqué).
        if ($verificationEnabled && ! $phoneVerified) {
            return response(['status' => false, 'message' => trans('all.message.code_is_invalid')], 422);
        }

        $name = AppLibrary::name($request->post('first_name'), $request->post('last_name'));
        // N'IMPORTE quel compte existant sur ce téléphone (invité OU plein — le plein est déjà bloqué en
        // amont par la règle unique is_guest=NO de SignupRequest).
        $existing = User::where('phone', $phone)->first();

        // [Root cause 2026-09-19, propriétaire : « je peux créer 2 compte avec meme email »]
        // SignupRequest n'exige l'unicité de l'e-mail que parmi les comptes is_guest=NO — une
        // exemption VOULUE pour laisser ce même formulaire mettre à niveau un compte invité déjà
        // porteur de cet e-mail, PAR TÉLÉPHONE (juste au-dessus). Mais rien ne relie l'exemption
        // à une preuve de téléphone : un compte invité créé au comptoir / à la borne / par
        // e-mail-OTP (téléphone A, e-mail X) laissait un client revenir ici avec un AUTRE
        // téléphone (B) et le MÊME e-mail — recherche par téléphone infructueuse, règle unique
        // muette (l'ancien compte est invité), second compte complet créé avec l'e-mail déjà
        // porté par le premier. Deux comptes, deux soldes de points, un seul humain. `users.email`
        // n'a par ailleurs aucune contrainte unique en base (index simple, pas UNIQUE) — rien
        // n'aurait arrêté l'écriture même via un autre chemin. Vérifié ici, sur TOUT compte
        // (invité ou non, y compris supprimé) porteur de cet e-mail mais PAS déjà celui qu'on
        // s'apprête à mettre à niveau.
        $email = $request->post('email');
        if (filled($email)) {
            $emailOwnerId = User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
                ->value('id');
            if ($emailOwnerId !== null && (! $existing || (int) $emailOwnerId !== (int) $existing->id)) {
                return response(['status' => false, 'message' => trans('all.message.code_is_invalid')], 422);
            }
        }

        if ($existing) {
            // On n'écrase un compte que si c'est un INVITÉ ET que le téléphone vient d'être PROUVÉ
            // (claim légitime). Sinon (invité non prouvé, ou vérification désactivée) → refus : jamais de
            // prise de contrôle d'un compte par téléphone seul.
            if ((int) $existing->is_guest === Ask::YES && $phoneVerified) {
                $existing->name = $name;
                $existing->username = Str::slug($name);
                $existing->email = $email;
                $existing->password = Hash::make($request->post('password'));
                $existing->is_guest = Ask::NO;
                $existing->save();

                return response(['status' => true, 'message' => trans('all.message.register_successfully')], 201);
            }

            return response(['status' => false, 'message' => trans('all.message.code_is_invalid')], 422);
        }

        $user = User::create([
            'name' => $name,
            'username' => Str::slug($name),
            'email' => $request->post('email'),
            'phone' => $phone,
            'country_code' => $request->post('country_code'),
            'branch_id' => 0,
            'email_verified_at' => Carbon::now()->getTimestamp(),
            'is_guest' => Ask::NO,
            'password' => Hash::make($request->post('password')),
        ]);
        $user->assignRole(EnumRole::CUSTOMER);

        return response(['status' => true, 'message' => trans('all.message.register_successfully')], 201);
    }
}
