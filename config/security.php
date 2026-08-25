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
    /*
     * [AUDIT-SUPERVISEUR 2026-08-25 · D-002] LE PONT D'IMPRESSION CUISINE MANQUAIT.
     *
     * `connect-src` n'autorisait que le port 9100 (pont CAISSE) alors que
     * `resources/js/helpers/kitchenLocalPrinter.js:22` compose le **9101** (pont
     * CUISINE). L'équipe de capture avait classé les `ERR_CONNECTION_REFUSED` associés
     * en « bruit Pusher allowlisté » ; le superviseur a montré que ce ne sont ni Pusher
     * ni des websockets, mais des requêtes HTTP vers les ponts d'impression.
     *
     * Aujourd'hui la politique est en `report_only` : le navigateur SIGNALE sans
     * bloquer, donc l'impression fonctionne et le défaut est invisible. Le jour où
     * `CSP_ENFORCE_MODE` passe à `enforce`, le navigateur BLOQUE l'appel et la cuisine
     * cesse d'imprimer — sans que rien dans le code n'ait changé. C'est une mine à
     * retardement armée par une configuration, pas par un bogue.
     *
     * L'allowlist BACKEND, elle, connaissait déjà le 9101 : la documentation quelques
     * lignes plus bas donne `127.0.0.1/32:9100-9101` en exemple. C'est la politique
     * NAVIGATEUR qui avait été oubliée — les deux moitiés d'une même porte.
     */
    'csp' => [
        'mode' => env('CSP_ENFORCE_MODE', 'report_only'),

        'directives' => trim(preg_replace('/\s+/', ' ', "
            default-src 'self';
            script-src 'self' 'unsafe-inline' 'unsafe-eval';
            style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
            font-src 'self' data: https://fonts.gstatic.com;
            img-src 'self' data: blob: https:;
            connect-src 'self' ws: wss: https: http://127.0.0.1:9100 http://localhost:9100 http://127.0.0.1:9101 http://localhost:9101;
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
    | legit internal printer host needs allowlisting.
    |
    | [OWNER DECISION 2026-08-13 — option (b) "allowlist host+port FERMÉE"]
    | Each entry carries its OWN port scope, and the host is only unlocked for
    | the ports it names. Rationale: TcpPrinterTransport::send() performs a
    | bare fsockopen($host,$port) and returns a distinct error per outcome, so
    | a host-only allowlist would hand admin — and POS-Operator via testPrint —
    | a port-scan oracle over all 65535 ports of the box. Scoping the port to
    | the print bridge keeps the oracle closed.
    |
    | Grammar (comma-separated):
    |
    |     <IPv4 CIDR|IPv4>:<port>          192.168.1.20/32:9100
    |     <IPv4 CIDR|IPv4>:<first>-<last>  127.0.0.1/32:9100-9103
    |     <IPv4 CIDR|IPv4>                 LEGACY host-only (see below)
    |
    | Le Cayenne real architecture (per-station LOCAL print bridge, CLAUDE.md):
    |
    |     SAFE_REMOTE_HOST_ALLOWLIST=127.0.0.1/32:9100-9101
    |
    | Multiple entries:
    |
    |     SAFE_REMOTE_HOST_ALLOWLIST=127.0.0.1/32:9100-9101,192.168.1.0/24:9100
    |
    | LEGACY host-only entries (the 2026-05-24 format) are still UNDERSTOOD:
    | they keep working for host-only fields (mail_host / MAIL_HOST boot guard,
    | which have no port to scope), but they are REFUSED for port-aware fields
    | (printer host) with an explicit error naming the required format — a
    | host-only entry cannot express the closed variant, so we fail closed
    | rather than silently granting every port. Symmetrically, a port-scoped
    | entry does NOT unlock mail_host: allowlisting the print bridge must not
    | open SMTP to loopback.
    |
    | Malformed entries (bad CIDR, port out of 1-65535, inverted range) are
    | ignored — fail-closed. IPv6 ranges are not supported by the allowlist
    | (V1 LOCAL = IPv4-only printer LAN); that is what makes ':' unambiguous.
    */
    'safe_remote_host_allowlist' => env('SAFE_REMOTE_HOST_ALLOWLIST', '')
        ? array_values(array_filter(array_map('trim', explode(',', env('SAFE_REMOTE_HOST_ALLOWLIST', '')))))
        : [],
];
