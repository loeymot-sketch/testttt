<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts()
    {
        // [Wave 3c P0 SYNC-ADV3C-01 heal 2026-05-18] Anchored regex
        // whitelist. Symfony wraps every entry as `{%s}i` and uses
        // unanchored preg_match (vendor/symfony/http-foundation/Request.php:652,
        // :1182-1183). Plain `127.0.0.1` matched `{127.0.0.1}i` which admitted
        // `127X0X0X1` (dot = any) and plain `localhost` matched `{localhost}i`
        // which admitted `attacker-localhost.com` / `evil.localhost-bypass.io`.
        // Combined with TrustProxies::$proxies='*' (Wave 3 SYNC-ADV3-01) and
        // HEADER_X_FORWARDED_HOST enabled (TrustProxies.php:33), the attacker
        // could spoof X-Forwarded-Host to poison Request::getHost() →
        // URL::route() / signed URLs / password reset links / abort_unless()
        // checks. Anchoring with `^...$` closes that vector. The IPv6
        // `^\[::1\]$` and `^0\.0\.0\.0$` entries cover IPv6 loopback (HTTP
        // Host: header for IPv6 carries the bracket form per RFC 3986; after
        // Symfony's `strtolower(preg_replace('/:\d+$/', '', trim($host)))` at
        // Request.php:1163 the value remains `[::1]` because the port-strip
        // regex only consumes a trailing `:port`) and `php artisan serve
        // --host=0.0.0.0` (SYNC-ADV3C-03). `allSubdomainsOfApplicationUrl()`
        // is already anchored by the vendor (`^(.+\.)?host$`).
        return [
            $this->allSubdomainsOfApplicationUrl(),
            '^127\.0\.0\.1$',
            '^localhost$',
            '^\[::1\]$',
            '^0\.0\.0\.0$',
        ];
    }
}
