# VERIFY-12 — Sécurité (XSS / CSRF / CORS / rate-limit / Sanctum / secrets)

**Date :** 2026-04-20  
**Mode :** AUDIT-ONLY (lecture seule, aucune modification de code)  
**Origine :** `tasks/verify-2026-04-20/12_VERIFY_SECURITY.md` + `reports/review/AUDIT_POS_110_SECURITY_2026-04-19.md`  
**Priorité :** P0  
**Auteur :** Planner-Orchestrator (Claude)

---

## 0. Plan exécuté (5 lignes)

1. **Pass A backend** : `app/Http/{Kernel,Middleware/*}`, `config/{cors,sanctum,session}.php`, `app/Providers/{Route,Auth}ServiceProvider.php`, `routes/{api,web}.php`, throttles nommés.
2. **Pass B frontend** : grep `v-html` dans `resources/js`, `bootstrap.js` (axios + Echo), `app.js` (interceptor Bearer), `localStorage` tokens, `master.blade.php` (injection runtime), `config/env.js`.
3. **Checklist OWASP-like + matrice route × throttle × auth × permission** (cf. §3 + §4).
4. **V1–V8** évalués (cf. §5).
5. **Verdict global** + cycles `P` de remédiation (cf. §6 + §8).

---

## 1. Sources lues (preuves)

| Domaine | Fichiers consultés |
|---|---|
| Kernel + middleware groups | `app/Http/Kernel.php` |
| Middlewares custom | `ApiKeyMiddleware`, `VerifyCsrfToken`, `EncryptCookies`, `TrustProxies`, `JsonMiddleware`, `CorrelationIdMiddleware` |
| Config sécurité | `config/cors.php`, `config/sanctum.php`, `config/session.php` |
| Providers | `RouteServiceProvider` (rate limiters nommés), `AuthServiceProvider` (Gate::before admin) |
| Routes | `routes/api.php` (1009 lignes), `routes/web.php` |
| HMAC / signatures | `app/Services/Fiscal/{ZReportService,AuditLogService}.php`, `app/Services/PaymentService.php` |
| Frontend | `resources/js/bootstrap.js`, `resources/js/app.js`, `resources/js/config/env.js`, `resources/views/master.blade.php` |
| v-html | 3 fichiers Vue (Page* / PageShow*) — tous via `safeHtml()` |
| Sanitization helper | `tests/js/safeHtml.spec.js`, `tests/Unit/Security/VHtmlStaticGuardTest.php`, `package.json` (`dompurify ^3.4.0`) |
| Secrets repo | `.gitignore` (lignes 8–10), `.env.example` (placeholders) |

`.env` : **présence vérifiée** (`ls -la`), **contenu non lu** (consigne). Listé dans `.gitignore`.

---

## 2. Architecture sécurité — état des lieux

```
Client (SPA) ── x-api-key (apiKey middleware) ──┐
              └─ Bearer Sanctum ────────────────┤
                                                 ▼
                                            routes/api.php
                                                 │
                            ┌────────────────────┼─────────────────────┐
                            ▼                    ▼                     ▼
                     middleware groups      named throttles      route-scoped throttles
                       'api' = throttle:api   login-lockout       throttle:N,M ad hoc
                                              kiosk-orders
                                              kiosk-menu
                                              admin-mutation
                                              pos-order-create/update
                                                 │
                                                 ▼
                                          JsonMiddleware (sec headers)
                                                 │
                                                 ▼
                                         CorrelationIdMiddleware
```

