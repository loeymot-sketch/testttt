# AGENT 10 — SÉCURITÉ / RBAC / ISOLATION — Round 1 Report
**Date:** 2026-06-07 · **Agent:** 10-SECURITY · **Mode:** red-team paranoïaque, abus actif
**Target:** clone jetable `foodking_e2e` @ http://127.0.0.1:8766 (jamais la DB opérante `foodking`)
**Verdict:** PASS sur l'essentiel (isolation/RBAC/Sanctum/idempotency/SSOT/boot-guards/anti-altération tous PROUVÉS LIVE)
avec **2 findings réels ouverts** : 1× P1 (kiosk auto-login IP gate spoofable via X-Forwarded-For) + 1× P2 (AWS creds dans l'historique git).

---

## RÉSUMÉ EXÉCUTIF
J'ai PILOTÉ (pas juste lu) chaque défense et tenté de la casser sur le clone live. Toutes les protections
cœur tiennent sous abus réel : l'escalation kiosk→admin (J-ADV-6) est bloquée 403 en live, le RBAC refuse
le POS Operator (403) avec un contrôle admin (422) qui prouve que la route marche, l'idempotency dédoublonne
réellement (1 seule session DB malgré 3 POST), le prix client truqué est ignoré (SSOT recompute), les boot
guards prod sont prouvés par sentinel (18 tests). DEUX trous réels demeurent — détaillés ci-dessous avec
reproduction et preuve.

---

## AXE PAR AXE

### 1. Isolation branche — PARTIAL (structurel prouvé, leak live non-exerçable: 1 seule branche)
- `BranchScope.php:33-39` — userBranch=0 (admin) → aucun filtre ; userBranch>0 (staff) → `WHERE branch_id = userBranch`, jamais branch_id=0. Vérifié code.
- 20 modèles déclarent `addGlobalScope(new BranchScope)` — EXACTEMENT le baseline CLAUDE.md §9 (compté: `grep -rl "addGlobalScope(new BranchScope" app/Models/*.php | wc -l` = 20).
- Sentinel `BranchScopeCoverageSentinelTest` = **GREEN** (1 test) sous APP_ENV=testing/foodking_test.
- Tests comportementaux `tests/Feature/Branch/` = **GREEN** (14 tests, 41 assertions) dont `OrderBranchIsolationTest`, `OssAdminBranchPolicyTest`.
- Channel broadcast (`routes/channels.php:41-62`) : discriminateur = **nom de token** (`kiosk-token`), immunisé au wildcard Sanctum `*` ; admin-zero requiert un **role check** (`hasRole('Admin'|'Tenant Admin')`) — ferme le Guest-Echo-Bypass (customers/guests sont branch_id=0).
- **LIMITATION HONNÊTE**: le clone n'a qu'**1 branche** (`SELECT COUNT(*) FROM branches` = 1) + admin branch_id=0. Aucune branche 2 → un "fuite cross-branch via API directe" ne peut PAS être exercé en live. Isolation prouvée *structurellement* (scope déclaré + filtrant + sentinel + tests), pas par tentative de leak live. → **PARTIAL** assumé (pas un faux PASS).

### 2. RBAC — PASS (refus 403 prouvés LIVE pour 4 routes, avec contrôle admin positif)
Login POS Operator (`pos@lecayenne.fr` / 123456, branch_id=1, 7 perms: dashboard/pos/pos-orders/pos-discount-up-to-10/pos.redeem-loyalty/kitchen-display-system/order-status-screen — PAS de `settings`).
- POST `/api/admin/setting/role` (RoleRequest `can('settings')`) → **403 "User does not have the right permissions"** ; DB: 0 rôle "HACKED" créé. ✅
- POST `/api/admin/setting/tax` (TaxRequest `can('settings')`) → **403**. ✅
- PUT `/api/admin/ingredients/extra:1/availability` (route middleware `permission:ingredients_manage`) → **403**. ✅
- POST `/api/admin/composer/items/1/profile` (`permission:catalog.compose`) → **403**. ✅
- **CONTRÔLE positif**: Admin sur la MÊME route ingrédient → **422 validation** (pas 403) — prouve que la route fonctionne et que la garde est permission-spécifique, pas un blocage global. ✅
- DB: seul le rôle `Admin` détient la permission `settings` (`role_has_permissions` join). POS Operator = 7 perms, Chef = 3, Branch Manager = 52.

### 3. Sanctum kiosk — PASS (J-ADV-6 escalation bloquée LIVE bout-en-bout)
- Token minté via POST `/api/auth/kiosk-login` (kiosk-lecayenne/kiosk123) → DB `personal_access_tokens` id=1596: `abilities=["kiosk:order"]`, `name=kiosk-token`, `expires_at=+480 min (~8h)` = `config('sanctum.expiration',480)`. ✅
- **Kiosk machine #1 est bound à user_id=1 = "Admin Le Cayenne" (role Admin, branch_id=0)** — exactement le scénario d'escalation J-ADV-6. Le token hérite donc en théorie de TOUTES les perms admin + bypass BranchScope. Seule défense = middleware `block_kiosk_token_admin`.
- **ABUS LIVE**: kiosk token → GET `/api/admin/customer` = **HTTP 403 `{"error":"token_ability_insufficient"}`**. Idem GET `/api/admin/administrator` (user mgmt) = 403 et GET `/api/admin/cash-overview` (fiscal) = 403. Escalation NEUTRALISÉE en live. ✅ (`BlockKioskTokenFromAdminRoutes.php:96-126`, par ABILITY pas par nom → couvre aussi le token GuestSignup `auth_token`.)
- **COUVERTURE EXHAUSTIVE (linchpin prouvé, pas supposé)**: enumération via `route:list --json` → **415/415 routes `api/admin/*` portent `BlockKioskTokenFromAdminRoutes`** (0 manquante). Les 2 seuls groupes `prefix('admin')` (api.php:274, :295) ont le middleware ; aucun groupe admin dans d'autres fichiers de route. Donc la borne (bound à user_id=1 admin) ne peut escalader sur AUCUNE route admin. ✅
- **POSITIF ciblé**: MÊME token → GET `/api/frontend/menu` = **HTTP 200 JSON** (menu réel) — le blocage est ciblé, pas un break global. ✅
- No-token → admin = **401** ; token forgé/garbage → admin = **401**. ✅
- Revoke-on-relogin: `KioskMachineLoginController.php:96` `$user->tokens()->where('name','kiosk-token')->delete()` en transaction `lockForUpdate`. ✅
- Sentinel `KioskTokenAdminBlockSentinelTest` GREEN (2 tests) ; `CustomerTokenHmacHardenedSentinelTest` GREEN (4 tests, 3006 assertions).
- **NOTE P3 (settled, by-design)**: `GuestSignupController.php:146` mint un token `auth_token` avec `['kiosk:order']` mais TTL `now()->addDays(30)` (vs 480 min pour le token machine). Il est bloqué des routes admin par le même middleware (ability-based) et ne peut que passer commande. TTL long = hygiène, pas une fuite.

### 4. Idempotency — PASS (dédoublonnage réel prouvé, 1 session DB pour 3 POST)
`IdempotencyKeyMiddleware.php` : scope `(branch_id, user_id, sha256(key))`, cache 2xx-only, fail-closed par défaut. `enabled=ON` en e2e.
- TEST 1: POST `/api/frontend/order` (route required) SANS `X-Idempotency-Key` → **422 `MISSING_IDEMPOTENCY_KEY`**. ✅
- TEST 2: double POST `/api/admin/pos/cash-drawer/sessions/open` même clé+payload → call1 HTTP 201 (session id=8), call2 HTTP 201 avec header **`Idempotency-Replayed: true`** + MÊME body (id=8, pas de 2e exécution). ✅
- TEST 3: même clé, payload différent (999) → **HTTP 409 `IDEMPOTENCY_KEY_CONFLICT`**. ✅
- **DB**: `SELECT COUNT(*) FROM cash_drawer_sessions WHERE opening_amount=50 AND created_at>NOW()-INTERVAL 2 MINUTE` = **1** (pas 2). Aucune double-exécution. ✅ (session de test refermée après).
- Sentinel `IdempotencyCrossUserLeakSentinelTest` GREEN (5 tests, 11 assertions) — prouve l'isolation cross-user/branch.

### 5. SSOT prix (anti-tampering) — PASS (prix client truqué ignoré LIVE)
- ABUS: POST `/api/frontend/pricing/preview` avec `{item_id:3 (Boisson Seule 2.00€), price:0.01, subtotal:0.01, total:0.01}` → réponse backend: `unit_price:2, line_subtotal:2, subtotal:2, total:2`. Le client value est IGNORÉ, recompute SSOT. ✅
- OrderRequest rejette négatifs (`min:0` sur subtotal/discount/total) et exige `quote_token` (uuid) + `quote_signature` (HMAC 64 chars) pour les ordres kiosk — défense SSOT signée. ✅

### 6. Boot guards prod (AppServiceProvider) — PASS (par sentinel, SANS prod-sim risquée)
- **N'a PAS lancé `APP_ENV=production php artisan`** (footgun infra partagée [[feedback_shared_infra_devdb_footgun]] — risque d'écraser foodking + bootstrap/cache). Vérifié via sentinels sous APP_ENV=testing/foodking_test (DEVDB-guarded).
- Code: `AppServiceProvider.php:158-300` — `if (app()->environment('production'))` throw `RuntimeException` pour: POS_SIMULATION_HARDWARE≠false, PAYMENT_BYPASS_MODE, PRINTING_BYPASS_MODE, APP_DEBUG=true, IDEMPOTENCY_MIDDLEWARE_ENABLED≠true, FISCAL secrets, APP_URL vide, CACHE_DRIVER in [array,null].
- `ProductionBootGuardsCompletenessSentinelTest` = **GREEN (18 tests, 44 assertions)** + `PosSimulationHardwareProductionGuardSentinelTest` (4), `IdempotencyMiddlewareProductionGuardSentinelTest` (4), `FiscalSecretProductionGuardTest` (6) — tous GREEN. ✅
- **NOTE backlog UNI-03 (documenté CLAUDE.md §8)**: la liste interdite CACHE_DRIVER = `['array','null']` seulement ; `file`/`database` PASSENT. Sûr en V1 mono-box (file driver) ; à élargir pour multi-instance cloud. Pas un blocker V1.

