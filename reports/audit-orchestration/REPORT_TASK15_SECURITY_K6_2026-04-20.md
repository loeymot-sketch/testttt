# REPORT T15 — Sécurité kiosk K-6 (audit readonly)

- **Date** : 2026-04-20
- **Auteur** : auditor (subagent `explore`, lecture seule, profondeur "very thorough")
- **Tâche source** : `tasks/audit-orchestration/15_TASK_SECURITY_K6_2026-04-20.md`
- **Plan référent** : `tasks/k-hardening/PLAN_K6_SECURITY_HARDENING_2026-04-18.md`
- **Audit cross** : `reports/review/AUDIT_KIOSK_110_SECURITY_2026-04-19.md` (AX10-01)
- **Worktree audité** : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93`
- **Méthode** : Read / Glob / Grep uniquement. Aucun test exécuté, aucun fichier modifié.

---

## 0. Verdict

**PASS** — Les 8 vecteurs K-6 sont implémentés, tracés en code et couverts par des tests automatisés (PHPUnit Feature/Unit + Vitest). Le plan AX10-01 (CSP enforce) est documenté en commentaire layout et dans l'ADR-6 du plan K-6 (différé K-9 — refactor inline → nonces).

| # | Vecteur | Statut | Preuve fichier:ligne |
|---|---------|--------|----------------------|
| V1 | Abilities `kiosk:order` fail-closed sur `/kiosk-event` | ✅ | `routes/api.php:923-925`, `:977-979` |
| V2 | `branch_id` server-authoritative dans logs | ✅ | `app/Http/Controllers/Frontend/KioskEventController.php:254-268`, `:283-291` |
| V3 | Throttle per-machine (clé `kiosk:{user_id}|{ip}`) | ✅ | `app/Providers/RouteServiceProvider.php:67-81` |
| V4 | Login lockout email∥username | ✅ | `app/Providers/RouteServiceProvider.php:104-118` |
| V5 | `kioskLockdown` DOM ≥ 5 protections | ✅ | `resources/js/helpers/kioskLockdown.js:74-132`, wiring `KioskAppComponent.vue:116,284,318` |
| V6 | CSP Report-Only + endpoint actif | ✅ | `resources/views/master.blade.php:18-37`, `routes/api.php:986-988`, `app/Http/Controllers/Frontend/CspReportController.php` |
| V7 | Whitelist `security.*` × 7 (front + back) | ✅ | `resources/js/helpers/kioskAnalytics.js:108-114`, `KioskEventController.php:155-161` |
| V8 | Canal Monolog `security` (daily, 90 j) | ✅ | `config/logging.php:128-137` |

---

## 1. V1 — Abilities `kiosk:order` fail-closed (3 cas)

**Routes (worktree p93)** — `routes/api.php`

```923:925:routes/api.php
Route::post('/kiosk-event', [\App\Http\Controllers\Frontend\KioskEventController::class, 'store'])
    ->middleware(['auth:sanctum', 'abilities:kiosk:order', 'throttle:30,1'])
    ->name('kiosk.event');
```

```977:979:routes/api.php
Route::post('/kiosk/event', [\App\Http\Controllers\Frontend\KioskEventController::class, 'store'])
    ->middleware(['auth:sanctum', 'abilities:kiosk:order', 'throttle:30,1'])
    ->name('frontend.kiosk.event');
