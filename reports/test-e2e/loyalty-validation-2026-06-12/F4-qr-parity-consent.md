# LANE F4 — QR, PARITÉ & CONSENTEMENT fidélité (2026-06-12)

Harnais: http://127.0.0.1:8767, APP_ENV=e2e, DB foodking_e2e (vérifié via tinker: env=e2e db=foodking_e2e).

## Étape 0 — Setup vérifié
- `config/loyalty.php:68-72` → `accept_legacy_plaintext` default FALSE; valeur RÉELLE e2e via tinker: `accept_legacy_plaintext=false`, `ttl=300`, `leeway=30`, `secret_len=64` (.env.e2e:101 LOYALTY_QR_SECRET présent).
- Routes (routes/api.php): `/loyalty/check` L1427 `['auth:sanctum','throttle:10,1']`; `/loyalty/register` L1428 `throttle:5,1`; `/loyalty/balance` L1444 `throttle:10,1`; `/loyalty/qr` L1452-1454 `throttle:30,1`; `/loyalty/opt-in` L1491-1493 `throttle:5,1`; `/loyalty/scan` L1498-1500 `['auth:sanctum','throttle:20,1']`.
- Groupe `frontend` L1264: middleware `['installed','apiKey','localization']` → header `x-api-key` requis (ApiKeyMiddleware.php:21-28).
- Acteurs e2e: victim user id=44 "Victim Secret" phone=0612345678 code=VICT1234 pts=165 status=5; attacker user id=53 (sans rôle, token ['*']); admin id=1 (Admin + KioskMachine user_id=1).

## Étape 1 — QR signé (LoyaltyQrSigner.php + scan) : 6/6 PASS
- Signer lu: `app/Services/Loyalty/LoyaltyQrSigner.php` — format `lqr.<payload b64url>.<hmac b64url>`, HMAC-SHA256 constant-time (L117-121 hash_equals), exp+leeway L134-138, nonce anti-replay INSERT-catch-UNIQUE L141-157 (table loyalty_qr_nonces_consumed), sign() accepte $now override L50.
- 1a MINT: POST /loyalty/qr (token victim id=44) → 200 `lqr.eyJ2IjoxLCJjdXN0Ijo0NCwiY29kZSI6IlZJQ1QxMjM0Iiwibm9uY2Ui...`, expires_at=iat+300, loyalty_code=VICT1234. PASS
- 1b SCAN OK: POST /loyalty/scan (admin=staff+KioskMachine) → HTTP 200, `ok=true, customer_token=lt_0eda6c76a566fc8d…(64hex), display_name="Victim" (prénom seul), loyalty_balance_points=165, header X-Loyalty-QR-Status: signed, X-RateLimit-Limit: 20`. PASS
- 1c REPLAY même token → 200 `ok=false, error_code="qr_replay"` (nonce consommé). PASS
- 1d TAMPER 1 char du payload (milieu du segment b64) → 200 `ok=false, error_code="qr_invalid_signature"`. PASS
- 1e TTL EXPIRÉ: forge tinker `sign(44,'VICT1234', now()-600s)` → exp dépassé de 300s (> leeway 30) → 200 `ok=false, error_code="qr_expired"`. PASS
- 1f PLAINTEXT: `FK:VICT1234` → `qr_legacy_rejected`; bare `VICT1234` → `qr_legacy_rejected`; phone `0612345678` → `qr_legacy_rejected` (config e2e réelle accept_legacy_plaintext=false vérifiée tinker). PASS — aucun PII leak (display_name/points null/0 dans tous les rejets).

## Étape 2 — Throttle scan/check : PASS
- `routes/api.php:1498-1500` /loyalty/scan middleware `['auth:sanctum','throttle:20,1']` (named throttle Laravel RateLimiter).
- Burst 25× POST /loyalty/scan (token kiosk admin): séquence observée `200×20 puis 429×5`. 21e requête = 429 `{"message":"Too Many Attempts."}`, header `Retry-After: 59`, `X-RateLimit-Limit: 20`. Limite réelle = 20/min confirmée empiriquement.
- /loyalty/check throttle:10,1 (L1427), /balance throttle:10,1 (L1444) — cités, cohérents.

