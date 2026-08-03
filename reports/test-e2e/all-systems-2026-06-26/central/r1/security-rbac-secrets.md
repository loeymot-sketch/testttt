# CENTRAL r1 — Lentille SÉCURITÉ / RBAC / SECRETS (Utilisateurs + RBAC + Settings)

Sous-système : back-office commerçant — gates Spatie `permission:*`, BranchScope, fuite secrets,
user-enumeration, export scope, asymétrie read/write des gates.
DB : `foodking_e2e`. Mode : READ-ONLY (SELECT + reflection middleware, 0 écriture).

---

## CONTEXTE PROUVÉ (faits vérifiés, base des findings)

- **Groupe de routes admin** (`routes/api.php:274` et `:295`) : middleware =
  `['installed','apiKey','auth:sanctum','block_kiosk_token_admin','localization','throttle:*']`.
  **AUCUN `permission:settings` blanket** — chaque contrôleur s'auto-gate. Donc tout
  token staff valide (POS Operator inclus) atteint physiquement les `index` de `setting/*`.
- **`block_kiosk_token_admin`** = `App\Http\Middleware\BlockKioskTokenFromAdminRoutes`
  (Kernel.php:146) — bloque UNIQUEMENT les tokens à capacité `kiosk:order`. Un POS Operator
  se connecte via login staff normal → token sans `kiosk:order` → **passe** le middleware.
- **Rôle POS Operator** = roles.id=7, **7 permissions** (DB) :
  `dashboard, kitchen-display-system, order-status-screen, pos, pos-discount-up-to-10,
  pos-orders, pos.redeem-loyalty`. **N'a PAS `settings`** (seul Admin id=1 détient `settings`).
- **Utilisateur réel POS Operator** : users.id=3 `pos@lecayenne.fr` branch_id=1 (+ stress/soak).
- **Valeur réelle** : `settings(group=license,key=license_key)` =
  `b6d68vy2-m7g5-20r0-5275-h103w73453q120` (DB). Cette valeur est aussi écrite en `.env` comme
  `MIX_API_KEY` (`LicenseService::update():45`).
- **Infra de test de gate** disponible et VERTE : `GatewaySecretIndexAuthzSentinelTest`,
  `MgmtReadAuthzGateSentinelTest`, `PermissionControllerIndexAuthzTest` (5 passed, 0.17s).

---

## FINDINGS

### [P1] app/Http/Controllers/Admin/LicenseController.php:18 — `index` lit le `license_key` en clair SANS `permission:settings` (asymétrie read/write, atteignable par POS Operator)

- **repro** :
  - `LicenseController::__construct():18` → `$this->middleware(['permission:settings'])->only('update')`.
    Le `index` (l.21-28) n'est PAS gaté.
  - Reflection live (tinker, read-only) sur `app(LicenseController::class)->getMiddleware()` :
    `permission:settings only=[update]` → **index gated by settings? NO**.
  - `LicenseResource::toArray():28` retourne `"license_key" => $this->info['license_key']`
    (clair, aucun masque).
  - Route `routes/api.php:432` : `Route::get('/', [LicenseController::class, 'index'])` sous
    `setting/license`, dans le groupe admin gardé seulement par `auth:sanctum` +
    `block_kiosk_token_admin`.
  - POS Operator (id=7) n'a pas `settings` (DB) → un token POS Operator passe le groupe et
    `index` s'exécute sans gate.
  - curl équivalent (token POS Operator de users.id=3) :
    `GET /api/admin/setting/license` → `200 {"data":{"license_key":"b6d68vy2-m7g5-20r0-5275-h103w73453q120"}}`.
    (Non exécuté en live pour respecter READ-ONLY/no-token-mint ; chaîne prouvée statiquement +
    reflection + DB ; la valeur est confirmée en base.)
- **evidence** : reflection middleware ci-dessus ; `LicenseResource.php:28` ; valeur DB confirmée ;
  jumeau **déjà traité** pour les gateways → `GatewaySecretIndexAuthzSentinelTest` (Payment/SmsGateway
  `->only('update')`→`->only('index','update')`) et `MailController.php:22` commentaire SET-02.
  **`LicenseController` a été OUBLIÉ par cette même vague** (aucun sentinel ne le couvre :
  grep License dans tests/Feature/Admin = absent ; seul KioskFrontendComprehensiveTest mentionne
  "license" hors-sujet).
- **lentille** : commerçant — un caissier (droits POS uniquement) lit une clé/credential que le
  patron entend réserver aux Settings. Asymétrie read/write : écriture gardée, lecture ouverte.
