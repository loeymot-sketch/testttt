<?php

namespace App\Http\Controllers\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureWheelAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * L'ACCUEIL DE LA ROUE — la seule porte d'entrée, et la seule page qui liste les autres.
 *
 * ── POURQUOI CETTE PAGE EXISTE ───────────────────────────────────────────────────────────────
 * [P0 2026-08-10] Deux défauts se cumulaient et se cachaient l'un l'autre :
 *
 *   1. les quatre écrans de la roue étaient INACCESSIBLES dans un navigateur (voir
 *      {@see EnsureWheelAccess}) ;
 *   2. AUCUN lien ne menait à eux, où que ce soit dans l'application. Il fallait taper l'URL de
 *      mémoire — `/admin/roue-reglages`, `/admin/roue-lot`… Un écran de service qu'on ne peut pas
 *      trouver n'existe pas, même quand il fonctionne.
 *
 * Cette page répond aux deux : elle ouvre l'accès par un code, puis elle NOMME les quatre écrans en
 * disant à quoi chacun sert et quand on s'en sert.
 */
class WheelAccessController extends Controller
{
    public function show(Request $request)
    {
        return view('admin.wheel.acces', [
            'ouvert' => $this->ouvert($request),
            'pinConfigure' => (string) config('wheel.access.pin', '') !== '',
            'message' => $request->session()->get('wheel_locked'),
            'jeuOuvertAuPublic' => (bool) config('wheel.enabled', false),
            'reglages' => app(\App\Services\Wheel\WheelSettingsService::class),
        ]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $attendu = (string) config('wheel.access.pin', '');

        if ($attendu === '') {
            // Fail-closed : sans code configuré, on n'ouvre RIEN. Ces écrans distribuent des lots.
            return back()->with('wheel_locked', 'Aucun code n\'est configuré sur cette machine.');
        }

        $data = $request->validate(['pin' => ['required', 'string', 'max:32']]);

        // Comparaison en temps constant : un `===` fuit, par son temps d'exécution, le nombre de
        // caractères corrects — et un code à quatre chiffres n'a pas de marge à donner.
        if (! hash_equals($attendu, (string) $data['pin'])) {
            Log::channel('daily')->warning('wheel.access_denied', [
                'ip' => $request->ip(),
            ]);

            return back()->with('wheel_locked', 'Code incorrect.');
        }

        // Anti-fixation de session au moment du déverrouillage.
        $request->session()->regenerate();
        $request->session()->put(EnsureWheelAccess::SESSION_KEY, now()->getTimestamp());

        return redirect()->route('admin.wheel.home');
    }

    public function lock(Request $request): RedirectResponse
    {
        $request->session()->forget(EnsureWheelAccess::SESSION_KEY);

        return redirect()->route('admin.wheel.home')
            ->with('wheel_locked', 'Écrans refermés.');
    }

    private function ouvert(Request $request): bool
    {
        $user = Auth::guard('web')->user();
        if ($user && $user->can('pos')) {
            return true;
        }

        if ((string) config('wheel.access.pin', '') === '') {
            return false;
        }

        $depuis = (int) $request->session()->get(EnsureWheelAccess::SESSION_KEY, 0);
        $duree = ((int) config('wheel.access.session_minutes', 240)) * 60;

        return $depuis > 0 && (now()->getTimestamp() - $depuis) <= $duree;
    }
}
