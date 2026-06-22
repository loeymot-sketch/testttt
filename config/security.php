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

    /*
    |--------------------------------------------------------------------------
    | Security — SafeRemoteHost allowlist (SSRF defense override)
    |--------------------------------------------------------------------------
    |
    | [GOAL-L2-HEAL-03 2026-05-24] L7.2 L7-2-F-01 P1 / L7-2-F-02 P1.
    |
    | App\Rules\SafeRemoteHost blocks RFC1918 / loopback / link-local
    | (incl. cloud metadata 169.254.169.254) / multicast / reserved IPv4 +
    | IPv6 loopback / link-local / unique-local. Used by PrinterRequest
    | (TcpPrinterTransport host) and MailRequest (mail_host).
    |
    | V1 LOCAL Le Cayenne: empty by default. Owner opts in via .env if a
    | legit LAN-hosted printer needs allowlisting:
    |
    |     SAFE_REMOTE_HOST_ALLOWLIST=192.168.1.0/24
    |
    | Multiple subnets are comma-separated:
    |
    |     SAFE_REMOTE_HOST_ALLOWLIST=192.168.1.0/24,10.10.0.0/16
    |
    | Each entry must be an IPv4 CIDR (a.b.c.d/n) or bare IPv4. IPv6 ranges
    | are not supported by the allowlist (V1 LOCAL = IPv4-only printer LAN).
    */
    'safe_remote_host_allowlist' => env('SAFE_REMOTE_HOST_ALLOWLIST', '')
        ? array_values(array_filter(array_map('trim', explode(',', env('SAFE_REMOTE_HOST_ALLOWLIST', '')))))
        : [],
];
