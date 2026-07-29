<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * [GOAL RUPTURE-CARNET 2026-07-15 / W4] Gate session du Carnet : la session doit
 * avoir été déverrouillée par le code PIN (POST /carnet/api/pin) et ne pas avoir
 * expiré (config daily_book.session_minutes, ravivée à chaque requête).
 */
class EnsureDailyBookPin
{
    public const SESSION_KEY = 'daily_book_unlocked_at';

    public function handle(Request $request, Closure $next)
    {
        // [S2 auto-RED cycle 2 2026-07-29] Le fail-closed posé sur le
        // déverrouillage ne fermait que la PORTE D'ENTRÉE : les sessions déjà
        // ouvertes survivaient indéfiniment (session glissante ravivée à chaque
        // requête). Comme l'ancien PIN était le défaut COMMITÉ '2468', public
        // dans le dépôt, quiconque avait déverrouillé gardait le registre
        // interne (dépenses, acomptes, factures) après le correctif. On coupe
        // donc AUSSI les sessions en cours quand aucun PIN n'est configuré.
        if ((string) config('daily_book.pin', '') === '') {
            $request->session()->forget(self::SESSION_KEY);

            return response()->json([
                'message' => 'Carnet non configuré.',
                'locked' => true,
            ], 403);
        }

        $unlockedAt = (int) $request->session()->get(self::SESSION_KEY, 0);
        $maxAge = ((int) config('daily_book.session_minutes', 240)) * 60;

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