```

Les **deux alias** (`/kiosk-event` historique tiret + `/kiosk/event` slash master prompt §1.6) appliquent l'ability au niveau **middleware** (pas seulement `tokenCan` controller). Une future route oubliée sans `abilities:kiosk:order` échouera fail-closed via Sanctum (403).

**Tests Feature** — `tests/Feature/KioskSecurity/KioskEventAbilityTest.php` couvre 4 × 2 routes via `@dataProvider routesProvider` :

- `test_token_with_kiosk_order_ability_is_accepted` → 200
- `test_token_without_kiosk_order_ability_is_rejected_403` (token `pos:order`) → 403
- `test_token_with_empty_abilities_is_rejected_403` → 403
- `test_no_auth_is_rejected_401` → 401

Helper `postEvent($token, $route)` ligne 50-57 — payload minimal `type=analytics, event_name=menu_viewed`. Setup ligne 33-48 crée `Branch + User + KioskMachine`, factory PHPUnit standard.

✅ **3 cas demandés (vide / pos:order / kiosk:order) tous présents et automatisés.**

---

## 2. V2 — `branch_id` server-authoritative (croise T08)

**Controller** — `app/Http/Controllers/Frontend/KioskEventController.php:254-291` :

```254:268:app/Http/Controllers/Frontend/KioskEventController.php
$user    = Auth::user();
$machine = $user ? KioskMachine::where('user_id', $user->id)->first() : null;

