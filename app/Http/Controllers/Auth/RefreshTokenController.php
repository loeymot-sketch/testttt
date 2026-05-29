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

            // [OI-3 2026-05-30] Do NOT revive an EXPIRED token. Sanctum's findToken()
            // returns the row regardless of expires_at; without this guard an expired
            // Bearer (e.g. a device left closed past the 480-min TTL) could be silently
            // refreshed into a fresh full-TTL session. The proactive 2h refresh always
            // runs on a still-valid token, so this only ever blocks a genuinely-expired
            // one — the correct security boundary.
            if ($token->expires_at && $token->expires_at->isPast()) {
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

            // [BS-3 2026-05-30] Preserve the source token's NAME on refresh. channels.php
            // grants kiosk WS channel auth only when the token name === 'kiosk-token' (it
            // pins kiosk identity by name); hardcoding 'auth_token' here would silently
            // break a kiosk token's channel authz if it were ever refreshed. Staff tokens
            // are already named 'auth_token' (LoginController), so this is a no-op for
            // them and makes the refresh name-preserving for every surface. Capture before
            // delete() (the in-memory model still holds the name, but be explicit).
            $tokenName = $token->name ?: 'auth_token';

            $token->delete();

            $token = $user->createToken(
                $tokenName,
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
