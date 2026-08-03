# CENTRAL r1 — Lentille 🧑‍💼 COMMERÇANT — Sous-système Utilisateurs + RBAC + Settings

Auditeur : psychologie commerçant (« fais-je confiance ? un employé peut-il abuser ses droits / lire un secret ? »).
DB : foodking_e2e (read-only). Serveur live :8766. Toutes les preuves ci-dessous = SELECT / GET / lecture de code. 0 écriture.

Question posée du gérant : « Mon caissier (POS Operator) — qu'a-t-il le droit de voir ? Peut-il lire un secret,
voir les réglages, exporter mes clients ? » → matrice prouvée + 1 vraie fuite (license_key).

---

## CONTEXTE PROUVÉ (rôles + permissions réels en DB)

POS Operator (role id=7, guard sanctum) — 7 permissions EXACTES (DB) :
`dashboard, kitchen-display-system, order-status-screen, pos, pos-discount-up-to-10, pos-orders, pos.redeem-loyalty`.
→ AUCUNE permission `settings`, `customers`, `*_report`, `administrators`, `employees`, `license`.
Vérif live : `$user->can('settings')` = **NO** pour `pos@lecayenne.fr` (id=3, branch 1, role POS Operator).

Donc tout ce qui est gaté par `permission:settings` ou `permission:customers` est CORRECTEMENT fermé au caissier.
Le problème est ce qui N'EST PAS gaté.

---

## [P2] app/Http/Controllers/Admin/LicenseController.php:18 — license_key (= clé API) lisible par TOUT staff authentifié, read-gate manquant (asymétrie read/write)

repro:
  - Code : `LicenseController:18` → `$this->middleware(['permission:settings'])->only('update');`
    → seule `update` est gatée. `index()` (l.21-28) retourne `LicenseResource` SANS aucun gate de permission.
  - Route `routes/api.php:431-433` : `Route::get('/', [LicenseController::class,'index'])` vit dans le groupe
    `admin` (api.php:295) dont le middleware est `['installed','apiKey','auth:sanctum','block_kiosk_token_admin',
    'localization','throttle:admin-mutation']` → **PAS de `permission:settings`** au niveau groupe.
  - Donc : tout user authentifié non-kiosk (POS Operator, Waiter, Chef, Branch Manager…) passe `auth:sanctum`
    et obtient la clé. `block_kiosk_token_admin` ne bloque QUE les tokens d'ability `kiosk:order`, pas un staff réel.
  - Valeur fuitée (DB) : `settings.license_key` = `b6d68vy2-m7g5-20r0-5275-h103w73453q120`.

evidence:
  - GRAVITÉ AMPLIFIÉE — la license_key EST la clé API du système :
    `config/app.php:63` → `'api_key' => trim(env('MIX_API_KEY') ?: env('API_KEY',''))`.
    `LicenseService.php:45` → `update()` écrit `$this->envService->addData(['MIX_API_KEY' => $request->license_key])`.
    Preuve live : `.env` `MIX_API_KEY=b6d68vy2-m7g5-20r0-5275-h103w73453q120` == valeur DB `license_key`.
    `ApiKeyMiddleware.php:21-24` valide le header `x-api-key` contre `config('app.api_key')`.
    → lire license_key = connaître le `x-api-key` qui ouvre toute l'API.
  - CONTRASTE = preuve d'oubli, pas d'intention. Les frères porteurs-de-secret gatent BIEN le read :
    `PaymentGatewayController:26` `->only('index','update')` ; `SmsGatewayController:26` `->only('index','update')` ;
    `MailController:22` `->only('index','update')` ; `KioskSetupController:18` + `LoyaltySetupController:18`
    portent un commentaire explicite `[GAP-19-2] Apply permission:settings on both read and write`.
    → une passe de durcissement (GAP-19-2) a fermé les `index` qui fuient ; **LicenseController a été manqué**.
  - Aucun test ne couvre ce read-gate : `grep -rln 'license_key|LicenseController' tests/` = vide
    (le plan 05_SYSTEM_CENTRAL le note « À CRÉER LicenseKeyReadAuthzSentinelTest »).
  - NOTE preuve live : un GET direct non-authentifié sur :8766 `/api/admin/license` renvoie la SPA (302/SPA via
    redirect login de `auth:sanctum`, comportement normal) — donc le leak se prouve par le code+route, pas par un
    curl anonyme. Le curl prouvant la valeur exacte exigerait de forger un token POS Operator (= write DB),
    explicitement INTERDIT par le mandat read-only → preuve = statique (route+controller+resource) + DB value.

