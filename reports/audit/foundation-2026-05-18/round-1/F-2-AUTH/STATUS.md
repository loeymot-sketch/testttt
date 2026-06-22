# F-2 Auth + Securite — Foundation Audit STATUS

> Audit READ-ONLY profond, parallele aux 9 autres systemes Foundation
> Branche: heal/cms-pr1-quickwins-2026-05-18
> Date: 2026-05-18
> Mode: 4 specialistes (Architect + Security + SRE + RED-team) en parallele
> Mantra: KEEP current working system — proposer V1 quick wins ou backlog V1.0.1/V1.0.2, JAMAIS toucher au frozen ou recemment-heal.

---

## 1. Resume executif (10 lignes)

L'authentification FoodKing est **HARDENED et CORRECTEMENT GATEE** apres les Wave H1/H6/3b/3c/5G/5H/7. Sur 13 vecteurs d'attaque RED-team probes, **12 sont fermes**, 1 partiel (OTP brute-force defendable par TTL+throttle).

Les heals critiques recents (RefreshToken abilities preservation, channel-auth par token NAME, TrustHosts anchored regex, EnsureUserStatusActive ordre middleware, bcrypt rounds 10->12 transparent rehash, token rotation on relogin) sont en place et **doivent etre preserves**.

**Trouvailles ouvertes**: 2 recommandations P1 (password-reset min:12 + auth-failure logging) et 4 recommandations P2 (CSP enforce, /authcheck throttle, FormRequest authz unification, sentinel test). **AUCUN P0**. **Aucune action V1 obligatoire**. Tout est backlog V1.0.1 ou V1.0.2.

---

## 2. DEAD-CODE (a supprimer)

**AUCUN dead-code identifie dans la zone Auth + Securite.**

Tous les fichiers, controllers, middlewares et limiters identifies sont actifs et reaches par au moins une route. La complexite du flow (3 paths token: admin / kiosk / guest) est documentee et necessaire.

---

## 3. SAFE-TO-CONSOLIDATE (re-organisation possible sans risque)

| # | Item | Justification | Cout | Risque |
|---|------|---------------|------|--------|
| C1 | 72 FormRequests avec `authorize(): return true` -> base class `RequiresPermission` avec ::requiredPermission(): array | Force la declaration explicite des authz; rend l'audit grep-able. Doc BRAIN '88 endpoints partial heal' (80 still scattered). | 2-3j (1 wave) | Moyen — touche 72 fichiers, regression possible si oubli de declaration. **V1.0.2 backlog**. |
| C2 | 2 groupes admin paralleles dans routes/api.php (lignes 256, 270) -> macro `Route::adminGroup(throttle: '60,1')` | Le `throttle:60,1` group existe seulement pour 3 endpoints (menu/availability/toggle x3). Pattern non-evident, comment block documente le piege. | 0.5j | Faible. **Optionnel V1.0.2**. |
| C3 | OTP rate-limit duplique entre Signup + GuestSignup + ForgotPassword | Trois fois le meme pattern `/otp throttle:5,1` + `/verify throttle:3,5`. Macro candidate. | 0.5j | Faible. **Optionnel V1.0.2**. |
| C4 | Log::channel('security') canal dedie + Log::warning() sur chaque 4xx branch des controllers Auth | Forensic gap: aucune visibilite sur brute-force ou kiosk-machine inactif. Sigle restaurant V1 OK; production -> requis. | 1j | Faible. **V1.0.2 backlog**. |

---

## 4. KEEP-AS-IS (NE PAS TOUCHER — etat parfait)

Liste explicite — ces composants sont **soit frozen soit recemment-healed correctement**, et toute modification serait une regression.

### 4.1 Frozen / recemment-heal (ne pas modifier sans LOCK + owner gate)