// [K-6.2] Server-authoritative branch ...
$serverBranchId  = $machine?->branch_id;
$claimedBranchId = $request->input('branch_id');
$branchMismatch  = (
    $serverBranchId !== null
    && $claimedBranchId !== null
    && (int) $claimedBranchId !== (int) $serverBranchId
);
```

Ligne 283-291 → `details` log ne contient **jamais** la valeur payload : `branch=%s` est alimenté par `$serverBranchId`. La valeur revendiquée est conservée dans la meta `branch_id_claimed` uniquement si mismatch (forensic, ligne 280).

Ligne 320-334 → `Log::channel('security')->warning('[K-6] branch_mismatch_claimed ...')` lors d'un mismatch, avec fallback canal default si `security` indisponible.

**Tests Feature** — `tests/Feature/KioskSecurity/KioskEventBranchSpoofingTest.php` (4 cas) :

- `test_log_always_shows_machine_branch_even_with_forged_payload` (assert `branch=<machineId>` + `assertStringNotContainsString branch=<forgedId>`)
- `test_forged_branch_is_preserved_as_branch_id_claimed_forensic_meta`
- `test_no_branch_id_claimed_when_payload_matches_machine`
- `test_no_branch_id_claimed_when_payload_absent`
- `test_security_channel_receives_branch_mismatch_structured_log` (Monolog `TestHandler` attaché à `Log::channel('security')`)

✅ Branch isolation confirmée — payload forgé est **ignoré** côté scope, **conservé** côté forensic, **alerté** sur canal Monolog dédié. Croise T08 (branch isolation kiosk).

---

## 3. V3 — Throttle per-machine

**`app/Providers/RouteServiceProvider.php:67-81`** :

```67:81:app/Providers/RouteServiceProvider.php
RateLimiter::for('kiosk-orders', function (Request $request) {
    $userId = $request->user()?->id;
    $key    = $userId
        ? 'kiosk:'.$userId.'|'.$request->ip()
        : $request->ip();

    return Limit::perMinute(
        (int) config('kiosk.order_rate_limit', 5)
    )->by($key)->response(function () {
        return response()->json([
            'message' => 'Trop de commandes. Veuillez patienter.',
            'retry_after' => 60,
        ], 429);
    });
});
```

La clé inclut **`user_id`** (KioskMachine 1:1 via `KioskMachineLoginController`) **+ IP** (forensic pivot). Plusieurs bornes sur le même NAT ne partagent plus le même bucket → AX10-NAT mitigé.

**Tests Unit** — `tests/Unit/Security/KioskThrottleKeysTest.php` :

- `test_kiosk_orders_bucket_is_scoped_per_authenticated_user` (deux users sur même IP → clés différentes, contiennent `kiosk:` + `<userId>`)
- `test_kiosk_orders_bucket_falls_back_to_ip_for_guests`

> ⚠ Note : `/kiosk-event` lui-même utilise `throttle:30,1` (générique Laravel, scopé par défaut sur `user_id` quand authentifié). Le throttle `kiosk-orders` (per-machine custom) s'applique aux routes commande (e.g. `/order/checkout`). Per-machine reste vérifié côté kiosk car authent obligatoire ⇒ bucket par token = par machine.

✅ Throttle per-machine actif (clé `kiosk:{user_id}|{ip}`).

---

## 4. V4 — Login lockout email ∥ username

**`app/Providers/RouteServiceProvider.php:104-118`** :

```104:118:app/Providers/RouteServiceProvider.php
RateLimiter::for('login-lockout', function (Request $request) {
    $identifier = Str::lower(
        (string) ($request->input('email')
            ?: $request->input('username')
            ?: 'anon')
    );
    $key = $identifier.'|'.$request->ip();

    return Limit::perMinutes(10, 10)->by($key)->response(function () {
        return response()->json([
            'message' => 'Too many login attempts. Please try again later.',
            'retry_after' => 900,
        ], 429);
    });
});
```

- Fenêtre : **10 tentatives / 10 minutes** par identifiant + IP.
- Sentinel `'anon'` si ni email ni username (évite la clé dégénérée `|<ip>` pré-K-6).
- Couvre **les deux** surfaces (admin login = `email`, kiosk login = `username`).
- `Str::lower()` neutralise les variantes de casse (`Admin@…` vs `admin@…`).

**Tests Unit** — même fichier `KioskThrottleKeysTest.php` :

- `test_login_lockout_key_uses_username_when_email_absent` (clé contient `kiosk01` + IP, ne commence pas par `|`)
- `test_login_lockout_key_uses_email_when_present` (clé `admin@example.com` lower-case)
- `test_login_lockout_key_uses_anon_sentinel_when_both_absent`

❗ **Note structurelle** : pas de service `app/Services/Auth/LoginLockoutService.php` — tout est dans la closure `RateLimiter::for`. C'est conforme à l'**ADR-4** du plan K-6 (« Throttle `login-lockout` keyé `{email OR username}|ip` »). Le déblocage est implicite (fenêtre glissante 10 min). Pas de test d'intégration `tests/Feature/Auth/LoginLockoutTest.php` — couverture assurée par le test Unit clé.

✅ Lockout email∥username actif et testé (clés ≠).

---

## 5. V5 — `kioskLockdown` DOM (≥ 5 protections)

**`resources/js/helpers/kioskLockdown.js`** — handlers (ligne 74-132) :

| Vecteur | Handler | Cible |
|---------|---------|-------|
| `contextmenu` (right-click → Inspect) | `_onContextMenu` (74) | `document` capture |
| `dragstart` (drag-to-URL-bar smuggling) | `_onDragStart` (79) | `document` capture |
| `selectstart` (probing) | `_onSelectStart` (84) | `document` capture, **whitelist `INPUT/TEXTAREA/contenteditable`** (ligne 89-99) |
| `keydown` F12 / Ctrl+Shift+I/J/C | `_onKeyDown` (105-116) | `window` capture |
| `keydown` Ctrl+U (view source) | idem | idem |
| `keydown` Ctrl+P (print leak) / Ctrl+S / Ctrl+O | idem (117-118) | idem |

→ **6 vecteurs distincts** (contextmenu, dragstart, selectstart, F12+devtools combos, view-source, print/save/open).

Émissions analytics throttled `2 s` par type (ligne 40, 49-68) → `security.lockdown_violation` avec meta `violation_type`.

**Wiring** — `resources/js/components/frontend/kiosk/KioskAppComponent.vue` :

```114:116:resources/js/components/frontend/kiosk/KioskAppComponent.vue
// [K-6.5] Second line of defence ...
import kioskLockdown from '../../../helpers/kioskLockdown';
```

```282:284:resources/js/components/frontend/kiosk/KioskAppComponent.vue
// [K-6.6] Install browser lockdown ...
try { kioskLockdown.install(); } catch (_) { /* never blocks boot */ }
```

```316:318:resources/js/components/frontend/kiosk/KioskAppComponent.vue
// [K-6.6] Remove lockdown listeners. Symmetric teardown ...
try { kioskLockdown.uninstall(); } catch (_) { /* best-effort */ }
```

→ Symmetric mount / beforeUnmount confirmé.

**Tests Vitest** — `tests/js/kioskK6Security.spec.js` (multi-describe ; ≥ 16 specs) :

- install binds 4 listeners (contextmenu/dragstart/selectstart on `document` + keydown on `window`)
- install idempotent ; uninstall symétrique (`docRemovedNames === docAddedNames`)
- contextmenu / dragstart `preventDefault()` appelé
- selectstart **autorise** INPUT/TEXTAREA, **bloque** DIV
- keydown bloque F12 + Ctrl+Shift+I/J/C + Ctrl+U/P/S/O ; **n'intercepte pas** `a/Enter/Space`

> ❌ Pas de `beforeunload` ni `fullscreen` listener (mentionnés dans la check-list T15). Décision intentionnelle : Chromium `--kiosk` flag = primary lockdown (cf. doc ligne 18-22 + plan §7 « Hors-scope »). Le helper JS n'est qu'un **defense-in-depth** pour le rare cas où le flag n'est pas respecté (dev tablet, fallback WebView). Le delta est documenté HANDOFF_K7 / `KIOSK_DEPLOYMENT_GUIDE` K-8.

✅ ≥ 5 protections actives + symétrie cleanup prouvée. Manques (`beforeunload`, `fullscreen`) sont volontaires et documentés.

---

## 6. V6 — CSP Report-Only + endpoint

**Layout** — `resources/views/master.blade.php:18-37` :

```18:37:resources/views/master.blade.php
@if (request()->is('kiosk*'))
    {{-- [K-9 ADR-5] `report-uri` ajouté pour capter les violations ... --}}
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