- **Auth** : Sanctum Bearer (token) — pas de session SPA stateful (groupe `api` n'inclut pas `EnsureFrontendRequestsAreStateful`, ligne commentée dans `Kernel.php:44`).
- **API gating** : `apiKey` middleware (header `x-api-key`) sur tous les groupes `auth`, `admin`, `frontend`, `table` — alignée avec invariant FoodKing #4 (`config('app.api_key')`, jamais `env()` direct).
- **CSRF** : conservé sur le groupe `web` ; exclusions seulement pour callbacks de passerelles paiement (sslcommerz, paytm, cashfree, phonepe, iyzico, pesapal).
- **Cookies** : `EncryptCookies` global, `same_site=lax`, `http_only=true`, `secure` piloté par `SESSION_SECURE_COOKIE`.

---

## 3. Checklist OWASP-like (Top 10 mapping)

| # | OWASP 2021 | Surface FoodKing | Constat | Verdict |
|---|---|---|---|---|
| A01 | Broken Access Control | `branch_id` global scope, `Spatie\Permission` (`role`, `permission`, `role_or_permission`), `Gate::before(admin)` | Admin contourne toutes les Gates (`AuthServiceProvider:31`). Routes admin protégées par `auth:sanctum + apiKey + throttle:admin-mutation`. Loyalty sensibles derrière Sanctum (regression bouchée). | OK |
| A02 | Cryptographic Failures | Cookies encrypted, secrets via `config()`, HMAC SHA-256 fiscal (`AuditLogService:223`, `ZReportService:341`) | Pas de hash maison ; Laravel encryption stack standard. | OK |
| A03 | Injection | Eloquent partout, `validate()` + `PosOrderRequest`, `ValidJsonOrder`, prepared statements implicites. `{!! $section->data !!}` dans `master.blade.php` mais source = settings admin only. | Risque résiduel : analytics HTML inject (admin-only XSS si compte admin compromis). | OK ⚠️ |
| A04 | Insecure Design | Pricing SSOT serveur (`OrderService` ignore total client) ; OTP avec ratelimit 3/5min + expiry 5min. | OK | OK |
| A05 | Security Misconfiguration | CORS allow-list explicite (3 origines env), pas de wildcard ; `TrustHosts` désactivé (Kernel:18) — accepté pour environnement derrière reverse-proxy. | Pas de CSP, pas de HSTS au niveau app (typiquement proxy). | WARN |
| A06 | Vulnerable Components | `package.json` : DOMPurify ^3.4.0, `pusher-js`, `laravel-echo`. Composer : non audité ici (hors scope task). | À couvrir par cycle dédié dépendances. | INFO |
| A07 | Identification & Auth Failures | `login-lockout` named limiter (10 / 10 min by `email\|ip`), forgot-password 3/h, OTP 5/min send + 3/5min verify. Sanctum token expiration = 480 min (8h). | OK | OK |
| A08 | Software & Data Integrity | HMAC chain `z_reports` + `audit_logs` ; webhooks paiement **CSRF-excluded**, signature gateway non vérifiée dans `PaymentService` (à confirmer côté gateway controllers, hors champ ici). | À vérifier par-passerelle. | WARN |
| A09 | Security Logging & Monitoring | `CorrelationIdMiddleware` + `Log::withContext(correlation_id, user_id, branch_id)`, `AuditLogService` HMAC. | OK | OK |
| A10 | SSRF | Pas de fetch outbound dynamique côté frontend serveur (review limitée). | INFO | OK |

---

## 4. Matrice route × throttle × auth × permission (extraits critiques)

| Route | Méthode | Auth | API key | Throttle | Permission/scope | Notes |
|---|---|---|---|---|---|---|
| `/api/auth/login` | POST | guest | apiKey | `login-lockout` (10/10min by email\|ip) | — | named limiter, retry-after 900s |
| `/api/auth/kiosk-login` | POST | guest | apiKey | `login-lockout` | — | partage le même limiter |
| `/api/auth/forgot-password` | POST | guest | apiKey | `3,60` (3/h) | — | anti-spam SMS |
| `/api/auth/forgot-password/verify-code` | POST | guest | apiKey | `5,1` | — | OK |
| `/api/auth/forgot-password/reset-password` | POST | guest | apiKey | `5,1` | — | OK |
| `/api/auth/signup/otp` | POST | guest | apiKey | `5,1` | — | anti-flood OTP |
| `/api/auth/signup/verify` | POST | guest | apiKey | `3,5` | — | anti-bruteforce OTP |
| `/api/auth/guest-signup/otp` | POST | guest | apiKey | `5,1` | — | OK |
| `/api/auth/guest-signup/verify` | POST | guest | apiKey | `3,5` | — | OK |
| `/api/refresh-token` | POST | — | apiKey | `throttle:api` (60/min default) | — | pas de Sanctum (par design) |
| `/api/admin/*` | * | sanctum | apiKey | `admin-mutation` (30/min) | `permission:*` (Spatie) | Gate::before admin bypass |
| `/api/admin/.../pos` (store) | POST | sanctum | apiKey | `pos-order-create` (60/min) | POS operator perms | OK |
| `/api/admin/.../pos/{id}` (update) | PATCH | sanctum | apiKey | `pos-order-update` (120/min) | POS operator perms | OK |
| `/api/admin/.../fiscal/z-report/open` | POST | sanctum | apiKey | `10,1` | admin/manager | NF525 — séquence monotone |
| `/api/admin/.../fiscal/z-report/close` | POST | sanctum | apiKey | `10,1` | admin/manager | NF525 — séquence monotone |
| `/api/frontend/order` (kiosk store) | POST | sanctum | apiKey | `kiosk-orders` (5/min/IP) | kiosk:order ability | OK |
| `/api/frontend/menu` (kiosk) | GET | sanctum | apiKey | `kiosk-menu` (60/min) | kiosk:order | OK — récemment ajouté ✅ |
| `/api/frontend/pricing/preview` | POST | sanctum | apiKey | `60,1` | kiosk:order | OK |
| `/api/frontend/promo/validate` | POST | sanctum | apiKey | `30,1` | kiosk:order | OK |
| `/api/frontend/upsell` | GET | sanctum | apiKey | `60,1` | kiosk:order | OK |
| `/api/frontend/loyalty/opt-in` | POST | guest | apiKey | `5,1` | — | OK |
| `/api/frontend/loyalty/scan` | POST | sanctum | apiKey | `20,1` | kiosk:order | OK |
| `/api/frontend/coupon/coupon-checking` | POST | guest | apiKey | `10,1` | — | OK (anti-bruteforce) |
| `/api/frontend/loyalty/check` | POST | sanctum | apiKey | `10,1` | — | régression sécurité corrigée ✅ |
| `/api/frontend/loyalty/register` | POST | guest | apiKey | `5,1` | — | OK |
| `/api/frontend/loyalty/{add-points\|redeem\|balance\|history}` | * | sanctum | apiKey | (group) | — | OK |
| `/api/frontend/subscriber` | POST | guest | apiKey | `5,1` | — | OK |
| `/api/table/dining-order` | POST | guest | apiKey | `20,1` | — | unauth = QR code, OK |
| `/api/health[/live\|/ready]` | GET | guest | — | `throttle:api` (60/min) | — | OK |
| `/payment/{gateway}/{order}/{success\|fail\|cancel}` | * | guest | — | aucun | — | **CSRF excluded** — signature à vérifier dans gateway controller |

**Synthèse matrice :** aucune route admin POST identifiée sans throttle. Le hook gateway paiement est le seul périmètre où un attaquant non authentifié peut interagir sans rate limiter explicite — risque atténué par la nature one-shot par `order` mais à durcir.

---

## 5. Vérifications obligatoires V1–V8

### V1 — CORS limité aux origines de prod : **PASS**
- `config/cors.php` : `allowed_origins = array_filter([env('APP_URL'), env('KIOSK_DOMAIN'), env('ADMIN_DOMAIN')])`. **Pas de wildcard**, `allowed_origins_patterns = []`.
- `paths = ['api/*', 'sanctum/csrf-cookie']` — ne s'applique pas aux routes web.
- `supports_credentials = true` — cohérent avec un éventuel mode SPA/cookie.
- ⚠️ Nuance : si `APP_URL`/`KIOSK_DOMAIN`/`ADMIN_DOMAIN` ne sont pas définis en prod, `allowed_origins` devient `[]` → toutes les requêtes cross-origin sont refusées (fail-closed, comportement sûr). Vérifier check de démarrage prod.

### V2 — Sanctum SameSite + Secure : **PASS conditionnel**
- `session.same_site = 'lax'` (hardcodé), `http_only = true`.
- `secure = env('SESSION_SECURE_COOKIE')` — **dépend de l'env prod**. Recommander un test post-déploiement.
- Sanctum guard `web`, expiration tokens = 480 min (override possible via `SANCTUM_TOKEN_EXPIRATION`).
- `EncryptCookies` actif globalement, exclusions vides.
- `EnsureFrontendRequestsAreStateful` est **commenté** dans `Kernel.php:44` → l'app fonctionne en mode Bearer pur côté API (pas de SPA stateful). Cohérent avec `app.js` (interceptor Bearer depuis `localStorage.vuex`).

### V3 — Throttles `pos`, `kiosk-menu`, `pricing`, `coupon` : **PASS**
- Tous les limiteurs nommés sont définis dans `RouteServiceProvider::configureRateLimiting()` (lignes 50–101) :
  - `api` (60/min cfg), `kiosk-orders` (5/min/IP), `kiosk-menu` (60/min), `admin-mutation` (30/min), `pos-order-create` (60/min), `pos-order-update` (120/min), `login-lockout` (10/10min).
- Coupon (`/coupon-checking`) : `throttle:10,1` ad hoc — OK.
- Pricing preview : `throttle:60,1` — OK.
- Aucune route POST/PUT/DELETE `/api/admin/*` sans throttle (hérite de `admin-mutation`).

### V4 — Aucun `v-html` non sanitisé : **PASS**
- 3 occurrences de `v-html` :
  - `resources/js/components/frontend/page/PageComponent.vue:12`
  - `resources/js/components/table/page/PageComponent.vue:12`
  - `resources/js/components/admin/settings/Page/PageShowComponent.vue:21`
- **Toutes** wrappées par `safeHtml(page.description)` (helper `resources/js/utils/safeHtml.js`, basé sur `dompurify ^3.4.0`).
- Garde-fou : `tests/Unit/Security/VHtmlStaticGuardTest.php` (regex test bloque toute régression).
- Tests : `tests/js/safeHtml.spec.js` (script tag, onerror, iframe, javascript: URL).
- ⚠️ `master.blade.php:35,49,65` utilise `{!! $section->data !!}` pour analytics → admin-only injection (settings authentifiés admin) ; risque XSS stocké si admin compromis. Note pour cycle séparé (hors scope V4 strict côté Vue).

### V5 — Aucun secret commit dans repo : **PASS**
- `.gitignore` : `.env`, `.env.backup`, `.env.testing` exclus (lignes 8–10).
- `.env.example` : valeurs **placeholders** (`change-me-long-random-string-local-dev`, etc.) — **vérifié sans lire `.env`**.
- `master.blade.php:78–110` : injecte côté client uniquement `apiKey`, `googleMapKey`, demo credentials (si `demo_mode=true`). **Pas de secret backend**.
- ⚠️ `apiKey` (`x-api-key` header) est exposé dans le bundle JS — c'est par design (clé publique d'app mobile/SPA, pas un secret backend) ; documenté dans `.env.example:40`. À traiter comme un identifiant client, pas comme un secret cryptographique.
- ⚠️ Mode démo : si `APP_DEMO_MODE=true` **en prod par accident**, les credentials demo sont publiés dans le HTML. Recommander un test boot-time (`AppServiceProvider::boot` → throw si `demo_mode && env=production`).

