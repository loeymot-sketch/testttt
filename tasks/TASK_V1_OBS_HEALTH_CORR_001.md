# TASK_V1_OBS_HEALTH_CORR_001 — /health + correlation_id + structured logging

## Meta
- **Priority** : P1
- **Vague** : 4 — Data, observabilité, tests
- **PRIMARY_MODEL** : Composer
- **TEST_STRATEGY** : `local-validation`
- **DEPENDS_ON** : TASK_V1_SYNC_BACKBONE_001
- **BLOCKS** : —
- **Estimation** : 2 j-h

## Contexte

Aucun endpoint de supervision. Impossible de savoir si Redis/Pusher/Queue/DB sont sains sans ssh + commandes manuelles. Logs non corrélés — un incident commande = 5 fichiers de log à crosscheck manuellement.

V1 : socle d'observabilité minimal mais suffisant pour opérer en production et débugger rapidement.

## Acceptance Criteria
- [ ] Endpoint `GET /health` (IP whitelist monitoring seulement) → JSON avec statut DB, Redis, Queue, Broadcast, Pusher, version app, uptime.
- [ ] Endpoint `GET /health/live` (200 si process up) pour liveness probe orchestrateur.
- [ ] Endpoint `GET /health/ready` (200 si tout sain, 503 si un sous-système KO) pour readiness probe.
- [ ] Middleware `CorrelationIdMiddleware` : génère UUID par requête, expose via header `X-Correlation-ID`, injecte dans `Log::withContext(['correlation_id' => $id])`.
- [ ] Format log JSON structuré en production : un nouveau channel `production_json` avec formatter Logstash/JSON, timestamps ISO 8601, niveau, correlation_id, branch_id, user_id, message, context.
- [ ] Propagation correlation_id vers jobs queue (via `HandleCorrelationId` trait sur base job).
- [ ] Propagation correlation_id vers events outbox (colonne dédiée déjà créée par OUTBOX_001).
- [ ] `docs/OBSERVABILITY.md` livré : comment consulter /health, format log, exemples trace correlation_id.

## Scope

### SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `app/Http/Controllers/HealthController.php` | nouveau | Write | No | No |
| `routes/web.php` + `routes/api.php` | ajout routes /health | Write | No | No |
| `app/Http/Middleware/CorrelationIdMiddleware.php` | nouveau | Write | No | No |
| `app/Http/Kernel.php` | enregistrer middleware dans web+api groups | Write | No | No |
| `config/logging.php` | nouveau channel `production_json` | Write | No | No |
| `app/Logging/JsonFormatter.php` | formatter logstash-like | Write | No | No |
| `app/Jobs/BaseJob.php` (ou trait) | propagation correlation_id | Write | No | No |
| `app/Http/Middleware/HealthIpWhitelistMiddleware.php` | optionnel | Write | No | No |
| `docs/OBSERVABILITY.md` | doc | Write | No | No |

### SUBSYSTEMS_OFF_LIMITS
- APM / tracing distribué complet (OpenTelemetry) — V1.5.
- Dashboards Grafana — V1.5.
- Métriques Prometheus — V1.5.
- Alerting PagerDuty — V1.5.

## Invariants at Risk
- [x] None
- [ ] Backend pricing SSOT
- [ ] OrderStatus enum
- [ ] branch_id data isolation
- [ ] Dispatch after DB commit
- [ ] OrderService / FrontendOrderService symmetry
- [ ] Frozen zone

## Execution Steps

### E1 — HealthController
```php
class HealthController {
    public function full(): JsonResponse {
        return response()->json([
            'status' => $this->allOk() ? 'ok' : 'degraded',
            'version' => config('app.version', 'dev'),
            'uptime' => now()->diffInSeconds(Cache::rememberForever('app_boot_at', fn() => now())),
            'subsystems' => [
                'db' => $this->checkDb(),
                'redis' => $this->checkRedis(),
                'queue' => $this->checkQueue(),
                'broadcast' => $this->checkBroadcast(),
                'pusher' => $this->checkPusher(),
            ],
        ]);
    }
    public function live(): Response { return response('OK', 200); }
    public function ready(): JsonResponse { /* 200 ou 503 */ }
}
```

### E2 — Checks
- `db` : `DB::connection()->getPdo()` + simple query `SELECT 1`.
- `redis` : `Redis::ping()`.
- `queue` : `Queue::size('default')` + `Queue::size('high')`.
- `broadcast` : config driver != null.
- `pusher` : HTTP HEAD sur `pusher.api.url` (timeout 1s).

### E3 — IP whitelist
Middleware simple : `env('HEALTH_IPS_ALLOWED')` CSV. 403 sinon. `/health/live` et `/health/ready` publics (orchestrateur les appelle sans auth).

### E4 — CorrelationIdMiddleware
```php
public function handle($req, Closure $next) {
    $cid = $req->header('X-Correlation-ID') ?: (string) Str::uuid();
    $req->headers->set('X-Correlation-ID', $cid);
    Log::withContext(['correlation_id' => $cid]);
    $res = $next($req);
    $res->headers->set('X-Correlation-ID', $cid);
    return $res;
}
```

### E5 — JSON formatter production
```php
// config/logging.php
'production_json' => [
    'driver' => 'monolog',
    'handler' => StreamHandler::class,
    'with' => ['stream' => 'php://stderr'],
    'formatter' => \App\Logging\JsonFormatter::class,
],
```
Formatter : pipe vers Logstash/Datadog/Papertrail sans retouche.

### E6 — Propagation jobs
Trait `HandleCorrelationId` :
```php
public function __construct(...) {
    $this->correlationId = Log::getContext()['correlation_id'] ?? (string) Str::uuid();
}
public function handle() {
    Log::withContext(['correlation_id' => $this->correlationId]);
    // ...
}
```
Appliquer sur `DispatchDomainEventsJob` (livré par OUTBOX_001) + autres jobs métier.

### E7 — Documentation
`docs/OBSERVABILITY.md` :
- Comment interroger `/health` (URL, auth).
- Schéma réponse complet.
- Format log JSON avec exemple ligne.
- Recipe debug : comment tracer une commande via `grep correlation_id xxx` dans logs.

### E8 — Tests
1. `HealthControllerTest` : retourne JSON conforme schema. 503 si `Queue::size` throw.
2. `CorrelationIdMiddlewareTest` : UUID généré si absent, propagé si présent.

## SYMMETRY_NOTE
N/A.

## GATE_CONDITIONS
- **Gate requise** : NON.
- Stop-gate si : demande d'intégrer un APM externe (Datadog APM, NewRelic) — V1.5.

## Status
- [ ] Pending plan
- [ ] Plan approved
- [ ] In execution
- [ ] Validation
- [ ] Audit
- [ ] Gate open
- [ ] Closed
