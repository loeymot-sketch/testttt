# V2 Révalidation adversariale — FIDÉLITÉ bout-en-bout

Cible : `/api/frontend/loyalty/{register,check,redeem,scan,qr}` + `LoyaltyQrSigner`.
HEAD `61e9ea7b7` + working-tree. Serveur LIVE `127.0.0.1:8766` (DB `foodking_e2e`).
Posture : réfuter le « GREEN » v1, casser la cible.

## Verdict : BROKEN (1 × P2 nouveau) — le fix P2-loyalty v1 (leak PII) TIENT, mais un vecteur voisin d'account-hijack sur `/register` est confirmé live.

---

## HELD-GREEN (attaques échouées = robustesse attestée)

1. **register email-tiers 409 → 0 fuite PII** (fix P2 v1 confirmé).
   `POST /register {phone:"0799999001", email:"wval-shared@foodking.test"}` (email appartient à user #68).
   → `409 {code:EMAIL_EXISTS, message, data.suggestion}`. AUCUN `loyalty_code`/`phone`/`name`/`points`. Aucune écriture (retour avant `save()` L146).

2. **register phone-existant → gate `wasRecentlyCreated`** (vecteur PHONE fermé, confirmé).
   `POST /register {phone:"0612345678"}` (Victim Secret #44).
   → `200 {status:true, code:PHONE_EXISTS}` — pas de `data`, pas de `loyalty_code`/`name`/`points`. Le branch L181 bloque la fuite.

3. **QR forge (LoyaltyQrSigner)** — HMAC contrefait rejeté.
   `POST /scan {method:qr, raw_data:"lqr.<payload cust=44 code=VICT1234 exp=9999999999>.AAAAforged"}` (token kiosk valide).
   → `200 {ok:false, error_code:"qr_invalid_signature"}`. `hash_equals()` constant-time (LoyaltyQrSigner:119) tient ; pas de résolution du compte #44.

4. **QR legacy plaintext → énumération bloquée.**
   `POST /scan {method:qr, raw_data:"FK:VICT1234"}` avec `loyalty.qr.accept_legacy_plaintext=false`.
   → `200 {ok:false, error_code:"qr_legacy_rejected"}`. Impossible de résoudre un `loyalty_code` en clair via /scan.

5. **IDOR redeem** — double barrière.
   Guest actif (status 5, ability `kiosk:order`, **sans** ligne `KioskMachine`, id 157) tente `POST /redeem {code:"VICT1234", points:100}` (code d'autrui).
   → `422 {code:MISSING_IDEMPOTENCY_KEY, "Idempotency requires authenticated user with resolvable branch_id"}`. Le guest (branch non résolvable) est stoppé AVANT le handler ; et même passé, l'owner-check `LoyaltyController:331` (`!$isKiosk && !$isStaff && caller->id !== user->id` → 403) ferme l'IDOR. `$isKiosk` exige une vraie `KioskMachine` (L305-309). Points victime inchangés (200→200).

6. **Idempotence redeem (double-débit)** — protégé (vérifié config+code ; débit live bloqué par le mandat no-write, à raison).
   `api/frontend/loyalty/redeem` ∈ `config/idempotency.php` required_routes (L85). `IdempotencyKeyMiddleware` : replay par clé scoped `(branch_id,user_id,key)` → réponse cachée rejouée sans ré-exécution (L86-95), payload différent → `409` (L88-93), cache 2xx-only (L145). Handler en `DB::transaction` + `lockForUpdate` (L324-374) → race-safe. Double-POST même clé ⇒ 1 seul débit.

---

## BROKEN — P2 : `/register` (public, non-auth) attache un email arbitraire à un compte fidélité téléphone-only → chaîne d'account-hijack

**Fichier** : `app/Http/Controllers/Frontend/LoyaltyController.php:159-171` (`register()`, branch « compte existant »).

**Défaut** : sur l'endpoint PUBLIC `POST /api/frontend/loyalty/register` (aucune auth, routes/api.php:1434), si le téléphone correspond à un compte existant SANS email, le code exécute `if ($email && empty($user->email)) { $user->email = $email; } ... $user->save();` AVANT le gate `wasRecentlyCreated`. Un attaquant qui connaît le téléphone d'un client peut donc **écrire son propre email** sur le compte fidélité de la victime.

**Repro LIVE (confirmée, sur compte jetable pour respecter le no-write sur les vrais comptes)** :
```
# 1) créer un compte téléphone-only (état = celui des comptes kiosk réels, email null)
POST /register {phone:"079TESTATK1", name:"ATK Throwaway"}
 → 200 {loyalty_code:"2296BF46", points:0}   (user #157, email=null)

# 2) ATTAQUANT (public, non-auth) attache SON email
POST /register {phone:"079TESTATK1", email:"attacker-evil-XXXX@evil.test"}
 → 200 {code:PHONE_EXISTS}

# 3) lecture DB : l'email attaquant est collé sur le compte
tinker: User#157 → {"email":"attacker-evil-20296@evil.test","phone":"079TESTATK1","loyalty_code":"2296BF46"}
```

**Chaîne d'impact** : email désormais attaquant-contrôlé → `POST /api/forgot-password` (email-based, `ForgotPasswordController:33-53` envoie le PIN à CET email) → `/reset-password` fixe le mot de passe → login email+password. Pour les comptes `status=ACTIVE(5)` (clients seedés/migrés, ex. Victim Secret #44 phone `0612345678` email null), c'est un **account takeover complet** (points, historique commandes, PII). Population vulnérable = tous les comptes fidélité créés téléphone-only au kiosk (email null).

**Préconditions** (→ pourquoi P2, pas P1) : (a) connaître le téléphone victime (semi-devinable, throttle:5/min), (b) victime sans email. Périmètre V1 LOCAL mono-restaurant → blast borné (points fidélité + PII d'un établissement). Reste une écriture non-authentifiée sur un compte tiers = à corriger.

**Fix suggéré** (hors frozen) : ne PAS attacher d'email sur un compte préexistant via cet endpoint public — exiger l'auth (le titulaire via `/loyalty` auth:sanctum) pour modifier l'email, ou retirer le bloc L159-163 et déplacer la MAJ email dans un flux authentifié.

---

## Notes (non-P0/P1)

- **Oracle d'existence email/phone** : `409 EMAIL_EXISTS` vs `200 PHONE_EXISTS` vs création confirment l'existence d'un email/téléphone SANS PII. Comportement standard d'un register, throttlé (5/min). Non-fuite PII → non retenu comme P.
- **status=1 lockout** : `/register` crée les comptes avec `status=1` ; `EnsureUserStatusActive` (middleware) exige `status=ACTIVE(5)` et **supprime le token** sinon → un compte créé au kiosk ne peut pas utiliser check/redeem/balance/qr avec SON propre token. Bug fonctionnel (mobile standalone non câblé V1) — pas sécurité, hors périmètre P0/P1.
- **Math points→discount** : `pointsToDiscount = round(points/rate,2)`, redeem multiple-de-rate + insuffisance + positivité vérifiés (L336-351), `balance_after` cohérent (valeur pré-décrément en mémoire − redeem = solde final). Correct.
