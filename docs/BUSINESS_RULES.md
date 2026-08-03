# FoodKing : Core Business Rules (Logique Mathématique)

Ce document centralise les **Lois Métier Mathématiques Inflexibles** du projet. Toute modification du code (`Controller`, `Service`, `Frontend`) doit respecter ces principes à la lettre sous peine de corruption financière (`php artisan test` validera ces règles).

### Convention de traçabilité P11

Les mentions `(cycle P11_*, 2026-04-20)` renvoient aux livrables de vérification / durcissement du même millésime. Elles servent à **lier** une règle rédigée ici au cycle qui l’a imposée ou l’a auditée, sans remplacer le détail des rapports `reports/review/VERIFY_*`.

---

## ⚖️ 1. Règle du Prix Central (SSOT)

**Principe : Le frontend (Kiosk, App Cliente, POS Web) n'a PAS le droit de définir le prix d'un produit.**

### Fonctionnement :
1. Le client sélectionne un Menu `Item A` à 10.00 €. Le Panier Kiosk affiche 10.00 €.
2. Le Kiosk envoie la requête API `/api/frontend/order` avec `total: 10.00`.
3. **Action Système :** Laravel capte l'ID de `Item A`, interroge la table `items`, récupère la vraie valeur serveur (10.00) et l'additionne au `Subtotal`. Si le backend indique 15.00 €, la commande vaudra 15.00 €. Le `total` du Payload initial est mathématiquement ignoré.

## 🧮 2. Calcul du Grand Total (Addition des frais)

Le `Total` d'une Order est le résultat direct de l'équation suivante gérée par le `FrontendOrderService` :

```text
ORDER_TOTAL = SUBTOTAL(items_db_price * quantity) 
              + DELIVERY_CHARGE (Si order_type == Delivery)
              - DISCOUNT (Si coupon valide)
              + TAXES (Si taxe locale définie)
```

### Détails Taxes (TVA) :
Le système calcule la taxe en **Cascade**.
- Chaque `Item` possède un `tax_id` dans la BDD.
- Le Service boucle sur le panier, regarde la Taxe assignée à l'Item et calcule le montant de taxe additionnel (ex: `Price * Tax_Rate / 100`).
- Il n'y a pas de "Taxe Unique Globale" appliquée sur le `Total`. C'est l'addition des taxes de chaque élément qui forme la "Global Tax" finale de la commande.

### Arrondi et borne inférieure

- Le sous-total et les taxes sont calculés côté serveur à partir des prix catalogue ; le frontend ne fait qu’afficher la projection.
- Toute réduction (coupon) est bornée pour que le total reste ≥ `0.00` après application (voir §3).

## 🎁 3. Les Réductions (Coupons)

**Un coupon n'est jamais poussé brût ("-5€"). Le Frontend donne le Code ("PROMO20") au Backend.**

- **Validation par le Backend** : Le `CouponService` vérifie la validité du Code (Type : `Date`, Type : `Fixed` (ex: 5€), Type : `Percentage` (ex: 20%)).
- **Plafond (Max Discount)** : Même un coupon `-50%` possède fréquemment une clause "Jusqu'à 10€ max" dans la BDD. Le Backend doit borner le rabais calculé.
- L'addition de tous les discounts ne peut **jamais** rendre un `ORDER_TOTAL` négatif. Le prix plancher est `0.00 €`.
- **Limite par utilisateur (V1)** `(cycle P11_COUPON_LIMIT_PER_USER_KIOSK, V2 — état code 2026-04-20)` : le modèle `Coupon` expose `limit_per_user` ; `CouponService::validateCouponForOrder` compte les lignes `OrderCoupon` pour `(user_id, coupon_id)` et rejette au-delà de la limite (`app/Services/CouponService.php:308-317`). Pas de table `coupon_usages` ni de jeton kiosk dédié dans le code actuel.
- **Scope par branche (prévu V2)** `(cycle P11_COUPON_BRANCH_ISOLATION, V2)` : le schéma `coupons` n’expose pas de `branch_id` au 2026-04-20 (`app/Models/Coupon.php`). La règle cible reste : coupon nullable global vs `branch_id` strict, filtrage dans la validation, cross-branche → HTTP 422 `coupon_not_applicable_for_branch`.

