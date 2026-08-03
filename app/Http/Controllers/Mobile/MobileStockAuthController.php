<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureMobileStockPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [GOAL MEGA W-MOBILE 2026-07-22] Déverrouillage du Stock mobile par code PIN.
 * Throttle 5/min IP + 15/min global sur la route (bruteforce) + comparaison
 * temps-constant. Miroir de {@see \App\Http\Controllers\DailyBook\DailyBookAuthController}.
 *
 * Divergence DURE vs Carnet : fail-closed. Si le PIN n'est pas configuré
 * (config('mobile_stock.pin') vide), AUCUN déverrouillage n'est possible —
 * hash_equals('', '') vaut true, donc le garde explicite ci-dessous est requis.
 */
class MobileStockAuthController extends Controller
{
    public function unlock(Request $request): JsonResponse
    {
        $configuredPin = (string) config('mobile_stock.pin', '');

        // Fail-closed : PIN non configuré = accès entièrement refusé (jamais de
        // session établie). Vérifié AVANT toute comparaison.
        if ($configuredPin === '') {
            return response()->json([
                'message' => 'Accès mobile non configuré.',
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
        $request->session()->put(EnsureMobileStockPin::SESSION_KEY, time());

        // regenerate() invalide AUSSI le token CSRF embarqué dans la page — on
        // renvoie le nouveau pour que le client le rafraîchisse (sinon 419 sur le
        // POST suivant).
        return response()->json([
            'message' => 'Stock déverrouillé.',
            'unlocked' => true,
            'csrf' => csrf_token(),
        ]);
    }

    public function lock(Request $request): JsonResponse
    {
        $request->session()->forget(EnsureMobileStockPin::SESSION_KEY);

        return response()->json(['message' => 'Stock verrouillé.', 'unlocked' => false]);
    }

    public function status(Request $request): JsonResponse
    {
        $configuredPin = (string) config('mobile_stock.pin', '');
        if ($configuredPin === '') {
            // Fail-closed : jamais déverrouillé si le PIN n'est pas configuré.
            return response()->json(['unlocked' => false, 'configured' => false]);
        }

        $unlockedAt = (int) $request->session()->get(EnsureMobileStockPin::SESSION_KEY, 0);
        $maxAge = ((int) config('mobile_stock.session_minutes', 720)) * 60;

        return response()->json([
            'unlocked' => $unlockedAt > 0 && (time() - $unlockedAt) <= $maxAge,
            'configured' => true,
        ]);
    }
}
