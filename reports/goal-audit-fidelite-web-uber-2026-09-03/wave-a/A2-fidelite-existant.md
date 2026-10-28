# A2 — Fidélité : cartographie de l'existant (lecture seule)

Dépôt `testttt`, branche `pos/category-first-caisse-2026-06-23`, HEAD `a91f95e2e`.
Base interrogée : `foodking_e2e` (mysql -uroot). Toute affirmation ci-dessous est ancrée
sur un `fichier:ligne` lu ou une requête SQL jouée.

---

## § Ce qui EXISTE

### 1. Schéma réel (vérifié par `DESCRIBE`)

**`loyalty_transactions`** (grand-livre, 54 lignes) — colonnes :
`id, user_id, loyalty_code(varchar 25), order_id, type ENUM('earn','redeem','manual_add','manual_deduct','expire'), points(int), balance_after(int), source_surface(varchar 20), pos_session_id, description, created_at, updated_at`.
Index UNIQUE `(user_id, order_id, type)` — migration `database/migrations/2026_03_26_075919_add_unique_to_loyalty_transactions.php`.

Répartition mesurée : `earn` 17 (+1278 pts), `redeem` 20 (−6140), `manual_add` 11 (+3170), `manual_deduct` 6 (−1150).
Par surface : `pos` 32, `kiosk` 13, `web` 7, `admin` 2.

**`loyalty_consents`** (9 lignes, 7 utilisateurs distincts) :
`user_id, consent_accepted, privacy_notice_version(varchar 20), ip_hash(char 64), user_agent_hash(char 64), occurred_at`.

**`loyalty_qr_nonces_consumed`** (19 lignes) :
`nonce(varchar 64 UNIQUE), customer_id, source_surface(varchar 32), exp_at, consumed_at`.

