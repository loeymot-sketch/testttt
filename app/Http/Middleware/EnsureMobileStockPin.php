<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * [GOAL MEGA W-MOBILE 2026-07-22] Gate session du Stock mobile (/m) : la session
 * doit avoir été déverrouillée par le code PIN (POST /m/api/pin) et ne pas avoir
 * expiré (config mobile_stock.session_minutes, ravivée à chaque requête).
 *
 * Miroir de {@see EnsureDailyBookPin}.
 */
class EnsureMobileStockPin
{
    public const SESSION_KEY = 'mobile_stock_unlocked_at';

    public function handle(Request $request, Closure $next)
    {
        // [S2 auto-RED cycle 2 2026-07-29] Miroir du garde posé sur le Carnet :
        // le fail-closed du contrôleur ne bloque que les NOUVEAUX déverrouillages,
        // une session déjà ouverte survivait indéfiniment (session glissante).
        // Si le PIN est retiré de la configuration, l'accès doit se refermer
        // immédiatement, y compris pour les sessions en cours.
        if ((string) config('mobile_stock.pin', '') === '') {
            $request->session()->forget(self::SESSION_KEY);

            return response()->json([
                'message' => 'Accès mobile non configuré.',
                'locked' => true,
            ], 403);
        }

        $unlockedAt = (int) $request->session()->get(self::SESSION_KEY, 0);
        $maxAge = ((int) config('mobile_stock.session_minutes', 720)) * 60;

        if ($unlockedAt <= 0 || (time() - $unlockedAt) > $maxAge) {
            $request->session()->forget(self::SESSION_KEY);

            return response()->json([
                'message' => 'Code PIN requis.',
                'locked' => true,
            ], 401);
        }

        // Session glissante : chaque action ravive le déverrouillage.
        $request->session()->put(self::SESSION_KEY, time());

        return $next($request);
    }
}
