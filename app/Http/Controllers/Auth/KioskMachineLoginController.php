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

        $kioskMachine = KioskMachine::where('username', $username)->first();

        if (!$kioskMachine) {
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

        if (! Hash::check((string) $request->post('password'), $kioskMachine->password)) {
            return response()->json([
                'errors' => ['validation' => trans('all.message.credentials_invalid')],
            ], 400);
        }

        DB::transaction(function () use ($kioskMachine) {
            $lockedKiosk = KioskMachine::lockForUpdate()->find($kioskMachine->id);
            $user         = User::find($lockedKiosk->user_id);

            // Revoke all existing kiosk tokens for this user to allow clean re-login
            $user->tokens()->where('name', 'kiosk-token')->delete();

            $this->token = $user->createToken(
                'kiosk-token',
                ['kiosk:order'],
                now()->addMinutes((int) config('sanctum.expiration', 480))
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
            // Identifier les machines potentiellement rattachées à cet user pour reset leur état de login
            $kiosks = KioskMachine::where('user_id', $user->id)->get();
            foreach ($kiosks as $k) {
                $k->update(['is_login' => Ask::NO]);
            }
            $current = $user->currentAccessToken();
            if ($current instanceof PersonalAccessToken) {
                $current->delete();
            }
        }

        return new JsonResponse([
            'message' => trans('all.message.logout_success')
        ], 200);
    }
}