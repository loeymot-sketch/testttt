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
use Illuminate\Support\Facades\Log;
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

            // [SEC-1 / P1-11] Kiosk-specific TTL (default 30 days). Distinct from
            // the global sanctum.expiration (8h) used by admin/POS/staff sessions.
            // See config/sanctum.php for rationale.
            $this->token = $user->createToken(
                'kiosk-token',
                ['kiosk:order'],
                now()->addMinutes((int) config('sanctum.kiosk_expiration', 60 * 24 * 30))
            )->plainTextToken;
            $lockedKiosk->update(['is_login' => Ask::YES]);
        });

        return response()->json([
            'message' => trans('all.message.login_success'),
            'token' => $this->token,
            'kiosk' => new KioskMachineResource($kioskMachine),
        ], 201);
    }

    /**
     * [SEC-1 / P1-11] Refresh a kiosk Sanctum token before it expires.
     *
     * Caller must hold a still-valid kiosk token (auth:sanctum + abilities:kiosk:order).
     * The current token is revoked atomically and a new one is issued with the same
     * single ability `kiosk:order` and a fresh kiosk_expiration TTL.
     *
     * Frontend contract (V1.x): axios interceptor catches 401 from a protected
     * route, calls this endpoint with the still-valid (or near-expired) token,
     * swaps the token in localStorage, and replays the original request. If the
     * current token is already past expires_at, Sanctum's auth:sanctum middleware
     * will return 401 BEFORE this controller runs — the kiosk must then
     * re-authenticate via /api/auth/kiosk-login.
     */
    public function refresh(Request $request): JsonResponse
    {
        // Match the convention used by login()/logout() in this controller:
        // $request->user() resolves through the default api guard which
        // Sanctum's middleware has already populated with the access token
        // (so currentAccessToken() returns the PersonalAccessToken row).
        $user = $request->user();
        if (! $user) {
            // Defensive: auth:sanctum middleware already guarantees this, but
            // we keep an explicit guard so the controller stays safe if the
            // route is ever wired without that middleware.
            return response()->json([
                'errors' => ['validation' => trans('all.message.credentials_invalid')],
            ], 401);
        }

        $kioskMachine = KioskMachine::where('user_id', $user->id)->first();
        if (! $kioskMachine) {
            return response()->json([
                'errors' => ['validation' => trans('all.message.kiosk_user_inactive')],
            ], 403);
        }

        // Reject refresh if the kiosk machine has been deactivated since login.
        if ((int) $kioskMachine->status !== (int) Status::ACTIVE) {
            return response()->json([
                'errors' => ['validation' => trans('all.message.kiosk_machine_inactive')],
            ], 403);
        }

        $newPlainToken = DB::transaction(function () use ($user, $kioskMachine) {
            // Revoke ONLY the current token, not all kiosk tokens for this user.
            // (Logout/login already handle "revoke all" semantics; refresh keeps
            // the re-login flow's invariant of "one active token at a time" by
            // deleting exactly the token that was just used to authenticate.)
            $current = $user->currentAccessToken();
            if ($current instanceof PersonalAccessToken) {
                $current->delete();
            }

            return $user->createToken(
                'kiosk-token',
                ['kiosk:order'],
                now()->addMinutes((int) config('sanctum.kiosk_expiration', 60 * 24 * 30))
            )->plainTextToken;
        });

        Log::info('[KioskAuth] Token refreshed', [
            'user_id'          => $user->id,
            'kiosk_machine_id' => $kioskMachine->id,
            'branch_id'        => $kioskMachine->branch_id,
        ]);

        return response()->json([
            'message'    => trans('all.message.login_success'),
            'token'      => $newPlainToken,
            'expires_at' => now()->addMinutes((int) config('sanctum.kiosk_expiration', 60 * 24 * 30))->toIso8601String(),
        ], 200);
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