lentille: commerçant — un employé caissier peut récupérer la clé qui authentifie toute l'API du restaurant.
  En V1-LOCAL mono-poste « Le Cayenne », le staff = les employés du gérant sur 1 machine, et le `x-api-key` est
  déjà embarqué côté navigateur SPA → blast radius limité (pas multi-tenant, pas cloud) → P2 et non P0/P1.
  Mais c'est un secret qu'un rôle bas ne devrait pas pouvoir lire via API ; asymétrie incohérente avec ses frères.

reco: aligner sur le pattern frère — `LicenseController:18` →
  `$this->middleware(['permission:settings'])->only('index', 'update');`. Sentinel TDD à créer :
  `tests/Feature/Admin/LicenseKeyReadAuthzSentinelTest.php` (POS Operator GET /api/admin/license → 403 ;
  Admin → 200). Hors-frozen, 1 ligne, scope-minimal.

---

## [P3] app/Http/Resources/SettingResource.php:66 — notification_fcm_api_key exposé publiquement (endpoint non-authentifié)

repro:
  - `SettingService.php:18` merge le groupe `notification` dans la payload de `SettingResource`.
  - `SettingResource:66-73` expose `notification_fcm_api_key` + 7 autres `notification_fcm_*`.
  - Servi par `SettingController@index` (Frontend) route `api.php:1248` dans le groupe `frontend`
    (`['installed','apiKey','localization']`) → **AUCUN auth:sanctum**.
  - Preuve live (read-only, x-api-key valide) : `GET /api/admin/.. ` non requis — endpoint PUBLIC :
    `curl /api/frontend/setting` retourne `notification_fcm_api_key` et les 8 clés FCM.

evidence:
  - Live : `fcm keys present: [notification_fcm_api_key, ...8]`, `notification_fcm_api_key value: ''` (VIDE).
  - V1-LOCAL Le Cayenne n'utilise pas FCM web-push → valeur vide, rien à fuiter aujourd'hui.
  - De plus, la clé Firebase web `apiKey` est PUBLIQUE par conception (embarquée dans le JS client) — ce n'est
    pas le « server key » secret. Le `license_key` N'EST PAS dans cette payload (SettingService exclut le groupe
    `license`) — bien.

lentille: commerçant — fuite structurelle d'un champ « clé », mais sans secret réel en V1 et clé publique-par-design.

reco: P3 / V1.0.X — si FCM web-push est activé un jour, ne JAMAIS exposer un éventuel server-key par ce canal ;
  documenter que `notification_fcm_*` = config web publique. Aucun changement V1 requis (champ vide, clé publique).
  NE PAS sur-réagir (la clé web Firebase est faite pour être publique).

---

## VÉRIFIÉ-PROPRE (germes du plan RÉFUTÉS par verify-before-report — NE PAS surfacer comme défaut)

- **POS Operator voit settings/users/exports ?** → NON.
  - Settings « company/site/mail/payment-gateway/sms-gateway/order-setup/tax/role… » : update gaté `permission:settings`
    partout (19 contrôleurs `->only('update')` + ceux en `->only('index','update')`). POS Operator `can(settings)=NO`.
  - `/admin/customer` (export clients + emails) : `CustomerController` gate `permission:customers` sur
    `index/export/show/...` (l.28-39). POS Operator n'a PAS `customers` → 403. La fuite-email-à-un-rôle-légitime
    (plan note #4) concerne un détenteur de `customers`, PAS une escalade POS Operator → hors-périmètre de cette lentille.
  - Reports/exports : POS Operator n'a aucune permission `*_report` → fermé.
- **CompanyController:19 gate update seul** → `index` company expose nom/email/adresse/pays (PAS un secret) → P3 cosmétique
  au plus, pas une fuite de secret. Non retenu comme P0/P1/P2.
- **Aucune** des preuves n'a pu être obtenue par mutation : token POS Operator NON forgé (write DB interdit).
  Les findings ci-dessus tiennent par analyse statique route+controller+resource CROISÉE avec valeurs DB live.

---

## SYNTHÈSE LENTILLE COMMERÇANT
1 vraie incohérence RBAC : la clé API (license_key) est lisible par un caissier via `/api/admin/license`
(read-gate manquant, asymétrie vs ses frères Payment/SMS/Mail qui gatent le read). Sévérité P2 en V1-LOCAL
(mono-poste, staff=employés du gérant, clé déjà côté client). Le reste du périmètre RBAC settings/users/exports
est correctement fermé au POS Operator. FCM = fuite structurelle vide + clé publique-par-design = P3.