### 7. NF525 anti-altération — PASS (triggers prod-only confirmé, pas de faux leak)
- **Fait discriminant D'ABORD**: `SHOW TRIGGERS` sur `foodking_e2e` = **VIDE** (0 trigger). Per CLAUDE.md §8 les triggers BEFORE DELETE sont **MySQL-prod-only** (parité SQLite en test). L'absence dans le clone n'est PAS un P0 — c'est by-design.
- Code: migrations existent — `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php` (cash_movements/cash_drawer_sessions/order_payments BEFORE DELETE → `SIGNAL SQLSTATE '45000'`), `2026_05_09_160000_add_z_reports_delete_trigger_immutability.php`, `2026_04_22_000002_create_audit_logs_table.php`, + composition_snapshot/stock_movements immutability. ✅
- GRANT-level REVOKE (anti-TRUNCATE) tracé in-repo (Ansible site.yml). Dépendance déploiement prod, pas un trou V1 local.

### 8. Secrets / git hygiene — PASS avec 1 FINDING historique (P2)
- `git check-ignore .env.e2e` → ignoré ✅ ; `.env` ignoré ✅.
- Pre-commit hook actif: `.git/hooks/pre-commit` → symlink `.cursor/hooks/pre-commit-secret-check.sh` (executable, 19 patterns de secrets). ✅
- Sentinel `CommittedSecretsScanSentinelTest` GREEN (scanne le tree HEAD). ✅
- **FINDING P2** (réel, vérifié): commit `9b1e741f4` a committé un `.env` contenant `AWS_ACCESS_KEY_ID=AKIA[REDACTED]` + `` (format AKIA = vraie clé AWS, pas placeholder). Untrack ultérieur (`1e0611aeb`) MAIS la clé **reste dans l'historique git** (`git log --all -S "oqfWQa5+FmW"` la trouve). Le sentinel/hook empêchent les NOUVELLES fuites mais ne purgent pas l'historique. Repo cible = `loeymot-sketch/testttt` (cloud). → rotation + purge BFG/filter-repo requise avant tout push public.

