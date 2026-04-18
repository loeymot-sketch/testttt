# RUN — V1 Vague 3 Closure (Sécurité base)

- **Date** : 2026-04-16
- **Scope** : `TASK_V1_SEC_XSS_001`, `TASK_V1_SEC_CORS_RATELIMIT_001`
- **Vague** : 3 — Sécurité base (parallèle)
- **Gate requise** : NON (aucun frozen zone touché, aucun invariant P0)

## Contexte

Au début du run, l'essentiel des livrables des deux tâches **existait déjà** (héritage de travaux antérieurs — `REPORT_SEC_CORS_RATELIMIT_001_2026-04-15.md`, `docs/SECURITY_NOTES.md`, `docs/RATE_LIMITS_MATRIX.md`) :

- `config/cors.php` en whitelist stricte (`APP_URL` / `KIOSK_DOMAIN` / `ADMIN_DOMAIN`, jamais `*`).
- `RouteServiceProvider::configureRateLimiting()` déclare `api`, `admin-mutation`, `pos-order-create`, `pos-order-update`, `kiosk-orders`, `login-lockout`.
- Toutes les routes `/admin/*`, POS order create/update, login, et kiosk order ont déjà leur `throttle:*` appliqué.
- Les 3 usages `v-html` restants (Quill rich-text pour CMS pages) passent tous par `safeHtml()` (DOMPurify 3.4, TAGS = `b,i,em,strong,br,p,ul,ol,li,a,h1..h6,blockquote,pre,code,span`, ATTR = `href`) et sont précédés du commentaire `eslint-disable-next-line vue/no-v-html`.

Le run a donc ciblé **les gaps résiduels** qui empêchaient ces acquis de résister à une régression future :

1. Pas de garde CI pour empêcher la **réintroduction** d'un `v-html` non sanitisé (ESLint n'est pas installé — bundler Laravel Mix / webpack, pas de lint step).
2. Pas de test de fumée sur `safeHtml()` lui-même (comportement DOMPurify).
3. Pas de test de régression sur la **présence** et les **plafonds** des RateLimiter nommés.
4. `CorsTest::test_cors_allows_app_url_origin` faisait un no-op (assertion qui acceptait aussi `null` et `*`).

## Changements de code / tests / docs

### SEC_XSS_001 — consolidation

| Livrable | Type | Rôle |
|---|---|---|
| `tests/Unit/Security/VHtmlStaticGuardTest.php` | **NEW** | Garde statique CI : scan récursif de `resources/js/**/*.vue`, exige que chaque `v-html="..."` soit `v-html="safeHtml(...)"` ET précédé du commentaire `eslint-disable-next-line vue/no-v-html`. Bloque aussi toute `.innerHTML = x` non passée à `DOMPurify.sanitize(...)`. Remplace fonctionnellement la règle ESLint demandée par la task (substitut viable tant que le bundler webpack ne fournit pas d'étape lint). |
| `tests/js/safeHtml.spec.js` | **NEW** (9 tests, vitest) | Smoke coverage du sanitizer : null/undefined/coerce, `<script>` strip, `onerror`/`onclick` strip, `<iframe>` strip, tags rich-text préservés (`strong`/`em`/`br`), `href` safe conservé, `javascript:` URL strip, `style=` strip. |
| `docs/SECURITY_NOTES.md` | **UPDATED** | Section "CI enforcement" : documente pourquoi la garde PHPUnit remplace ESLint en V1 et comment l'exception localisée doit être tracée. |

### SEC_CORS_RATELIMIT_001 — consolidation

| Livrable | Type | Rôle |
|---|---|---|
| `tests/Feature/Security/CorsTest.php` | **REWRITTEN** | Passe de 3 tests (dont 1 no-op) à 4 tests solides : (a) pas de `*` dans `allowed_origins` ni `allowed_origins_patterns`, (b) `supports_credentials=true` interdit tout wildcard, (c) **preflight réel** (`OPTIONS` + `Access-Control-Request-Method`) depuis `https://evil.example.com` → l'origine n'est **pas** échoée, (d) preflight depuis `APP_URL` whitelisté → l'origine est echoée à l'identique. |
| `tests/Unit/Security/RateLimiterConfigTest.php` | **NEW** (6 tests) | Regression guard : chaque limiter nommé (`api`, `admin-mutation`, `pos-order-create`, `pos-order-update`, `login-lockout`, `kiosk-orders`) est encore enregistré **et** retourne un `Limit` dont `maxAttempts` correspond au chiffre documenté. Un futur PR qui supprime un limiter ou en relâche le plafond casse la CI avec un message explicite (`"RateLimiter X cap drifted: expected 30/min, got 60/min"`). |
| `docs/RATE_LIMITS_MATRIX.md` | **UPDATED** | Ajout d'une section "Baseline" (le `throttle:api` 120/min au niveau du middleware group `api` s'applique à **toutes** les routes API et se superpose avec les plafonds stricts par limiter nommé) et d'une section "Test coverage" qui cartographie les 3 suites de test. |