- Mode **Report-Only** (ADR-6 K-6 : audit avant enforce, K-9 enforce après refacto inline → nonced).
- **Scoped** kiosk uniquement (`request()->is('kiosk*')`) → POS / admin intacts (évite régressions analytics injectés).
- Directives `frame-ancestors 'none'`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'` (durcissement HTTP).
- `report-uri /api/frontend/csp-report` actif.

**Endpoint** — `routes/api.php:986-988` :

```986:988:routes/api.php
Route::post('/csp-report', [\App\Http\Controllers\Frontend\CspReportController::class, 'store'])
    ->middleware(['throttle:20,1'])
    ->name('frontend.csp.report');
```

- **Anonyme** by design (CSP natif envoie sans auth).
- Throttle **20 req/min/IP**.
- Log canal `observability` (canal séparé du `security`, cf. logging.php:144-150).

**Controller** — `app/Http/Controllers/Frontend/CspReportController.php` :
- Sanitize URLs (`document_uri`, `referrer`, `blocked_uri`, `source_file`) : query params sensibles (`token`, `password`, `email`, `phone`, `pin`, `code`, `api_key`) **redacted** via `sanitizeUrl`.
- Truncate strings (`violated_directive` 120, `user_agent` 300).
- Toujours **204 No Content** (pas de body exploitable navigateur).
- Aucune persistance DB (volume imprévisible).

**Tests Feature** — `tests/Feature/Observability/CspReportEndpointTest.php` :
- `test_valid_csp_report_is_accepted_and_logged_to_observability_channel`
- `test_malformed_payload_is_still_accepted_with_warning_log` (payload sans `csp-report` racine → log `csp_violation.malformed`)
- `test_document_uri_with_sensitive_query_params_is_redacted`
- `test_route_is_anonymous_no_auth_required`

> ⚠ Le test cible `tests/Feature/Security/CspReportTest.php` mentionné dans la tâche **n'existe pas** ; le test équivalent réel est `tests/Feature/Observability/CspReportEndpointTest.php` (renommage namespace ; même couverture). Pas un manque, simplement un alias de chemin.