**Colonnes portées ailleurs** : `users.loyalty_code` (varchar 15, UNIQUE) + `users.loyalty_points` (int, défaut 0) ;
`orders.loyalty_points_awarded` (int nullable, sentinelle d'idempotence) + `orders.loyalty_customer_code` (varchar 25).
Volumétrie : 172 utilisateurs porteurs d'un code, 24 avec un solde > 0, 47 commandes rattachées à un code, 11 créditées.
⚠️ `frontend_orders` **n'existe pas** comme table physique — `FrontendOrder::$table = "orders"`
(commentaire `app/Listeners/AwardLoyaltyPointsOnDelivery.php:98`).

11 migrations `*loyal*` dans `database/migrations/` (de `2026_03_08_145926` à `2026_08_01_190000`).

### 2. Le barème — définition UNIQUE
`app/Services/Loyalty/LoyaltyRules.php` (123 l.), source unique depuis 2026-08-10 :
- `pointsPerEuro()` :34 → réglage `loyalty_points_per_euro`, **défaut 10 pts/€**
- `rate()` :40 → `loyalty_points_for_1_euro_discount`, **défaut 100 pts = 1 €**
- `floorSetting()` :53 → `loyalty_min_redeem_points`, défaut 50
- `effectiveFloor()` :64 → premier multiple du taux ≥ réglage (l'annonce ne peut plus mentir)
- `usablePoints()` / `pointsMissingBeforeUse()` :99/:113
Stockage : `Settings::group('loyalty_setup')` (paquet `smartisan/settings`), **pas** un fichier de config.
Écran d'administration : `resources/js/components/admin/settings/LoyaltySetup/LoyaltySetupComponent.vue`,
route `admin.settings.loyaltySetup` (`resources/js/router/modules/settingRoutes.js:160-167`),
API `routes/api.php:547-549`. Démasqué du nav le 2026-08-10 (`resources/js/config/v1-hidden-modules.js:32`).

### 3. Où les points sont crédités / débités
- **Crédit** : `app/Listeners/AwardLoyaltyPointsOnDelivery.php`. Deux déclencheurs :
  1. Événement `OrderStatusChanged` (`app/Providers/EventServiceProvider.php:162`) → DELIVERED, ou PREPARED pour KIOSK/TAKEAWAY (`:41-47`).
  2. **Appel direct au paiement caisse** : `app/Services/OrderService.php:1516-1518`,
     gardé par `if ((int) $order->payment_status === PaymentStatus::PAID)`. Correctif du 2026-08-19 —
     307 ventes de caisse étaient restées sans crédit car nées au statut « en préparation ».
  Calcul : `floor(total × pointsPerEuro)` (`AwardLoyaltyPointsOnDelivery.php:145`), idempotent via
  sentinelle atomique `-1` sur `orders.loyalty_points_awarded` (`:96-104`).
- **Reprise** : `LoyaltyService::refundPoints()` (`app/Services/LoyaltyService.php:21`) rend les points
  DÉPENSÉS à l'annulation, par porteur issu du grand-livre ; `clawbackEarnedPoints()` (:137+) reprend les
  points GAGNÉS au remboursement (`app/Listeners/ClawbackLoyaltyPointsOnRefund.php`,
  `app/Services/PaymentService.php:913` et `:937`, `app/Services/OrderService.php:2412` et `:2617`).
- **Débit caisse** : `app/Services/Loyalty/PosRedemptionService.php` (359 l.), appelé par
  `OrderService::applyPosLoyaltyRedemption()` (`:977`) puis `debitPosLoyaltyPoints()` (`:1234`).

**Le paiement carte crédite-t-il ?** Oui pour le TPE de caisse : le crédit est branché sur
`payment_status = PAID`, indépendamment du moyen. Pour la borne, sur PREPARED. En revanche
`payment_gateways` en base : `cash-on-delivery` et `credit` actifs (status 5 = `Status::ACTIVE`),
**`paypal` et `stripe` INACTIFS** (status 10) — aucun encaissement carte *en ligne* n'est ouvert.

### 4. Le compte client
Il n'y a **pas de modèle `Customer`** : `app/Models/Customer.php` n'existe pas ; tout est porté par
`app/Models/User.php` (rôle Customer), le scope de branche étant un no-op explicite sur ce modèle
(cf. CLAUDE.md §9). Portes d'entrée réelles, toutes en API :
- `POST /api/auth/signup/{otp,verify,register}` — `routes/api.php:202-211`, `SignupController`
- `POST /api/auth/guest-signup/{otp,email-otp,email-login,verify}` — `routes/api.php:213-232`, `GuestSignupController`
- `POST /api/auth/social/{apple|google}` — `routes/api.php:246-248`
- `POST /api/frontend/loyalty/register` (public, `throttle:5,1`) — `routes/api.php:2132`
- `POST /api/frontend/loyalty/opt-in` (RGPD, écrit `loyalty_consents`) — `routes/api.php:2199`,
  méthode `app/Http/Controllers/Frontend/LoyaltyController.php:565`
Authentification : Sanctum. Les écrans Vue existent (`resources/js/router/modules/authRoutes.js` :
`/signup`, `/signup/verify`, `/signup/register`, `/guest-login`).

### 5. Côté caisse (POS) — la surface la plus aboutie
La caisse **voit** le solde et sait créditer/débiter :
- Composants : `resources/js/components/admin/pos/PosLoyaltyIdentifyModal.vue` (1167 l.),
  `PosLoyaltyRedeemModal.vue` (505 l.), `resources/js/helpers/posLoyaltyMainCta.js`,
  intégration `PosComponent.vue:484-492` (CTA « Fidélité client ») et `:1120-1136` (badge « N pts fidélité »).
- API : `routes/api.php:1717-1747` — `pos-loyalty/lookup`, `/history`, `/customers` (création client au comptoir),
  `/credit-manual`, `/deduct-manual` ; plus `pos-order/{order}/redeem-loyalty` (`:1495`) et
  `/attach-loyalty` (`:1503`). Contrôleur `app/Http/Controllers/Admin/PosLoyaltyController.php` (426 l.).
- Services : `PosCustomerLookupService` (recherche par téléphone :76, code :94, **QR :119**),
  `PosLoyaltyAttachService`, `PosManualCreditService`, `PosRedemptionService`.
- ⚠️ `public/js/pos-wizard.js` (assistant vanilla FROZEN §7) contient **0 occurrence** de
  `loyal|fidel|points` — la fidélité vit entièrement dans la coquille Vue, jamais dans le popup gelé.

### 6. Le QR — brique déjà complète
`app/Services/Loyalty/LoyaltyQrSigner.php` (264 l.). Format `lqr.<payload b64url>.<hmac b64url>`,
charge `{v:1, cust:<user_id>, code:<loyalty_code>, nonce, iat, exp}`, HMAC-SHA256 sur
`config('loyalty.qr.secret')` (`config/loyalty.php:32`, env `LOYALTY_QR_SECRET`, refus de boot en prod si vide).
**TTL 300 s**, tolérance d'horloge 30 s (`config/loyalty.php:39` et `:45`).
- Émission : `POST /api/frontend/loyalty/qr` (`routes/api.php:2159`) →
  `LoyaltyController::generateQr()` (`:1070`), mint du `loyalty_code` à la volée si absent.
- Consommation : `verifyAndConsume()` (`LoyaltyQrSigner.php:94`) — INSERT dans
  `loyalty_qr_nonces_consumed` puis capture de la violation UNIQUE = signal de rejeu (pas de TOCTOU, `:140-147`).
  Deux consommateurs : la borne via `POST /api/frontend/loyalty/scan` (`LoyaltyController.php:834`,
  exige un vrai `KioskMachine` ou du staff) et **la caisse via la caméra de la tablette**
  (`PosLoyaltyIdentifyModal.vue:815 demarrerScan()`, `BarcodeDetector` natif, `:830`) → `byQr()`.
- Le texte brut hérité `FK:<code>` est **refusé par défaut** (`LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=false`).

### 7. Tests existants (chemins réels)
33 fichiers PHPUnit — dont `tests/Feature/Loyalty/` (15 fichiers : `LoyaltyRulesTest`,
`KioskLoyaltyEarnCycleProofTest`, `LoyaltyClawbackOnRefundSentinelTest`, `LoyaltyHealthCheckTest`…),
`tests/Feature/Pos/PosLoyalty{Attach,LookupEndpoint,ManualCredit,ManualDeduct,Redeem,RedeemFloor}Test.php`,
`tests/Feature/Sentinels/LoyaltyQrSigningSentinelTest.php`,
`tests/Feature/Security/{LoyaltyCheckIdorTest,LoyaltyRegisterNoLeakTest}.php`,
`tests/Feature/Frontend/LoyaltyPointsLifecycleTest.php`, `tests/Feature/LoyaltyApiTest.php`.
6 Vitest : `tests/js/{posLoyaltyAttachCart,posLoyaltyMainPageCta,posLoyaltyRedeemModal,adminCustomerLoyaltyHistory,kioskLoyaltyConsentWiring,kioskLoyaltyDiscountConsistency}.spec.js`.
20 Playwright mobile : `tests/mobile-e2e/loyalty-01..15` + `loyalty-adv-A1..A5`.

---

## § Ce qui MANQUE pour la demande owner

1. **La vitrine client web — ABSENTE.** `routes/web.php:43-46` : `/`, `/menu`, `/offers` **redirigent
   vers `/login`**, et le routeur Vue fait la même chose (`resources/js/router/modules/frontendRoutes.js:22-25`).
   Les composants existent (`resources/js/components/frontend/{account,auth,checkout,search,tracking}`)
   et `/checkout` (`frontendRoutes.js:90`) est déclaré, mais **sans catalogue navigable il est orphelin**.
   Conséquence : aujourd'hui un client ne peut PAS créer un compte depuis un navigateur sur ce
   backend, ni commander en ligne. Les seules portes sont les API, consommées par la borne et
   l'app mobile (elle-même STANDALONE non câblée, CLAUDE.md §3bis).
   → **ABSENT — à créer** : réactivation de `frontendRoutes` (`/menu`, `/home`) + une page
   `resources/js/components/frontend/account/loyalty/` (solde, historique, QR).

2. **Le paiement carte en ligne — ABSENT.** `SELECT status FROM payment_gateways` : `stripe` = 10
   (INACTIVE), `paypal` = 10. Aucune passerelle carte web n'est active ; la carte n'existe qu'au TPE
   de caisse (SumUp, non câblé banque, CLAUDE.md §3bis) et en Plan B borne → comptoir.
   → **ABSENT — à créer/activer** : `app/Http/PaymentGateways/Gateways/Stripe.php` existe mais la
   ligne `payment_gateways` est désactivée ; décision owner requise (NF525 + encaissement réel).

3. **Le QR au RETRAIT — la lecture existe, le déclencheur non.** Le signer, le nonce anti-rejeu, la
   caméra caisse et le scan borne sont tous en place. Ce qui manque : la **liaison retrait**, c'est-à-dire
   l'association d'un QR à une commande précise à récupérer (le token porte `cust`+`code`, jamais
   d'`order_id`) et l'écran comptoir « scanner pour remettre la commande ».
   → **ABSENT — à créer** : extension de la charge (`LoyaltyQrSigner.php:57-64`, champ `ord`) +
   un point d'entrée `POST /api/admin/pos-loyalty/scan-retrait`.

4. **Historique client self-service en web** : `GET /api/frontend/loyalty/history` existe
   (`routes/api.php:2152`) mais aucun écran web ne le consomme (seul l'admin l'affiche via
   `tests/js/adminCustomerLoyaltyHistory.spec.js`).

5. **Consentement RGPD non systématique** : 7 utilisateurs consentants pour 172 porteurs de code.
   `optIn()` n'est atteint que par la borne ; `POST /loyalty/register` et `guest-signup` créent un
   `loyalty_code` **sans écrire** de `loyalty_consents`.

---

## § Risques

**NF525 — les points ne doivent jamais altérer le prix côté client.** Aujourd'hui l'invariant tient :
le débit passe par `PosRedemptionService`, appelé depuis `OrderService` APRÈS le calcul SSOT
(`OrderService.php:977`, sur `$posSsotPricingResult`), et la remise fidélité est tracée
(`orders.discount_type = 'loyalty_redeem'`, `loyalty_points_redeemed`, `OrderService.php:1391-1392`).
⚠️ **Toute future surface web qui calculerait elle-même « X points = Y € »** rouvrirait exactement le
défaut du 10 août (« le jumeau oublié », `LoyaltyRules.php:12-20`) : la caisse n'appliquait aucun
plancher parce que la règle était recopiée quatre fois. Règle dure pour la suite : **passer par
`LoyaltyRules` et par le backend, jamais recalculer côté client**.

**RGPD.** `loyalty_consents` est bien conçu (hash SHA-256 de l'IP et de l'UA, jamais la valeur brute —
`LoyaltyConsent::hashIdentifier`), mais sa couverture est de 7/172. Ouvrir l'inscription web
**sans** brancher `optIn()` sur ce chemin aggraverait le déficit de preuve de consentement.
Aucune purge/rétention n'est visible sur `loyalty_transactions` ni sur `loyalty_qr_nonces_consumed`
(cette dernière croît sans nettoyage : aucun job ne la purge après `exp_at`).

**Sécurité.** Le secret QR est correctement gardé au boot en production (`config/loyalty.php:6-10`).
Point de vigilance déjà corrigé mais à ne pas régresser : `/loyalty/scan` exige un vrai
`KioskMachine` ou du staff (`LoyaltyController.php:852-855`) — un jeton invité portant
`kiosk:order` suffisait auparavant à énumérer les profils.