### V6 — Headers de sécurité : **WARN (partiel)**
- `JsonMiddleware` (groupe `api`) ajoute :
  - `X-Content-Type-Options: nosniff` ✅
  - `X-Frame-Options: SAMEORIGIN` ✅
  - `X-XSS-Protection: 1; mode=block` ✅ (legacy mais inoffensif)
  - `Referrer-Policy: strict-origin-when-cross-origin` ✅
- **Manquants :**
  - `Content-Security-Policy` (aucun, ni en blade ni en middleware) ❌
  - `Strict-Transport-Security` (HSTS) — typiquement au reverse-proxy ⚠️
  - `Permissions-Policy` — utile pour kiosk (caméra/micro/géolocalisation) ❌
  - Headers absents sur le groupe `web` (HTML SPA root) — `JsonMiddleware` est dans le groupe `api` uniquement.
- → Cycle dédié `P12_SECURITY_HEADERS` recommandé (cf. §8).

### V7 — Routes auth (validation OTP / rate limit login) : **PASS**
- `login` + `kiosk-login` : `login-lockout` named limiter (10 essais / 10 min, clé = email\|ip + retry-after 900s).
- OTP send : `throttle:5,1` (signup, guest-signup).
- OTP verify : `throttle:3,5` (3 tentatives / 5 min) — laisse 4-digit OTP impossible à brute-forcer dans la fenêtre d'expiration (cf. commentaire `routes/api.php:168–169`).
- `forgot-password` : 3/h, validé OTP 5/min, reset 5/min.
- Pas de captcha — acceptable au vu des limiteurs.

