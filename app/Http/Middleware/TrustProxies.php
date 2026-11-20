<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * [Wave 3 P1 SYNC-ADV3-01 heal 2026-05-18] Trust all upstream proxies.
     * The local single-restaurant deployment runs PHP-FPM behind nginx on
     * 127.0.0.1; with $proxies=null, Laravel ignored X-Forwarded-For and
     * `$request->ip()` returned the nginx loopback for every caller. That
     * collapsed `throttle:60,1` (Wave 2 webhook heal) to a single LAN-wide
     * bucket — any noisy LAN host could starve Stripe webhook delivery and
     * stall fiscal-sequence allocation. Trust='*' restores per-source IP
     * keying so the throttle protects per attacker, not per LAN.
     *
     * [W7-D D-002 2026-11-04] `'*'` croyait N'IMPORTE QUEL `X-Forwarded-For`, y compris celui que l'attaquant écrit
     * lui-même : `request()->ip()` prenait la valeur la plus à gauche, et tous les limiteurs « par IP » des routes
     * publiques (géocodage payant de la livraison, newsletter, codes promo, csp-report) se contournaient en changeant
     * l'en-tête à chaque requête (prouvé : 429 puis 201). On ne croit plus que le mandataire local — nginx sur le
     * loopback, qui AJOUTE l'adresse réelle en fin de chaîne — ou la liste `TRUSTED_PROXIES` (config `app.trusted_proxies`).
     * Le besoin de 2026-05-18 (une adresse par client derrière nginx) reste satisfait, sans l'usurpation.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = null;

    /**
     * @return array<int, string>|string
     */
    protected function proxies()
    {
        $valeur = trim((string) config('app.trusted_proxies', ''));

        if ($valeur === '') {
            return ['127.0.0.1', '::1'];
        }
        if ($valeur === '*' || $valeur === '**') {
            return $valeur;
        }

        return array_values(array_filter(array_map('trim', explode(',', $valeur))));
    }

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
