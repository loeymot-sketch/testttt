# Z6 — ANIMATION COMMERCIALE (ONB-09 / W1) — 2026-08-26

> **Mandat de session : LECTURE SEULE ABSOLUE.** Aucun POST/PUT/PATCH/DELETE, aucune écriture DB, aucune commande
> créée, aucun worker lancé. 100 % analyse de code (Read/grep), une migration de schéma lue, et deux vérifications
> Redis en lecture seule (`TYPE`/`LLEN`) + une lecture `ps aux` pour l'état du worker. Chaque `file:line` ci-dessous a
> été ouvert et lu ; ce qui n'a pas pu être vérifié par le code seul est marqué **NON VÉRIFIÉ**.

---

## 1. « -10 % sur les menus le mardi » — possible aujourd'hui ? **NON, partiellement.**

Deux mécanismes existent, aucun ne couvre le cas complet.

**Coupon** (`coupons` table) — couvre **pourcentage + jour-de-semaine**, mais **PAS la portée catégorie** :
- `discount_type = PERCENTAGE` existe et est validé ≤ 100 % (`app/Http/Requests/CouponRequest.php:56-58,83`).
- `valid_days_of_week` (JSON, colonne réelle) existe depuis la migration
  `database/migrations/2026_05_06_140000_add_advanced_promo_fields_to_coupons.php:26-28`, validée
  `Rule::in(['mon'..'sun'])` (`CouponRequest.php:76-77`), et **appliquée réellement** dans
  `app/Models/Coupon.php::isUsableNow()` (bloc « Jour de la semaine », lignes ~117-125 : compare
  `Carbon::englishDayOfWeek` au jour du coupon).
- **Correction au mandat de mission** : le §0.7/§G du GOAL suppose que la planification hebdomadaire (« G-DATA ») est
  peut-être absente (« `coupons.days_of_week` si absente »). C'est FAUX : elle existe et fonctionne déjà pour les
  coupons — G-DATA est donc déjà satisfait pour ce mécanisme, pas à ouvrir.
- **Ce qui manque : aucune portée catégorie/article.** `grep -n "category" app/Models/Coupon.php
  app/Services/CouponService.php` = 0 résultat. Un coupon n'a que `discount`, `discount_type`, `minimum_order`,
  `maximum_discount` — il s'applique au **sous-total entier du panier**
  (`app/Services/CouponService.php::calculateDiscountAmount()`, lignes ~318-327 : `$subtotal * discount / 100`),
  jamais à une sélection d'articles. « -10 % sur les menus » (une catégorie) ne peut donc PAS être exprimé — seul
  « -10 % sur toute la commande le mardi » le peut.

**Offer** (`offers`/`offer_items`) — couvre la **portée article** (`offer_items.item_id`,
`database/migrations/2022_11_17_111737_create_offer_items_table.php:17`), mais :
- `amount` est un montant **FIXE** (`decimal 19,6`, `create_offers_table.php:15`) — **aucun type pourcentage**,
  aucune colonne `discount_type`.
- **Aucune colonne jour-de-semaine/heure** sur `offers` (seulement `start_date`/`end_date` datetime).
- Et de toute façon **une offre n'est jamais facturée** : `OfferController.php:31-38` bloque `store/update/destroy`
  tant que `features.offers_enabled !== true` (faux par défaut), avec le commentaire exact : *« PricingService
  (frozen SSOT) does NOT apply offer discounts, so a created/edited offer would be DISPLAYED but never charged »*.

**Verdict** : le cas exact de Nadia (pourcentage + catégorie + jour) n'est exprimable par **aucun** des deux
mécanismes. **Ce qui manque précisément** : un champ de portée catégorie/article sur `coupons` (ou un
`discount_type=PERCENTAGE` + `valid_days_of_week` sur `offers`). Sans développeur, un commerçant peut aujourd'hui
créer « -10 % sur TOUT le panier le mardi » (coupon, techniquement correct) mais pas « -10 % sur les menus »
(catégorie) le mardi.

---

## 2. Devis ≠ commit — **CONFIRMÉ, avec preuve file:line, et cause racine identifiée.**

