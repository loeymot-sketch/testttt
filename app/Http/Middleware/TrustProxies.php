<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * [HEAL-H2 P1 SEC-XFF-01 2026-06-07] Trust ONLY the loopback reverse proxy.
     *
     * History: Wave 3 (SYNC-ADV3-01) set $proxies='*' to restore per-source IP
     * keying for `throttle` — with $proxies=null Laravel ignored
     * X-Forwarded-For and `$request->ip()` returned the nginx loopback for
     * every caller, collapsing the rate-limit into one LAN-wide bucket. But
     * '*' trusts the client-supplied X-Forwarded-For from ANY peer, so a
     * remote attacker could forge `X-Forwarded-For: <trusted kiosk LAN IP>`
     * and bypass the kiosk auto-login IP allowlist
     * (`master.blade.php` gate + KIOSK_AUTO_LOGIN_TRUSTED_IPS) to harvest the
     * machine `spa_payload` credentials — and likewise spoof the rate-limit
     * key and audit-log IP, which all read `request()->ip()`.
     *
     * Loopback-only trust fixes all three: when REMOTE_ADDR is NOT a trusted
     * proxy (i.e. any direct/external client), Symfony ignores forwarded
     * headers entirely and `request()->ip()` resolves to the authentic
     * REMOTE_ADDR. This restores genuine per-source keying (the goal of the
     * '*' change) WITHOUT trusting attacker-supplied headers. A genuine on-box
     * loopback reverse proxy (nginx → soketi proxy_pass, which DOES append the
     * real client to XFF) still has its forwarded chain honoured.
     *
     * V1 LOCAL Le Cayenne is a single box: nginx + PHP-FPM on 127.0.0.1, so
     * loopback is the only legitimate proxy. CLOUD CUTOVER: if a real
     * load-balancer / CDN is added in front, append its real IP(s) here —
     * NEVER restore '*'.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = ['127.0.0.1', '::1'];

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
