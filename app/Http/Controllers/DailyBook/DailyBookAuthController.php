<?php

namespace App\Http\Controllers\DailyBook;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureDailyBookPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [GOAL RUPTURE-CARNET 2026-07-15 / W4] Déverrouillage du Carnet par code PIN.
 * Throttle 5/min sur la route (bruteforce) + comparaison temps-constant.
 */
class DailyBookAuthController extends Controller
{
    public function unlock(Request $request): JsonResponse
    {
        $configuredPin = (string) config('daily_book.pin', '');

        // [S2 2026-07-29] Fail-closed : PIN non configuré = accès entièrement
        // refusé (jamais de session). Miroir de MobileStockAuthController —
        // supprime le défaut commité 2468 comme seul rempart hors production.
        if ($configuredPin === '') {
            return response()->json([
                'message' => 'Carnet non configuré.',
                'unlocked' => false,
            ], 403);
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'max:32'],
        ]);

        if (!hash_equals($configuredPin, (string) $validated['pin'])) {
            return response()->json(['message' => 'Code PIN incorrect.'], 401);
        }

        // Anti fixation de session au moment du déverrouillage.
        $request->session()->regenerate();
        $request->session()->put(EnsureDailyBookPin::SESSION_KEY, time());

        // regenerate() invalide AUSSI le token CSRF embarqué dans la page —
        // on renvoie le nouveau pour que le client le rafraîchisse (sinon 419
        // sur le POST suivant, constaté e2e navigateur 2026-07-15).
        return response()->json([
            'message' => 'Carnet déverrouillé.',
            'unlocked' => true,
            'csrf' => csrf_token(),
        ]);
    }

    public function lock(Request $request): JsonResponse
    {
        $request->session()->forget(EnsureDailyBookPin::SESSION_KEY);

        return response()->json(['message' => 'Carnet verrouillé.', 'unlocked' => false]);
    }

    public function status(Request $request): JsonResponse
    {
        $unlockedAt = (int) $request->session()->get(EnsureDailyBookPin::SESSION_KEY, 0);
        $maxAge = ((int) config('daily_book.session_minutes', 240)) * 60;

        return response()->json([
            'unlocked' => $unlockedAt > 0 && (time() - $unlockedAt) <= $maxAge,
        ]);
    }
}
