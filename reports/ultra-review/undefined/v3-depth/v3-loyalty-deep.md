# V3 DEPTH — Fidélité bout-en-bout (v3-loyalty-deep)

HEAD 61e9ea7b7 + working-tree. Serveur LIVE 127.0.0.1:8766 (foodking_e2e). Posture: GREEN = hypothèse à réfuter.

## Verdict: BROKEN (1× P2 IDOR/énumération sur /loyalty/check) — le reste HELD GREEN.

---

## FINDING P2 — `/api/frontend/loyalty/check` : reverse phone→name + fuite loyalty_code/points à TOUT porteur de token
`app/Http/Controllers/Frontend/LoyaltyController.php:60-104` · route `routes/api.php:1433`

La route `check` n'a que `['auth:sanctum','throttle:10,1']` — **aucune** ability
(`kiosk:order`) ni contrôle de propriété. `check()` fait
`User::where('loyalty_code',$input)->first()` puis fallback
`User::where('phone',$phone)->first()` et renvoie
`{name, points, discount_value, loyalty_code}` du titulaire — pour **n'importe quel**
code/téléphone fourni, sans vérifier que le caller EST ce titulaire.

Or un token Sanctum s'obtient trivialement et publiquement :
- `GuestSignupController` (route publique `guest-signup/otp`+`verify`) émet un
  `auth_token` ability `['kiosk:order']` 30 j (`GuestSignupController.php:146`) ;
- login client émet un token `['*']` (`LoginController.php:157-159`).
Les deux satisfont `auth:sanctum` sur `/check` (pas de filtre d'ability).

Impact : un attaquant qui fait un simple OTP invité peut ensuite POSTer
`{"code":"06XXXXXXXX"}` et obtenir le **nom** derrière ce numéro + son solde de
points + son **loyalty_code** (déanonymisation / reverse-lookup ciblé), ou énumérer
l'espace des loyalty_code (8 hex) / téléphones à 10 req/min.

C'est **la même classe de fuite** que celle colmatée en V2 sur `/register`
(PHONE_EXISTS neutre) et `/scan` (legacy plaintext désactivé, `accept_legacy=false`
vérifié runtime) — mais `/check` est resté ouvert et est désormais le vecteur le
plus simple pour transformer un code/téléphone en PII.

Preuve LIVE (read-only) :
- `curl -X POST .../loyalty/check` sans token → bloqué (302 login) ; token bidon →
  bloqué. Donc `auth:sanctum` EST l'unique barrière → tout token valide passe.
- tinker LECTURE SEULE : il existe bien de la donnée exfiltrable —
  `id=95 name="WVAL2 Consent" code=2FB41817 phone=065660254 pts=25` (8 comptes
  fidélité en base). Un `{"code":"065660254"}` renverrait ce name+code+pts.

Verdict PLAUSIBLE : chemin de code déterministe + barrière d'auth confirmée en LIVE ;
la requête authentifiée finale n'a pas été rejouée pour respecter la règle « aucune
écriture DB » (l'OTP invité créerait un user). Fix : exiger `kiosk:order`/staff OU
restreindre au propriétaire (`caller->id === user->id`), comme redeem.

---

## HELD GREEN (attaques tentées + preuve de réfutation)

### (a) `/register` 409 email-tiers → 0 fuite — HELD
`LoyaltyController.php:130-148` : conflit email d'un autre compte → 409
`EMAIL_EXISTS` message neutre, **aucun** loyalty_code/phone du titulaire renvoyé.
Commentaire `[SEC-LOYALTY-LEAK 2026-07-02]`. Confirmé par lecture.

### (b) email-attach sur compte existant → FERMÉ — HELD
`LoyaltyController.php:159-190` : branche `else` (compte préexistant) = **no-op**,
email jamais attaché ; de plus compte trouvé par phone préexistant → réponse neutre
`PHONE_EXISTS` (ligne 184, `wasRecentlyCreated`) sans PII. Vecteur account-hijack
forgot-password fermé. Confirmé par lecture.

### (c) Forge / rejoue / tamper QR (`LoyaltyQrSigner`) → TOUT REJETÉ — HELD
tinker read-only (`scratchpad/qr_attack.php`, 9 attaques, aucune n'insère de nonce
car rejet AVANT l'insert) :
- A1 hmac aléatoire → `qr_invalid_signature`
- A2 hmac clé devinée `changeme` → `qr_invalid_signature`
- A3 hmac clé vide → `qr_invalid_signature`
- A4 hmac = app.key → `qr_invalid_signature`
- A5 hmac hex (alg-confusion raw/hex) → `qr_invalid_signature`
- A6 pas de signature → `qr_invalid_format`
- A7 payload vide → `qr_invalid_format`
- A8 **tamper code+cust d'un token légitime, on garde la signature** →
  `qr_invalid_signature` (HMAC lie les octets exacts du payload)
- A9 token signé mais expiré → `qr_expired`
HMAC SHA-256 `hash_equals` constant-time sur les octets décodés bruts (pas de
mismatch de canonicalisation). Secret 64 chars, non-sentinelle. Replay : consommation
nonce via INSERT UNIQUE `uk_loyalty_qr_nonce` + catch (migration
`2026_05_19_100000`), pas de TOCTOU. Réfuté.

### (d) redeem double-débit (idempotence) — HELD
`config('idempotency.enabled')=true` (runtime), `api/frontend/loyalty/redeem` ∈
`required_routes` (`config/idempotency.php:85`). Middleware
`IdempotencyKeyMiddleware:52-58` : route requise + header `X-Idempotency-Key` absent →
`MissingIdempotencyKeyException` (422) → **pas de double-submit sans clé**. Avec clé :
réponse mise en cache, replay = même clé → réponse rejouée sans ré-exécution. Débit
lui-même en `DB::transaction` + `lockForUpdate` (`LoyaltyController.php:327-358`),
insuffisance vérifiée sous verrou → pas d'overdraw concurrent. Réfuté (config+code).

### (e) IDOR redeem d'autrui — HELD
`LoyaltyController.php:308-336` : `$isKiosk` exige une **vraie ligne KioskMachine**
(pas juste l'ability `kiosk:order` — heal GOAL-2026-05-29 SEC-P1). Sinon check
propriétaire `caller->id !== user->id` → 403. Un guest kiosk:order (sans KioskMachine)
tombe sur le check propriétaire → ne peut PAS débiter le compte d'autrui. Réfuté.
De plus redeem globalement gaté par `pos.manual_discount_enabled` (=true ici, mais
kill-switch documenté).

### (f) math points→€ — HELD
`pointsToDiscount()` = `round(points/rate,2)`, rate<=0 court-circuité à 0. redeem
impose `points % rate == 0` (`:348`, anti-micro-transaction) et `points>0` (`:339`).
`loyalty_points` entier non-négatif (décrément gardé par `< pointsToRedeem`). Pas
d'arrondi exploitable, pas de négatif, pas d'overflow atteignable. Réfuté.

## Attaques exécutées (commandes)
- `php artisan tinker scratchpad/qr_attack.php` (9 forges QR)
- `curl -X POST /api/frontend/loyalty/check` sans token / token bidon
- tinker read-only : config runtime (idempotency, manual_discount, secret len,
  accept_legacy) + échantillon donnée fidélité exfiltrable
- `grep`/`Read` sur LoyaltyController, LoyaltyQrSigner, routes/api.php,
  config/loyalty.php, config/idempotency.php, IdempotencyKeyMiddleware, GuestSignup/Login
