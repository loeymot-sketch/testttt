<?php

namespace App\Http\Controllers\Auth;


use App\Enums\Ask;
use App\Models\User;
use App\Models\KioskMachine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\KioskMachineResource;

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

        $kioskMachine = KioskMachine::where('username', $request->post('username'))->first();

        if (!$kioskMachine || !Hash::check($request->post('password'), $kioskMachine->password)) {
            return response()->json([
                'errors' => ['validation' => trans('all.message.credentials_invalid')]
            ], 400);
        }

        $loginResult = null;
        DB::transaction(function () use ($kioskMachine, &$loginResult) {
            // Recharger avec lock pour éviter la race condition
            $lockedKiosk = KioskMachine::lockForUpdate()->find($kioskMachine->id);
            if ($lockedKiosk->is_login === Ask::YES) {
                $loginResult = 'already_logged_in';
                return;
            }
            $user = User::find($lockedKiosk->user_id);
            $this->token = $user->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;
            $lockedKiosk->update(['is_login' => Ask::YES]);
            $loginResult = 'success';
        });

        if ($loginResult === 'already_logged_in') {
            return response()->json(['errors' => ['validation' => trans('all.message.already_logged_in')]], 400);
        }

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
            if ($user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
            }
        }

        return new JsonResponse([
            'message' => trans('all.message.logout_success')
        ], 200);
    }
}