- **reco** (scope-minimal, hors-frozen, motif identique SET-01/SET-02) :
  `LicenseController.php:18` → `$this->middleware(['permission:settings'])->only('index', 'update');`
  + créer `tests/Feature/Admin/LicenseKeyReadAuthzSentinelTest.php` (miroir exact de
  `GatewaySecretIndexAuthzSentinelTest` : assert `permission:settings` gate `index`).
  Le seul consommateur du read est le composant Settings (qui détient `settings`) → 0 surface cassée.
- **note sévérité** : classé **P1** car fuite-credential à un rôle bas (doctrine secret-leak), et
  c'est le jumeau direct des heals SET-01 traités sérieusement. Nuance honnête : `license_key` =
  `MIX_API_KEY`, identifiant API/build du SPA, PAS un secret de paiement/fiscal NF525 ; le plan
  CENTRAL le pré-classe P2 (PIÈGES #2). À arbitrer owner P1/P2 ; le **défaut et la repro sont
  certains**, seule l'étiquette est discutable.

---

### [P3] app/Http/Controllers/Admin/NotificationController.php:18 — `index` (config FCM) gaté seulement sur `update` (asymétrie read/write, mais config Firebase Web = publique-par-design)

- **repro** : `NotificationController.php:18` `->only('update')` ; `index` (l.21) retourne
  `NotificationResource` qui expose `notification_fcm_api_key`, `..._app_id`, `..._project_id`,
  `..._messaging_sender_id`, `..._vapid_key`, etc. (`NotificationResource.php:28-37`). Reflection :
  `permission:settings only=[update]` → index non gaté. Route `routes/api.php:452`.
- **evidence** : reflection middleware ; `NotificationResource.php:28-37` ; **valeurs DB toutes vides**
  (`settings group=notification` → tous `$value=""`). Ces clés FCM sont la **config Firebase Web SDK**
  (apiKey/authDomain/projectId/senderId/vapid), publiques-par-conception (Google : la Web apiKey
  identifie le projet, l'accès est régi par les Security Rules) — **et déjà exposées publiquement**
  par `Frontend\SettingController` via `SettingResource.php:66` sur la route PUBLIQUE
  `routes/api.php:1248` (`/api/frontend/setting`, middleware sans auth).
- **lentille** : commerçant/technique — asymétrie de gate cosmétique ; pas de vrai secret en jeu.
- **reco** : aligner par cohérence (`->only('index','update')`) comme Mail/Gateway, MAIS **NON
  bloquant** : pas une fuite-secret (config web publique + valeurs vides). À traiter en même temps
  que le P1 license pour homogénéiser le pattern read/write.
- **note** : **PAS** un P0/P1. La server-key FCM (réel secret push) n'est pas exposée ici ; ce
  resource ne porte que la config Web. Reporté pour traçabilité du pattern, pas comme leak.

---

## VECTEURS TESTÉS = SAINS (réfutés / déjà gardés — anti-faux-positif)

- **User-enumeration `/admin/customers`** : `CustomerController::index/export` gaté
  `permission:customers` (l.30-35). POS Operator n'a pas `customers` → 403. **Pas de fuite.**
- **`/admin/administrators` (emails Admin)** : `AdministratorController:32` gate
  `permission:administrators` sur index/export. POS Operator n'a pas `administrators` → 403. **Gardé.**
- **Export CSV catalogue** : `ItemCategoryController:8` `export` sous `permission:settings`.
  POS Operator → 403. **Gardé.**
- **Gateways secrets (Payment/Sms/Mail)** : `index` **déjà** gaté `permission:settings`
  (SET-01/SET-02) — sentinels VERTS. **Non régressé.**
- **`Company/OrderSetup/Otp/Cookies/NotificationAlter` index non gatés** : reads sans secret
  (adresse société, flags order-setup, type/digits OTP, texte cookies, toggles alertes). **Aucune
  fuite** → non reporté comme sécurité (info de cohérence uniquement).
- **BranchScope** : `AdminController::authorizeBranchScope/authorizeWritableBranchScope` (l.15-40)
  applique l'isolation branche pour staff scoped ; non contourné sur les chemins audités.

---

## VERDICT lentille SÉCURITÉ/RBAC/SECRETS
- 1× **P1** réel et reproductible (license_key read ungated → POS Operator) — étiquette P1/P2 à
  arbitrer owner ; défaut+repro certains, sentinel à créer.
- 1× **P3** cohérence (FCM notification index asymétrie ; pas un secret).
- Reste du périmètre RBAC/secret/user-enum/export = **SAIN** (vérifié, plusieurs jumeaux déjà
  healés et sentinel-verrouillés).
