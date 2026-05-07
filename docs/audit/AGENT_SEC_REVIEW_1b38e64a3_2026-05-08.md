# AGENT_SEC review — commit `1b38e64a3` (ULTRA-V1.x batch)

- **Date** : 2026-05-08
- **Reviewer** : Agent SECURITY (rôle GSTACK), persona R1-sec hostile
- **Commit** : `1b38e64a3` "[ULTRA-V1.x] 5 plans GSTACK exécutés en parallèle multi-agents"
- **Surface** : 42 fichiers (+5432 / -319)
- **Méthode** : 10 hypothèses adversaires S1..S10, file:line evidence, trust-but-verify

> Verdict bref : **GO security V1**. Aucun P0/P1 trouvé. Les findings réels sont
> tous *réfutés* ou *by design + gate compensatoire*. Deux observations
> INFO-level honnêtement levées en §"Limitations".

---

## 1. Bilan par hypothèse

### S1 — Authz endpoint `/admin/observability/outbox` accessible par `pos_operator` ?

**RÉFUTÉ.**

Evidence : `app/Http/Controllers/Admin/Observability/SyncOverviewController.php`
lignes 47-56 — un middleware Spatie `role:Admin|Tenant Admin` est appliqué
**explicitement** aux trois actions sensibles :

```php
$this->middleware(['role:Admin|Tenant Admin'])->only(
    'outboxOverview',
    'outboxRetryFailed',
    'outboxDrainFailed'
);
```

Test sentinel direct : `tests/Feature/Observability/OutboxOverviewControllerTest.php`
lignes 52-69 vérifie qu'un Branch Manager (rôle adjacent le plus probable de
slipper) reçoit 403 sur les trois endpoints (GET, retry, drain). Test 35-44
verrouille Chef = 403. Pas de bypass possible via `pos_operator` — le rôle
n'a même pas `kitchen-display-system` au sens du middleware parent (ligne 45)
mais surtout n'a pas le rôle Admin/Tenant Admin du middleware enfant.

### S2 — Branch isolation observability : manager branch=2 voit-il branch=1 ?

**RÉFUTÉ avec nuance "by design".**

L'endpoint `outboxOverview` est **délibérément** global (toutes branches
agrégées), cf doc-bloc lignes 282-303 et requête ligne 308 :
`DB::table('domain_events')->whereNull('dispatched_at')` — pas de filtre
branch_id. C'est compensé par la gate `Admin|Tenant Admin` qui interdit
tout opérateur non-tenant-admin (un Branch Manager n'a aucune visibilité,
puisqu'il est 403 avant même d'arriver au query).

Divergence assumée vs `index()` (ligne 188 : `resolveBranchScope` appelle
le branch-isolation gate) qui, lui, est gated par `permission:kitchen-display-system`
et donc accessible aux opérateurs branch-scopés. Le designer a fait le choix
d'élever le gate proportionnellement à l'élargissement du scope. Cohérent.

### S3 — 422 leak info "POS catalog requires branch_id" → énumération branches ?

**RÉFUTÉ.**

Evidence : `app/Http/Controllers/Admin/ItemController.php` lignes 59-64.
Le message est statique, ne contient ni branch_id valide ni nombre de
branches existantes. Il fire **uniquement** quand `branch_id` est null/<1
ET surface=pos ET caller a `pos`. Aucun side-channel. Côté tests, le
sentinel `PosCatalogRequiresBranchSentinelTest::test_ItemController_aborts_422_for_surface_pos_without_branch`
verrouille la string littérale.

### S4 — Bypass `user()->can('pos')` ? Admin sans `pos` passe-t-il le guard ?

**RÉFUTÉ + comportement intentionnel.**

Evidence — chaîne de garde dans `ItemController::index` (lignes 46-77) :

1. **forcePosRuntimeBranchScope** (ligne 259-273) : si user a `pos` ET
   PAS `items_show` (= POS Operator pur), force `branch_id` depuis
   `user->branch_id` (ou abort 403 si `branch_id < 1`).
2. **Guard 422 surface=pos** (lignes 60-64) : ne fire QUE si caller a
   `can('pos')`.
3. **applyDefaultPosSurfaceForPosRuntimeUser** (ligne 280-292) : pour les
   POS-only users (pos sans items_show), force `surface=pos` par défaut.

Vérification rôles via `database/seeders/RolePermissionTableSeeder.php`
lignes 30, 36, 85 :