| Fichier | Etat | Raison KEEP |
|---------|------|-------------|
| `app/Http/Middleware/IdempotencyKeyMiddleware.php` | FROZEN | NF525-adjacent, gere replay HTTP avec idempotency-key. Heal Wave H = stable. |
| `app/Http/Middleware/TrustProxies.php` | HEALED Wave 3 SYNC-ADV3-01 | $proxies='*' correct pour LAN nginx+PHP-FPM. Comment block documente le pourquoi. |
| `app/Http/Middleware/TrustHosts.php` | HEALED Wave 3c b1c50311d (anchored regex) | Ferme l'attaque host-spoof prior audit. NE PAS modifier la regex. |
| `app/Http/Kernel.php` ($middlewarePriority array) | HEALED Sprint H1 Z6-06 | Insere EnsureUserStatusActive APRES AuthenticatesRequests. Sans cet override, Laravel array_unshift place le middleware en position 0 -> bypass. |
| `routes/channels.php` Broadcast::channel('branch.{branchId}') | HEALED R3 T-3.2.2 F-SEC-W6-01/02 | Discriminator par token NAME (kiosk-token) immune au wildcard Sanctum '*'. Plus la clause Admin/Tenant Admin (role check) qui ferme la guest-branch_id=0 path. |
| `app/Http/Controllers/Auth/RefreshTokenController.php` | HEALED iter15-P0-07 | Preserve abilities source, fallback `[]` (jamais `['*']`). Ferme privilege escalation. |
| `app/Http/Controllers/Auth/LoginController.php` (lignes 95-115) | HEALED Wave 5G/5D Z6-01 | Transparent bcrypt rehash + token rotation on relogin. CLAUDE.md §9 explicit. |
| `app/Http/Controllers/Auth/GuestSignupController.php` (ligne 146) | HEALED Sprint H1 Z6-02 | Token guest = ability ['kiosk:order'] (pas '*') + 30j TTL. Ferme le wildcard guest. |
| `config/hashing.php` rounds=12 | HEALED Wave 5G | Bumped 10 -> 12. Rehash transparent on login. |
| `config/auth.php` login_lockout config | HEALED W8.B REM B3 | is_string() guards contre fuzz email[]=array attack. |
| `app/Listeners/RevokeTokensOnBranchDeactivated.php` | OK | Scope strict `tokenable_type=User::class` pour NE PAS toucher aux kiosk-machine tokens. |

### 4.2 Working-as-designed (ne pas modifier sans owner gate)

| Composant | Etat | Raison KEEP |
|-----------|------|-------------|
| `EnsureUserStatusActive` per-request DB SELECT | Owner Gate G4 choisi | Trade-off documente: simplicite des semantiques (admin disable -> next request 401) vs perf cost. Bounded par SELECT indexe. |
| CSP mode='report_only' default | Owner gate K-9 dedie | Volontairement permissif pour Vue kiosk inline scripts. Tightening attend 30j de CSP-report telemetry. |
| x-api-key shared (config/app.api_key) | V1 single-tenant | Defense-in-depth, pas auth principale. SaaS V2 demanderait per-tenant. |
| TwoFactor Auth absente | V1 scope | Owner V1 = single-restaurant, OTP guest + login-lockout suffisent. V2 SaaS = backlog. |

---

## 5. RECOMMENDATIONS (V1.0.1 quick wins + V1.0.2 backlog)

### 5.1 V1.0.1 quick wins (1-2 lignes, faibles risques)

