<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Injects the Content-Security-Policy header on web responses.
 *
 * RED-R2 §1 P2 — délivre la CSP via header HTTP (au lieu du <meta http-equiv>
 * qui est ignoré par les navigateurs modernes pour `frame-ancestors`, `sandbox`,
 * `report-uri`, …).
 *
 * Mode pilotable via env `CSP_ENFORCE_MODE` :
 *   - enforce      → `Content-Security-Policy`
 *   - report_only  → `Content-Security-Policy-Report-Only` (défaut)
 *   - disabled     → aucun header (rollback rapide)
 *
 * @see config/security.php
 * @see docs/runbooks/CSP_HEADER_MIGRATION.md
 */
class ContentSecurityPolicyHeader
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $mode = (string) config('security.csp.mode', 'report_only');
        $directives = trim((string) config('security.csp.directives', ''));

        if ($mode === 'disabled' || $directives === '') {
            return $response;
        }

        $headerName = $mode === 'enforce'
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        $response->headers->set($headerName, $directives, true);

        return $response;
    }
}