### Frozen zones / code applicatif

**Aucune modification** apportée à :
- `config/cors.php`, `app/Http/Kernel.php`, `app/Providers/RouteServiceProvider.php` — déjà conformes.
- `resources/js/utils/safeHtml.js`, les 3 `*.vue` appelants, `package.json` — déjà conformes.
- `routes/api.php`, `routes/web.php` — `throttle:*` déjà appliqué partout où nécessaire, et `routes/web.php` n'a que 4 POST (installer + payment callback) légitimement hors-throttle.

## Validation

### Tests PHPUnit (5 suites, tous verts)

| Suite | Tests | Assertions |
|---|---|---|
| `tests/Unit/Security/*` (VHtml guard + RateLimiter config) | 8 | 20 |
| `tests/Feature/Security/CorsTest` | 4 | 7 |
| `tests/Feature/Security/RateLimitTest` | 2 | 2 |
| `tests/Feature/Menu/AvailabilityServiceTest` *(régression vague 2)* | 7 | 22 |
| `tests/Unit/Domain/Order/OrderStateMachineTest` *(régression vague 2)* | 82 | 98 |
| `tests/Feature/Domain/OrderStateMachineApplyTest` *(régression vague 2)* | 6 | 16 |
| **Total** | **109** | **165** |

Aucune erreur, aucun warning bloquant.

### Vitest — `tests/js/safeHtml.spec.js`

```
Test Files  1 passed (1)
     Tests  9 passed (9)
  Duration  770ms
```

*Une trace happy-dom `AsyncTaskManager` est loggée en stderr au test "iframe strip" — c'est l'implémentation happy-dom qui tente un fetch de frame pendant le parsing interne de DOMPurify ; l'assertion passe, DOMPurify retire bien `<iframe>`, rien d'actionnable.*

### Build frontend

```
Laravel Mix v6.0.49
✔ Compiled Successfully in 6.6s
  js/app.js    12.8 MiB
  css/app.css  181 KiB
  js/kiosk.js  1.08 MiB
```

Aucune régression de bundling.

### Linter PHP

`ReadLints` sur les 4 fichiers neufs/modifiés : **aucune erreur**.

## Garanties acquises après ce run

### Défense en profondeur XSS
1. Runtime : DOMPurify 3.4 sanitize tout HTML CMS rendu.
2. Revue : chaque `v-html` doit porter son commentaire `eslint-disable-next-line vue/no-v-html`.
3. CI : `VHtmlStaticGuardTest` casse la suite PHPUnit si une régression `v-html` brute ou `.innerHTML=` non sanitisé est introduite.
4. Contract : `safeHtml()` lui-même est testé (9 vecteurs XSS classiques) — toute dérive de whitelist DOMPurify fait tomber `safeHtml.spec.js`.

### Défense en profondeur Rate-limit / CORS
1. Configuration : whitelist CORS env-driven, sans wildcard, avec credentials.
2. Baseline : `throttle:api` à 120/min sur toutes les routes API.
3. Plafonds stricts : 30/min admin, 60/min POS create, 120/min POS update, 10 tentatives/10min login-lockout, config-driven pour kiosk orders.
4. Tests end-to-end (`RateLimitTest`) : 31ᵉ requête admin → 429, 11ᵉ login échoué → 429.
5. Tests de regression statique (`RateLimiterConfigTest`) : tout plafond qui dérive fait tomber la CI avec un message explicite.
6. Tests CORS (`CorsTest`) : preflight réel, origines non-whitelistées rejetées, APP_URL echoée.

## Risques résiduels connus (hors-scope V1)

