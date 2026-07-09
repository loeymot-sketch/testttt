# Ultra-review système I — FIDÉLITÉ (loyalty)

HEAD audité : `61e9ea7b7` (working-tree AS-IS)
Date : 2026-07-02
Verdict : **GREEN** — HEAL SEC-01 confirmé, 0 nouveau P0/P1/P2.

## HEAL SEC-01 (P2 fuite PII loyalty) — CONFIRMÉ

Fichier : `app/Http/Controllers/Frontend/LoyaltyController.php`

1. **409 EMAIL_EXISTS sans PII** (l.139-146) : conflit email d'autrui → réponse
   neutre `code=EMAIL_EXISTS` + suggestion générique. AUCUN `existing_loyalty_code`
   ni `existing_phone`. Confirmé par lecture.
2. **Gate `wasRecentlyCreated`** (l.181-187) : vecteur PRINCIPAL (phone requis). Un
   compte préexistant trouvé par téléphone → `PHONE_EXISTS` neutre, PAS de
   name/loyalty_code/points d'autrui. Les data ne sont renvoyées QUE si le compte a
   été créé par CETTE requête. Confirmé.
3. **Routes** (`routes/api.php:1431-1457`) : `/register` public (throttle:5,1, crée
   un nouveau compte) ; `/check` `/redeem` `/balance` `/history` `/add-points` `/qr`
   tous sous `auth:sanctum`. `/redeem` a `idempotency` (anti double-débit).
   `/check` throttle:10,1 (anti-énumération). Confirmé.
4. **opt-in aussi couvert** (`routes/api.php:1494` public throttle:5,1) : délègue à
   `register()` → hérite du gate `wasRecentlyCreated`, réponse neutre pour compte
   existant. Pas de fuite PII via ce chemin non plus.

## Preuves

- `php artisan test tests/Feature/Security/LoyaltyRegisterNoLeakTest.php` → **3/3 PASS**
  (conflit email, lookup par téléphone seul, nouveau compte reçoit son code).
- Live `127.0.0.1:8766` (foodking_e2e) : `POST /loyalty/check` sans auth →
  `{"message":"Unauthenticated."}` HTTP **401**. `/balance` et `/redeem` sans auth →
  redirect 302 (accès refusé, jamais 200+PII).
- `LoyaltyQrSigner.php` : signature HMAC-SHA256 (`hash_hmac` l.117/173) + comparaison
  constant-time `hash_equals` l.119. `verifyAndConsume` vérifie exp + nonce.

## Vérifications d'invariants

- redeem : gate `pos.manual_discount_enabled !== true` (l.286) → 422 avant tout débit
  (points jamais débités si remises off). IDOR kiosk protégé par `KioskMachine` réelle
  (l.305-309), sinon owner-only check (l.331). Débit atomique `lockForUpdate` + ledger.
- addPoints : réservé staff roles (l.211), increment atomique.
- check : `auth:sanctum` requis — lookup PII (name/points/code) réservé aux sessions
  authentifiées (kiosk machine) + throttle 10/min. C'est le flux de lookup légitime,
  pas une fuite (distinction clé vs register qui était PUBLIC).

## Note (non-finding, benign)

opt-in public délègue à register ; pour un phone existant il crée un `LoyaltyConsent`
(ip_hash/ua_hash de l'appelant) sans rien fuiter. Simple ligne d'audit append-only,
rate-limitée 5/min, flux kiosk RGPD à présence physique → risque nul en V1 LOCAL.
Pas un défaut à corriger.