### V8 — Webhooks signés (HMAC) : **WARN**
- Fiscal interne (`ZReportService::computeSignature`, `AuditLogService::computeSignature`) : HMAC SHA-256 chaîné — **PASS** sur surface fiscale.
- Pusher / Echo : auth via `/api/broadcasting/auth` (Sanctum) — **PASS** (pas un webhook entrant à proprement parler).
- Webhooks paiements : `VerifyCsrfToken::$except` exclut `/payment/{sslcommerz,paytm,cashfree,phonepe,iyzico,pesapal}/*`. **`PaymentService` ne vérifie aucune signature** ; les contrôleurs gateway individuels n'ont **pas** été inspectés (hors scope task §2 mais surface critique).
- **Risque** : si un contrôleur gateway ne vérifie pas la signature côté provider, un attaquant peut forger un callback `success` et marquer une commande comme payée.
- → Cycle dédié `P11_WEBHOOK_SIGNATURE_AUDIT` recommandé (cf. §8).

---

## 6. Verdict

| V | Critère | Statut |
|---|---|---|
| V1 | CORS allow-list | ✅ PASS |
| V2 | Sanctum cookies | ✅ PASS (conditionnel `SESSION_SECURE_COOKIE` prod) |
| V3 | Throttles | ✅ PASS |
| V4 | v-html sanitisé | ✅ PASS |
| V5 | Secrets repo | ✅ PASS |
| V6 | Security headers | ⚠️ WARN (CSP + HSTS + Permissions-Policy manquants ; pas de headers sur groupe `web`) |
| V7 | Auth/OTP throttles | ✅ PASS |
| V8 | Webhooks HMAC | ⚠️ WARN (fiscal HMAC OK ; signature webhooks paiement non confirmée) |

