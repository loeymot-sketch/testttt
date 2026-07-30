# ABUSE AUTH — inscription / connexion / OTP Le Cayenne (adversaire sécurité)

Cible : API réelle VPS `staging` (`APP_ENV=staging`, `APP_DEBUG=false`, `DEMO=false`,
`CACHE_DRIVER=redis`), curl loopback `https://127.0.0.1`. Réglages : `otp_type=5`(BOTH,
code **4 chiffres**), `otp_expire_time=10`, `site_phone_verification=10`(**DISABLE**),
`site_default_sms_gateway=0`, `site_guest_login=5`(ENABLE). Tél de test `0699000001-9`
**nettoyés** (3 otps supprimés, 0 user créé, cache purgé).

## Synthèse (6 lignes)
1. **P1 fuite `dev_code`** : `POST /guest-signup/otp` renvoie le VRAI code dans le JSON — prouvé `"dev_code":"6528"`. Bypass OTP total sur le staging public.
2. **P1 confusion de canal EMAIL→prise de compte** : le code d'un téléphone est envoyé à un email fourni par l'attaquant → token du compte-victime (fidélité/PII). Vaut aussi en **prod**.
3. **Anti-brute-force SOLIDE** : `/verify` renvoie 429 immédiat (throttle 3/5min) ; identity-lock 5 échecs en complément. 10 codes faux = 10×429.
4. **Resend SOLIDE** : `/otp` & `/email-otp` = 5/min (prouvé 5 puis 429) ; chaque envoi **invalide l'ancien code** (seul `2332` survit).
5. **Pas de compte fantôme** : `/otp` répété sans verify → **aucun user créé** (001/002/003 = none). Création uniquement au register vérifié.
6. **Token & garde staff OK** : abilities=`["kiosk:order"]` exp. 30j ; téléphone admin → register REFUSE (`refuse=true`). Validation email/tél stricte.

## P1 — 2

### P1-1 · EMAIL-OTP : confusion de canal → prise de compte fidélité (prod incluse)
`GuestSignupController::emailOtp` (L105-135) génère l'OTP pour le **téléphone** mais l'envoie
à l'**email fourni dans la requête** (L128 `Mail::to($request->post('email'))`). `verify`→
`register` (L183-215) **réutilise tout compte invité existant portant ce téléphone** puis émet
un token (L270). Preuve mécanique : register lookup par phone, garde email (L242-246) empêche
seulement l'écrasement d'email, **PAS l'émission du token**. Impact : connaître le tél d'une
victime (souvent public) → attaquant reçoit le code sur SON email → token du compte-victime →
`loyaltyRedeem`, historique commandes, profil (nom/email/tél). Ne dépend PAS de `dev_code` →
**exploitable en production**. Racine : la preuve OTP porte sur un email arbitraire, l'identité
est le téléphone. Fix : lier le compte à l'email vérifié, ou refuser la réutilisation d'un
invité dont l'email diffère.

### P1-2 · Fuite `dev_code` (OTP en clair dans la réponse) sur staging public
`GuestSignupController::otp` L69-85 : hors production, si SMS non-forcé (`site_phone_verification
!=ENABLE`) → ajoute `dev_code` = `otps.token`. Staging (`env=staging`) + réglage DISABLE →
**actif**. Preuve live : `{"status":true,...,"dev_code":"6528"}` ; tél inexistant idem
(`4570`). La clé `x-api-key` est un secret front public (livrée à chaque navigateur ;
mauvaise clé → 400). Donc quiconque atteint le VPS OVH public obtient l'OTP de **n'importe
quel téléphone** → verify → token. La prod est protégée (env-gate + `PreflightProductionCommand`
+ boot-guard DEMO L261). Défaut = **staging internet-facing servant de vraies données client**.

## P2 — 4
- **P2-1 énumération staff** : tél staff+OTP valide → message `credentials_invalid` (L189),
  distinct de `code_is_invalid` (mauvais code) → révèle qu'un tél est staff. Prouvé staff id=1
  `refuse=true`. Faible (exige possession OTP).
- **P2-2 compteur anti-brute non-atomique (TOCTOU)** : `OtpManagerService` L103/134
  `Cache::get`+`Cache::put` (pas `Cache::increment`) → l'identity-lock 5 peut être dépassé en
  concurrence. Atténué par le throttle IP (429 dur). À vérifier : `TrustProxies` forwarde-t-il
  l'IP réelle derrière nginx (sinon buckets partagés).
- **P2-3 `email:rfc` laxiste** : `a@b` PASS, `tëst@exämple.fr` PASS → envoi mail synchrone vers
  adresse douteuse, échec avalé en 422 ; mild email-bomb (5/min/IP, pas de throttle par
  destinataire). XSS/espace/>190 correctement **REJETÉS**.
- **P2-4 `first_name` stocké brut** : register L202-203 `mb_substr(first,0,100)` sans
  sanitisation → `<img onerror>` stocké verbatim ; sûr tant que chaque rendu échappe (Vue/Blade
  OK par défaut) ; risque stored-XSS si une surface (ticket/PDF/export) l'émet cru. Troncature/
  emoji OK.

## P0 — 0
Néant. Le pire (bypass DEMO, `dev_code`) est env-gaté hors prod avec boot-guard
(`AppServiceProvider` L261) + preflight. La prod go-live reste protégée.

## Confirmé SAIN (réfuté)
Brute-force /verify (429), resend 5/min + invalidation ancien code, aucun compte fantôme
(OTP flow), token `kiosk:order`+30j, énumération /otp identique, validation tél stricte
(8 chiffres/lettres/`PENDING_`/>15 = REJECT), email XSS/espace/long = REJECT, garde
staff→pas de token invité, DEMO/APP_DEBUG=false.
Pointeur : le vrai « compte fantôme » historique vient probablement de
`/frontend/loyalty/register` (crée un user par tél SANS OTP) — hors périmètre OTP, à auditer à part.

**COMPTE : P0=0 · P1=2 · P2=4**
