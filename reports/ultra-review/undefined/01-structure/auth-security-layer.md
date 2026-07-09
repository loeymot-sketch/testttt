# Cartographie — Couche AUTH / SÉCURITÉ (W1, lecteur read-only)

Date : 2026-07-02 · Branche : `pos/category-first-caisse-2026-06-23` · Slug : auth-security-layer
Discipline : chaque file:line ci-dessous a été LU dans cette session (Read/grep). Aucune supposition.

---

## 1. Vue d'ensemble

La sécurité repose sur 6 couches empilées :
1. **Isolation multi-tenant** — global scope Eloquent `BranchScope` (20 modèles, sentinel CI).
2. **AuthN** — Sanctum bearer tokens (TTL 480 min) + session web ; kiosk = ability `kiosk:order` uniquement.
3. **AuthZ** — Spatie permissions (guard `sanctum`) au niveau route/controller + FormRequests (66 `return true;` baselinés) + middleware dédié `block_kiosk_token_admin`.
4. **Anti-rejeu** — `IdempotencyKeyMiddleware` (frozen) scope (branch,user,key) + table `webhook_events` UNIQUE(provider,webhook_id).
5. **Boot guards production** — `AppServiceProvider::boot()` refuse de démarrer sur ~10 conditions dangereuses.
6. **Webhooks entrants** — Uber Eats HMAC-SHA256 fail-closed, endpoint public throttlé.

---

## 2. BranchScope (frozen) — isolation par branche