| Rôle              | a `pos` ? | a `items_show` ? | Comportement                                    |
|-------------------|-----------|------------------|-------------------------------------------------|
| Admin             | Oui (toutes) | Oui            | Étape 1 no-op, étape 2 fire si `?surface=pos` sans branch (intentionnel CV1) |
| Tenant Admin      | Oui (toutes) | Oui            | Idem Admin                                      |
| Branch Manager    | Oui       | Non              | Étape 1 force `branch_id=user->branch_id` → étape 2 ne fire pas |
| POS Operator      | Oui       | Non              | Idem Branch Manager                             |
| Chef              | Non       | Non              | Étape 2 ne fire pas (`! can('pos')`), comportement existant préservé |

Pas de bypass. La condition `user()->can('pos')` est explicitement large
(elle inclut Admin) parce que c'est précisément le cas que le fix
CV1-POS-AVAILABILITY-LIVE-001 cible (admin global avec branch_id=0
appelant `?surface=pos` sans préciser branch).

### S5 — Bootstrap script secrets dans `storage/logs` ? Permissions PID files ?

**RÉFUTÉ.**

Evidence : `scripts/ci-bootstrap-websockets-harness.sh` — grep `PUSHER`,
`FISCAL`, `SECRET`, `env` retourne ZÉRO match dans le script. Le script ne
lit ni n'écrit aucun secret. Il :

- redirige stdout/stderr des subprocess (`websockets:serve` ou `soketi`)
  vers `${WS_LOG}` (`>>"${WS_LOG}" 2>&1` lignes 107-110, 132, 141)
- écrit `<backend>:<pid>` dans `${PIDS_FILE}` (ligne 156)
- fait des probes TCP locales

Si un subprocess (websockets:serve) loggait sa config Pusher dans son stdout,
elle se retrouverait dans `WS_LOG`. **Mais** le workflow CI utilise des
**creds non-sensibles publiquement-connus** (cf S6) — pas de leak réel.

Permissions PID file : `${PIDS_FILE}="${LOG_DIR}/ci-harness.pids"` — hérite
des permissions du dossier `storage/logs/` (typiquement 0775 dir, 0664
file). Suffisant pour un environnement CI éphémère. Non-blocker V1.

### S6 — GitHub Actions workflow utilise des `${{ secrets.* }}` exposés ?

**RÉFUTÉ — strictement aucun secret réel n'est utilisé.**

Evidence : `.github/workflows/ci-sync-rupture-harness.yml` lignes 81-104.
Tout le bloc `env:` contient des **valeurs hardcodées non-sensibles** :

```yaml
PUSHER_APP_ID: app-id
PUSHER_APP_KEY: app-key
PUSHER_APP_SECRET: app-secret
FISCAL_AUDIT_SECRET: testing-fiscal-audit-secret-padding-48chars-ok
FISCAL_Z_REPORT_SECRET: testing-fiscal-zreport-secret-padding-48chars-ok
```

Aucune référence à `${{ secrets.*  }}` dans le fichier. C'est un workflow
CI hermétique pour test du pipeline Pusher → MySQL local + soketi local.
Conséquence directe : **aucun secret exposable** dans le `WS_LOG`, dans
le DB MySQL service ou dans les artefacts CI (`upload-artifact` ligne
181-185 charge `storage/logs/websockets-ci.log` qui ne peut contenir
que `app-key`, déjà publique).

À surveiller à futur : si quelqu'un câble un vrai broker Pusher (cluster
managé) sur ce workflow, il faudra repasser sur `${{ secrets.PUSHER_APP_KEY }}`
et masquer. Pour V1, RAS.

### S7 — `kdsInflight` Vuex persisté → forge `recentlyDeavailable` malicieuse ?

**RÉFUTÉ.**

Evidence : `resources/js/store/index.js` lignes 240-279 — block
`createPersistedState({ paths: [...] })`. Liste explicite des modules
persistés : `auth`, `globalState`, `frontendCart`, `frontendSignup`,
`GuestSignup`, `posCart`, `tableCart`, `kioskCart.*`, `kioskSettings.*`.

**`kdsInflight` n'est PAS dans cette liste.** L'état est strictement
runtime, jamais sérialisé en localStorage. Confirmé par le commentaire
docstring du module (`resources/js/store/modules/kdsInflight.js` lignes
17-19 : "State is plain JS — NOT persisted (volatile, purely runtime
warning)").

