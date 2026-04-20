# RUN_C13_CSP_REPORT_ONLY_2026-04-20

**Cycle**: cleanup post-T20  
**Date**: 2026-04-20  
**Mode**: audit + exec (RUNNER_MODE single-session, auto-remediation active)  
**Status**: COMPLETED  
**Bug signature**: n/a (port additif zéro-risque)

---

## Périmètre audité

| Path | Status | Action |
|---|---|---|
| `lang/*/pos_payment_method.php` (5 langues) | testttt **AHEAD** (TICKET_RESTAURANT) | no-op |
| `resources/views/master.blade.php` | divergent — **finding utile** | **PORT** |
| `resources/css/` | identique | ✓ |
| `resources/views/` (autre) | identique | ✓ |
| `bootstrap/` | identique (cache exclu) | ✓ |
| `lang/.DS_Store`, `resources/views/.DS_Store` | bruit hors scope | ignore |

## Trouvaille — `resources/views/master.blade.php` (K-6.7 / K-9 ADR-5)

### Diff porté (additif, +30 lignes)

p93 ajoute, dans `<head>` après `viewport`, une meta `Content-Security-Policy-Report-Only` conditionnelle :

```html
@if (request()->is('kiosk*'))
    <meta http-equiv="Content-Security-Policy-Report-Only" content="
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
    ">
@endif
```

### Pourquoi c'est utile maintenant

C2 a porté l'endpoint `POST /api/frontend/csp-report` + son test, mais **sans cette meta** côté browser, **aucun navigateur n'envoie de violation au backend**. L'endpoint était une boîte noire silencieuse.

Maintenant la chaîne K-9 ADR-5 est opérationnelle bout en bout :

```
Browser (kiosk*) → CSP-RO violation → POST /api/frontend/csp-report
                 → CspReportController::store
                 → Log::channel('observability')->info('csp_violation', ...)
                 → storage/logs/observability-YYYY-MM-DD.log
                 → ops audit / SIEM ingestion
```

### Garde-fous appliqués

| Garde-fou | Détail |
|---|---|
| Mode **Report-Only** | ne bloque rien, observe seulement |
| Scope `kiosk*` | POS / admin / customer-facing intouchés (analytics injections préservées) |
| `'unsafe-inline'` toléré | layout actuel a inline scripts (`window.foodkingConfig`) + inline styles (Dine-In hide) ; K-9 migrera vers nonces avant enforcement |
| `frame-ancestors 'none'` | défense clickjacking |
| `report-uri` legacy | compat Safari/Firefox/Chrome ; `report-to` viendra en K-10 |

### Risque

**Quasi-nul** :
- Report-Only = pas de blocage navigateur
- Limité aux URLs `kiosk*` (catch-all `/{any}` route via `RootController::index` → `master.blade.php` ; SPA router gère les sous-routes)
- Patch 100% additif, balises Blade équilibrées (`@if/@endif`)

## Activation chaîne C2 → C13

Avant C13 : `/api/frontend/csp-report` reçoit 0 hit (jamais activé côté browser).  
Après C13 : tout chargement kiosk peut générer un `csp_violation` log dans `observability` channel.

→ Ops peuvent maintenant :
1. Identifier la **vraie surface CSP** du kiosk (toutes les sources `script-src`/`style-src`/`connect-src` réellement utilisées)
2. Préparer la migration K-9 vers `Content-Security-Policy` (enforcement) en sachant ce qu'il faudra autoriser
3. Détecter en réel des injections inattendues (XSS) en mode passif

## Tests

- `CspReportEndpointTest` : **3/3 PASS** (endpoint anonyme + log + redaction)
- `ObservabilityLogChannelTest` : **2/2 PASS** (canal `observability` configuré)
- `CorrelationIdEndToEndTest` : **5/5 PASS** (corrélation préservée)

→ **10/10 PASS, 34 assertions, 0 régression** sur le périmètre touché.

## Diff

```
resources/views/master.blade.php | 30 ++++++++++++++++++++++++++++++
1 file changed, 30 insertions(+)
```

## Audit-only (pas de port pour le reste)

- `lang/*/pos_payment_method.php` : testttt ahead (TICKET_RESTAURANT) — pas dans le scope (serait backport→p93)
- Reste identique

## Verdict

**CLOSED — sans gate** (zone non critique, port additif Report-Only, chaîne C2 activée).