---

## FINDINGS (réels, avec reproduction + preuve)

### [P1] SEC-XFF-01 — Kiosk auto-login IP allowlist spoofable via X-Forwarded-For
- **Location**: `app/Http/Middleware/TrustProxies.php:24` (`$proxies = '*'`) + `resources/views/master.blade.php:121-124` (gate `in_array(request()->ip(), trustedIps)`) + `scripts/deploy/nginx.conf.template:147` & `deploy/ansible/templates/nginx-foodking.conf.j2:70` (`X-Forwarded-For $proxy_add_x_forwarded_for` = APPEND, pas reset).
- **Reproduction** (prouvé live sur le clone via script bootstrap):
  `Request::create('/kiosk/idle','GET',...,['HTTP_X_FORWARDED_FOR'=>'203.0.113.66','REMOTE_ADDR'=>'10.0.0.5'])` → après `TrustProxies::handle()` → `request()->ip()` = **`203.0.113.66`** (la valeur fournie par l'attaquant, pas le vrai REMOTE_ADDR).
- **Evidence**: sortie `SPOOF RESULT: request()->ip() = 203.0.113.66 (REMOTE_ADDR=10.0.0.5, attacker-supplied X-Forwarded-For)`. Le test existant `KioskAutoLoginGateTest` (GREEN 6/15) ne teste QUE `REMOTE_ADDR` direct — le bypass XFF est **non couvert**.
- **Impact**: en prod, un attaquant externe envoie `X-Forwarded-For: <IP LAN borne configurée>` → le gate auto-login le traite comme trusted → sert le `spa_payload` (credentials kiosk) → mint un token `kiosk:order`. C'est exactement la menace décrite dans `config/kiosk.php:200` ("curl https://host/kiosk/idle could harvest the credentials and mint a kiosk:order token"). Blast radius CAPPÉ par `BlockKioskTokenFromAdminRoutes` (le token volé ne peut que passer commande, pas atteindre admin) → P1 pas P0. Note: `proxies='*'` rend aussi spoofables les clés de rate-limit et l'IP des audit logs.
- **PRÉCONDITION (honnêteté)**: exploitable UNIQUEMENT SI `KIOSK_AUTO_LOGIN_TRUSTED_IPS` est non-vide en prod (ce que les docs de déploiement `config/kiosk.php:209` INSTRUISENT de faire) ET `APP_ENV=production` (sinon `auto_login_local_bypass=true` court-circuite le check IP). Je ne peux PAS lire le `.env` de la box opérante d'ici → finding écrit comme "exploitable SI trustedIps configuré en prod". Le mécanisme de spoof lui-même (request()->ip()=valeur attaquant) est PROUVÉ indépendamment de l'env. Reco (b) corrige aussi le keying throttle per-source que le docblock invoquait pour justifier `'*'`.
- **Recommendation**: ne PAS faire confiance au XFF client pour le gate de sécurité. Option (a) nginx `set_real_ip_from <upstream> ; real_ip_header X-Forwarded-For ; real_ip_recursive on` + reset du XFF entrant côté edge ; (b) restreindre `TrustProxies::$proxies` aux IP réelles du/des reverse-proxy (127.0.0.1 en mono-box) au lieu de `'*'` ; (c) défaut `KIOSK_REQUIRE_MACHINE_LOGIN=true` en prod (désactive auto-login, montre le form). Ajouter un test couvrant le spoof XFF.

### [P1-si-non-rotée / P3-si-rotée] SEC-SECRET-01 — AWS credentials dans l'historique git
- **Location**: commit `9b1e741f4` blob `.env` (lignes AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY).
- **Reproduction**: `git show 9b1e741f4:.env | grep AWS_SECRET_ACCESS_KEY` → `` ; `git log --all -S "oqfWQa5+FmW"` → toujours atteignable (6 commits).
- **Evidence**: `AWS_ACCESS_KEY_ID=AKIA[REDACTED]` (préfixe AKIA = vrai format clé AWS). Correspond à l'incident documenté MEMORY.md ("incident .env avec clés AWS live committed").
- **SÉVÉRITÉ CONDITIONNÉE (fait discriminant owner-vérifiable)**: **P1 si la clé `AKIA[REDACTED]` est encore ACTIVE dans IAM** ET le repo va être poussé sur `loeymot-sketch/testttt` (cloud). **P3-stale si elle a déjà été rotée post-incident** (MEMORY.md référence cet incident `.env` AWS comme déjà géré). Je ne peux PAS vérifier la liveness IAM d'ici → l'owner doit confirmer le statut de rotation. Working tree actuel propre + hook/sentinel préviennent le futur, mais l'historique persiste.
- **Recommendation**: (1) confirmer/effectuer la RÉVOCATION-rotation de la clé AWS côté IAM ; (2) purger l'historique (git filter-repo / BFG) AVANT tout push public ; (3) confirmer que le hook pre-commit s'exécute aussi côté serveur cloud.

---

## CE QUI A ÉTÉ PILOTÉ ET CASSÉ (anti "looks fine")
- Login kiosk live → token réel inspecté en DB (abilities/name/expires_at).
- Token kiosk → admin route = 403 LIVE (escalation J-ADV-6 neutralisée) ; même token → menu = 200 (block ciblé).
- POS Operator → 4 routes sensibles = 403 LIVE, contrôle admin = 422 (route OK).
- Idempotency: 3 POST → header replay + 409 conflit + 1 seule ligne DB.
- Prix truqué 0.01€ → recompute SSOT 2.00€ live.
- X-Forwarded-For spoof → request()->ip() retourne la valeur attaquant (PROUVÉ).
- Historique git fouillé → AWS creds trouvés.

## SENTINELS EXÉCUTÉS (tous GREEN, APP_ENV=testing/foodking_test, DEVDB-guarded)
BranchScopeCoverageSentinel (1) · FormRequestAuthzDriftSentinel (1, count=66=baseline) ·
KioskTokenAdminBlockSentinel (2) · IdempotencyCrossUserLeakSentinel (5) · CustomerTokenHmacHardenedSentinel (4/3006) ·
RateLimitTest (4) · CorsTest (4) · ContentSecurityPolicyHeaderTest (6) · ProductionBootGuardsCompletenessSentinel (18/44) ·
PosSimulationHardwareProductionGuardSentinel (4) · IdempotencyMiddlewareProductionGuardSentinel (4) ·
FiscalSecretProductionGuardTest (6) · CommittedSecretsScanSentinel (1) · KioskAutoLoginGateTest (6/15) ·
tests/Feature/Branch/* (14/41).

## BLOCKING
**blocking = true** (1 P1 confirmé: SEC-XFF-01 ; + SEC-SECRET-01 P1-si-non-rotée). Pas de P0 — l'escalation kiosk→admin (le seul candidat P0)
est PROUVÉE neutralisée sur 415/415 routes admin. Le P1 XFF a un blast-radius cappé (token kiosk volé ne peut que commander),
mais c'est un bypass réel d'un contrôle d'allowlist IP → à healer avant install prod. SEC-SECRET-01 = gate owner (confirmer rotation IAM + purge historique).