`app/Models/Scopes/BranchScope.php` (42 lignes) :
- :21-23 — **jamais appliqué au modèle `User`** (récursion Sanctum : résoudre l'user déclencherait le scope qui ré-appelle le guard).
- :27 — appliqué si `(!runningInConsole() || runningUnitTests()) && Auth::check()` → les commandes artisan/cron NE SONT PAS scopées (par design), les tests PHPUnit le sont.
- :33-36 — `branch()===0` (admin) → **aucun filtre** (voit tout, y compris branch_id=0).
- :39 — staff → `WHERE <table>.branch_id = <userBranch>` (n'expose jamais branch_id=0). Commentaire `[FIX-54-8]`.
- Résolution du branch via `app/Traits/DefaultAccessModelTrait.php:14-28` : table `default_accesses` (user_id,name='branch_id') d'abord, sinon `users.branch_id`, fallback `Settings site_default_branch`.

Variante : `app/Models/Scopes/WizardProfileBranchScope.php` — utilisée par `ItemWizardProfile.php:24` (global-or-branch published, le BranchScope standard 500-erait sur cette table).

**Liste RÉELLE vérifiée** (`grep -l "addGlobalScope(new BranchScope" app/Models/*.php` = **20 exactement**, conforme baseline CLAUDE.md §9) :
CashDrawerSession, CashMovement, DeliveryBoyCashMovement, DeliveryBoyCashSession, DiningTable, FrontendOrder, ItemBranchAvailability, KioskMachine, Order, OrderItem, OrderPayment, OrderQuote, PaymentTerminal, PendingPaymentConfirmation, PosParkedOrder, Printer, PushNotification, StockLevel, StockMovement, User.

**Sentinel** : `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php`
- :48-66 `EXEMPTED_MODELS` = Branch (auto-référence), Customer (récursion token Sanctum), + 10 exemptions BASELINE_V1_2026-05-18 (FrontendDiningTable, ZReport, AuditLog, OrderDiscountLog, Message, DiningTableAuditLog, KioskPromo, UpsellRule, ActionLog, DomainEvent).
- :99-111 — scanne tout modèle dont la table a une colonne `branch_id` et exige `addGlobalScope(new BranchScope` dans la source.
- `WebhookEvent.php:44` — exempté par ABSENCE de colonne branch_id pertinente scannable ? Non : le doc-block dit explicitement pas de BranchScope « providers don't carry tenant context » (le fichier référence BranchScope en commentaire seulement).

---

## 3. Sanctum — tokens & abilities

`config/sanctum.php:51` — `'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 480)` (8h). :36 guard `['web']`.

**Mint kiosk** : `app/Http/Controllers/Auth/KioskMachineLoginController.php`
- :71 vérifie le password AVANT tout état de compte (anti-énumération, F3 heal 2026-06-09).
- :78-89 machine ACTIVE + user lié ACTIF requis.
- :91-110 transaction : `lockForUpdate` sur KioskMachine avec `withoutGlobalScope(BranchScope::class)` explicite (:96, pre-auth), **révocation des anciens tokens `kiosk-token`** (:102), puis `createToken('kiosk-token', ['kiosk:order'], now()->addMinutes(config sanctum.expiration))` (:104-108).

Autres mints : `GuestSignupController.php:146` — `createToken('auth_token', ['kiosk:order'], now()->addDays(30))` (**TTL 30 jours**, plus long que kiosk) ; LoginController:157, RefreshTokenController:68, ForgotPasswordController:183 (non lus en détail — périmètre W4).

**Usages `tokenCan('kiosk:order')`** (grep complet, 12 fichiers) : OrderRequest.php:83,:374 ; OrderStatusRequest.php:31,:93 (kiosk ne peut PAS CANCEL) ; PaymentConfirmRequest:20 ; Kiosk/PromoValidateRequest:19 ; Kiosk/PricingPreviewRequest:30 ; UpsellController:32 ; MenuController:37 ; LoyaltyController:287,:611 ; PaymentReconcileController:87 ; ItemResource:178 ; NormalItemResource:204 ; OrderQuoteService:170.

Routes avec middleware ability : `routes/api.php:1462` + :1508 `abilities:kiosk:order` (2 routes seulement — le reste s'appuie sur les checks FormRequest/controller ; :1325-1328 documente ce choix).

---

## 4. Middleware — stacks et pièces

`app/Http/Kernel.php` :
- **Global** (:17-30) : TrustHosts (anti host-spoof, pin APP_URL — :18-23), TrustProxies, HandleCors, PreventRequestsDuringMaintenance, ValidatePostSize, TrimStrings, ConvertEmptyStringsToNull.
- **Groupe `api`** (:51-64) : `throttle:api` (60/min), SubstituteBindings, JsonMiddleware, CorrelationIdMiddleware, **EnsureUserStatusActive** (revalidation status user à CHAQUE requête — un user désactivé perd l'accès immédiatement au lieu d'attendre l'expiration 8h ; ordre forcé APRÈS auth via `$middlewarePriority` :85-97).
- **Groupe `web`** (:38-49) : cookies chiffrés, session, CSRF, CorrelationId, ContentSecurityPolicyHeader (mode env `CSP_ENFORCE_MODE`, défaut report_only — :46-48).
- **Aliases** (:106-147) : `apiKey`→ApiKeyMiddleware, `role`/`permission`/`role_or_permission` (Spatie), `abilities`/`ability` (Sanctum), `kiosk.locale`→ValidateKioskLocale, `idempotency`→IdempotencyKeyMiddleware, `block_kiosk_token_admin`→BlockKioskTokenFromAdminRoutes.

`ApiKeyMiddleware.php:21-28` — compare header `x-api-key` à `config('app.api_key')` (comparaison `===` simple, non-timing-safe — préliminaire, clé côté client MIX_API_KEY exposée au bundle de toute façon).

`CorrelationIdMiddleware.php:14-24` — X-Correlation-ID entrant ou UUID, `Log::withContext(correlation_id, user_id, branch_id)`, renvoyé en réponse.

`EnsureUserStatusActive.php` — doc :12-44 : token Sanctum indépendant de `users.status` → middleware every-request (Owner Gate G4 Option A) ; skip anonyme/non-User ; sur status≠ACTIVE : supprime le token courant + 401.

**BlockKioskTokenFromAdminRoutes.php** (`:57-127`) — P0 empirique J-ADV-6 : un token `['kiosk:order']` atteignait `/api/admin/pos-order` car Spatie `permission:*` teste `Auth::user()->can()` pas `$token->can()`, et la borne est liée à l'user ADMIN (seeder KioskMachineTableSeeder:47 cité). Le middleware 403 tout token portant `kiosk:order` sans wildcard `*` sur `/api/admin/*` (:96-126). Appliqué aux deux groupes admin `routes/api.php:281` et `:302` (juste après `auth:sanctum`). Layer 2 (user kiosk dédié sans rôle) = proposal en attente (:39-43). Sentinel : `tests/Feature/Security/KioskTokenAdminBlockSentinelTest.php`.

---

## 5. IdempotencyKeyMiddleware (frozen)

`app/Http/Middleware/IdempotencyKeyMiddleware.php` :
- :41 flag `idempotency.enabled` (env IDEMPOTENCY_MIDDLEWARE_ENABLED) — OFF = passthrough total (d'où le boot guard prod).
- :52-58 clé absente : 422 si route dans `config('idempotency.required_routes')` (:159-175 matching `name:` ou path), sinon passthrough.
- :61 format `^[A-Za-z0-9._\-]{8,64}$`.
- :70-74 exige user authentifié + branch résolvable.
- :77-82 clé scopée `idempotency:v1:{branch}:{user}:{sha256(key)}`.
- :84-118 replay-first : hash payload différent → **409 IDEMPOTENCY_KEY_CONFLICT** ; acquire atomique NX-EX ; course → wait 1500ms puis 425 IN_FLIGHT.
- :126-135 storage indisponible : fail_open configurable (défaut false → **503**, s'appuie sinon sur l'UNIQUE app-layer).
- :139-155 cache le résultat **2xx uniquement** ; non-2xx ou exception → release (retry autorisé).
- Repo : `RedisIdempotencyKeyRepository` bindé dans AppServiceProvider (:72-77).

---

## 6. Spatie RBAC

- `config/permission.php` : **ABSENT (vérifié `ls` → No such file)** — le package utilise sa config vendor par défaut.
- Rôles seedés (`database/seeders/RoleTableSeeder.php:19-62`, guard_name=`sanctum`) : Admin, Customer, Delivery Boy, Waiter, Chef, Branch Manager, POS Operator, Stuff (+ suite non lue).
- Autres seeders permissions : PermissionTableSeeder(+V2), RolePermissionTableSeeder, AdminWebGuardPermissionsSyncSeeder, ComposerPermissionsMinimalSeeder, E2EPlaywrightPermissionsHealSeeder, IngredientPermissionSeeder, LeCayenneRoleLandingUrlSeeder, SpatieRoleLookup.
- `permission:settings` : appliqué au niveau **controller** (81 controllers Admin avec `permission:` — grep -rln), ex. `PermissionController.php:33` `$this->middleware(['permission:settings'])`, AnalyticSectionController:26. Routes api.php n'en portent que 5 directement (:621, :679 doc, :760 ingredients_manage, :774 catalog.compose, :796 catalog.publish).
- Sentinel de couverture routes admin : `tests/Feature/Sentinels/RouteCoverage_AdminPermissionGateSentinelTest.php` (existence vérifiée par ls).

**FormRequests** : `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php`
- :65 `RETURN_TRUE_BASELINE = 66` — **le ratchet à 66 a été FAIT** (2026-05-29 SUP-2, :30-39) contrairement à la note CLAUDE.md §9 qui parle encore de 69.
- :83-84 regex sur le corps exact de `authorize()`. Count > 66 → CI rouge ; count < baseline → message STDOUT pour abaisser (:108-112).
- 8 FormRequests critiques déjà refactorés `can()` (:41-48 : PosOrderRequest→can('pos'), PermissionRequest/KioskMachineRequest→can('settings'), ItemRequest, CouponRequest, etc.).

---

## 7. Boot guards production — AppServiceProvider

`app/Providers/AppServiceProvider.php`, bloc `if (app()->environment('production'))` **:178-474** (plage réelle lue, plus large que le ~78-215 attendu). REFUSE TO BOOT (RuntimeException) si :
1. :185 `POS_SIMULATION_HARDWARE` true (NF525 cash-trail).
2. :197 `PAYMENT_BYPASS_MODE` true ; :204 `PRINTING_BYPASS_MODE` true.
3. :222 `APP_DEBUG` true.
4. :243 `IDEMPOTENCY_MIDDLEWARE_ENABLED` != true (23 routes mutantes).
5. :261-271 `LOYALTY_QR_SECRET` vide.
6. :281 `APP_URL` vide (CORS/broadcasting auth).
7. :293 `BROADCAST_DRIVER` null/'null' ; :299 `QUEUE_CONNECTION` = sync.
8. :314-322 `CACHE_DRIVER` ∈ {array,null} (locks chaîne audit NF525 cross-worker — `file`/`database` PASSENT, backlog UNI-03 confirmé au code).
9. :343-361 `STRIPE_WEBHOOK_SECRET` vide SI gateway stripe ENABLE en DB (soft-degrade si table absente).
10. :400-437 secret SenangPay vide SI gateway senangpay ENABLE (lookup gateway_options).
11. :461-473 `MAIL_HOST` en plage IP interdite (loopback/link-local/RFC1918/metadata — anti-SSRF via env-editor admin, règle `SafeRemoteHost`).
Hors-prod : :483-494 `innodb_lock_wait_timeout=5s` (anti pool-starvation), :497-524 REGEXP SQLite pour tests.
Aussi :127-172 hook `ZReport::updated` → ancre cross-chain `z_report.closed` dans audit_logs (best-effort, ZReportService frozen non touché).

---

## 8. CORS

`config/cors.php` :
- :4 paths `api/*` + `sanctum/csrf-cookie`.
- :6-21 origins = APP_URL, KIOSK_DOMAIN, ADMIN_DOMAIN, localhost:8000/127.0.0.1:8000 (Echo), FRONTEND_WEB_DOMAIN, :8011 (web standalone).
- :22-27 **pattern regex `#^http://(localhost|127\.0\.0\.1):\d{2,5}$#`** — tout port loopback accepté (commentaire : safe car même box ; à challenger en W4 pour la posture prod).
- :31 `supports_credentials => true` ; :28 headers `*`.
- Sentinel prod : `tests/Feature/Sentinels/CorsAppUrlProductionGuardSentinelTest.php` (existence vérifiée).

---

## 9. Webhook Uber Eats + webhook_events

Route : `routes/api.php:161-163` — `POST /api/webhooks/uber`, middleware `['installed','throttle:60,1']`, **PUBLIC** (pas d'apiKey — commentaire :157-160 : Uber n'a pas notre clé, auth = HMAC).

`app/Http/Controllers/Webhook/UberWebhookController.php` :
- :156-169 `signatureValid` — secret `config('uber.webhook_signing_secret')` ; **vide → refuse tout (fail-closed :159-162)** ; `hash_equals(hash_hmac('sha256', raw, secret), header X-Uber-Signature)` (:167-168).
- :54-70 idempotence via `webhook_events` (provider='uber_eats', webhook_id) — status pending→processed/failed ; migration `2026_05_09_120000_create_webhook_events_table.php:83` **UNIQUE(provider, webhook_id)** `uk_webhook_provider_id`.
- :78-91 échec de traitement → status failed + **répond 200 quand même** (anti retry-storm, choix documenté :89).
- :108-143 création Order `forceFill` : branch depuis `config('uber.branch_id',1)` (:109), status=ACCEPT, payment_status=PAID (Uber prépayé), source_surface='uber_eats', **fiscal_sequence non alloué par défaut** (:123-124, canal séparé) ; OrderItems avec composition_snapshot mappé.
- Config `config/uber.php:16-26` : UBER_CLIENT_ID/SECRET, ORG_ID, STORE_ID, TOKEN_URL, API_BASE, SCOPES (`eats.store eats.order`), `webhook_signing_secret` = UBER_WEBHOOK_SECRET **fallback UBER_CLIENT_SECRET** (:26).
- Services : `app/Services/Uber/UberClient.php` (OAuth+fetch/accept), `UberOrderMapper.php` (non lus ligne à ligne — périmètre W2/W4).

---

## 10. Postures .env.example (NOMS de clés seulement, vérifiés par grep)

APP_KEY, MIX_API_KEY (→ config app.api_key), API_THROTTLE_PER_MINUTE, LOGIN_LOCKOUT_MAX_ATTEMPTS, MIX_GOOGLE_MAP_KEY, PUSHER_APP_KEY/SECRET, FCM_SERVER_KEY, AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY, LOYALTY_QR_SECRET, STRIPE_WEBHOOK_SECRET, FISCAL_AUDIT_SECRET / FISCAL_Z_REPORT_SECRET (≥48 chars, runbook docs/FISCAL_SECRETS.md, variante par branche FISCAL_AUDIT_SECRET_BRANCH_17 commentée), UBER_CLIENT_SECRET / UBER_TOKEN_URL / UBER_API_BASE / UBER_WEBHOOK_SECRET. Checklist déploiement intégrée (:434-460).

---

## 11. Couverture de tests observée (ls réels)

- `tests/Feature/Branch/` : BranchScopeCoverageSentinelTest, OrderBranchIsolationTest, BranchDeactivationTokenRevokeTest, BranchDestroyRevokesTokensTest, OssAdminBranchPolicyTest, BranchFiscalIdentityTest.
- `tests/Feature/Auth/` : GuestSignupAbilityScopeTest, RefreshTokenAbilityPreserveTest, KioskThrottleKeysTest, UserStatusRevalidationTest, UserMassAssignmentTest, BcryptRoundsUpgradeTest.
- `tests/Feature/Security/` (16 fichiers) : KioskTokenAdminBlockSentinelTest, CorsTest, ContentSecurityPolicyHeaderTest, IdempotencyCrossUserLeakSentinelTest, IdempotencyPendingTtlSentinel, RateLimitTest, MailHostAllowlistSentinelTest, PrinterHostAllowlistSentinelTest, CustomerTokenHmacHardenedSentinelTest, FileUploadHardenedSentinel, UserSuperAdminDisableHardenedSentinel, LoginPasswordValidationParity, CashierAttributionAndLoginAuditSentinel, FirebaseKeyStorageSecurityTest, LanguageServicePathContainmentSentinel, InnodbLockWaitTimeoutSentinel.
- `tests/Feature/Sentinels/` (~100 fichiers) dont : FormRequestAuthzDriftSentinelTest, RouteCoverage_AdminPermissionGateSentinelTest, FrozenZoneSha256BaselineSentinelTest (+frozen-zone-sha256-baseline.json), IdempotencyMiddleware(ProductionGuard)SentinelTest, WithoutGlobalScopesAuditSentinelTest, AdministratorBranchZeroMintBypassSentinelTest, PasswordResetRevokesTokensSentinelTest, CommittedSecretsScanSentinelTest, PosSimulationHardwareProductionGuardSentinelTest, StripeWebhookReplayToleranceSentinelTest, PaymentConfirm*SentinelTest (ability/cross-branch/concurrency).
- Racine Feature : KioskSecurityTest, KioskScopeIsolationTest, BranchIsolationTest, CorrelationIdMiddlewareTest, dossiers Uber/, Webhooks/, Idempotency/, KioskSecurity/.

---

## 12. Risques préliminaires (observations à VÉRIFIER en W2/W4 — pas des findings)

1. **CORS loopback any-port regex actif aussi en prod** (`config/cors.php:26`) — un service local malveillant sur la box pourrait faire des requêtes credentialed cross-origin. Contexte single-box V1 en atténue la portée.
2. **Webhook Uber crée l'Order en `forceFill` avec `total` fourni par Uber** (UberWebhookController:117) — bypasse PricingService (assumé : canal agrégateur, prix Uber = source), et `status=ACCEPT`+`PAID` sans état-machine. À challenger vs invariant « backend SSOT pricing » (probable exception voulue, non fiscalisée par défaut).
3. **Fallback `UBER_WEBHOOK_SECRET` → `UBER_CLIENT_SECRET`** (config/uber.php:26) — si le client_secret fuit côté OAuth, la signature webhook est forgeable.
4. **GuestSignup token 30 jours** (GuestSignupController:146) vs 8h kiosk — sprawl potentiel de tokens `kiosk:order` longue durée sur des devices clients.
5. **ApiKeyMiddleware `===`** non timing-safe + clé publiée au bundle (MIX_API_KEY) — c'est un identifiant d'app, pas un secret ; à requalifier comme tel dans la doc.
6. **Uber webhook répond 200 sur échec de traitement** (:90) — perte silencieuse possible d'une commande si le fetch/mapping échoue durablement (compensé par status=failed en DB + log, mais pas d'alerte).
7. **kiosk-token révoqués par NOM** (`KioskMachineLoginController:102` `where('name','kiosk-token')`) — les tokens `auth_token` guest du même user (si partagé) ne sont pas touchés ; OK si users distincts.
8. Divergence documentaire mineure : CLAUDE.md §9 dit sentinel FormRequest baseline=69, le code dit **66** (ratchet fait) — la doc est en retard, pas le code.

## 13. Questions ouvertes

- Le Layer 2 kiosk (user dédié sans rôle Spatie, proposals/PROPOSAL_KIOSK_DEDICATED_USER_REFACTOR.md) est-il toujours planifié ? Layer 1 (middleware) est seul en place.
- `config/permission.php` absent : le cache Spatie (permission.cache) tourne donc sur les défauts vendor — est-ce voulu avec CACHE_DRIVER=file en V1 ?
- Le flux `uber.fiscalize=true` (commentaire UberWebhookController:123-124) a-t-il un chemin d'allocation fiscale implémenté quelque part (cron ?) — non vu dans ce périmètre.
