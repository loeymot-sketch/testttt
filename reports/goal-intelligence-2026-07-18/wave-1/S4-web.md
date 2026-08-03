# S4 — FINDER adversaire WEB (API backend du site) — GOAL intelligence 2026-07-18

Repo : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt` — READ-ONLY.
Périmètre : endpoints `frontend/*` + `auth/*` consommés par le site Vercel séparé.
Config LIVE vérifiée : `site_phone_verification=DISABLE(10)`, `site_guest_login=ENABLE(5)`, `DEMO=false`.
DB : 187 users, 54 comptes invités (`is_guest=5`), 10 avec `loyalty_points>0`, colonne `users.declared_allergens` ABSENTE.

---

## FINDINGS

### S4-01 — Prise de contrôle d'un compte INVITÉ par téléphone seul (sans OTP) + robinet à tokens `kiosk:order` — P2 (config-gated, LIVE)

**Fichiers** : `app/Http/Controllers/Auth/GuestSignupController.php:60-86` (verify) + `:88-183` (register) ; `app/Services/OtpManagerService.php` ; route `routes/api.php:196-205` (`/api/auth/guest-signup/verify`, throttle 3/5min).

**Mécanisme** : quand `site_phone_verification == DISABLE` (valeur LIVE = 10, ET défaut semé `SiteTableSeeder.php:35`), `verify()` prend la branche L63-70 :
```php
if (Settings::group('site')->get('site_phone_verification') == Activity::DISABLE) {
    $otp = DB::table('otps')->where([['phone', $request->post('phone')]]);
    $otp?->delete();                 // supprime l'OTP sans le vérifier
    return $this->register([...]);   // émet un token
}
```
`register()` : `User::withoutGlobalScopes()->withTrashed()->where('phone', $phone)->first()` → si le téléphone correspond à un compte **invité existant** (is_guest=5), `Auth::guard('web')->loginUsingId()` + `createToken('auth_token', ['kiosk:order'], now()->addDays(30))` → renvoie le token **+ `UserResource($user)`**. Les comptes staff/plein sont protégés (L102-105 throw). Mais les 54 comptes invités sont ouvrables **avec le seul numéro de téléphone**, aucun code SMS requis.

**Repro** (config live) :
```
POST /api/auth/guest-signup/verify
Header: x-api-key: <clé publique du site>
Body: {"phone":"<numéro d'un client invité>","code":"33","token":"0000"}
→ 201 { token: "<sanctum kiosk:order 30j>", user: {...} }
```
Avec ce token : `GET /api/frontend/order` (`FrontendOrderService::myOrder` scope `user_id=auth`, L100) = **tout l'historique de commandes de la victime** (n° série, totaux, items, adresses) ; `GET /api/frontend/loyalty/balance` + `/history` = **points + grand-livre fidélité**. Sur un téléphone inconnu → crée un invité (spam de comptes + robinet à tokens).

**Impact** : IDOR-par-bypass-auth sur 54 comptes invités (numéros de téléphone = semi-publics, énumérables). Aussi : n'importe qui peut minter des tokens `kiosk:order` (ability partagée avec la vraie borne).

**Nuance / non-recyclage** : la CAUSE (vérification désactivée car SMS non câblé) recoupe le gap connu « SMS clés absentes ». La CONSÉQUENCE spécifique (login invité par téléphone + minting de token) est distincte du tap connu de l'ultraplan (qui visait `/api/auth/kiosk-login` + `kiosk123`, un AUTRE endpoint donnant des tokens liés à une KioskMachine). **Se ferme automatiquement dès que la vérification est activée avec un vrai SMS** (branche ENABLE L71 exige `otpManagerService->verify` = OTP réel). À traiter dans le même lot que l'activation SMS prod.

---

### S4-02 — `/api/frontend/loyalty/scan` : énumération PII (nom + solde fidélité) par TOUT token client — P2 (NON config-gated)

**Fichiers** : `app/Http/Controllers/Frontend/LoyaltyController.php:645-832` (scan) ; garde unique L648-654 ; chemin legacy L722-767 ; route `routes/api.php:1551-1553` (`['auth:sanctum','throttle:20,1']`, PAS d'`abilities` au niveau route).

**Mécanisme** : la SEULE garde de `scan` est :
```php
if (!$user || !$user->tokenCan('kiosk:order')) { return 403; }
```
Or `tokenCan('kiosk:order')` est VRAI pour **tout token client `['*']`** (LoginController.php:157-159 émet `['*']`) ET tout token invité. Ses jumeaux `check` (L88-97) et `redeem` (L324-328) ont été DURCIS pour exiger une **vraie KioskMachine** (`KioskMachine::...->where('user_id',$caller->id)->exists()`) précisément parce que les tokens invités ont l'ability `kiosk:order` — mais `scan` n'a **jamais reçu ce même durcissement**. Le commentaire de `check` (L84) affirme « /scan colmaté en V2 » : c'est faux au niveau garde d'appelant.

Chemin legacy actif (`loyalty.qr.accept_legacy_plaintext` défaut `true`, L724) : `raw_data` = `<loyalty_code>` OU `<téléphone E.164>` (L737-758) → réponse L802-813 :
```json
{ "ok": true, "display_name": "<prénom>", "loyalty_balance_points": <points>,
  "declared_allergens": [...], "customer_token": "lt_..." }
```

**Repro** : n'importe quel client authentifié (compte auto-créé en 1 requête) →
```
POST /api/frontend/loyalty/scan
Body: {"method":"qr","raw_data":"+336XXXXXXXX"}   (ou un loyalty_code 8 hex)
→ 200 { data: { ok:true, display_name:"Marie", loyalty_balance_points:340, ... } }
```
Itérer les numéros (préfixes mobiles FR) = récolte {existence compte, prénom, solde fidélité}. Throttle 20/min/IP (rotation d'IP contourne).

**Impact** : fuite PII (prénom + solde) + oracle d'existence, par énumération, accessible à tout compte client trivialement créé. `declared_allergens` (donnée de santé RGPD) = **latent** aujourd'hui (colonne absente → `[]`), devient une fuite de santé dès que la migration `declared_allergens` est livrée.

**Fix suggéré** : aligner `scan` sur `check`/`redeem` — exiger une vraie KioskMachine OU la propriété du code, sinon réponse neutre.

---

### S4-03 — Champ `source` entièrement piloté par le client — P3 (intégrité data / analytics)

**Fichiers** : `app/Http/Requests/OrderRequest.php:181` (`'source' => ['required','numeric']`) ; `FrontendOrder` fillable inclut `source` (`app/Models/FrontendOrder.php:61`) ; mass-assigné dans `myOrderStore`.

Le client choisit librement `source` (5=WEB, 10=APP, 15=POS, ou tout entier) ; aucune dérivation serveur. Un appelant web peut poster `source=15` (POS). **Aucun impact fiscal/sécurité** (prix = SSOT ; les builders de notif et le Dashboard s'appuient surtout sur `source_surface`, lui dérivé serveur `FrontendOrderService:577-579`), mais pollue tout bucketing basé sur `source`. Note structurelle : borne et web partagent `source=5`, et `source` est non-fiable — préférer `source_surface` (dérivé serveur) partout.

---

## CHEMINS VÉRIFIÉS SAINS (TIEN)

- **Prix = SSOT (lens c)** : `myOrderStore` recalcule via `PricingService::calculateOrder` (`FrontendOrderService:301-320`) ; `total/subtotal/discount` client dé-settés (L271) ; item inconnu rejeté (L377) ; injection cross-item variation/extra gardée (L401,427). Le web n'envoie que item_id/qty/options. **Sain.**
- **Idempotence double-submit (lens c)** : `X-Idempotency-Key` + `Cache::lock(5s)` + recovery scoped **(branch_id, user_id, key)** (`findExistingFrontendOrderForIdempotencyRecovery:723-742`) + DB unique + catch 23000 (L684-693) + middleware route `idempotency`. **Sain.**
- **Tracking IDOR / TRACK-02 (lens d)** : `show` garde de propriété `user_id !== Auth::id() → 403` (`FrontendOrderService:717`). Adresses : IDOR fermé sur show/update/destroy (`AddressController:37,60,74`), store/list scoped `user_id=auth` (`AddressService:31,53`). OrderAddress IDOR fermé (L602-612). **Sain.**
- **Fidélité accrual double-crédit (lens b)** : sentinelle atomique `orders.loyalty_points_awarded` (claim -1, exactly-once) — `AwardLoyaltyPointsOnDelivery:52-60`. Annulation double-remboursement fermée par `lockForUpdate` + early-return idempotent (`changeStatus:772-778`). **Sain.**
- **Redeem / coupon (lens b,e)** : remises DÉSACTIVÉES en V1 — `assertDiscretionaryDiscountAllowed` throw si discount>0 (L864-870) ; `redeem` refuse en amont (L305-310) → points jamais débités. Coupon : min-order/dates/limit_per_user/max_global validés (`CouponService:418-461`), discount borné `max(0,min(amount,subtotal))` (jamais négatif). **Sain (moot en V1).**
- **Loyalty check/register/addPoints (lens a,b)** : `check` durci KioskMachine-ou-owner-ou-staff (L88-97) ; `register` public ne renvoie de données que si `wasRecentlyCreated` (L200) ; `addPoints` staff-only (L230). **Sain** (résiduel mineur : `/register` public confirme l'existence d'un email via 409 EMAIL_EXISTS + crée un compte par téléphone neuf sondé — énumération throttlée 5/min, faible).
- **OTP anti-brute-force (lens a)** : compteur d'échecs par-identité (5) + throttle par-IP (3/5min) + expiry + CSPRNG `random_int` (`OtpManagerService:82-124`). Reset password = table `password_resets` séparée, durcie (min:12, révocation tokens, verrou par-email) (`ForgotPasswordController`). **Sain.**
- **Livraison frais serveur (lens f)** : `OrderRequest::prepareForValidation` fusionne le devis serveur (`DeliveryQuoteService`) ; livraison offerte calculée sur sous-total SSOT (L542-547) ; `delivery_charge` forcé à 0 pour non-DELIVERY (L280). **Sain.**
- **Endpoints borne-only (escpos, paymentConfirm)** : exigent une vraie KioskMachine + propriété commande + amount-echo ±1c (`OrderController:88-114,130-256`) → invités web ne peuvent pas les atteindre. **Sain.**

## VÉRIFS DB / repro
- `Settings site.site_phone_verification = 10 (DISABLE)`, `site_guest_login = 5 (ENABLE)`, `env(DEMO)=false` (tinker).
- `Schema::hasColumn('users','declared_allergens') = false`.
- `User is_guest=5 → 54` comptes (blast radius S4-01).