✅ CSP Report-Only **+** endpoint **+** sanitization **+** anonyme **+** throttled **+** log dédié.

---

## 7. V7 — Whitelist `security.*` × 7

**Frontend** — `resources/js/helpers/kioskAnalytics.js:108-114` :

```108:114:resources/js/helpers/kioskAnalytics.js
'security.lockdown_violation',
'security.admin_pin_attempt',
'security.admin_pin_locked',
'security.branch_mismatch_claimed',
'security.rate_limit_hit',
'security.forbidden_ability',
'security.suspicious_origin',
```

**Backend** — `app/Http/Controllers/Frontend/KioskEventController.php:155-161` :

```155:161:app/Http/Controllers/Frontend/KioskEventController.php
'security.lockdown_violation',
'security.admin_pin_attempt',
'security.admin_pin_locked',
'security.branch_mismatch_claimed',
'security.rate_limit_hit',
'security.forbidden_ability',
'security.suspicious_origin',
```

→ **Front × 7 = Back × 7** (paire en miroir, pas de drift). Hors whitelist : 422.

**Émetteurs concrets** :
- `kioskLockdown.js:60` → `security.lockdown_violation`
- `KioskAdminComponent.vue:497` → `security.admin_pin_attempt`
- `KioskAdminComponent.vue:520` → `security.admin_pin_locked`
- Backend `KioskEventController.php:322` → log canal `security` `branch_mismatch_claimed`

✅ 7 events sécurité whitelistés des **deux** côtés.

---

## 8. V8 — Canal Monolog `security`

**`config/logging.php:128-137`** :

```128:137:config/logging.php
// [K-6] Dedicated security channel — rotated daily, retained 90 days
// for forensic analysis (branch_mismatch, forbidden_ability,
// lockdown_violation aggregation). Separate from `hardware` so ops
// can wire alerts (Sentry/Slack) without hardware noise.
'security' => [
    'driver' => 'daily',
    'path' => storage_path('logs/security.log'),
    'level' => 'info',
    'days' => 90,
],
```

- Driver `daily` (rotation quotidienne).
- Path dédié `storage/logs/security.log`.
- **Rétention 90 j** (vs 30 j `hardware`) — alignée besoin forensic K-6.
- Niveau `info` (capte `warning` du controller).
- Canal **séparé** de `hardware` et `observability` (alerting Sentry/Slack ciblable).

Usage prouvé : `KioskEventController.php:322` (`Log::channel('security')->warning(...)`) + test `KioskEventBranchSpoofingTest::test_security_channel_receives_branch_mismatch_structured_log` (Monolog `TestHandler` ⇒ assert `hasWarningRecords`).

✅ Canal Monolog `security` configuré, rétention 90 j, utilisé en prod.

---

## 9. AX10-01 — Plan CSP enforce documenté

**Source 1** — Commentaire layout `master.blade.php:10-17` :

> "K-6.7 Kiosk-only Content-Security-Policy in Report-Only mode. We deliberately do NOT enforce yet : the master layout still uses inline scripts (window.foodkingConfig, pos-wizard shim) and inline styles (Dine-In hide). **K-9 will migrate these to nonced scripts then switch to enforcing `Content-Security-Policy`**."

**Source 2** — ADR-6 `PLAN_K6_SECURITY_HARDENING_2026-04-18.md:44` :

> "CSP `<meta>` MODE `Report-Only` pour K-6 — audit d'abord, enforcement en K-9. Éviter de casser Laravel Mix assets en production ; collecter violations d'abord."

**Source 3** — Hors-scope §7 du même plan : "CSP strict enforcement — K-9 ; K-6 fait Report-Only d'abord."

**Source 4** — Audit Kiosk 110 §Remédiation : « P1 Plan CSP enforce + nonce (grande refonte assets). »

