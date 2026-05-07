<?php

/*
|--------------------------------------------------------------------------
| Security — Content-Security-Policy (HTTP header)
|--------------------------------------------------------------------------
|
| RED-R2 §1 P2 — La CSP délivrée via <meta http-equiv> est ignorée par les
| navigateurs modernes pour plusieurs directives critiques (frame-ancestors,
| sandbox, report-uri, …). Pour la défense en profondeur, on délivre la CSP
| via un header HTTP réel injecté par App\Http\Middleware\ContentSecurityPolicyHeader.
|
| Le <meta> dans resources/views/master.blade.php reste en place comme
| FALLBACK transitionnel (rollback safety) — voir docs/runbooks/CSP_HEADER_MIGRATION.md.
|
| Modes :
|   - enforce      : header `Content-Security-Policy` (bloque les violations)
|   - report_only  : header `Content-Security-Policy-Report-Only` (log only) [DEFAULT]
|   - disabled     : aucun header (rollback rapide)
|
| Les directives sont volontairement portées à l'identique du <meta> kiosk
| existant — toute restriction supplémentaire est confiée à un cycle K-9 dédié.
*/

return [
    'csp' => [
        'mode' => env('CSP_ENFORCE_MODE', 'report_only'),

        'directives' => trim(preg_replace('/\s+/', ' ', "
            default-src 'self';
            script-src 'self' 'unsafe-inline' 'unsafe-eval';
            style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
            font-src 'self' data: https://fonts.gstatic.com;
            img-src 'self' data: blob: https:;
            connect-src 'self' ws: wss: https:;
            frame-ancestors 'none';
            base-uri 'self';
            form-action 'self';
            object-src 'none';
            report-uri /api/frontend/csp-report;
        ")),
    ],
];
