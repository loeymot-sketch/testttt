<?php

namespace App\Http\Controllers\Auth;


use Exception;
use App\Http\Controllers\Controller;
use App\Http\Requests\TokenStoreRequest;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Http\JsonResponse;


class RefreshTokenController extends Controller
{
    public function refreshToken(TokenStoreRequest $request)
    {
        try {
            $sanctumToken = $request->token;
            $token = PersonalAccessToken::findToken($sanctumToken);

            // [iter15-P0-07] Reject invalid/missing tokens explicitly so the catch
            // block returns a proper 401 instead of allowing a malformed refresh
            // (previously a missing $token caused a fatal on $token->tokenable
            // which was masked by the generic 422 catch).
            if (! $token) {
                return response(['status' => false, 'message' => trans('all.message.token_is_invalid')], 401);
            }

            $user = $token->tokenable;
            if (! $user) {
                return response(['status' => false, 'message' => trans('all.message.token_is_invalid')], 401);
            }

            // [iter15-P0-07] PRIVILEGE-ESCALATION FIX
            // Previously: ['*'] was hard-coded — a kiosk token (ability `kiosk:order`)
            // could refresh into an admin-equivalent token with full abilities. Any
            // device that captured a kiosk token + apiKey could escalate.
            // Fix: preserve the original token's abilities verbatim. If the source
            // token had no abilities (defensive: legacy / corrupted record), fall
            // back to [] (least privilege) — never to ['*'].
            // Audit reference: reports/audit/PHASE2_PLAN_TRAINS_REWORKED_2026-04-27.md (P0-07)
            $abilities = $token->abilities ?? [];
            if (! is_array($abilities)) {
                $abilities = [];
            }

            $token->delete();

            $token = $user->createToken(
                'auth_token',
                $abilities,
                now()->addMinutes((int) config('sanctum.expiration', 480))
            )->plainTextToken;

            return new JsonResponse([
                'token'      => $token,
            ], 201);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => trans('all.message.token_is_invalid')], 422);
        }
    }
}