### Points d’entrée publics côté coupon

- `CouponService::resolveCouponById` / `resolveCouponByCode` délèguent à `validateCouponForOrder` (méthode **privée**, partagée) (`app/Services/CouponService.php:248-262`, `287+`).
- `CouponService::calculateDiscountAmount` applique le plafond `maximum_discount` avant de retourner le montant (`app/Services/CouponService.php:268-279`).

## 🔄 4. Transitions d'États Logiques (Order Status)

Une commande vit via un pipeline unidirectionnel (Enum `OrderStatus`).

- **Source de vérité des entiers :** `app/Enums/OrderStatus.php` (à jour avec la colonne `orders.status`).
- Pipeline principal : `PENDING (1)` ➔ `ACCEPT (4)` ➔ `PREPARING (7)` ➔ `PREPARED (8)` ➔ `OUT_FOR_DELIVERY (10)` ➔ `DELIVERED (13)`.
- États terminaux / exceptionnels : `CANCELED (16)`, `REJECTED (19)`, `RETURNED (22)` (voir enum et `OrderStateMachine::allows` / `ValidStatusTransition`).
- **Interdit :** Passer de `PENDING` (Non payé par le Front/TPE) directement à `PREPARING` ou `DELIVERED`. Le Backend rejettera (422/400) ou restaurera le statut via ses Observers. Seul un Admin dashboard avec confirmation de paiement peut bypasser ce flow manuellement (Tracé dans `action_logs`).
- **Isolation Branche :** La succursale B ne peut pas valider une commande passée sur la succursale A. Le Backend vérifie que l'user assigné au changement de statut appartient au même `branch_id` que l'`Order`.
- **RETURNED — no-op de machine d’état** `(cycle P11_RETURNED_IDEMPOTENCY, 2026-04-20)` : `OrderStateMachine::allows` accepte `$from === $to` (`app/Domain/Order/OrderStateMachine.php:29-30`) et `OrderStateMachine::apply` retourne sans mutation si le statut courant égale déjà la cible (`app/Domain/Order/OrderStateMachine.php:139-141`). Le chemin historique `OrderService::changeStatus` (`app/Services/OrderService.php:1440+`) enchaîne encore validation, cashback loyalty et enregistrement d’audit pour les transitions de type annulation/retour : une idempotence « métier » complète sur `RETURNED → RETURNED` doit être confirmée côté POS / tests de régression.
- **Garde fiscal sealed-Z (état constaté)** `(cycle P11_FISCAL_Z_OPEN_HARDENING, 2026-04-20)` : après clôture Z, un ordre agrégé est traité comme scellé pour la **suppression** : `OrderService::destroy` renvoie **HTTP 409** si l’ordre tombe dans la fenêtre d’un `ZReport` `closed` (`app/Services/OrderService.php:1735-1752`). Aucun `HTTP 423` ni garde équivalente n’a été relevé dans `changeStatus` / `changePaymentStatus` sur l’extrait audité au 2026-04-20.
- **Accès chemin KDS vs POS** `(cycle P11_RETURNED_KDS_BYPASS_LOCKDOWN, 2026-04-20)` : `KitchenDisplaySystemOrderService::list` ne charge que `ACCEPT` / `PREPARING` / `PREPARED` (`app/Services/KitchenDisplaySystemOrderService.php:54-55`), donc pas de file cuisine standard pour `DELIVERED`. Le retour caisse passe par le flux POS `POST /api/admin/pos-order/change-status/{order}` (`routes/api.php:633-634`). Il n’existe pas de route dédiée `…/return` dans `routes/api.php` au 2026-04-20.

### Journalisation des transitions