Chemin de validation d'un coupon = `CouponService::resolveCouponById()` →
`validateCouponForOrder()` → `Coupon::isUsableNow($branchId, $surface, $now)` (`app/Models/Coupon.php`). Si
`branch_scope`/`surfaces` sont définis et que `$branchId`/`$surface` valent `null`, `isUsableNow()` **refuse** (pas un
bypass permissif — vérifié : lignes ~103-116 branche, ~119-126 canal, chacune `return false` sur `null`).

**L'écart** : les 4 sites de COMMIT passent le VRAI `branch_id` + la VRAIE surface —
`app/Services/OrderService.php:600-609` (`'pos'`), `:1150-1165` (`'pos'`), `:1795-1811` (`'pos'`),
`app/Services/FrontendOrderService.php:572-596` (`'kiosk'`/`'web'` selon `$isKioskMachineOrder`).

Mais le calcul de PRIX (devis ET nouveau calcul de scellement à la finalisation) passe par le chemin SSOT gelé :
`PricingService::calculateOrder()` (`app/Services/Pricing/PricingService.php:330-337`) → `DiscountCalculator::
couponDiscount()` (`app/Services/Pricing/DiscountCalculator.php:12-19`) → `resolveCouponById($couponId, $subtotal,
$customerUserId)` — **SEULEMENT 3 arguments**, `$branchId`/`$surface` jamais transmis, alors que
`PricingRequest` (le `$req` reçu par `PricingService::calculateOrder`) **porte déjà** `branchId` et `context`
(`'pos'`/`'kiosk'`/`'web'`/`'table'`, `PricingRequest.php:14,16`) — donnée disponible, simplement pas relayée
(`PricingService.php:332-337`).

Conséquence exacte : `OrderQuoteService::quote()` (devis, `app/Services/Order/OrderQuoteService.php:298-352`
`calculatePricing()`) ET `OrderQuoteService::sealForCommit()` (`:123-138`, qui **rappelle** `quote()` pour comparer le
total au moment de payer) évaluent TOUS LES DEUX un coupon `surfaces`/`branch_scope` restreint SANS contexte —
`isUsableNow(null, null, ...)` **refuse systématiquement**, même quand le coupon correspond exactement à la surface
réelle (ex. coupon `surfaces=['kiosk']` appliqué sur un VRAI devis borne).

**Preuve indépendante, déjà dans la base** : `tests/Feature/Coupon/CouponSurfaceEnforcedAtCommitTest.php`, test
`test_kiosk_surface_coupon_accepted_on_kiosk_order_pending_frozen_pricing_gate()` (fin de fichier) — **SKIPPÉ**
explicitement pour cette raison exacte, commentaire cite `PricingService.php:~331` + `DiscountCalculator.php:12`,
conclut : *« Requires a human-gated frozen change to thread surface+branch »*. Le défaut est donc déjà connu et
documenté dans le code, pas halluciné ici.

