# S4-WEB — Chasse READ-ONLY aux problèmes de LOGIQUE (endpoints backend consommés par le site)

Date : 2026-07-18 · Scope : `app/Http/Controllers/Frontend/*`, `/api/frontend/order` (source=5),
`app/Services/{FrontendOrder,Coupon,Loyalty,Otp}`, listeners loyalty, `routes/api.php` groupe frontend.
Méthode : lecture code + `tinker` READ-ONLY DB `foodking_e2e`. **Rien modifié.**

Contexte runtime vérifié (tinker) : `pos.manual_discount_enabled = TRUE`, `pricing.use_ssot_service = TRUE`,
`pricing.tax_inclusive_prices = TRUE`, `idempotency.enabled = TRUE`, `DEMO=false`.
→ Les remises **coupon + fidélité sont RÉACTIVÉES** (le fix F1 a flippé le défaut false→true le 2026-05-31),
donc les lentilles fidélité/coupon sont **réellement atteignables** (et non plus dormantes).

---

## FINDINGS CONFIRMÉS

### [P2] `app/Http/Controllers/Frontend/LoyaltyController.php:166-190` — `/loyalty/register` crée un compte `is_guest=NO` SANS rôle → verrouillage cross-surface du login web pour ce téléphone

**Preuve lue.** `register()` (public, `routes/api.php:1477`, middlewares `installed|apiKey|localization` +
`throttle:5,1`, **pas d'auth**) crée un `User` :
- ligne 172 `username = uniqid('kiosk_')`, ligne 173 password aléatoire, ligne 174 `status = 1` ;
- **jamais** `assignRole(...)` (contrairement à Guest/Signup qui font `assignRole(CUSTOMER)`) ;
- `is_guest` non renseigné → **défaut colonne `Ask::NO` (10)** (`2014_10_12_000000_create_users_table.php:30`).

Or le portillon guest-signup refuse tout compte non-invité :
`GuestSignupController.php:102` → `if ($user && $user->is_guest != Ask::YES && !$user->trashed()) throw credentials_invalid`.
`Ask::YES=5`, `Ask::NO=10` → `10 != 5` = **true → 422**. Le full-signup est bloqué aussi :
`SignupController.php:88` n'écrase que `is_guest === Ask::YES`, sinon `SignupController.php:99` renvoie `code_is_invalid` (422).

**Impact.** Un client dont le téléphone a été enregistré en fidélité via l'endpoint public `/loyalty/register`
(borne SPLASH loyalty, ou `/loyalty/opt-in` qui délègue à `register()`) **ne peut plus JAMAIS créer de login web**
avec ce même numéro : ni guest-OTP, ni inscription complète. Le compte a en plus **aucun rôle**, ce qui casse la
résolution `roles[0]` (`LoginController`/menu/permission) s'il finit par s'authentifier (reset password).

**Reachability prouvée (tinker READ-ONLY).** 7 comptes déjà dans cet état exact
(`loyalty_code` non nul + `is_guest=10` + `whereDoesntHave('roles')`), usernames `kiosk_*` = signature de
`LoyaltyController::register:172` :
```
Loyalty accounts is_guest=NO(10) + NO role : 7
  id=155 phone=0000000199 user=kiosk_6a45a8529db6e is_guest=10
  id=157 phone=079TESTATK1 user=kiosk_6a45cda0eabb0 is_guest=10
```

**Repro.**
1. `POST /api/frontend/loyalty/register` `{phone:"0612345678", name:"X"}` → crée User(is_guest=10, no role).
2. `POST /api/auth/guest-signup/otp` `{phone:"0612345678",...}` puis `/verify` → `GuestSignupController::register`
   → 422 `credentials_invalid` (bloqué L102).
3. `POST /api/auth/signup/verify` (full) → `SignupController::register` → 422 `code_is_invalid` (bloqué L99).

Fix candidat (hors scope ici) : `/loyalty/register` doit créer le compte fidélité en `is_guest=YES` + `assignRole(CUSTOMER)`
(comme guest-signup), ou marquer ces comptes « claimables » par OTP. **Ne rien toucher sans gate.**

---

### [P3] `LoyaltyController.php:386` vs `FrontendOrderService.php:929` — asymétrie `source_surface` entre `/loyalty/redeem` et l'attache pré-redeem de la commande (double-débit transitoire, self-healing)

**Preuve lue.** `/loyalty/redeem` écrit la ligne pending avec `source_surface => $isKiosk ? 'kiosk' : 'pos'`
(L386). `$isKiosk` exige une **vraie KioskMachine** (L324-328) → pour un token **web/guest** (kiosk:order mais
sans machine), `$isKiosk=false` → `source_surface='pos'`. Mais l'attache dans la commande
(`applyKioskLoyaltyDiscount` L925-934) filtre **`->where('source_surface','kiosk')`** + `whereNull('order_id')`.
→ Le pending 'pos' d'un client web n'est **jamais rattaché** ; la commande refait un **débit inline** (L958-967).

**Impact.** Si le front web utilise le motif pré-redeem (`/redeem` puis commande), le client est débité 2×
(ex. solde 200 → /redeem 100 → 100 → commande inline 100 → 0) pour une remise de 100 pts. **Net-neutre** car
`reapOrphanRedemptions` (planifié via `CleanupStalePendingKioskOrders`, `Kernel.php:112`, fenêtre 30 min)
recrédite l'orphelin 'pos' (`order_id=NULL`, `type=redeem`, `points<0`). Fenêtre d'incohérence de solde ≤ 30 min
+ appel `/redeem` web totalement gaspillé. Bas car auto-réparé et net-correct. Si le cron est arrêté → points
temporairement perdus jusqu'au prochain run.

---

### [P3] `app/Services/CouponService.php:439-461` — `limit_per_user` / `max_uses_global` non-atomiques (double-usage concurrent)

**Preuve lue.** Les deux plafonds sont vérifiés en **comptant** `OrderCoupon` (L441, L457) *avant* l'insert de la
ligne `OrderCoupon` (fait plus tard dans `FrontendOrderService::myOrderStore:624-631`). Deux commandes concurrentes
du même user avec un coupon `limit_per_user=1` passent toutes deux le `count()==0` avant qu'aucune n'insère.
Le middleware idempotency ne dédup que les payloads **identiques** — deux paniers différents ne collisionnent pas.
Documenté dans le code (« single-box V1: same non-atomic semantics », L454). Bas (mono-poste, faible concurrence),
mais réel maintenant que les coupons sont ON.

---

### [P3] `LoyaltyController.php:100-103` — écriture (`save`) dans l'endpoint de lecture `check()` ; génération `loyalty_code` sans retry sur collision

**Preuve lue.** `check()` (lecture solde) fait un `save()` pour backfiller `loyalty_code` si absent. Sous concurrence,
2 `/check` simultanés génèrent 2 codes différents ; et le code 8-hex (`md5(uniqid())`, 32 bits) est inséré sur une
colonne **UNIQUE** (`2026_03_08_145926...:16`) **sans retry** → une collision (astronomiquement rare) fait remonter
un `500 Erreur serveur` sur un simple check de solde. Idem `register`/`generateQr`/guest-signup. Trivial à l'échelle
mono-restaurant mais c'est une écriture-sur-lecture + un chemin d'erreur non géré.

---

## ÉCARTÉS (vérifiés SAINS — pas de report)

- **Idempotence commande (double-submit → 2 commandes)** : `api/frontend/order` est dans
  `config/idempotency.php:41` `required_routes` → header manquant = **422** (`IdempotencyKeyMiddleware:52-57`) ;
  middleware `enabled=true` runtime ; scope `(branch_id,user_id,sha256(key))` + payload-hash (409 si payload
  divergent) ; **double couche** service (`FrontendOrderService:168-184` Cache::lock + unique
  `(branch_id,idempotency_key,user_id)`) + DB. Solide.
- **Prix non recalculé serveur (trust client)** : `myOrderStore` **unset** total/subtotal/discount client
  (`:271`) et recalcule via `PricingService::calculateOrder(PricingRequest::forKiosk(...))` (SSOT), prix **toujours**
  depuis la DB, gardes cross-item variation/extra (`:398-435`), items inexistants rejetés 422. **Web ET borne
  passent le MÊME service** (cohérence prix confirmée).
- **Fidélité — solde autoritatif / boucle earn+burn / points négatifs / double-crédit** : solde 100% serveur.
  Accrual `AwardLoyaltyPointsOnDelivery` = sentinelle atomique `loyalty_points_awarded=-1` (exactly-once) ;
  redeem inline plafonné (`DiscountCalculator::kioskLoyaltyRedemption:66-68` refuse si `points < requis`, jamais
  négatif) ; clawback refund `LoyaltyService::clawbackEarnedPoints` clampé `max(0,...)` + NOOP idempotent
  (index UNIQUE `(user_id,order_id,type)`) ; `refundPoints` NOOP idempotent. Pas de boucle inflationniste
  (earn sur `total` payé post-remise, burn à la création). RAS.
- **OTP brute-force / réutilisation / énumération** : `OtpManagerService` CSPRNG `random_int` ; OTP **supprimé au
  succès** (`:106-109`) donc non réutilisable ; compteur d'échecs **par téléphone** (5 → brûle l'OTP, `:88-95`) en
  plus du throttle route (otp 5/min, verify 3/5min) ; marqueur one-time `phone_verified:` (anti-hijack) ;
  purge des OTP périmés. Portillon `!$otp->exists()` inversé déjà corrigé (SELF-AUDIT R5). Solide.
