# A. Architecture Complète du Système FoodKing

## A.1 Vue d'Ensemble

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           CLIENT - BORNE TACTILE                             │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐ │
│  │  Idle Screen │→ │ Categories  │→ │  Products   │→ │   Wizard (pain,    │ │
│  │  (video/bg)  │  │  (grid)     │  │  (list)     │  │   viande, sauce...)│ │
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────────────┘ │
│         ↓                                                                    │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐ │
│  │    Cart     │→ │   Upsell    │→ │  Payment    │→ │   Waiting Screen   │ │
│  │  (qty,edit) │  │ (max-selling)│  │(card/cash/TR)│  │ (queue #, cancel)  │ │
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────────────┘ │
│         ↓                                                                    │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │                    Confirmation + Print Ticket                          │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼ API REST (axios)
┌─────────────────────────────────────────────────────────────────────────────┐
│                            BACKEND - LARAVEL 9                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  Auth Layer                                                         │   │
│  │  - auth/kiosk-login (machine auth) → token Sanctum + ability      │   │
│  │  - auth:sanctum guard + verify.api middleware                      │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                    │                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  Controllers Frontend                                               │   │
│  │  - KioskMachineLoginController (login/logout borne)                 │   │
│  │  - OrderController (create, payment-confirm webhook)                │   │
│  │  - LoyaltyController (check par code/téléphone, register)            │   │
│  │  - ItemController (products, upsells, categories)                   │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                    │                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  Services Métier                                                    │   │
│  │  - FrontendOrderService (création commande, auto-accept kiosk)       │   │
│  │  - OrderStatusScreenOrderService (orders pour OSS)                  │   │
│  │  - AwardLoyaltyPointsOnDelivery (attribution points PREPARED)        │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                    │                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  Events & Listeners                                                 │   │
│  │  - OrderStatusChanged → broadcast Echo + loyalty listener           │   │
│  │  - OrderCreated → broadcast Echo                                     │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                    ┌───────────────┼───────────────┐
                    ▼               ▼               ▼
┌─────────────────────┐ ┌─────────────────┐ ┌─────────────────────┐
│  KDS (Cuisine)      │ │  OSS (Client)   │ │  POS (Caisse)       │
│  - Colonne "Borne"  │ │  - Numéros queue│ │  - Badge kiosk cash │
│  - Temps réel Echo  │ │  - Pulsant vert │ │  - Polling 30s      │
└─────────────────────┘ └─────────────────┘ └─────────────────────┘
```

## A.2 Architecture Détaillée par Couche

### A.2.1 Couche Client (Vue 3 SPA)

**Structure du Store Vuex :**

```javascript
// store/modules/kioskCart.js - State complet
state: {
    items: [],                    // Lignes panier
    orderRef: null,              // ID commande soumise
    queueNumber: null,           // Numéro de file affiché
    upsellShown: false,          // Flag pour ne pas reshow upsell
    loyaltyCustomer: null,       // { name, points, loyalty_code }
    loyaltyDiscount: 0,          // Montant remise appliquée
    branchId: null,              // Branche courante
    idempotencyKey: null,        // Clé anti-double-soumission
    kioskToken: null,            // Token machine Sanctum
    kioskMachineId: null,        // ID machine pour logs
}

// Persisté via vuex-persistedstate (survive page refresh)
paths: [
    "kioskCart.branchId",
    "kioskCart.orderRef",
    "kioskCart.queueNumber",
    "kioskCart.idempotencyKey",
    "kioskCart.items",
    "kioskCart.loyaltyDiscount",
    "kioskCart.loyaltyCustomer",
    "kioskCart.kioskToken",
    "kioskCart.kioskMachineId",
]
```

**Intercepteurs Axios :**

```javascript
// Request - app.js
// Priorité: kioskToken > authToken > vide
const kioskToken = vuex.kioskCart?.kioskToken;
const userToken   = vuex.auth?.authToken;
const token       = kioskToken || userToken;
config.headers['Authorization'] = token ? `Bearer ${token}` : '';

// Response - app.js (NOUVEAU - Round 9)
// Capture 401 global
if (status === 401 && !_401Handling) {
    if (path.startsWith('/kiosk')) {
        store.commit('kioskCart/CLEAR_KIOSK_TOKEN');
        router.push({ name: 'kiosk.login' });
    } else {
        store.dispatch('auth/logout');
        router.push({ name: 'auth.login' });
    }
}
```

### A.2.2 Couche Backend (Laravel 9)

**Routes API Clés :**

```php
// Routes publiques (throttle protégé)
POST   auth/kiosk-login                    → KioskMachineLoginController@login

// Routes protégées auth:sanctum
POST   auth/kiosk-logout                   → KioskMachineLoginController@logout
POST   frontend/order                      → Frontend\OrderController@store
GET    frontend/order/{id}                 → Frontend\OrderController@show
POST   frontend/order/{id}/change-status   → Frontend\OrderController@changeStatus
POST   frontend/order/{id}/payment-confirm → Frontend\OrderController@paymentConfirm  [TPE webhook]
POST   frontend/loyalty/check              → Frontend\LoyaltyController@check
POST   frontend/loyalty/register           → Frontend\LoyaltyController@register
GET    frontend/item/kiosk-upsell          → Frontend\ItemController@kioskUpsell

// Admin routes (KDS)
GET    admin/kds-order                     → KitchenDisplaySystemOrderController@index
```

**Modèles Clés :**

```php
// KioskMachine (table kiosk_machines)
- id, user_id, branch_id, machine_id (string), username, password, is_login, status
- machine_id = identifiant unique physique de la borne
- is_login = flag Ask::YES/NO pour empêcher double connexion

// FrontendOrder (table frontend_orders)
- id, user_id (kiosk machine user), order_serial_no, queue_number, order_type (25)
- order_status (1,4,7,8,13,16), payment_status, payment_method, transaction_id
- order_amount, total, source (5), loyalty_code, loyalty_points_awarded
- idempotency_key (unique, anti-double)

// User (extension pour fidélité)
- loyalty_code (string 8 chars), loyalty_points (int), phone
```

### A.2.3 Couche Temps Réel (WebSocket)

**Channels Echo :**

```javascript
// KDS - subscription par branche
window.Echo.private(`branch.${branchId}`)
    .listen('.OrderStatusChanged', () => refreshOrderList())
    .listen('.OrderCreated', () => refreshOrderList());

// Fallback polling 30s si Echo indisponible
```

**Events Broadcastés :**

```php
// OrderStatusChanged
// Quand: changement status commande (ACCEPT, PREPARING, PREPARED, etc.)
// Payload: order minimal (id, status, queue_number)

// OrderCreated
// Quand: nouvelle commande créée
// Payload: order minimal
```

## A.3 Flux de Données Détaillés

### A.3.1 Authentification Machine (Round 9)

```
[Borne physique - première utilisation]
    ↓
Navigate to /kiosk → requireKioskAuth vérifie kioskToken
    ↓ (pas de token)
/kiosk/login → formulaire username/password
    ↓
POST auth/kiosk-login
    ↓
KioskMachineLoginController:
    1. Find machine by username
    2. Hash::check password
    3. DB::transaction with lockForUpdate()
    4. Revoke existing tokens: $user->tokens()->where('name', 'kiosk-token')->delete()
    5. Create new token: $user->createToken('kiosk-token', ['kiosk:order'])
    6. Update is_login = YES
    ↓
{kioskToken, kioskMachineId} → SET_KIOSK_TOKEN mutation
    ↓
localStorage (vuex-persistedstate) sauvegarde token
    ↓
Toutes requêtes axios incluent Authorization: Bearer {kioskToken}
```

### A.3.2 Création Commande Kiosk

```
[KioskPaymentComponent - confirmPayment()]
    ↓
submitOrder({ paymentMethod: 'card'|'cash'|'tr' })
    ↓
Vérification: idempotencyKey existe déjà ? → retourne commande existante
    ↓
Génération idempotencyKey = uuidv4()
    ↓
POST frontend/order
    ↓
OrderRequest validation:
    - branch_id: required|exists:branches
    - items: required|array
    - order_type: nullable|numeric
    - idempotency_key: required|string
    - payment_method: nullable|numeric
    ↓
FrontendOrderService::submitOrder():
    1. Vérifie idempotence (cherche par idempotency_key)
    2. Calcule totaux côté serveur (NE PAS faire confiance client)
    3. Crée FrontendOrder:
       - order_type = OrderType::KIOSK (25)
       - source = SOURCE_KIOSK (5)
       - order_status = OrderStatus::ACCEPT (4)  ← AUTO-ACCEPT
       - queue_number = généré atomiquement
    4. Crée OrderItems liés
    5. Event: OrderStatusChanged(order, PENDING, ACCEPT)
    6. OrderCreated broadcast
    ↓
Réponse: { id, queue_number, order_amount }
    ↓
SET_ORDER_REF(orderId, queueNumber)
    ↓
Si cash: navigate waiting
Si card/TR: affiche TPE overlay 5s puis waiting
```

### A.3.3 Mise à Jour Statut Cuisine

```
[Cuisine - chef change statut]
    ↓
POST admin/kds-order/change-status/{id}
    ↓
KitchenDisplaySystemOrderController
    ↓
OrderStatusChanged event dispatch
    ↓
Broadcast Echo: branch.{branchId} channel
    ↓
[KDS Browser] ← reçoit .OrderStatusChanged → refreshOrderList()
[Kiosque Waiting] ← polling 5s GET frontend/order/{id} → détecte PREPARED
[OSS Browser] ← reçoit .OrderStatusChanged → refresh
```

## A.4 Sécurité

### A.4.1 Auth Tokens

```php
// Kiosk Machine Token
$user->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;

// Ability 'kiosk:order' utilisé dans:
// - OrderStatusRequest@authorize() pour annulation commande
// - Middleware personnalisé (si besoin futur)
```

### A.4.2 Guards Vue Router

```javascript
// kioskRoutes.js
function requireKioskAuth(to, from, next) {
    if (to.name === 'kiosk.login') return next();
    const token = store.state.kioskCart?.kioskToken;
    if (!token) return next({ name: 'kiosk.login' });
    next();
}

function requireCart(to, from, next) {
    const isEmpty = store.getters['kioskCart/isEmpty'];
    if (isEmpty) return next({ name: 'kiosk.cart' });
    next();
}

function requireOrderRef(to, from, next) {
    const orderRef = store.state.kioskCart?.orderRef;
    if (!orderRef && !to.params.orderId) return next({ name: 'kiosk.idle' });
    next();
}
```

### A.4.3 Idempotence

```php
// Clé unique par tentative de soumission
// Si retry (même idempotency_key), retourne commande existante
// Prévient double paiement sur retry réseau
```

## A.5 Connexions Inter-Systèmes

### A.5.1 Borne ↔ KDS (Cuisine)

- **Trigger:** OrderStatusChanged event
- **Broadcast:** Echo private channel `branch.{id}`
- **Payload minimal:** order.id, order.order_status, order.queue_number
- **KDS affiche:** Colonne "Borne" dédiée avec N° queue en rouge

### A.5.2 Borne ↔ OSS (Écran Client)

- **Trigger:** Même OrderStatusChanged
- **OSS filtre:** whereNotNull('token') OR order_type = KIOSK
- **Affichage:** `N°{queue_number}` au lieu de token
- **États:** Rouge (PREPARING), Vert pulsant (PREPARED)

### A.5.3 Borne ↔ POS (Caisse)

- **Cas:** Commandes kiosk avec paiement CASH
- **Mécanisme:** Polling 30s GET admin/kds-order
- **Filtre:** order_type=25, payment_method=1, status=[4,7]
- **Affichage:** FAB (Floating Action Button) rouge pulsant avec badge compteur
- **Panel:** Drawer latéral listant commandes à encaisser

---

**Suite :** Voir `02-BORNE-SYSTEM.md` pour le détail ultra-fine du système borne (parcours utilisateur, UX/UI, wizard, animations, états).