| ID | Titre | Fichier | Effort | Risque |
|----|-------|---------|--------|--------|
| **R1 (P1)** | Password reset rules: bump `min:6` -> `min:12` sur reset-password endpoint (parite avec staff create/update) | `app/Http/Controllers/Auth/ForgotPasswordController.php:122-124` | 2 lignes | Faible — affecte UI password-reset (message d'erreur "trop court"). Tests E2E auth-reset doivent etre mis a jour. |
| **R2 (P2)** | /authcheck explicit throttle | `routes/api.php:202` | 1 ligne (`->middleware('throttle:60,1')`) | Faible. Verifier que SPA hydration n'appelle pas authcheck en boucle. |

### 5.2 V1.0.2 backlog (workshops dedies)

| ID | Titre | Effort | Owner gate |
|----|-------|--------|-----------|
| **B1 (P1)** | Auth-failure structured logging — Log::channel('security')->warning() sur tous les 4xx auth | 1j (3 controllers + config/logging.php) | Non |
| **B2 (P2)** | FormRequest authz unification — base class + sentinel test bloquante CI | 2-3j (1 wave dediee) | Oui (touche 80 fichiers) |
| **B3 (P2)** | CSP enforce migration — collecter 30j de report-only puis bascule conditionnelle | 2j + 30j observation | Oui (cycle K-9 dedie) |
| **B4 (P3)** | Sentinel test middlewarePriority — assert EnsureUserStatusActive runs apres TokenGuard | 0.5j | Non |
| **B5 (P3)** | Per-email throttle sur verify-code OTP (cross-keys email|ip comme login-lockout) | 0.5j | Non |
| **B6 (P3)** | Production-readiness checklist cloud-deploy — TrustProxies $proxies -> CIDR range | 0.5j (doc + deploy doctrine) | Oui (declenche par owner cloud-go) |

---

## 6. User-friendly decision points (questions a poser au owner si reactive)

> Le owner a explicitement demande de continuer sans pauses. Les questions ci-dessous sont **archive non-bloquante** — a poser uniquement si le owner reouvre le sujet F-2.

1. **R1 (password reset min:12)** — "On bump le min de password sur le reset endpoint de 6 a 12 caracteres. Impacts: l'UI password-reset doit afficher l'erreur 'min 12'. Tests E2E auth-reset a regenerer. Aller V1.0.1 ou attendre V1.0.2?" — **Default: V1.0.1.**

2. **B1 (auth-failure logging)** — "On ajoute un canal de log 'security' avec Log::warning sur chaque 4xx d'auth (login fail, kiosk inactive, OTP fail, ...). Permet l'audit forensic post-incident. Rotation logs 30j. V1.0.2 OK?" — **Default: V1.0.2.**

3. **B2 (FormRequest authz unification)** — "80 FormRequests ont encore `authorize(): return true`. On factorise une base class RequiresPermission + sentinel test CI. Cout 2-3 jours, risque moyen (touche 80 fichiers). V1.0.2 ou V2?" — **Default: V1.0.2.**

4. **B3 (CSP enforce)** — "La CSP est en mode 'report_only' depuis cycle K-... On collecte 30 jours de telemetry puis on bascule en enforce. Necessite cycle K-9 dedie. Cycle V1.0.2 ou apres?" — **Default: V1.0.2.**

---

## 7. Attestations (etat actuel verifie par lecture)

- bcrypt rounds = 12 (config/hashing.php:39) + transparent rehash on login (LoginController.php:95-98)
- Sanctum TTL = 480min default, env-overridable (config/sanctum.php:50)
- Token rotation on relogin (LoginController.php:109 + KioskMachineLoginController.php:96)
- Login-lockout = 10/10min keyed email|ip + is_string() fuzz guard (RouteServiceProvider.php:154-173, config/auth.php:125-128)
- Kiosk-login = 30/min keyed username|ip (RouteServiceProvider.php:115-128)
- RefreshToken preserve source abilities, jamais '*' fallback (RefreshTokenController.php:42-46)
- Channel-auth par token NAME, immune au wildcard (routes/channels.php:41-65)
- EnsureUserStatusActive correctement ordonne via Kernel.php $middlewarePriority (Kernel.php:80-95)
- TrustHosts regex anchored (TrustHosts.php:34-40)
- TrustProxies $proxies='*' = correct pour LAN PHP-FPM local
- CSP mode=report_only default (config/security.php:26)
- VerifyCsrfToken exclut stripe-webhook + senangpay-webhook (signature provider attestee)
- Guest signup phone-takeover ferme (GuestSignupController.php:102-105)
- DeleteAccount soft-delete + token revoke (DeactivateController.php:18-44)

---

## 8. Verdict global F-2

**HARDENED. AUCUN BLOQUANT V1. 2 quick wins V1.0.1 + 4 items backlog V1.0.2.**

Foundation Auth + Securite est en etat de **production-ready single-restaurant**. Les fenetres d'attaque connues (privilege escalation, channel wildcard, host spoof, token replay sur user-disable, OTP brute-force, mass-assignment) sont toutes fermees ou defendues. Le mantra "KEEP current working system" s'applique strictement: les ~12 composants healed listes en §4.1 sont **a ne pas modifier sans LOCK explicite + owner gate**.

---

## 9. Specialistes JSONs (livraison parallele)

- `architect.json` — auth flow integrity + 88-FormRequest authz scattered analysis
- `security.json` — OWASP top 10 mapped + password-reset min:6 vs min:12 finding
- `sre.json` — rate-limit coverage matrix + logging gap + middleware priority
- `red-team.json` — 13 attack vectors probed (12 closed, 1 partial)

**Total wall-clock: ~30 minutes** (specs + RECON + 4 JSONs + STATUS). Conforme au mandat task brief.
