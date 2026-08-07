<?php

namespace App\Http\Controllers\Auth;


use App\Enums\Ask;
use App\Enums\Status;
use App\Models\User;
use App\Models\KioskMachine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\KioskMachineResource;
use Laravel\Sanctum\PersonalAccessToken;

class KioskMachineLoginController extends Controller
{
    public string $token;


    /**
     * @throws \Exception
     */

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6']
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $username = trim((string) $request->post('username'));
        if (str_contains($username, '@')) {
            return response()->json([
                'errors' => ['validation' => trans('all.message.kiosk_username_not_email')],
            ], 422);
        }

        // [iter12 P1 KIOSK 2026-05-09] PRE-AUTH lookup: scope explicitly bypassed.
        //
        // Username is a public credential; resolution must succeed before the
        // user is authenticated, so BranchScope (which runs only when
        // Auth::check()=true) returns no rows here in practice anyway. We mark
        // the bypass with `withoutGlobalScope` to make the intent explicit and
        // survive any future BranchScope refactor that activates pre-auth.
        $kioskMachine = KioskMachine::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('username', $username)
            ->first();

        if (!$kioskMachine) {
            return response()->json([
                'errors' => ['validation' => trans('all.message.credentials_invalid')],
            ], 400);
        }

        // [F3 heal 2026-06-09] Verify the password BEFORE any account-state check.
        // Returning distinct 'kiosk_machine_inactive' / 'kiosk_user_inactive'
        // messages ahead of Hash::check let a caller enumerate valid usernames and
        // probe account state with no credentials. Now those specific messages are
        // only reachable WITH a valid password (legitimate staff keep the helpful
        // copy); every other failure returns the generic 'credentials_invalid'.
        if (! Hash::check((string) $request->post('password'), $kioskMachine->password)) {
            return response()->json([
                'errors' => ['validation' => trans('all.message.credentials_invalid')],
            ], 400);
        }

        // Borne désactivée dans Admin → Bornes (status ≠ actif)
        if ((int) $kioskMachine->status !== (int) Status::ACTIVE) {
            return response()->json([
                'errors' => ['validation' => trans('all.message.kiosk_machine_inactive')],
            ], 400);
        }

        $linkedUser = User::query()->find($kioskMachine->user_id);
        if (! $linkedUser || (int) $linkedUser->status !== (int) Status::ACTIVE) {
            return response()->json([
                'errors' => ['validation' => trans('all.message.kiosk_user_inactive')],
            ], 400);
        }

        DB::transaction(function () use ($kioskMachine, $request) {
            // [iter12 P1 KIOSK 2026-05-09] In-transaction lookup runs while the
            // user has not yet been authenticated for this request, so BranchScope
            // would still skip; we bypass explicitly for intent + parity with the
            // pre-auth lookup above.
            $lockedKiosk = KioskMachine::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->lockForUpdate()
                ->find($kioskMachine->id);
            $user         = User::find($lockedKiosk->user_id);

            // [MULTI-DEVICE 2026-08-07] Jumelle du défaut corrigé dans
            // `LoginController` : la purge visait TOUS les jetons `kiosk-token`
            // du compte lié. Or plusieurs bornes peuvent partager le même
            // compte utilisateur — démarrer la borne 2 tuait donc la session de
            // la borne 1, qui tombait en 401 en plein parcours client.
            //
            // L'identité d'appareil n'est pas prise dans l'en-tête ici mais
            // DÉRIVÉE DE LA MACHINE elle-même : une borne EST un appareil
            // identifié en base, c'est une source plus fiable qu'un en-tête
            // fourni par le client, et ça reste stable même si le navigateur
            // de la borne perd son stockage local.
            $this->token = app(\App\Services\Auth\DeviceTokenService::class)
                ->issueForDevice(
                    $user,
                    'kiosk-token',
                    ['kiosk:order'],
                    $request,
                    (int) config('sanctum.expiration', 480),
                    'kiosk-' . (int) $lockedKiosk->id,
                    // `kiosk_machines` n'a pas de colonne `name` : l'identifiant
                    // lisible côté exploitation est `machine_id`.
                    'Borne ' . ($lockedKiosk->machine_id ?: ('#' . (int) $lockedKiosk->id))
                )->plainTextToken;
            $lockedKiosk->update(['is_login' => Ask::YES]);
        });

        return response()->json([
            'message' => trans('all.message.login_success'),
            'token' => $this->token,
            'kiosk' => new KioskMachineResource($kioskMachine),
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $current = $user->currentAccessToken();

            // [MULTI-DEVICE 2026-08-07] La déconnexion remettait `is_login=NO`
            // sur TOUTES les bornes rattachées au compte. Avec plusieurs bornes
            // sur un même compte, éteindre la borne 1 affichait donc la borne 2
            // comme déconnectée dans l'admin alors qu'elle servait un client.
            // On ne touche que la borne réellement identifiée par le jeton
            // présenté (device_id = 'kiosk-<id>'), avec repli sur l'ancien
            // comportement pour les jetons hérités qui n'ont pas d'identité.
            $kioskId = null;
            if ($current instanceof PersonalAccessToken
                && is_string($current->device_id)
                && str_starts_with($current->device_id, 'kiosk-')) {
                $kioskId = (int) substr($current->device_id, 6);
            }

            $kiosks = KioskMachine::where('user_id', $user->id)
                ->when($kioskId, fn ($q) => $q->where('id', $kioskId))
                ->get();

            foreach ($kiosks as $k) {
                $k->update(['is_login' => Ask::NO]);
            }

            if ($current instanceof PersonalAccessToken) {
                $current->delete();
            }
        }

        return new JsonResponse([
            'message' => trans('all.message.logout_success')
        ], 200);
    }
}