<?php

namespace App\Http\Middleware;

use App\Models\KioskMachine;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * K-8 — Multi-branch deployment.
 *
 * Valide que la locale demandée (header `X-Kiosk-Locale` ou query `?lang=`)
 * appartient à la liste `Branch.available_locales` de la borne authentifiée.
 *
 * Invariants :
 *  - Header / query absent ⇒ passthrough (le front choisit la default_locale).
 *  - Locale hors allowlist ⇒ 400 avec code `LOCALE_NOT_ALLOWED_FOR_BRANCH`.
 *  - User sans `KioskMachine` associée ⇒ passthrough (l'auth middleware en amont
 *    doit déjà bloquer, et ce middleware ne doit jamais masquer un 401).
 *  - Locale format invalide (regex simple `/^[a-z]{2}(-[A-Z]{2})?$/`) ⇒ 400.
 *
 * Enregistré dans `Kernel::middlewareAliases` sous `kiosk.locale`.
 */
class ValidateKioskLocale
{
    /** Regex pragmatique : `fr`, `en`, `ar`, `pt-BR`, etc. */
    private const LOCALE_REGEX = '/^[a-z]{2}(-[A-Z]{2})?$/';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->extractLocale($request);

        if ($locale === null) {
            return $next($request);
        }

        if (preg_match(self::LOCALE_REGEX, $locale) !== 1) {
            return response()->json([
                'status'  => false,
                'message' => 'Locale invalide.',
                'code'    => 'LOCALE_FORMAT_INVALID',
            ], 400);
        }

        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $machine = KioskMachine::query()
            ->with('branch')
            ->where('user_id', $user->id)
            ->first();

        if (!$machine || !$machine->branch) {
            return $next($request);
        }

        $allowed = $machine->branch->activeLocales();
        if (!in_array($locale, $allowed, true)) {
            return response()->json([
                'status'  => false,
                'message' => 'Locale non autorisée pour cette branche.',
                'code'    => 'LOCALE_NOT_ALLOWED_FOR_BRANCH',
                'meta'    => [
                    'requested' => $locale,
                    'allowed'   => $allowed,
                ],
            ], 400);
        }

        return $next($request);
    }

    private function extractLocale(Request $request): ?string
    {
        $header = $request->header('X-Kiosk-Locale');
        if (is_string($header) && $header !== '') {
            return trim($header);
        }
        $query = $request->query('lang');
        if (is_string($query) && $query !== '') {
            return trim($query);
        }
        return null;
    }
}
