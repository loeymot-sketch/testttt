# NUIT Wave A — Sécurité résiduelle (uploads, mass-assign, rate-limit, webhook replay, IDOR)

Slug: `nva-security-residual` · HEAD `cfc23966a` · 2026-07-03 · read-only (0 écriture code/DB)
Serveur LIVE 127.0.0.1:8766 (DB foodking_e2e).

## Verdict : IMPROVABLE — 1 résidu réel (P2), le reste SOLIDE

La surface sécurité demandée est **très largement durcie**. Un seul résidu concret
survit : les endpoints de **vérification de code (OTP guest / reset password) n'ont
qu'un throttle par-IP** — pas de compteur par-identité ni de consommation du code sur
échec. Un attaquant qui tourne ses IP peut brute-forcer un code OTP 4 chiffres dans la
fenêtre d'expiration. Tout le reste (uploads, mass-assignment, webhook Uber, IDOR profil)
résiste aux attaques menées.

---

## FINDING 1 (P2, improvement/durabilité) — Brute-force OTP/reset : throttle par-IP seul, code non consommé sur échec

**Fichiers :**
- `routes/api.php:200-204` (`guest-signup/verify` → `throttle:3,5`)
- `routes/api.php:189-191` (`signup/verify` → `throttle:3,5`)
- `routes/api.php:180-181` (`forgot-password/verify-code` → `throttle:5,1`)
- `app/Services/OtpManagerService.php:71-102` (`verify()` : supprime la ligne `otps`
  UNIQUEMENT sur match ; sur mauvais token → exception, **la ligne reste**, aucun
  compteur d'échec)
- `app/Http/Controllers/Auth/ForgotPasswordController.php:69-115` (`verifyCode` : idem,
  la ligne `password_resets` n'est PAS supprimée sur mauvais code)

**Mécanisme :** les 3 throttles sont des limiteurs de chaîne par défaut → **keyés par IP**
(pas d'utilisateur authentifié). Ni le téléphone (OTP) ni l'email (reset) ne sont dans la
clé. Aucun compteur `failed_attempts` sur la ligne `otps`/`password_resets` : un mauvais
essai ne « brûle » jamais le code. Le seul rempart est donc le plafond par-IP, contournable
par rotation d'IP (proxies/botnet).

**Repro LIVE (curl x-api-key) :**
```
POST /api/auth/guest-signup/otp {"phone":"0600000288","code":"+33"}  → 200 (rows=1 dans otps)
POST /api/auth/guest-signup/verify {"phone":...,"token":"0000",...}  attempt1 → 422
                                                                      attempt2 → 422
                                                                      attempt3 → 429 (Too Many Requests)
```
Confirmé : 3 essais/IP puis 429 ; la ligne OTP survit aux échecs (verify ne supprime que
sur match — lecture `OtpManagerService.php:88-95`).

**Impact durabilité :** OTP guest = **4 chiffres** (`random_int(1000,9999)` = 10 000 combos,
`OtpManagerService.php:46`), expiry défaut 5 min. À 3 essais/IP, ~3 334 IP × 3 = 10 000 essais
tiennent dans la fenêtre de 5 min → prise de contrôle d'un compte guest lié à un n° de
téléphone (historique commandes + email + solde fidélité). Le web ordering étant désormais
câblé sur cette auth guest-OTP (surface internet réelle), le résidu compte pour le
long-terme même si V1 est mono-poste. Le reset-password (6 chiffres = 900 000 combos, 5/min)
est plus coûteux mais partage le même angle mort (pas de lock par-email).

**Pourquoi P2 et pas P1 :** le throttle par-IP existe (l'attaque exige rotation d'IP + parallélisme),
V1 est LOCAL mono-poste, et le compte visé est bas-privilège (guest). C'est un **durcissement
défense-en-profondeur**, pas un exploit trivial.

**Fix proposé (non-frozen, hors §7) :**
1. Ajouter un `RateLimiter::for('otp-verify')` **keyé par téléphone** (ex. 5/15 min) et
   l'appliquer sur `guest-signup/verify` + `signup/verify` ; idem `RateLimiter::for('reset-verify')`
   keyé par email sur `forgot-password/verify-code`. La rotation d'IP ne contourne plus.
2. (optionnel, plus robuste) Colonne `attempts` sur `otps`/`password_resets` incrémentée à
   chaque échec ; invalider le code après 5 échecs (le brûler comme s'il était consommé).
3. (durcissement) Passer l'OTP guest à 6 chiffres pour l'aligner sur le reset.

---

## Attaques menées qui ont ÉCHOUÉ (preuve de robustesse — refute-by-default confirmé)

### Uploads (items/logo/kiosk-attract/slider/profil) — SOLIDE
Toutes les FormRequests d'image imposent `image` + `mimes:jpg,jpeg,png[,webp]` + `max` +
règle custom `NoDangerousFileExtension` (`ItemRequest.php:85`, `SliderRequest.php:39`,
`ThemeRequest.php:34-36`, `ChangeImageRequest.php:30`, `ItemCategoryRequest.php:50`,
`CouponRequest.php:60`, `OfferRequest.php:51`, `PageRequest.php:40`, `MessageRequest.php:35`,
`ItemPhotoUploadRequest.php:21`). **SVG rejeté** par `mimes` (donc pas de SVG-XSS stocké).
Stockage via **Spatie MediaLibrary** `addMediaFromRequest` (`SliderService.php:68`,
`ProfileService.php:64`) → noms de fichiers sanitizés/aléatoires, pas de path-traversal via
`getClientOriginalName`. FCM JSON (`NotificationRequest.php:36`) `mimes:json`, admin-only.

### Mass-assignment (User/Order/Setting) — SOLIDE
Aucun modèle en `guarded=[]`. `User.$fillable` (`User.php:41-52`) n'expose **aucun** champ
de rôle (rôles via Spatie `model_has_roles`, pas de colonne `role_id`/`is_admin`). Les
services écrivent via `$request->validated()` (FormRequests) ou champ-par-champ. Le
self-update profil (`ProfileService.php:20-38`) affecte **explicitement** name/phone/email/
country_code seulement — `branch_id`/`status`/`is_guest` **non atteignables** (la « faille
profil PUT kiosk-token » escaladée précédemment est refermée). Un seul `$request->all()`
subsiste (`DefaultAccessController.php:41`) — admin-only, pas de champ sensible.

### Webhook Uber — replay & HMAC SOLIDE
`UberWebhookController.php:174-187` : signature HMAC-SHA256 sur le body brut, comparaison
`hash_equals` (timing-safe), **fail-closed** si secret absent ou header vide. Pas de fenêtre
timestamp — mais c'est conforme au schéma réel Uber (signature = body seul) et le **rejeu est
neutralisé par double idempotence** : `webhook_events(provider,webhook_id)` (`:54-70`,
`already_processed`→200) ET dédup ordre sur `transaction_id='uber:<id>'` (`:113-116`). Échec
transitoire → 503 (Uber rejoue) borné à 5 tentatives puis ACK 200 (pas de commande payée
perdue, pas de boucle). Robuste.
*Nit non-bloquant :* `webhookId` retombe sur `resource_id` si `event_id` absent
(`:45`) → deux events distincts sur la même commande partageraient la clé ; fonctionnel, pas
sécurité, `event_id` normalement présent.

### Rate-limiting global — SOLIDE (hors résidu Finding 1)
`login` : `login-lockout` keyé `email|ip`, 10/10min (`RouteServiceProvider.php:247`).
`kiosk-login` : keyé `username|ip`, 30/min (`:208`). `oss-public` : 60/min/IP (`:238`).
OTP send : 5/min. POS/KDS/admin-mutation : buckets dédiés. Confirmé LIVE : guest-verify
coupe à 3 essais.

### IDOR / route-model-binding — pas de nouveau vecteur trouvé
Profil self-service scopé à `auth()->user()->id` (pas d'ID en paramètre). Les groupes admin
(`api.php:281,302`) portent `auth:sanctum` + `block_kiosk_token_admin` ; l'autorisation fine
est le ratchet FormRequest connu (sentinel-tracké, backlog). Les IDOR loyalty/message/dine-in
sont déjà healés (mémoire). Aucun endpoint route-model-bound non-gaté nouveau isolé dans le
temps imparti.

## Attacks run
uploads(mimes/SVG/path-traversal) · mass-assignment(User/Order/Setting/$request->all) ·
rate-limit brute-force OTP LIVE(3→429) · webhook Uber HMAC+replay+idempotence ·
profil self-update mass-assign · IDOR route-model-binding admin.