> **Application des critères §6 du task :** V1, V2, V4, V5 = PASS → **pas de FAIL**. V6 partiel + V8 partiel → **WARN**.

### **GLOBAL: WARN**

---

## 7. Hypothèses challenged (récap)

| # | Hypothèse | Résultat |
|---|---|---|
| H1 | Route admin POS sans throttle | **Faux** — toutes les mutations admin héritent de `admin-mutation`, POS dédié `pos-order-create`/`pos-order-update`. |
| H2 | v-html sur input non sanitisé | **Faux** — 3 occurrences, toutes via `safeHtml()` + DOMPurify + test statique. |
| H3 | CORS allow-all en prod | **Faux** — allow-list à 3 origines env (fail-closed si vide). |
| H4 | Sanctum cookie non SameSite/Secure | **Partiel** — `same_site=lax` OK, `secure` dépend de `SESSION_SECURE_COOKIE` env (à vérifier en prod). |
| H5 | Secrets commit / leak JS | **Faux** — `.env` gitignore + placeholders `.env.example` ; `apiKey` exposé volontairement (clé publique). |
| H6 | Throttle `kiosk-menu` mal calibré | **Faux** — 60/min raisonnable pour usage borne (~1 req/min nominal, pic admissible). |
| H7 | Webhooks non signés | **Probable mais non confirmé** — `PaymentService` ne vérifie pas ; à confirmer par-gateway. |

---

## 8. Suite — cycles `P` recommandés

| Priorité | Cycle | Trigger | Scope |
|---|---|---|---|
| P0 | `P11_WEBHOOK_SIGNATURE_AUDIT` | V8 WARN | Auditer chaque contrôleur de `app/Http/PaymentGateways/Gateways/*.php` + routes `/payment/{gateway}/*` : confirmer vérification signature provider (HMAC ou checksum). FAIL si une seule passerelle traite le callback sans signature. |
| P1 | `P12_SECURITY_HEADERS` | V6 WARN | Ajouter middleware `SecurityHeadersMiddleware` sur **tous les groupes** (`web` + `api`) : `Content-Security-Policy` (nonce), `Permissions-Policy` (caméra/micro/geo désactivés sauf borne), `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy`. HSTS au niveau reverse-proxy. |
| P2 | `P13_DEMO_MODE_PROD_GUARD` | V5 WARN secondaire | Boot-time check : `AppServiceProvider::boot` → `throw RuntimeException` si `app.demo_mode === true && app.env === 'production'`. Couvre le risque demo credentials publiés en prod. |
| P3 | `P14_SECURE_COOKIE_BOOT_CHECK` | V2 conditionnel | Ajouter une validation `php artisan about`/health-check : refuser le démarrage en prod si `SESSION_SECURE_COOKIE != true`. |
| P4 | `P15_ANALYTICS_HTML_SANITIZE` | A03 nuance | Sanitiser ou whitelister les snippets analytics injectés via `{!! !!}` dans `master.blade.php` ; alternative : passer par un store de tags structurés (GA/Pixel ID + script généré). |

Aucun `FAIL` détecté → pas de gate de blocage immédiate ; procéder par cycles ordonnés P0 → P4.

---

## 9. Conformité scope

- **Aucune** modification de code applicatif effectuée.
- **Aucun** secret lu dans `.env` (présence vérifiée par `ls -la` uniquement).
- Seul livrable écrit : ce rapport.
- Subsystèmes lus uniquement (`app/Http`, `config`, `routes`, `resources/js`, `resources/views/master.blade.php`, `tests`).
- Invariants FoodKing respectés : pas d'altération auth / pricing / dispatch / branch isolation.

---

## 10. Conclusion

**GLOBAL: WARN**

- 6 vérifications PASS sur 8.
- 2 WARN : `V6` (security headers HTML/CSP) et `V8` (signatures webhooks paiement non confirmées).
- 0 FAIL.
- Surface critique sécurisée : auth/login lockout, OTP, throttles POS/kiosk/pricing/coupon, CORS allow-list, Sanctum cookies (config), v-html sanitisation, isolation `branch_id`, fiscal HMAC chain.
- Plan de remédiation en 5 cycles ordonnés (P0 webhooks → P4 analytics).

---