- `OrderStateMachine::recordTransition` est un **best-effort** : si `from_status === to_status`, la méthode retourne immédiatement sans écrire de ligne (`app/Domain/Order/OrderStateMachine.php:92-94`).
- Les transitions « sensibles » (annulation / retour) déclenchent en plus une écriture `AuditLogService::write` dans `OrderService::changeStatus` (`app/Services/OrderService.php:1543-1565`).

## Kiosk Idle Timeout
Canonical value: **3 minutes (180 000 ms)**.
"Still there?" modal appears at **2 min 30 s** (150 000 ms).
If no interaction within 3 min, the kiosk resets to idle screen and clears the cart.
Timer is NOT started on: idle screen, payment, waiting, confirmation routes.

## 5. Stock & Availability (par branche) `(cycle P11_BUSINESS_RULES_DOC_SYNC, 2026-04-20)`

FoodKing gère la **disponibilité** des articles **par branche** (rupture / « 86 »), pas un inventaire quantitatif complet en V1.

- **Persistance** : table `item_branch_availability`, modèle Eloquent `ItemBranchAvailability` (`app/Models/ItemBranchAvailability.php:10-11`). Absence de ligne ⇒ article considéré disponible (`app/Services/Menu/AvailabilityService.php:101-110`).
- **Toggle admin** : `POST /api/admin/menu/availability/toggle` (`routes/api.php:238-239`), contrôleur `AvailabilityController::toggle` (`app/Http/Controllers/Admin/AvailabilityController.php:22-81`), permission `items_edit` (`app/Http/Controllers/Admin/AvailabilityController.php:19`). Le service métier `AvailabilityService::toggle` mutualise création / mise à jour transactionnelle (`app/Services/Menu/AvailabilityService.php:31-72`).
- **À la commande** : `OrderService` et `FrontendOrderService` appellent `AvailabilityService::assertItemsOrderableForBranch` avant de figer les prix (`app/Services/OrderService.php:360-363`, `app/Services/FrontendOrderService.php:273-276`). Item indisponible ⇒ `InvalidArgumentException` avec code **422** (`app/Services/Menu/AvailabilityService.php:142-149`).
- **Temps réel** : `ItemAvailabilityChanged::forBranch` (`app/Events/ItemAvailabilityChanged.php:71-86`) ; autorisation de canal `branch.{branchId}` dans `routes/channels.php:25-38` (côté client Echo, abonnement privé du type `private-branch.{id}` — cf. tests `tests/Feature/Menu/AvailabilityServiceTest.php`).
- **Cache kiosk** : invalidation `kiosk.menu.branch.{id}` via listener dédié (`app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php` et enregistrement dans `EventServiceProvider`).
- **Quantités** : compteurs journaliers `max_daily_qty` / `daily_consumed_qty` et auto-86 possible (`app/Services/Menu/AvailabilityService.php:158-202`) ; pas de colonne `stock_quantity` globale sur `items` dans le flux décrit ici.
- **UI admin** : cycle `P11_AVAILABILITY_TOGGLE_UI_ADMIN` — finalisation de l’écran Menu côté admin suivie séparément.

### Double implémentation toggle (contrôleur + service)

- L’`AvailabilityController` gère le scope multi-branche admin, les transactions et le dispatch **après commit** (`app/Http/Controllers/Admin/AvailabilityController.php:39-72`).
- `AvailabilityService::toggle` reste le point d’entrée pour POS / jobs : verrou de ligne, idempotence partielle si l’état ne change pas (`app/Services/Menu/AvailabilityService.php:37-62`), et `toggleForAllBranches` pour une propagation catalogue (`app/Services/Menu/AvailabilityService.php:79-96`).

## 6. Conformité NF525 `(cycle P11_BUSINESS_RULES_DOC_SYNC, 2026-04-20)`

Le périmètre fiscal français (NF525 / traçabilité caisse) s’appuie sur les briques suivantes **dans le code audité** :

