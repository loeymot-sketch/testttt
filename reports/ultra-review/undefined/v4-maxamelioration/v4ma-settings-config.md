# V4-MAX-AMÉLIORATION — Surface SETTINGS / CONFIG admin

HEAD 61e9ea7b7 + working-tree + 47f3ad545. Serveur LIVE 127.0.0.1:8766 (DB foodking_e2e).
Posture refute-by-default. Toute écriture DB/fichier interdite (sauf ce rapport).

## Périmètre attaqué
- Groupe `Route::prefix('admin')->middleware(['installed','apiKey','auth:sanctum','block_kiosk_token_admin','localization','throttle:admin-mutation'])` (routes/api.php:302) — PAS de gate `permission:settings` au niveau du groupe : chaque contrôleur gate lui-même.
- `setting/{company,site,order-setup,kiosk-setup,loyalty-setup,mail,currency,tax,payment-gateway,sms-gateway,notification,notification-alert,theme,social-media,analytic,slider,page,branch,item-category,item-attribute,menu-template,otp,cookies,default-access,license}`
- `SiteService` (APP_DEBUG / env), `CompanyService` (APP_NAME/env), `EnvEditor::save/santize`, `SettingResource` public.

## Verdict global : LARGEMENT PROTÉGÉ (heals V1–V4 confirmés)
Toutes les MUTATIONS de settings sont gatées `permission:settings`, et `permission:settings`
est accordé UNIQUEMENT au rôle Admin (vérifié LIVE) :
```
role_has_permissions ∩ (name='settings') → role_id=1 name=Admin guard=sanctum ; 0 grant direct utilisateur
POS Operator (pos@lecayenne.fr) can_settings=N ; Chef (chef@lecayenne.fr) can_settings=N ; Branch Manager can_settings=N
```
Non-auth → 401 sur `PUT /admin/setting/site` et `GET /admin/setting/payment-gateway` (LIVE).
Les FormRequests sensibles (Tax/Otp) doublent en defense-in-depth (`->can('settings')`).

## FINDINGS (améliorables)

### [P3, latent P2] `NotificationController::index` NON gaté → credentials FCM lisibles par un rôle non-admin
- Fichier : app/Http/Controllers/Admin/NotificationController.php:18
  `$this->middleware(['permission:settings'])->only('update');`  → **index absent**.
- Contraste (heals explicites) : MailController.php:22 `->only('index','update')` (SET-02),
  PaymentGatewayController.php:26 `->only('index','update')` (SET-01), SmsGatewayController `->only('index','update')`.
  Notification a été **oublié** par la même vague de heal.
- Le groupe (routes/api.php:458-461) n'exige que `auth:sanctum` + `block_kiosk_token_admin`.
  Donc `GET /api/admin/setting/notification` est atteignable par TOUT utilisateur panel
  authentifié — **POS Operator / Chef** (can_settings=N) — qui n'a PAS le droit `settings`.
- Payload fuité (NotificationResource) : `notification_fcm_api_key`, `notification_fcm_json_file`
  (référence du fichier **service-account JSON**), auth_domain, project_id, sender_id, app_id…
  Le service-account JSON = clé privée d'envoi de push.
- Repro (par construction, sans écriture DB) :
  1. NotificationController.php:18 ne couvre pas `index` ;
  2. MailController.php:22 / PaymentGatewayController.php:26 couvrent `index` (preuve que le pattern attendu = gater index) ;
  3. DB : POS Operator/Chef `can_settings=N` ; groupe admin sans gate settings.
- CAVEAT honnête : dans CETTE base V1 (mono-poste, push non configuré) les 9 champs FCM sont
  **vides** (`Settings::group('notification')->all()` → "" partout). Impact concret nul tant que
  FCM n'est pas configuré → **P3**. Devient **P2** (fuite de secret réelle) dès qu'un déploiement
  active les notifications push. C'est une régression de couverture du heal SET-01/02, pas un mythe.
- Fix : `->only('index','update')` sur NotificationController (miroir de Mail/PaymentGateway/Sms).

### [P3] Injection de ligne `.env` via champs settings non assainis (EnvEditor naïf)
- `CompanyService.php:44` écrit `APP_NAME => company_name` dans .env via EnvEditor ;
  `SiteService.php:47-55` écrit APP_DEBUG/TIMEZONE/CURRENCY_*/DATE_FORMAT depuis les champs site.
- Validation : `CompanyRequest` `company_name => ['required','string','max:190']`, `SiteRequest`
  champs `string` — **aucun blocage de `"` ni de saut de ligne**.
- EnvEditor `save()` (vendor/dipokhalder/laravel-env-editor/src/EnvEditor.php:398-414) fait
  `$key.'='.$value` puis `implode("\n")` ; `santize()` (l.488-500) n'enveloppe de guillemets que
  si l'espace est présent et gère mal un `"` interne.
- Repro (simulation pure, sans toucher .env) — un `company_name` = `x" \nPOS_SIMULATION_HARDWARE=true`
  produit dans .env :
  ```
  APP_NAME="x" 
  POS_SIMULATION_HARDWARE=true"
  ```
  → primitive d'injection : une nouvelle ligne `KEY=value` est écrite dans .env. Selon la
  tolérance de phpdotenv, cela peut injecter/écraser une clé (ex. APP_DEBUG, POS_SIMULATION_HARDWARE)
  ou corrompre .env (brick au prochain optimize:clear / boot).
- Gated `permission:settings` (admin) → pas d'escalade non-admin, donc **P3**. Mais contourne
  l'invariant « les flags env NF525-critiques sont fixés au déploiement seulement » et un admin
  compromis peut poisonner l'env sans laisser de trace côté audit fiscal.
- Fix : `regex:/^[^\r\n"]+$/` (ou strip CR/LF/`"`) sur les champs settings liés à l'env.

### [P3] Incohérence defense-in-depth : `authorize() { return true; }` sur FormRequests settings
- SiteRequest.php:16 et CompanyRequest.php:17 → `return true;` (protégés uniquement par le
  middleware contrôleur), alors que TaxRequest/OtpRequest ont été durcis en `->can('settings')`.
- Non exploitable aujourd'hui (le middleware contrôleur gate). Devient exploitable si un futur
  refactor déplace la route hors du groupe gaté ou réutilise le FormRequest ailleurs.
- Fix : aligner sur TaxRequest (`return $this->user()?->can('settings') ?? false;`).

## Attaques réfutées (settings = protégé, prouvé)
- Écriture setting par POS Operator/Chef/Branch Manager : **bloqué** — tous les writes gatés
  `permission:settings`, accordé au seul rôle Admin (DB LIVE).
- APP_DEBUG activable en prod via SiteService : admin-only + boot-guard AppServiceProvider refuse
  le boot → pas un P1 (conforme aux vérités de mission).
- Fuite secrets via `GET /frontend/setting` (public) : SettingResource = allowlist explicite,
  0 champ payment/secret.
- payment-gateway / mail / sms-gateway index : gatés (SET-01/02) → 401 non-auth confirmé.
- Tax/Currency modifient le pricing : PricingService recalcule depuis la config live (SSOT) et
  les commandes passées gardent `composition_snapshot` gelé → pas de réécriture fiscale rétroactive.