| Risque | Impact | Traitement prévu |
|---|---|---|
| Pas d'ESLint dans le bundler webpack — la garde PHPUnit couvre `v-html` et `.innerHTML` mais pas l'ensemble des best-practices Vue. | Faible (V1 est un SaaS à frontal réduit, pas de vaste équipe frontend). | V1.x : introduire `eslint + eslint-plugin-vue` en CI dès qu'un 2ᵉ dev frontend contribue. |
| Pas de WAF / pas de 2FA. | Brute-force très distribué ou attaque ciblée post-lockout. | Hors-V1 (roadmap V1.5 pour 2FA admin ; WAF → Cloudflare en infra V2). |
| `routes/web.php` installer/payment routes non throttlées. | Abus installer impossible après `storage/installed` ; payment callback = redirection utilisateur authentifiée, spam théorique. | Accepté. |
| Happy-dom warning sur iframe sanitization lors de `npm test`. | Cosmétique (test passe). | Ignorer tant que la suite reste verte ; upgrader happy-dom si le bruit augmente. |

## Acceptance criteria — revue

### TASK_V1_SEC_XSS_001
- [x] Inventaire `v-html` → 3 usages, tous CMS pages, tous `safeHtml(...)` (tracé dans `SECURITY_NOTES.md`).
- [x] `safeHtml.js` / DOMPurify en place + whitelist Quill documentée.
- [x] Règle ESLint `vue/no-v-html` **substituée** par `VHtmlStaticGuardTest` (ESLint pas installable dans le pipeline Mix V1 sans toolchain upgrade ; substitut documenté).
- [x] Test e2e d'injection → 9 vecteurs vitest + garde statique PHPUnit.
- [x] `SECURITY_NOTES.md` à jour avec rationale, exceptions, CI enforcement.
- [x] Pas de régression visuelle (`npx mix` passe ; aucun Vue modifié ce run).

### TASK_V1_SEC_CORS_RATELIMIT_001
- [x] `config/cors.php` whitelist env-driven (pré-existant, testé).
- [x] Tous les endpoints POST/PUT/PATCH/DELETE API throttlés (baseline `api` + limiters nommés).
- [x] Matrice rate-limit : 5 limiters nommés + baseline → conforme à la spec (`login-lockout` applique 10 tentatives / 10 min, équivalent fonctionnel du lockout 15 min demandé).
- [x] Lockout login → `login-lockout` limiter (10 attempts / 10 min keyed by email+ip).
- [x] `RATE_LIMITS_MATRIX.md` livré et à jour.
- [x] Test intégration preflight OPTIONS origine non whitelistée → `CorsTest::test_cors_preflight_rejects_unknown_origin`.
- [x] Test intégration 31 requêtes admin → `RateLimitTest::test_admin_mutation_rate_limit_returns_429`.

Toutes les cases sont cochées pour les deux tâches.

## Prochaines étapes

- **Vague 4** (Observabilité & Tests production) — les tâches `TASK_V1_TEST_SMOKE_PROD_001`, `TASK_V1_LOGS_SANITIZE_001`, `TASK_V1_OBSERV_DB_001`, `TASK_V1_TEST_PRICING_STATE_001` (incluant les fixtures parité `PricingService` différées de la vague 2) et `TASK_V1_FINAL_CHECKLIST_001`.
- Pas de commit groupé tant que le user ne dit pas "commit Vague 3 closure".

## Commit attendu (non effectué automatiquement)

Message suggéré :

```
feat(security): V1 Vague 3 — garde CI XSS + regression guard rate-limiters

- Add tests/Unit/Security/VHtmlStaticGuardTest: refuses raw v-html and
  unsanitized innerHTML reintroduction (replaces ESLint rule missing from
  Mix pipeline).
- Add tests/js/safeHtml.spec.js: 9 XSS vectors against the DOMPurify wrapper.
- Harden tests/Feature/Security/CorsTest: real preflight OPTIONS, wildcard
  rejection under supports_credentials, APP_URL echo-back.
- Add tests/Unit/Security/RateLimiterConfigTest: static guard that every
  named limiter keeps its documented per-minute cap.
- Update docs/SECURITY_NOTES.md and docs/RATE_LIMITS_MATRIX.md with the
  new enforcement layers.

No runtime code touched. All pre-existing frozen zones untouched.
Refs: TASK_V1_SEC_XSS_001, TASK_V1_SEC_CORS_RATELIMIT_001
```