Conséquence : un client malveillant ne peut pas forger ces flags via
localStorage. Il pourrait dispatcher une mutation Vuex via la console
devtools, mais c'est local à sa session — sans effet sur le KDS d'un
autre opérateur (broadcast event source = Pusher serveur uniquement).

Branch isolation aussi présente côté action (`markDeavailable` lignes
110-124) : si l'event Pusher est branch-scopé ET le KDS context est
connu, skip si mismatch. Pas d'interférence cross-branch.

### S8 — Dashboard JSON shape expose payload PII / correlation_id ?

**RÉFUTÉ pour le code NOUVEAU (cible de l'audit).**

Evidence : `outboxOverview()` lignes 311 — sélection explicite des colonnes :

```php
->select(['id', 'event_type', 'aggregate_type', 'aggregate_id', 'branch_id',
          'attempts', 'last_error', 'occurred_at', 'created_at'])
```

**`payload` est explicitement omis** (la colonne JSON contient les
order items, customer info, fiscal data → omission correcte).
**`correlation_id` aussi omis** sur le payload outboxOverview — divergence
notable avec l'ancien `index()` (ligne 138) qui lui expose
`correlation_id` dans `recent_failures`. Ce dernier n'est pas modifié
par ce commit (pre-existing surface).

`failed_jobs.rows` (lignes 470-483) tronque `exception` à 500 chars de
la première ligne uniquement — `exception_first_line`, pas la stack
complète. Limite le risque de leak de query/payload via stack trace
StackOverflow-style.

INFO-level (non-blocker V1) : `last_error` (string DB) et
`exception_first_line` peuvent contenir des fragments SQL/payload selon
la nature de l'exception. Surface admin-only (Admin / Tenant Admin via
gate S1), donc risque contained.

### S9 — Route binding observability : auth + verified, ou public ?

**RÉFUTÉ.**

Evidence : `routes/api.php` ligne 242 :

```php
Route::prefix('admin')->name('admin.')
    ->middleware(['installed', 'apiKey', 'auth:sanctum', 'localization', 'throttle:admin-mutation'])
    ->group(function () {
```

Le block englobe (par accolade) jusqu'au matching `}` qui ferme à la
fin du fichier admin. Les 3 nouveaux endpoints (lignes 968-974) sont
DANS ce groupe :

- GET `/observability/outbox` — auth:sanctum ✓
- POST `/observability/outbox/retry-failed` — auth:sanctum + `throttle:10,1` ✓
- POST `/observability/outbox/drain-failed` — auth:sanctum + `throttle:5,1` ✓

S'ajoutent au gate role:Admin|Tenant Admin du controller (S1). Triple
défense (route + role + ratelimit). Le test
`OutboxOverviewControllerTest::test_unauthenticated_user_receives_401`
verrouille le 401 sur GET non-auth.

Concernant `verified` : la chaîne du groupe admin n'inclut PAS `verified`
(email verification middleware Laravel). Pre-existing — TOUS les
endpoints admin du système se basent sur `auth:sanctum` + role/permission.
Ce commit ne dégrade rien.

### S10 — CSRF + idempotency-key sur Retry/Drain ?

**RÉFUTÉ pour CSRF, NUANCÉ pour idempotency.**

CSRF : `app/Http/Middleware/VerifyCsrfToken.php` ligne 14-21 — le
`$except` array ne contient AUCUN endpoint admin/observability. Les
routes API Sanctum côté Laravel utilisent par défaut le `EnsureFrontendRequestsAreStateful`
+ token-based auth (le client envoie un Bearer/cookie session). Le SPA
Vue (cf `OutboxOverviewComponent.vue` lignes 376, 385) appelle via
axios qui hérite de la config app pour le X-XSRF-TOKEN. CSRF protection
appliquée.

Idempotency-Key : `app/Http/Middleware/IdempotencyKeyMiddleware.php`
existe (cf middleware list) mais n'est PAS appliqué sur retry/drain
explicitement dans `routes/api.php`. **Acceptable car** :

