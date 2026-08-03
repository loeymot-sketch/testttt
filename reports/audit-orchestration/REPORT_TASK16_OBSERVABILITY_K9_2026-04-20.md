# T16 — Audit profond Observability K-9 (2026-04-20)

> **Mode** : `explore` readonly · **Profondeur** : *very thorough*
> **Racine auditée** : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93`
> **Tâche source** : `tasks/audit-orchestration/16_TASK_OBSERVABILITY_K9_DEEP_2026-04-20.md`
> **Lien** : T02 (schedule Laravel) — T03 (Sentry front) — T09 (EventContract / outbox)

---

## 0. Verdict global

**❌ FAIL** — 7/10 V cochés ; 3 défauts dont **2 critiques** invalidant des invariants K-9 :

| Critère FAIL | Constat |
|---|---|
| ≥ 1 SLO sans collector (effectif prod) | `SloEvaluatorJob` n’est planifié nulle part (`app/Console/Kernel.php` ne contient pas le `->job(...)->everyFiveMinutes()->withoutOverlapping()` mentionné dans `VERIFY_K9_OBSERVABILITY_2026-04-18.md §G5`). En prod, **0 évaluation, 0 alerte breach**. |
| Correlation chain cassée | 3 listeners outbox (Order Created / Order Status Changed / Item Availability Changed) génèrent un `Str::uuid()` neuf au lieu de réutiliser le `correlation_id` HTTP/middleware. Le KDS et l’outbox **portent un `correlation_id` ≠ celui** émis par le kiosk → grep cross-surface impossible (AX12-02 confirmé non résolu). |
| PII non scrubbé | **Non** — le scrub PII est conforme (heal P2 effectif côté FE & BE). |

→ Bien que le critère « PII non scrubbé » soit OK, la règle T16 stipule **PASS uniquement si 10/10 V cochées** ; les 3 V partiels suffisent à classer **FAIL**.

---

## 1. Méthodologie

- Lecture des artefacts cités (SLO doc, ADR K-9, VERIFY K-9, AUDIT_KIOSK_110_OBSERVABILITY_PERF, K-10 ACCEPTANCE).
- Inspection du code clé : `SloMetricCollector`, `SloEvaluatorJob`, `KioskEventController`, `CspReportController`, `CorrelationIdMiddleware`, `EventContract`, listeners outbox, `correlation.js`, `heatmap.js`, `sentry.js`, `WebSocketService.js`, `bootstrap.js`, `master.blade.php`, `config/logging.php`.
- Croisement statique : whitelists FE/BE, scheduler Laravel, registration middleware, branchement runtime des observers POC.
- Aucune commande exécutée, aucun fichier modifié.

---

## 2. Checklist V1..V10

### V1 — 5 SLO documentés ✅

`docs/observability/SLO_KIOSK_2026-04-18.md` §1 expose les 5 SLO avec **target / warn / breach / fenêtre / SLI source / agrégateur / owner** :

| # | SLO | Cible | Warn | Breach | Fenêtre | Source SLI | Owner |
|---|-----|-------|------|--------|---------|------------|-------|
| 1 | Uptime kiosk | ≥ 99.5 % | 99.0 % | < 98.5 % | 30 j | `ActionLog` kiosk | Ops Kiosk |
| 2 | TTI p95 | < 3000 ms | ≥ 3000 ms | ≥ 5000 ms | 7 j | event `perf.lcp` | Frontend Kiosk |
| 3 | Order completion rate | ≥ 95 % | 92 % | < 90 % | 7 j | ratio order/wizard | Produit |
| 4 | Echo reconnect p95 | < 5000 ms | ≥ 5000 ms | ≥ 10000 ms | 24 h | `observability.ws_reconnect` | Ops |
| 5 | Payment success rate | ≥ 98 % | 96 % | < 95 % | 7 j | ratio paid/attempted | Produit + Ops |

Toutes les SLI sont scoppées `branch_id` (K-8).
Cibles dupliquées en code : `SloMetricCollector::SLO_TARGETS` (`app/Services/Observability/SloMetricCollector.php` L30-L36) — alignement strict avec le doc.

### V2 — `SloMetricCollector` sans double-comptage ✅

`SloMetricCollector::evaluate()` invoque chaque collector **une seule fois** par métrique et par branche :

```55:118:app/Services/Observability/SloMetricCollector.php
public function collectUptime(Branch $branch, int $windowDays): float { … }
public function collectTtiP95(Branch $branch, int $windowDays): ?float { … }
public function collectOrderCompletionRate(Branch $branch, int $windowDays): float { … }
public function collectEchoReconnectP95(Branch $branch, int $windowHours): ?float { … }
public function collectPaymentSuccessRate(Branch $branch, int $windowDays): float { … }
```

- Lecture SELECT pure (DISTINCT windows pour uptime, COUNT pour completion/payment), pas d’INSERT.
- Filtrage `branch_id` partout (`->where('branch_id', $branch->id)`).
- ⚠️ Mineur : `collectUptime` utilise `strftime("%s", created_at)` (SQLite), commenté « fallback MySQL clamp ». En prod MySQL, l’expression sera remplacée silencieusement par 0 (clamp 0..1) — la métrique remontera systématiquement 0 ou 1 sans bruit. Non bloquant pour la sémantique « no_data → ok » mais à corriger pour reporting fidèle.

Pas de double-comptage détecté.

### V3 — `SloEvaluatorJob` persiste `slo_evaluation` + Slack breach ⚠️ partiel (FAIL effectif)

Code OK (`app/Jobs/Observability/SloEvaluatorJob.php`) :

- `persistSnapshot()` crée un `ActionLog{action='slo_evaluation', resource='kiosk', details=JSON{category, snapshot, at}}` + log canal `observability` info `slo_evaluation`.
- `dispatchAlerts()` itère le snapshot, et pour chaque `status === 'breach'` : `Log::channel('observability')->alert('slo_breach', …)` **et** `Log::channel('slack')->alert(message, payload)` enveloppé dans try/catch (fallback observability si webhook absent — conforme `SLO_KIOSK §3.4`).

⛔ **Mais** : le job n’est **planifié nulle part**. `app/Console/Kernel.php` ne contient que :

```16:33:app/Console/Kernel.php
$schedule->call(function () { /* purge-expired-otps */ })->everyFifteenMinutes()->name('purge-expired-otps')->withoutOverlapping();
$schedule->command('foodking:outbox:rescue')->everyMinute()->withoutOverlapping();
```

- Aucun `$schedule->job(new SloEvaluatorJob)->everyFiveMinutes()->withoutOverlapping()`.
- `routes/console.php` : aucun match `Slo|observability`.
- Aucun service provider ne dispatch le job.

Conséquence : en production le pipeline E (collector + alerting + ActionLog snapshot) est **du code mort**. Le `VERIFY_K9_OBSERVABILITY_2026-04-18.md §G5` affirme à tort `app/Console/Kernel.php : scheduled ->everyFiveMinutes()->withoutOverlapping()` — cette ligne **n’existe pas** dans la copie auditée. Alignement avec le **constat T02** (worktree `kiosk-p93` n’a pas la planification SLO).

### V4 — Correlation chain end-to-end ⚠️ cassée à l’étage outbox/broadcast (FAIL)

Maillon par maillon :

1. **Client kiosk** ✅ — `resources/js/observability/correlation.js` génère/persiste un UUID v4 dans `localStorage.__kioskCorrelationId`, ajoute `X-Correlation-ID` aux requêtes `/api/frontend/*` via `installCorrelationInterceptor` (idempotent), et l’interceptor est wiré côté `bootstrap.js` :
   ```22:23:resources/js/bootstrap.js
   import { installCorrelationInterceptor } from './observability/correlation';
   installCorrelationInterceptor(window.axios);
   ```
2. **API Laravel** ✅ — `CorrelationIdMiddleware` (registré à la fois dans `web` et `api` groups : `app/Http/Kernel.php` L40 et L50) lit le header, génère sinon, le réinjecte dans la requête, dans `Log::withContext`, et le réémet dans la réponse.
3. **Domain event envelope** ✅ — `EventContract::buildEnvelope()` propage `correlation_id => $event->correlation_id` (envelope L67).
4. **Listeners outbox / broadcast** ❌ — Les 3 listeners écrasent ce contrat avec un UUID neuf :

```31:33:app/Listeners/PersistOrderStatusChangedToOutbox.php
'broadcast_as' => 'OrderStatusChanged',
'correlation_id' => (string) Str::uuid(),
```

```32:34:app/Listeners/PersistOrderCreatedToOutbox.php
'broadcast_as' => 'OrderCreated',
'correlation_id' => (string) Str::uuid(),
```

```48:50:app/Listeners/PersistItemAvailabilityChangedToOutbox.php
'broadcast_as'   => 'ItemAvailabilityChanged',
'correlation_id' => (string) Str::uuid(),
```

→ Le KDS, l’outbox et tous les downstream broadcastés portent un `correlation_id` **différent** de celui émis par le kiosk et loggé par les couches HTTP. Grep cross-surface (`storage/logs/laravel-*` ↔ `outbox` ↔ `KDS Sentry breadcrumb`) impossible. C’est exactement l’**AX12-02** identifié par `reports/review/AUDIT_KIOSK_110_OBSERVABILITY_PERF_2026-04-19.md` — non résolu dans cette copie.

5. **CSP report endpoint** ✅ — anonyme + middleware `CorrelationIdMiddleware` (groupe `api`) : test `CorrelationIdEndToEndTest::test_correlation_id_works_on_anonymous_routes_csp_report` couvre le cas (L79-L89).

Tests présents (`tests/Feature/Observability/CorrelationIdEndToEndTest.php` × 4 + `tests/Feature/CorrelationIdMiddlewareTest.php` + `tests/js/kioskCorrelationPropagation.spec.js` × 11) — **mais aucun n’assertit le saut HTTP → broadcast**. Le test « end-to-end » s’arrête au header de réponse / log context, manquant précisément le maillon cassé.

### V5 — Heatmap opt-in RGPD strict ⚠️ POC dormant

- `resources/js/observability/heatmap.js` expose `installHeatmap(stepResolver, trackFn)` / `uninstallHeatmap()` ; pattern correct (l’opt-in est délégué à l’appelant qui watch `consentAnalytics`, pas de listener installé tant qu’on n’appelle pas `installHeatmap`).
- Buckets 10×10 + clamp + payload réduit à `{step, zone_x, zone_y}` — zéro PII.
- Test `tests/js/kioskHeatmapConsent.spec.js` × 7 valide bucketing, idempotence, no-emit si stepResolver→null, exclusion de la valeur input.

⛔ **Aucun appelant** : `Grep installHeatmap` côté `resources/` ne renvoie que la docstring du module et le spec Vitest. `KioskAppComponent.vue::mounted()` watch bien `consentAnalytics` mais **uniquement pour `kioskAnalytics.setConsent`** (L673-L681), pas pour la heatmap. La heatmap est donc **POC mort** côté runtime — l’opt-in « strict » revient à zéro émission par défaut, ce qui est *trivialement RGPD-safe* mais ne livre aucune donnée. À documenter ou wirer.

### V6 — CSP report throttled + PII scrubbed ✅

- Route : `POST /api/frontend/csp-report` middleware `throttle:20,1` (`routes/api.php` L981-L988) — anonyme par design.
- Controller : `CspReportController::store()` log canal `observability` `info('csp_violation', $safe)` ou `warning('csp_violation.malformed', …)` selon shape ; **204 No Content** systématique.
- `sanitizeUrl()` masque `token, password, email, phone, pin, code, api_key` dans les query params (URL + sources) puis tronque à 1000 chars. Truncate global 120/300 chars sur directives & UA.
- Aucune persistance DB (volume), rétention via canal Monolog 90 j. Conforme ADR-5.
- Tests `tests/Feature/Observability/CspReportEndpointTest.php` × 4 (valid log, malformed warning, redaction, anonyme).
- Meta `Content-Security-Policy-Report-Only` avec `report-uri /api/frontend/csp-report` confirmée dans `resources/views/master.blade.php` L24-L36.

### V7 — Canal `observability` 90 j JSON ✅

```144:150:config/logging.php
'observability' => [
    'driver' => 'daily',
    'path' => storage_path('logs/observability.log'),
    'level' => 'info',
    'days' => 90,
    'formatter' => \App\Logging\JsonFormatter::class,
],
```

Test `ObservabilityLogChannelTest::test_observability_channel_is_configured` verrouille (`daily` + `90` + `info` + path `observability.log` + `JsonFormatter`). Garde-fou `security`/`hardware` préservés.

### V8 — Whitelist `observability.*` × 5 ✅

`KioskEventController::ALLOWED_ANALYTICS_EVENTS` L180-L184 contient exactement :
1. `observability.ws_reconnect`
2. `observability.heatmap_click`
3. `observability.slo_breach`
4. `observability.correlation_trace`
5. `observability.csp_violation`

Test paramétré `ObservabilityEventWhitelistTest::test_observability_event_names_are_whitelisted` itère les 5 (provider + payload type-safe) ; `test_unknown_observability_subfamily_is_rejected` couvre la régression (422 sur `observability.not_a_real_sli`) ; `test_slash_alias_accepts_observability_events` valide l’alias `/api/frontend/kiosk/event`.

Heal P1 `add_to_cart_guarded_against_double_click` également présent (L104) avec test dédié.

### V9 — Scrub PII heal P2 ✅

**Backend** `app/Observability/SentryBridge.php` :

```36:52:app/Observability/SentryBridge.php
public const SCRUB_BODY_KEYS = [
    'password', 'password_confirmation', 'current_password',
    'token', 'access_token', 'refresh_token', 'api_key',
    'email', 'phone', 'mobile', 'address',
    'card_number', 'cvv', 'cvc', 'pin',
    'secret', 'private_key',
    // K-9 heal P2 (verifier)
    'device_id', 'session_id', 'session_cookie',
];
public const SCRUB_USER_KEYS = [
    'email', 'ip_address', 'phone', 'mobile', 'address',
    // K-9 heal P2
    'username', 'name',
];
```

`scrubArray()` couvre `request.headers` (Authorization/Cookie/X-Api-Key/X-Csrf-Token/Proxy-Authorization), `request.cookies` (REDACTED total), `request.data` (récursif via `scrubBodyArray`), `user.*`, `extra.*`. Tests `SentryBridgeTest` couvrent headers, cookies, body data, user, extra, et préservation de `branch_id` / `correlation_id`.

**Frontend** `resources/js/observability/sentry.js` :

```12:38:resources/js/observability/sentry.js
const SENSITIVE_KEY_FRAGMENTS = [
    'password','token','email','phone','cookie','device_id','ip_address',
    'session_id','username','card','cvv','secret','authorization',
];
const SENSITIVE_QUERY_PARAMS = new Set([
    'email','token','card','cvv','password','authorization','secret',
    'session_id','device_id',
]);
```

- `isSensitiveKey()` fait un `includes(fragment)` → catches `session_cookie` (via `cookie`) et toutes variantes case-insensitives. ⚠️ Mineur : la clé exacte `name` n’est pas listée FE (à dessein — collision `branch_name`, `user_name`, `merchant_name`). Le scrub `name` est uniquement BE (où la portée est `user.*` donc safe). Acceptable.
- `beforeSend` orchestre request/cookies/breadcrumbs/tags/extra/contexts/exception via `scrubObject`. Cookies → `REDACTED` via `sanitizeRequest()` L178-L180.
- ALLOWED_TAGS strict (5 entrées : branch_id, kiosk_machine_id, correlation_id, release, environment) → tags inconnus filtrés.

Regex EMAIL/PHONE/CARD/CVV exécutées sur strings via `scrubStringWithContext` ; la regex CARD (`\b(?:\d[ -]*?){13,19}\b`) capte 13-19 digits avec séparateurs.

### V10 — Backlog K-10.1 ✅

`tasks/k-hardening/ADR_K10_ACCEPTANCE_SCOPE_2026-04-19.md` §1 + `K_TRACKER.md` ligne K-10 mentionnent explicitement :
- UI admin SLO timeline (visu)
- Heatmap renderer (POC dashboard)
- Purge ActionLog dédiée (catégorie `slo_evaluation` + `heatmap_click`)
- CSP `Report-Only → enforce`
- Pusher multi-tenant (per-branch channels)

OTel non-prévu K-9 (ADR_K9 §ADR-1) et reporté.

---

## 3. Synthèse par Gate

| V | État | Bloquant ? |
|---|------|------------|
| V1. 5 SLO documentés | ✅ | — |
| V2. Collector sans double-compte | ✅ (mineur SQLite/MySQL uptime) | — |
| V3. SloEvaluatorJob persiste + Slack breach | ⚠️ code OK, **schedule manquant** | 🔴 oui |
| V4. Correlation chain end-to-end | ❌ outbox écrase l’UUID | 🔴 oui |
| V5. Heatmap opt-in RGPD strict | ⚠️ POC non wiré | 🟡 cosmétique |
| V6. CSP throttled + scrubbed | ✅ | — |
| V7. Canal observability 90j JSON | ✅ | — |
| V8. Whitelist observability × 5 | ✅ | — |
| V9. PII scrub heal P2 | ✅ | — |
| V10. Backlog K-10.1 | ✅ | — |

---

## 4. Top 3 actions correctives (FAIL → action)

1. **Planifier `SloEvaluatorJob`** (croise T02 / T19). Ajouter dans `app/Console/Kernel.php::schedule()` :
   ```php
   $schedule->job(new \App\Jobs\Observability\SloEvaluatorJob())
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->name('slo-evaluator');
   ```
   Couvrir par un test « `Schedule::events()` contient `slo-evaluator` cadencé 5 min ». Aligner `VERIFY_K9_OBSERVABILITY_2026-04-18.md §G5` avec la réalité ou rouvrir le ticket K-9.

2. **Réparer la propagation `correlation_id` dans les listeners outbox** (lien T09 AX12-02). Dans `PersistOrderCreatedToOutbox`, `PersistOrderStatusChangedToOutbox`, `PersistItemAvailabilityChangedToOutbox` :
   ```php
   'correlation_id' => request()?->header('X-Correlation-ID')
                       ?? \Illuminate\Support\Facades\Log::sharedContext()['correlation_id']
                       ?? (string) Str::uuid(), // last-resort fallback
   ```
   Ajouter un test feature qui POST une commande avec `X-Correlation-ID: foo-bar` et asserte que le `outbox.correlation_id` (ou l’envelope `EventContract::buildEnvelope` du `DomainEvent` chargé) vaut bien `foo-bar`.

3. **Wirer la heatmap au cycle consent** (ou marquer le module *deprecated/POC-only*). Dans `KioskAppComponent.vue::mounted()`, ajouter un watch :
   ```js
   import { installHeatmap, uninstallHeatmap } from '@/observability/heatmap';
   this._unwatchHeatmap = this.$store.watch(
     (s) => s.kioskSettings?.consentAnalytics,
     (on) => on
       ? installHeatmap(() => this.currentWizardStep, kioskAnalytics.track)
       : uninstallHeatmap(),
     { immediate: true }
   );
   ```
   À défaut, déplacer `heatmap.js` sous `resources/js/observability/_dormant/` et noter explicitement dans le backlog K-10.1 que la heatmap est livrée mais dormante côté runtime.

---

## 5. Annexes — Preuves supplémentaires

### A. Fichiers audités (chemins absolus, copie `testttt-kiosk-p93`)

- `docs/observability/SLO_KIOSK_2026-04-18.md`
- `app/Services/Observability/SloMetricCollector.php`
- `app/Jobs/Observability/SloEvaluatorJob.php`
- `app/Console/Kernel.php` · `routes/console.php`
- `app/Http/Controllers/Frontend/KioskEventController.php`
- `app/Http/Controllers/Frontend/CspReportController.php`
- `app/Http/Middleware/CorrelationIdMiddleware.php` · `app/Http/Kernel.php`
- `app/Domain/Events/EventContract.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php` · `…/PersistOrderStatusChangedToOutbox.php` · `…/PersistItemAvailabilityChangedToOutbox.php`
- `app/Observability/SentryBridge.php`
- `config/logging.php` · `routes/api.php` · `resources/views/master.blade.php`
- `resources/js/observability/correlation.js` · `…/heatmap.js` · `…/sentry.js`
- `resources/js/services/WebSocketService.js` · `resources/js/bootstrap.js`
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (watcher consent)
- `tests/Feature/Observability/{SloEvaluatorJobTest,CorrelationIdEndToEndTest,CspReportEndpointTest,ObservabilityEventWhitelistTest,ObservabilityLogChannelTest,SentryBridgeTest}.php`
- `tests/js/{kioskCorrelationPropagation,kioskHeatmapConsent}.spec.js`
- `tasks/k-hardening/{PLAN_K9_OBSERVABILITY,ADR_K9_OBSERVABILITY_STRATEGY,ADR_K10_ACCEPTANCE_SCOPE,K_TRACKER}.md`
- `reports/execution/VERIFY_K9_OBSERVABILITY_2026-04-18.md`
- `reports/review/AUDIT_KIOSK_110_OBSERVABILITY_PERF_2026-04-19.md`

### B. Constats secondaires (non bloquants)

- **Uptime SQLite-only** : `SloMetricCollector::collectUptime` utilise `strftime` ; en MySQL le résultat sera 0 (clamp 0..1 = 0 → status `breach`). Risque de fausse alerte si MySQL en prod. À factoriser via Builder Eloquent + `DB::raw` portable, ou dispatcher `slo.uptime` via heartbeat backend pré-calculé.
- **Test correlation chain incomplet** : aucun test ne couvre le saut request → outbox/broadcast. Recommander un `OrderCorrelationChainTest` qui POST un order kiosk avec `X-Correlation-ID` et asserte le même ID dans `outbox` + envelope du `DomainEvent` ou du `BroadcastEvent` capturé.
- **`Log::channel('slack')` sans webhook** : graceful (try/catch), mais aucun test n’asserte le fallback (couverture incomplète sur `slo_breach.slack_unreachable`).
- **Aucun test JS sur `WebSocketService` reconnect SLI** (le module est testé indirectement dans `bootstrap.js` only). Brancher un `tests/js/wsReconnectLatency.spec.js` clarifierait la couverture G3.
- **Heal P2 FE** : `name` non listé FE (acceptable mais à documenter — collisions `branch_name` etc.).

---

## 6. Si FAIL → action

→ Déclencher **T16b** (`generalPurpose`) avec scope :
1. Patch scheduler (action 1).
2. Patch listeners outbox (action 2) + test feature dédié.
3. Décision wire vs deprecate heatmap (action 3).
4. Re-run `SloEvaluatorJobTest`, `CorrelationIdEndToEndTest`, `ObservabilityEventWhitelistTest`, `kioskHeatmapConsent.spec.js`, `kioskCorrelationPropagation.spec.js`.
5. Mettre à jour `VERIFY_K9_OBSERVABILITY_2026-04-18.md §G5` (mensonge documentaire à corriger).

---

*Rapport généré 2026-04-20 par sous-agent `explore` (readonly), audit profond conforme `tasks/audit-orchestration/16_TASK_OBSERVABILITY_K9_DEEP_2026-04-20.md`.*