- **Tracking — fuite PII cross-commande (id/token deviné)** : `OrderController::show` →
  `FrontendOrderService::show:717` garde propriété `user_id !== Auth::id()` → **403** ; `index` scoping
  `where('user_id', auth()->id())` ; `escpos`/`paymentConfirm` exigent KioskMachine propriétaire + branch match.
  `OrderDetailsResource` masque le tél livreur (`PhoneDisplay::safe`) et n'expose plus balance/email (heal).
- **Adresse / IDOR** : `AddressController` show/update/destroy vérifient `user_id !== auth()->id()` → 403 ;
  snapshot d'adresse sur commande re-vérifie l'ownership (`FrontendOrderService:602-612`, throw 403 + rollback).
- **Livraison — zone/frais** : `OrderRequest::prepareForValidation:103-129` recalcule `delivery_charge` serveur
  via `DeliveryQuoteService::quoteForSavedAddress` (géocode adresse **sauvegardée**, non falsifiable) ; fallback
  distance-client seulement si `address_id` absent → rejeté par les règles (address_id requis) avant persist ;
  minimum livraison + tél valides gardés ; livraison offerte ≥ seuil appliquée sur subtotal serveur.
- **Coupon validité/expiry** : `validateCouponForOrder` vérifie min_order (sur **subtotal serveur** en création),
  fenêtre start/end (end inclusif endOfDay), status ACTIVE via `isUsableNow` (jours/heures/branch/surface) ;
  liste publique `couponDateWise` filtre désormais `status` (heal). Preview `/coupon-checking` non-auth = advisory
  (l'ordre re-valide en autoritatif) — pas de fuite exploitable au-delà de codes coupons (partageables) sous throttle.

---

## SYNTHÈSE
5 systèmes web-backend très durcis (≈50+ sessions d'audit visibles). **1 P2 réel + reachable** (verrou de login web
par `/loyalty/register` is_guest=NO/no-role, 7 comptes déjà affectés), 3 P3 (asymétrie source_surface self-healing,
coupon non-atomique documenté, write-on-read `check`). Aucun P0/P1. Money-path prix/fidélité/OTP/idempotence/IDOR :
sains.