- `outboxRetryFailed` (lignes 377-406) est *idempotent par data design* :
  `forceFill(['attempts' => 0, 'last_error' => null])` n'incrémente
  pas une séquence et `DispatchDomainEventsJob::dispatch($event->id)`
  est lui-même idempotent côté worker (cf docstring "a retry on an
  already-dispatched event is a no-op the worker handles").
- `outboxDrainFailed` (lignes 417-435) est *idempotent par requête* :
  `WHERE failed_at < cutoff DELETE` — exécutée 2× = 2× zero rows
  affectés (les rows ont déjà été deleted).
- Les 2 endpoints sont protégés par `throttle:10,1` et `throttle:5,1`
  respectivement, soit borne max d'effet par minute = 50 retries et
  5 drains. Pas un vecteur DoS exploitable.

INFO-level : ajouter Idempotency-Key serait propre pour la trace
audit-log (mais coût > bénéfice à V1).

---

## 2. Top 3 vraies failles (P0/P1)

**Aucune. Zéro P0, zéro P1.**

Le pipeline GSTACK 5-agents a apparemment posé les bons garde-fous au
moment du build (gate `role:Admin|Tenant Admin` sur le contrôleur,
sentinel test direct sur la 422 POS, exclusion `kdsInflight` du
`createPersistedState`). Les 3 surfaces les plus à risque (authz nouvel
endpoint, élévation scope cross-branch, persistence client-tampered)
sont chacune verrouillées avec evidence.

Si je devais inventer 3 P3 (cosmétiques, pour l'honnêteté du rapport
hostile) :

1. **P3** — `last_error` exposé en clair côté dashboard pourrait fuiter
   un fragment de query SQL/payload en cas d'exception verbeux (ex :
   `SQLSTATE[23000]: Integrity constraint violation: ... column 'phone'`).
   Surface admin-only, risque maîtrisé. Mitigation V1.x : ajouter une
   normalisation `last_error` (regex strip strings/numbers) côté
   controller. Non-blocker.
2. **P3** — Pas de logging audit-trail explicite sur `outboxRetryFailed`
   et `outboxDrainFailed` (pas vu d'appel `AuditLogService::log(...)`
   dans le controller). Un Tenant Admin malicieux pourrait drain les
   `failed_jobs` sans laisser de trace côté audit. Non-régression V1
   (pre-existing absence sur autres endpoints `Tenant Admin`-gated).
3. **P3** — `IdempotencyKeyMiddleware` non appliqué sur les mutations
   (cf S10). Contournement via idempotence data-design ; ajout propre
   en V1.x.

---

## 3. Verdict GO/NO-GO security V1

**GO security V1.** Aucun blocker.

Critères respectés :
- Zéro P0 / Zéro P1
- Authz défense en profondeur (route → role → throttle)
- Branch isolation préservée (forcePosRuntimeBranchScope inchangé,
  resolveBranchScope inchangé sur surface non-globale, observability
  globale gate-up à Admin|Tenant Admin)
- Aucun secret exposé en CI
- Aucune nouvelle surface persistée client-tampered
- Tests sentinels en place sur les 2 invariants critiques (S1 et S7)

---

## 4. Limitations honnêtes de cet audit

1. **Pas de runtime test** — j'ai lu les 8 fichiers principaux + la
   configuration de routes/middleware/seeders, mais je n'ai pas
   exécuté `php artisan test --filter=OutboxOverview` ni Playwright.
   Si le test sentinel `OutboxOverviewControllerTest::test_branch_manager_is_forbidden`
   échoue à l'exécution (ex : seed Branch Manager différent de prod),
   je ne le détecte pas.
2. **Pas d'analyse statique formelle** — pas de Psalm/PHPStan run sur
   le diff. Une vulnérabilité de type "type juggling" pourrait
   théoriquement m'échapper si elle dépend du runtime PHP exact.
3. **Périmètre limité au commit** — je n'ai pas audité les
   pre-existing surfaces (`index()`, `forcePosRuntimeBranchScope`
   antérieur). Si la base contient une vuln pré-existante, le commit
   ne la corrige pas mais ne l'aggrave pas non plus.
4. **Hypothèse soketi sandboxed** — j'ai présumé que le workflow CI
   tourne en sandbox isolé GitHub-hosted. Si quelqu'un re-câble ce
   workflow vers un self-hosted runner avec accès réseau interne, il
   faudra réauditer le port 6001 binding et la surface MySQL.
5. **Pas de revue front-end approfondie** — j'ai lu `OutboxOverviewComponent.vue`
   pour confirmer que les calls axios pointent vers le backend gated,
   mais pas inspecté chaque branche XSS/innerHTML. Le composant
   utilise `{{ ... }}` interpolation Vue (echappement auto), pas de
   `v-html` détecté dans les sections lues.

---

**Fin rapport AGENT_SEC pour commit `1b38e64a3`.**