## Étape 5 — PII / IDOR enumeration : PASS (défense vérifiée)
ATTENTION méthodo: mon 1er "attacker" id=53 était EN FAIT une machine kiosk (KioskMachine.user_id IN 1,7,9,18,21,23,25,27,47,53) → le 200+PII reçu = comportement kiosk LÉGITIME (la borne doit résoudre n'importe quel client). FAUX POSITIF écarté après vérif tinker. Re-test avec un vrai client non-kiosk non-staff (id=65 "Mylene Hills II", hasKM=false, hasRole=false):
- 5g attacker(65) wildcard token /loyalty/check `{"code":"0612345678"}` (phone victime) → **404 "Non trouvé"** — aucun nom/points/code exposé. PASS
- 5h attacker(65) /loyalty/check `{"code":"VICT1234"}` (code victime) → **404 "Non trouvé"**. PASS
- 5i attacker(65) token ability `kiosk:order` SEULE mais sans ligne KioskMachine → toujours **404** (guard exige tokenCan AND KioskMachine row, `LoyaltyController.php:88-99`). PASS
- 5c oracle: code inexistant `0699999999` → 404 identique à un hit refusé → **pas d'oracle d'existence**. PASS
- 5d non-authentifié → **401 Unauthenticated**. PASS
- 5j attacker(65) scan() avec un QR signé VALIDE de la victime → **403 "Accès kiosk requis."** — le guard IDOR (`LoyaltyController.php:693-702`) FIRE AVANT la vérif token → pas de leak même avec un token valide d'autrui. PASS
- 5e/5k owner self-check OK (200 ses propres données / 422 si code manquant).
VERDICT: un attaquant avec juste un téléphone NE PEUT PAS deviner le solde d'autrui. Throttle 10/min + guard IDOR fail-closed = double défense. Aucun PII leak prouvé.

## Étape 3 — Parité : SUITE VERTE mais DRIFT DATA À L'ÉCRAN
- `./vendor/bin/phpunit --filter "LoyaltyRateParitySentinel|LoyaltyQrSigningSentinel|EnvExampleHasLoyaltyQrSecret|LoyaltyRateSsot"` → **OK (18 tests, 86 assertions)**.
- MAIS le sentinel ne valide QUE les fallbacks code (`?? 1`, `get('loyalty_points_per_euro', 1)` LoyaltyRateParitySentinelTest L31-40) + miroirs JS mobile/web — JAMAIS la valeur Settings stockée en DB.
- À L'ÉCRAN /admin/settings/loyalty-setup (capture F4-setup-rates.png, analysée): **POINTS PAR € = 10**, POINTS POUR 1€ = 100, **MINIMUM POUR UTILISER = 50**. Aperçu rendu: "10€ d'achat = 100 pts → 1.00€ de réduction". → canon attendu = 1/100/100, RÉEL = **10/100/50**.
- Preuve API live: `GET /api/frontend/loyalty/config` → `{"points_per_euro":10,"points_for_1_euro_discount":100,"min_redeem_points":50,...}` HTTP 200.
- Preuve DB: `settings` group=loyalty_setup → loyalty_points_per_euro={$value:10} (id73), loyalty_points_for_1_euro_discount=100 (id74), loyalty_min_redeem_points=50 (id75), tous created_at 2026-06-10 23:09:07 (= timestamp de clone, PAS une mutation de lane récente).
- Conséquence: l'accrual live (`AwardLoyaltyPointsOnDelivery.php:84,103`) crédite **10 pts/€** (10× le mandat 1pt/€) et le redeem est ouvert dès **50 pts** (canon 100). Le sentinel reste VERT car aveugle à la valeur Settings stockée → la régression que le sentinel devait fermer (GOAL L3) passe silencieusement au niveau DATA.
- Caveat attribution: foodking (prod) INTERROGEABLE INTERDIT. e2e est un clone de foodking; timestamp de clone + valeurs non-triviales (min=50) suggèrent fortement que prod stocke les mêmes 10/100/50. Le heal "CONVERGED to 1pt/€" (342a0ab80) a corrigé les fallbacks CODE mais PAS la ligne Settings déjà stockée.

## Étape 4 — Consentement RGPD : GAPS (V1-personnel = urgence réduite)
- Opt-in correct: `LoyaltyOptInRequest.php:37-38` exige `consent_accepted: required|accepted` + `privacy_notice_version: required`; `LoyaltyController::optIn` L485-491 écrit une ligne `loyalty_consents` (IP/UA hashés sha256+salt, `LoyaltyConsent::hashIdentifier` L60-68). CHEMIN OPT-IN = OK.
- MAIS "earn SANS opt-in → pas d'accrual?" → **FAUX**. `register()` (route PUBLIQUE non-auth `api.php:1428` throttle:5,1) crée le compte + mint loyalty_code + **+25 pts bienvenue** + ledger earn — SANS aucun consentement. Preuve live: POST /loyalty/register phone=0781224314 → 200 `{loyalty_code:1EA65006, points:25}`; tinker: user_id=69, **loyalty_consents rows=0**, earn txns=1.
- L'accrual sur commande (`AwardLoyaltyPointsOnDelivery.php` lignes 25-175) n'a **AUCUNE** vérification de consentement — il crédite dès qu'une commande porte un `loyalty_customer_code`/`loyalty_code` (L66-74). Zéro référence à loyalty_consents.
- "opt-out → accrual stoppé?" → **AUCUNE route opt-out/withdraw n'existe** (grep routes/api.php+web.php+Controllers: seulement opt-in). Droit de retrait RGPD art.7.3 non implémenté côté API.
- LoyaltyConsentTest.php (57 L) ne teste QUE `hashIdentifier` (PII hashing), PAS le gating accrual/opt-out.
- Contexte: V1 = outil PERSONNEL Le Cayenne mono-resto (mandat MEMORY). Le client présente son code à chaque scan = acte affirmatif → risque pratique faible. Gap réel mais V1-deferrable.

## Étape 6 — Suite consentement : VERTE
- `./vendor/bin/phpunit --filter "LoyaltyConsent|LoyaltyOptIn|KioskLoyalty"` → **OK (19 tests, 56 assertions)**.

## SYNTHÈSE F4
- QR signé: 6/6 PASS (mint/scan/replay/tamper/expiry/plaintext-reject), aucun PII leak sur rejet. accept_legacy_plaintext=false confirmé config e2e réelle.
- Throttle scan 20/min → 429 prouvé (21e req). check/balance 10/min.
- PII/IDOR: défense fail-closed vérifiée avec VRAI attaquant non-kiosk (404 sur check, 403 sur scan AVANT vérif token). Faux-positif initial (id=53 = machine kiosk) écarté.
- Suites: parité 18/18, consentement 19/19 — toutes vertes.
- 2 findings: P1 DATA+sentinel-blind (10pt/€ live, min 50, sentinel aveugle) ; P2 RGPD (register bypass consent + pas d'opt-out).