**Sens réel de la divergence** (à corriger dans le cadrage GOAL) : ce n'est pas « accepté au devis, refusé au
commit » au sens temporel simple — le même refus frappe la toute première étape de tarification (devis) ET le
scellement au paiement, pour la MÊME raison. Le point d'acceptation trompeur est ailleurs : `POST
/api/frontend/coupon-checking` (`CouponCheckRequest` a bien `branch_id`+`surface`, `app/Http/Requests/
CouponCheckRequest.php:29-30`, relayés dans `CouponService::couponChecking()`,
`app/Services/CouponService.php:255-263`) valide **avec** contexte et peut dire « code valide, -10 % » — puis le
devis/paiement scellé (même coupon, même commande) échoue avec `coupon_not_applicable_now` (422). C'est l'écart
aperçu-prix vs paiement demandé par la mission, confirmé.

**Portée réelle** : uniquement les coupons utilisant `branch_scope` ou `surfaces` (champs ajoutés 2026-05-06,
optionnels). Un coupon sans ces champs n'est pas affecté. Mais `surfaces=['kiosk']` est exactement l'exemple de
Nadia (« code BIENVENUE sur la borne uniquement ») — **ce cas précis casse**.

**Zone gelée concernée** : la cause vit dans `PricingService.php`/`DiscountCalculator.php` (§7 CLAUDE.md, LOCK
G-PRIX-COUPON requis avant tout correctif — je n'ai touché ni l'un ni l'autre).

---

## 3. Fidélité — modèle, règles, réglable ou codé ?

**Réglable par le commerçant, SANS code**, via `LoyaltySetupController` (`index`+`update`, gate
`permission:settings`, `app/Http/Controllers/Admin/LoyaltySetupController.php:19-32`) →
`LoyaltySetupRequest` (`app/Http/Requests/LoyaltySetupRequest.php:16-20`) → stocké `Settings::group('loyalty_setup')`
(pas `.env`, pas code) :
- `loyalty_points_per_euro` (points gagnés par € dépensé, défaut **10**)
- `loyalty_points_for_1_euro_discount` (« le taux » — points requis pour 1 € de remise, défaut **100**)
- `loyalty_min_redeem_points` (plancher d'utilisation, défaut **50**)

Barème centralisé dans `app/Services/Loyalty/LoyaltyRules.php` (une seule définition pour caisse/borne/site — évite
le bug du 2026-08-14, facteur 10, documenté dans le fichier même). Simulation « 25 € → N points » : **pas d'écran
dédié trouvé** (`grep -rn "simulat" resources/js/components/**/loyalty*` = 0 résultat) — la formule existe
(`pointsPerEuro()`) mais rien ne l'affiche en aperçu ; **NON VÉRIFIÉ** qu'un tel écran existe ailleurs.

**PAS réglable, code/`.env` uniquement** (`config/loyalty.php`) : secret HMAC QR (`:31` `LOYALTY_QR_SECRET`), TTL
token (`:39`, 300 s), tolérance d'horloge (`:44`, 30 s), `accept_legacy_plaintext` (`:68-72`, faux par défaut,
bascule sécurité), longueur min. du secret (`:98`, 32), fenêtre de purge des rachats orphelins (`:119`, 30 min). Ce
sont des paramètres de sécurité/infrastructure, pas des règles commerciales — cohérent avec le mandat (le secret QR
« reste `.env` », mission §4 T-2.1.1).

**Pas d'expiration de points trouvée** : `grep -n "expir" app/Services/Loyalty*.php app/Services/LoyaltyService.php`
ne montre qu'un type d'action `'expire'` listé en commentaire d'énumération (`LoyaltyService.php:143`) sans logique
de purge active repérée dans le temps imparti — **NON VÉRIFIÉ** que l'expiration est totalement absente (recherche
non exhaustive), mais aucun mécanisme actif trouvé.

---

## 4. Où le prix est-il réellement modifié ?

**Dans `PricingService.php` (gelé), pas ailleurs**, comme l'exige NF525 :
`PricingService::calculateOrder()` ligne 330-337 appelle `DiscountCalculator::couponDiscount()`
(`DiscountCalculator.php:12-19`), qui appelle `CouponService::calculateDiscountAmount()`
(`app/Services/CouponService.php:318-327`) — c'est la SEULE ligne qui transforme un `coupon_id` en montant de
remise, pour TOUS les chemins (devis POS, devis kiosk, commit POS ×3 sites, commit frontend/kiosk/web). Le bug de
la section 2 est donc **à l'intérieur** de la zone gelée SSOT, pas un contournement client — cohérent avec C6 (0
remise calculée côté client). Les offres (`Offer`) ne modifient AUCUN prix nulle part : `grep -rn "Offer::"
app/Services/Pricing/` = 0 résultat, confirmé par `OfferController.php:33-38`.

---

## 5. File de notifications — mesure en direct (lecture seule, Redis `TYPE`/`LLEN`, aucun worker lancé)

- Clé `le_cayenne_database_queues:notifications` (liste Redis, préfixe `config/database.php:128`,
  `env('REDIS_PREFIX', 'le_cayenne_database_')`) : **`TYPE` = list, `LLEN` = 1511** jobs en attente.
- Clé jumelle `...notifications:notify` (mécanique interne Laravel Redis-queue, pas un second lot) : même longueur,
  1511.
- Aucune clé `...notifications:reserved` ni `...notifications:delayed` trouvée (`--scan --pattern "*queues*"` = 2
  clés seulement) → aucun job actuellement pris en charge par un worker.
- **Un worker TOURNE bel et bien** (`ps aux`, PID 35253) : `php artisan queue:work --queue=high,default --tries=3
  --timeout=60` — mais son périmètre EXCLUT explicitement `notifications`. La file n'est donc pas juste
  « pas encore vue », elle est **structurellement exclue** du worker actif. `SendFcmNotificationJob::__construct()`
  pose `$this->onQueue('notifications')` (`app/Jobs/SendFcmNotificationJob.php:66-67`) — chaque notification créée
  s'ajoute à une file qu'aucun processus n'écoute.
- Chiffre cohérent avec le brief (« ~1 490-1 511 ») — confirmé à 1511 exactement à l'instant de la mesure.

---

## Résumé pour le rapport de mission (25 lignes)

1. **« -10 % le mardi » : NON, pas dans le cas exact demandé.** Coupon = pourcentage + jour-de-semaine OK
   (`valid_days_of_week`, `Coupon.php` `isUsableNow()`) mais s'applique au panier ENTIER, aucune portée catégorie
   (`grep category` = 0 dans `Coupon`/`CouponService`). Offer = portée article OK mais montant FIXE seulement, pas
   de jour-de-semaine, et **jamais facturée** (`OfferController.php:31-38`, `PricingService` ne l'applique pas).
   Manque exact : un champ de portée catégorie/article sur `coupons`. Correction au GOAL : G-DATA (planification
   hebdo) est déjà résolu pour les coupons — ce n'est PAS le vrai manque.
2. **Devis ≠ commit : CONFIRMÉ, cause racine trouvée.** `DiscountCalculator::couponDiscount()`
   (`DiscountCalculator.php:12-19`) appelle `resolveCouponById()` avec 3 arguments seulement — jamais
   `branchId`/`surface`, alors que `PricingRequest` les porte déjà (`PricingRequest.php:14,16`) et que
   `PricingService.php:332-337` ne les relaie pas. Résultat : un coupon `surfaces=['kiosk']` (exactement l'exemple
   « code BIENVENUE borne ») est refusé au devis ET au scellement paiement (même cause), alors que
   `/coupon-checking` (qui reçoit `branch_id`/`surface`, `CouponCheckRequest.php:29-30`) le dit valide. Déjà
   documenté dans le code : `CouponSurfaceEnforcedAtCommitTest.php`, test SKIPPÉ nommé explicitement pour ce défaut,
   citant les mêmes 2 fichiers. Portée : uniquement les coupons avec `branch_scope`/`surfaces` définis. Cause vit en
   zone gelée (`PricingService`/`DiscountCalculator`) → LOCK requis avant tout fix, non touché ici.
3. **Fidélité : réglable sans code.** `LoyaltySetupController` (`permission:settings`) → 3 règles dans
   `Settings::group('loyalty_setup')` : points/€ gagnés (déf. 10), points/1€ remise (déf. 100), plancher
   d'utilisation (déf. 50). Secret QR/TTL/`accept_legacy_plaintext` restent `.env`/code (sécurité, pas commercial) —
   cohérent avec le mandat. Aucune simulation « 25€ → N points » trouvée à l'écran. Pas d'expiration de points
   active repérée.
4. **Prix modifié UNIQUEMENT dans `PricingService.php`** (`:330-337` → `DiscountCalculator.php:12-19` →
   `CouponService.php:318-327`) — SSOT respecté, le bug §2 est À L'INTÉRIEUR de la zone gelée, pas un contournement
   client. Offres = 0 ligne de prix nulle part (jamais câblées).
5. **File `notifications` : 1511 jobs en attente** (Redis `le_cayenne_database_queues:notifications`, `LLEN`
   mesuré). Un worker tourne (PID 35253) mais scope `--queue=high,default` — `notifications` explicitement exclu,
   donc structurellement orpheline, pas juste en retard.