- **Signatures Z** : à la clôture, `ZReportService::close` enchaîne `prev_hash` (signature du Z fermé précédent) et calcule `signature` pour le rapport qui passe à `closed` (`app/Services/Fiscal/ZReportService.php:131-145`). Les statuts de cycle de vie du modèle sont `open` et `closed` uniquement (`app/Models/ZReport.php:15-16`) ; la clôture lit le rapport ouvert sous `lockForUpdate` (`app/Services/Fiscal/ZReportService.php:117-122`).
- **Audit fiscal append-only** : `AuditLogService` écrit une chaîne HMAC par branche avec verrouillage et contraintes d’unicité documentés dans le service (`app/Services/Fiscal/AuditLogService.php:15-65`).
- **Immutabilité après clôture Z** : garde sur `OrderService::destroy` (HTTP 409, message « sealed ») (`app/Services/OrderService.php:1735-1752`).
- **Paiements** : constantes `PaymentStatus` (`app/Enums/PaymentStatus.php:5-9`) ; changement de statut de paiement tracé via `OrderService::changePaymentStatus` et `AuditLogService::write` (`app/Services/OrderService.php:1628-1643`). Idempotence de création de commande : en-tête **`X-Idempotency-Key`** côté `OrderService` / `FrontendOrderService` (`app/Services/OrderService.php:558-561`, `app/Services/FrontendOrderService.php:128-130`). *Note : aucune classe `PaymentStateMachine` dédiée n’existe dans `app/` au 2026-04-20 — le cycle `P11_PAYMENT_STATUS_STATE_MACHINE` couvre la règle métier cible.*

### Ouverture de période Z

- `ZReportService::open` refuse un `branch_id` non positif (`app/Services/Fiscal/ZReportService.php:46-48`), sérialise via `Cache::lock` (`app/Services/Fiscal/ZReportService.php:51-57`) et interdit deux rapports `open` simultanés pour la même branche (`app/Services/Fiscal/ZReportService.php:60-68`).

### Agrégats signés

- La méthode `aggregate` documente les règles d’inclusion des commandes (présence de `fiscal_sequence_no`, demi-intervalle temporel cohérent avec la clôture) (`app/Services/Fiscal/ZReportService.php:181-214`). Seules les commandes réellement comptabilisées fiscalement alimentent les totaux signés.

## 7. Isolation branche (SaaS) `(cycle P11_BUSINESS_RULES_DOC_SYNC, 2026-04-20)`

- **Scope global** : `BranchScope` restreint les requêtes au `branch_id` de l’utilisateur authentifié, sauf `branch_id === 0` (court-circuit admin) (`app/Models/Scopes/BranchScope.php:27-39`). Le scope est enregistré sur `Order`, `FrontendOrder`, `User`, `DiningTable`, `PushNotification` (voir `static::addGlobalScope` dans chaque modèle) ; le modèle `User` est exclu de l’application du filtre dans `apply` pour éviter la récursion Sanctum (`app/Models/Scopes/BranchScope.php:21-23`).
- **Admin (`branch_id = 0`)** : peut lister toutes les branches ; toute action sensible reste tracée (`action_logs`, `audit_logs` selon le flux — ex. changement de statut `app/Services/OrderService.php:1531-1541`).
- **Canaux temps réel** : `routes/channels.php:25-38` — jetons kiosk limités à la branche de la `KioskMachine`, staff à sa branche, admin `branch_id = 0` autorisé sur tous les canaux `branch.{branchId}`.
- **Routes fiscales** : `ZReportController` exige la permission `pos-manage-fiscal` (`app/Http/Controllers/Admin/Fiscal/ZReportController.php:91-95`, seed `database/seeders/RolePermissionTableSeeder.php:35-36`) et `resolveBranchId` refuse un admin sans branche épinglée (**HTTP 422**) pour éviter une clôture accidentelle (`app/Http/Controllers/Admin/Fiscal/ZReportController.php:98-108`).

### Modèles branch-scopés (référence rapide)

