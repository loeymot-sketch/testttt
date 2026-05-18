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
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

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