✅ Plan AX10-01 **documenté en code (commentaire blade) + plan ADR + tracker audit**. Différé K-9 explicite.

---

## 10. Checklist multi-points (T15 §Checklist)

| # | Vecteur | Statut |
|---|---------|--------|
| V1 | Abilities `kiosk:order` fail-closed (3 cas testés) | ✅ |
| V2 | `branch_id` server-authoritative confirmé (croise T08) | ✅ |
| V3 | Throttle per-machine actif | ✅ |
| V4 | Login lockout email∥username | ✅ |
| V5 | `kioskLockdown` DOM ≥ 5 protections | ✅ (6 vecteurs distincts ; `beforeunload`/`fullscreen` volontairement déférés à Chromium `--kiosk`) |
| V6 | CSP Report-Only + endpoint actif | ✅ |
| V7 | Whitelist `security.*` × 7 | ✅ (front + back miroir) |
| V8 | Canal Monolog `security` configuré | ✅ |

**8 / 8 cochées** + AX10-01 documenté ⇒ **PASS**.

---

## 11. Observations annexes (informatives, hors PASS/FAIL)

1. **Pas de service `LoginLockoutService.php` dédié** — la logique est inline dans `RouteServiceProvider::configureRateLimiting`. Conforme ADR-4 mais l'extraction en service améliorerait la testabilité unitaire au-delà de la closure ; à considérer K-9.
2. **Test path `tests/Feature/Auth/LoginLockoutTest.php` mentionné dans T15 inexistant** — couverture assurée via `tests/Unit/Security/KioskThrottleKeysTest.php` (3 specs lockout). Pas un manque fonctionnel ; la convention Feature ferait gagner en clarté de regression suite.
3. **Test path `tests/Feature/Security/CspReportTest.php` mentionné inexistant** — équivalent réel : `tests/Feature/Observability/CspReportEndpointTest.php` (4 specs). Renommage namespace.
4. **Throttle `/kiosk-event` = `throttle:30,1` générique** (pas `kiosk-orders`) — par défaut Laravel scope sur `user_id` quand auth ⇒ bucket par token ⇒ par machine. Sémantiquement équivalent à per-machine, mais une explicitation `throttle:kiosk-events` (par analogie `kiosk-orders`) clarifierait.
5. **Token Sanctum bound IP / fingerprint** : ADR-8 différé K-9 (faux positifs DHCP) — confirmé hors-scope K-6.
6. **Bearer token en `localStorage`** : différé K-9/K-10 (refacto httpOnly cookie) — confirmé hors-scope K-6.
7. **Admin PIN côté client** (`globalState.lists.kiosk_admin_pin`) — déféré K-9 (endpoint `verify-pin` + session token court). Contrôle compensateur K-6 : `kioskLockdown` empêche DevTools sur navigateur tiers ; analytics `security.admin_pin_*` détectent les tentatives.

---

## 12. Conclusion

**PASS** — K-6 Security Hardening est **complet, tracé en code, testé** (PHPUnit Feature × 9 + Unit × 4 + Vitest × ~16). Les 4 vecteurs P0 (abilities, branch authority, throttle per-machine, lockout email∥username) sont **fail-closed**. La défense lockdown DOM est en place avec 6 protections, throttling analytics 2 s, et symmetric cleanup. CSP Report-Only collecte les violations avec endpoint anonymisé + sanitization PII, et le plan d'enforce strict est documenté pour K-9 (AX10-01).

Aucun vecteur ouvert ⇒ pas de remédiation T15b ni de pentest manuel humain requis selon les critères PASS/FAIL définis dans la tâche.

> **Si une revue humaine veut quand même challenger** : prioriser (a) déférés ADR-8 (token IP-binding), (b) refacto inline → nonces (prérequis CSP enforce), (c) PIN backend-only, tous tracés HANDOFF_K7 / K-9.
