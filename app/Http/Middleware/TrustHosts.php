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
        // [Wave 2c P1 SYNC-ADV3B-01 2026-05-18] Whitelist defense vs Host
        // spoof. TrustProxies::$proxies='*' (Wave 2b) honours
        // X-Forwarded-Host from any upstream; this pins acceptable hosts
        // to APP_URL subdomains + loopback so an attacker can't poison
        // URL::route() / abort_unless($request->getHost(), ...) checks.
        return [
            $this->allSubdomainsOfApplicationUrl(),
            '127.0.0.1',
            'localhost',
        ];
    }
}