| Modèle | Fichier | Global scope |
| --- | --- | --- |
| `Order` | `app/Models/Order.php` | `BranchScope` |
| `FrontendOrder` | `app/Models/FrontendOrder.php` | `BranchScope` |
| `User` | `app/Models/User.php` | `BranchScope` (court-circuité dans `apply`) |
| `DiningTable` | `app/Models/DiningTable.php` | `BranchScope` |
| `PushNotification` | `app/Models/PushNotification.php` | `BranchScope` |

### Contrôle d’accès réseau (rappel)

- Même lorsque le scope SQL est contourné (`withoutGlobalScopes` dans certains rapports fiscaux), les agrégations Z filtrent explicitement `branch_id` (`app/Services/Fiscal/ZReportService.php:207-208`).
- Les jetons API kiosk doivent rester cantonnés à la branche physique : la détection se fait via `tokenCan('kiosk:order')` + `KioskMachine.branch_id` dans `routes/channels.php:27-29`.

## Synthèse des écarts plan / code au 2026-04-20

Les éléments suivants ont été constatés pendant la synchronisation documentaire ; ils ne sont **pas** des bugs déclarés, mais des deltas à connaître pour éviter une lecture trop optimiste des anciens plans :

1. **Modèle de disponibilité** : le code utilise `ItemBranchAvailability` / `item_branch_availability`, pas `BranchItemAvailability` / `branch_item_availabilities`.
2. **Garde HTTP après clôture Z** : `HTTP 409` sur destruction d’ordre scellé (`OrderService::destroy`) plutôt que `HTTP 423` sur `changeStatus`.
3. **État intermédiaire Z** : seuls `open` et `closed` existent sur `ZReport` ; pas de statut `CLOSING` nommé dans le modèle.
4. **Machine de paiement dédiée** : pas de classe `PaymentStateMachine` dans `app/` ; les transitions se lisent dans les services/contrôleurs et l’enum `PaymentStatus`.
5. **Coupons** : `branch_id` coupon et table `coupon_usages` absents — la limite utilisateur repose sur `OrderCoupon` + `limit_per_user`.
6. **Route POS retour** : pas d’endpoint dédié `…/return` — le statut `RETURNED` transite via `change-status` avec les validations habituelles.

---

**Dernière révision :** 2026-04-20 — cycle `P11_BUSINESS_RULES_DOC_SYNC` — sources d'autorité : `reports/review/VERIFY_*_2026-04-20.md`, `reports/review/AUDIT_POS_110_*.md`, `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md`.

---

## Fidélité — structure pérenne (2026-07-28, GOAL WEB COMMANDE Wave E)

**Le TÉLÉPHONE est la clé du compte fidélité** — web, borne, caisse, et app future.
L'email n'est que le canal de **vérification/login** (code EMAIL-OTP, 0 SMS).

Chaîne canonique (verrouillée par `tests/Feature/Loyalty/EmailSignupLoyaltyLinkTest.php`) :
1. Signup web `POST /auth/guest-signup/email-otp` (phone + email) → verify → le User
   porte **dès sa création** un `loyalty_code` (LOY-WEB-01, `GuestSignupController::register`).
2. Commande livrée → `AwardLoyaltyPointsOnDelivery` crédite `floor(total × loyalty_points_per_euro)`
   (défaut 10 pts/€). ⚠ **Sans `loyalty_code` le listener n'attribue RIEN** — c'est pourquoi
   le maillon signup est sentinellisé.
3. Lookup cross-surface **par téléphone** : `POST /frontend/loyalty/check` (fallback phone après
   loyalty_code) — même endpoint que l'écran Fidélité caisse et le scan borne.

Ajout de points par téléphone en caisse (client sans compte web) : `loyalty/register` par phone
crée/rattache le compte — le client retrouve ses points le jour où il crée son compte web
(même téléphone). Aucune migration nécessaire : `loyalty_code`/`loyalty_points` vivent sur `users`.
