# D. Référence Complète des Fichiers

## D.1 Structure des Dossiers Kiosk

```
resources/js/components/frontend/kiosk/
├── KioskAppComponent.vue              # Shell principal
├── KioskLoginComponent.vue            # Auth machine (NEW R9)
├── KioskIdleScreenComponent.vue       # Écran accueil
├── KioskCategoriesComponent.vue       # Grille catégories
├── KioskProductListComponent.vue      # Liste produits + pagination
├── KioskWizardComponent.vue           # Wizard personnalisation
├── KioskOrderSummaryComponent.vue     # Récap étape finale
├── KioskCartComponent.vue             # Panier + édition (R9)
├── KioskLoyaltyComponent.vue          # Fidélité
├── KioskUpsellComponent.vue           # Max-selling (R8)
├── KioskPaymentComponent.vue          # Paiement + TPE (R8)
├── KioskWaitingComponent.vue          # Attente cuisine
├── KioskConfirmationComponent.vue     # Confirmation + print (R6)
└── steps/
    ├── KioskStepPainComponent.vue
    ├── KioskStepViandeComponent.vue
    ├── KioskStepSauceComponent.vue
    ├── KioskStepGarnituresComponent.vue
    ├── KioskStepSupplementsComponent.vue
    └── KioskStepMenuComponent.vue

resources/js/store/modules/
├── kioskCart.js                       # Store panier borne (NEW)
└── kioskMachine.js                    # CRUD admin machines (existant)

resources/js/router/modules/
└── kioskRoutes.js                     # Routes + guards (NEW)

resources/js/config/
└── env.js                             # Config API (modifié)

resources/js/
├── app.js                             # Intercepteurs axios (R9)
└── store/index.js                     # Persist kiosk token (R9)

app/Http/Controllers/Auth/
└── KioskMachineLoginController.php    # Auth borne + token (modifié R9)

app/Http/Controllers/Frontend/
├── OrderController.php                # CRUD commandes + payment-confirm
├── LoyaltyController.php              # Check/register fidélité (R7)
└── ItemController.php                 # Upsell endpoint

app/Http/Controllers/Admin/
└── KioskMachineController.php         # CRUD admin

app/Http/Resources/
├── KDSOrderDetailsResource.php        # Ajout queue_number (R6)
└── CDSOrderDetailsResource.php        # Ajout queue_number (R6)

app/Listeners/
└── AwardLoyaltyPointsOnDelivery.php   # PREPARED pour kiosk (R8)

app/Services/
├── FrontendOrderService.php           # Auto-accept kiosk + queue
├── OrderStatusScreenOrderService.php  # Include kiosk orders (R6)
└── OrderService.php                   # Core order logic

app/Enums/
├── OrderType.php                      # KIOSK = 25
├── PaymentGateway.php                 # CARD=4, TICKET_RESTAURANT=5 (R6)
└── OrderStatus.php                    # PENDING=1, ACCEPT=4, PREPARING=7, PREPARED=8, DELIVERED=13, CANCELED=16

app/Models/
├── KioskMachine.php                   # Table kiosk_machines
└── FrontendOrder.php                  # Table frontend_orders
```

## D.2 Fichiers Clés - Détail

### D.2.1 Frontend - Store Kiosk (Cœur du Système)

**Fichier :** `resources/js/store/modules/kioskCart.js`

**État (state) :**
```javascript
state: {
    items: [],                    // Lignes panier
    orderRef: null,              // ID commande soumise
    queueNumber: null,           // Numéro file
    upsellShown: false,          // Flag upsell déjà vu
    loyaltyCustomer: null,       // { name, points, loyalty_code }
    loyaltyDiscount: 0,          // Remise appliquée
    branchId: null,              // Branche courante
    idempotencyKey: null,        // Clé anti-double
    kioskToken: null,            // Token machine (R9)
    kioskMachineId: null,        // ID machine (R9)
}
```

**Actions critiques :**
- `kioskLogin({ username, password })` → POST auth/kiosk-login → SET_KIOSK_TOKEN
- `kioskLogout()` → POST auth/kiosk-logout → CLEAR_KIOSK_TOKEN + RESET
- `submitOrder({ paymentMethod })` → POST frontend/order avec idempotence
- `popItem(index)` → Retire et retourne item pour édition (R9)
- `fetchOrderStatus(orderId)` → GET frontend/order/show/{id}
- `fetchUpsellItems()` → GET frontend/item/kiosk-upsell

**Persisté dans localStorage (vuex-persistedstate) :**
```javascript
paths: [
    "kioskCart.branchId",
    "kioskCart.orderRef",
    "kioskCart.queueNumber",
    "kioskCart.idempotencyKey",
    "kioskCart.items",
    "kioskCart.loyaltyDiscount",
    "kioskCart.loyaltyCustomer",
    "kioskCart.kioskToken",         // (R9)
    "kioskCart.kioskMachineId",     // (R9)
]
```

### D.2.2 Frontend - Router avec Guards

**Fichier :** `resources/js/router/modules/kioskRoutes.js`

**Guards :**
```javascript
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

**Routes :**
- `kiosk.login` (standalone, pas dans KioskAppComponent)
- `kiosk.idle` → requireKioskAuth
- `kiosk.categories` → requireKioskAuth
- `kiosk.products` → requireKioskAuth
- `kiosk.wizard` → requireKioskAuth
- `kiosk.cart` → requireKioskAuth
- `kiosk.loyalty` → requireKioskAuth + requireCart
- `kiosk.upsell` → requireKioskAuth + requireCart
- `kiosk.payment` → requireKioskAuth + requireCart
- `kiosk.waiting` → requireKioskAuth + requireOrderRef
- `kiosk.confirmation` → requireKioskAuth

### D.2.3 Frontend - Intercepteur 401 Global (R9)

**Fichier :** `resources/js/app.js`

**Code clé :**
```javascript
// Request interceptor (existant modifié R9)
const kioskToken = vuex.kioskCart?.kioskToken;
const userToken   = vuex.auth?.authToken;
const token       = kioskToken || userToken;  // Kiosk prioritaire

// Response interceptor (NOUVEAU R9)
let _401Handling = false;
axios.interceptors.response.use(
    response => response,
    error => {
        const status = error?.response?.status;
        if (status === 401 && !_401Handling) {
            _401Handling = true;
            setTimeout(() => { _401Handling = false; }, 3000);
            
            const path = window.location.pathname || '';
            if (path.startsWith('/kiosk')) {
                store.commit('kioskCart/CLEAR_KIOSK_TOKEN');
                router.push({ name: 'kiosk.login' });
            } else {
                store.dispatch('auth/logout');
                router.push({ name: 'auth.login' });
            }
        }
        return Promise.reject(error);
    },
);
```

### D.2.4 Backend - Auth Machine (R9)

**Fichier :** `app/Http/Controllers/Auth/KioskMachineLoginController.php`

**Méthode login modifiée (re-login permis) :**
```php
public function login(Request $request): JsonResponse
{
    // Validation username/password
    $kioskMachine = KioskMachine::where('username', $request->post('username'))->first();
    
    if (!$kioskMachine || !Hash::check($request->post('password'), $kioskMachine->password)) {
        return response(['errors' => ['validation' => 'Invalid']], 400);
    }
    
    DB::transaction(function () use ($kioskMachine) {
        $lockedKiosk = KioskMachine::lockForUpdate()->find($kioskMachine->id);
        $user = User::find($lockedKiosk->user_id);
        
        // Révoque anciens tokens (re-login permis)
        $user->tokens()->where('name', 'kiosk-token')->delete();
        
        $this->token = $user->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;
        $lockedKiosk->update(['is_login' => Ask::YES]);
    });
    
    return response([
        'token' => $this->token,
        'kiosk' => new KioskMachineResource($kioskMachine),
    ], 201);
}
```

### D.2.5 Backend - Création Commande Kiosk

**Fichier :** `app/Services/FrontendOrderService.php`

**Méthode createOrder (auto-accept) :**
```php
public function createOrder(array $data): FrontendOrder
{
    // Idempotence check
    if (!empty($data['idempotency_key'])) {
        $existing = FrontendOrder::where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) return $existing;
    }
    
    $order = new FrontendOrder();
    $order->user_id = Auth::id();  // Machine kiosk
    $order->order_type = OrderType::KIOSK;  // 25
    $order->source = Source::KIOSK;  // 5
    $order->idempotency_key = $data['idempotency_key'] ?? null;
    
    // Auto-accept pour kiosk
    $order->order_status = OrderStatus::ACCEPT;  // 4
    
    // Queue number atomique
    $lastQueue = FrontendOrder::where('branch_id', $data['branch_id'])
        ->where('order_type', OrderType::KIOSK)
        ->whereDate('created_at', today())
        ->max('queue_number') ?? 0;
    $order->queue_number = $lastQueue + 1;
    
    $order->save();
    
    // Event pour broadcast
    event(new OrderStatusChanged($order, OrderStatus::PENDING, OrderStatus::ACCEPT));
    
    return $order;
}
```

### D.2.6 Backend - Attribution Fidélité Kiosk

**Fichier :** `app/Listeners/AwardLoyaltyPointsOnDelivery.php`

**Modification R8 (PREPARED pour kiosk) :**
```php
public function handle(OrderStatusChanged $event): void
{
    $order = $event->order;
    $isKiosk = (int) ($order->order_type ?? 0) === OrderType::KIOSK;
    
    // Kiosk: PREPARED (8), Normal: DELIVERED (13)
    $triggerStatus = $isKiosk ? OrderStatus::PREPARED : OrderStatus::DELIVERED;
    
    if ($event->newStatus !== $triggerStatus) {
        return;
    }
    
    // Idempotence
    if (!empty($order->loyalty_points_awarded)) {
        return;
    }
    
    // Attribution atomique
    $rate = Settings::get('loyalty_points_per_euro', 10);
    $pointsToAward = floor($order->order_amount * $rate);
    
    User::where('id', $user->id)
        ->where('loyalty_code', $user->loyalty_code)
        ->increment('loyalty_points', $pointsToAward);
    
    $order->update(['loyalty_points_awarded' => $pointsToAward]);
}
```

### D.2.7 Admin - KDS Colonne Borne (R6)

**Fichier :** `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`

**Modification grille (4 colonnes) :**
```vue
<!-- Avant: 3 colonnes -->
<!-- Après: 4 colonnes avec Borne -->
<div class="xl:grid-cols-4">
    <!-- Colonne 1: Dine In -->
    <!-- Colonne 2: Take Away -->
    <!-- Colonne 3: Delivery -->
    <!-- Colonne 4: 🖥️ Borne -->
    <div>
        <h3>🖥️ Borne</h3>
        <div v-for="order in kioskOrders" :key="order.id">
            <div class="order-card">
                <span class="queue-number-large">{{ order.queue_number }}</span>
                <!-- items -->
            </div>
        </div>
    </div>
</div>
```

### D.2.8 Admin - POS Badge Kiosk Cash (R9)

**Fichier :** `resources/js/components/admin/pos/PosComponent.vue`

**Ajouts R9 :**
- Data: `kioskCashOrders`, `kioskCashLoading`, `showKioskCashPanel`, `_kioskPollTimer`
- Mounted: Polling 30s `loadKioskCashOrders()`
- FAB flottant: `v-if="kioskCashOrders.length > 0"` avec animation pulse
- Panel drawer: Liste commandes kiosk cash en attente
- Méthode: `loadKioskCashOrders()` → GET admin/kds-order + filtre client status [4,7]

## D.3 Modifications par Round

### D.3.1 Round 1-5 - Implémentation Initiale

- Création des 14 composants Vue kiosk
- Store kioskCart avec actions de base
- Routes kiosk avec guards
- Backend: auto-accept, queue number, fidélité check

### D.3.2 Round 6 - Bug Fixes (R20-R27)

**Fichiers modifiés :**
- `KDSOrderDetailsResource.php` → ajout queue_number
- `CDSOrderDetailsResource.php` → ajout queue_number
- `OrderStatusScreenOrderService.php` → include kiosk orders
- `KitchenDisplaySystemComponent.vue` → colonne Borne
- `PreparingAndReadyComponent.vue` → affichage N° queue
- `KioskConfirmationComponent.vue` → print ticket
- `KioskWaitingComponent.vue` → cancel avec error handling
- `FrontendOrderService.php` → event arity fix

### D.3.3 Round 7 - Robustesse (R28-R32)

**Fichiers modifiés :**
- `KioskAppComponent.vue` → loading overlay branche, retry
- `KioskLoyaltyComponent.vue` → check par téléphone
- `KioskCategoriesComponent.vue` → retry button
- `KioskProductListComponent.vue` → retry button
- `KioskWaitingComponent.vue` → network banner
- `AwardLoyaltyPointsOnDelivery.php` → trigger PREPARED pour kiosk

### D.3.4 Round 8 - Auth & UX (R33-R35)

**Fichiers créés :**
- `KioskLoginComponent.vue` (NOUVEAU)

**Fichiers modifiés :**
- `KioskMachineLoginController.php` → re-login permis
- `kioskCart.js` → kioskToken, kioskLogin, kioskLogout
- `store/index.js` → persist paths
- `app.js` → token kiosk prioritaire
- `kioskRoutes.js` → requireKioskAuth
- `KioskPaymentComponent.vue` → écran TPE 5s
- `KioskUpsellComponent.vue` → auto-skip 30s

### D.3.5 Round 9 - Finalisation (R36-R38)

**Fichiers modifiés :**
- `app.js` → intercepteur 401 global
- `KioskCartComponent.vue` → bouton edit article
- `kioskCart.js` → action popItem
- `PosComponent.vue` → badge + panel kiosk cash

## D.4 Variables et Constants Critiques

### D.4.1 Enums JavaScript

```javascript
// resources/js/enums/modules/orderTypeEnum.js
export const orderTypeEnum = {
    DINEIN: 5,
    DELIVERY: 10,
    TAKEAWAY: 15,
    POS: 20,
    KIOSK: 25,  // ← NOUVEAU
};

// resources/js/enums/modules/orderStatusEnum.js
export const orderStatusEnum = {
    PENDING: 1,
    CONFIRM: 2,
    PROCESSING: 3,
    ACCEPT: 4,      // ← Kiosk auto-accept
    PAID: 5,
    SHIPPED: 6,
    PREPARING: 7,   // ← Seuil annulation kiosk
    PREPARED: 8,    // ← Award loyalty kiosk
    DELIVERED: 13,  // ← Award loyalty normal
    CANCELED: 16,
    REJECTED: 21,
    RETURNED: 22,
    NOT_REFUND: 23,
    REFUNDED: 24,
};

// resources/js/enums/modules/paymentGatewayEnum.js
export const paymentGatewayEnum = {
    CASH_ON_DELIVERY: 1,  // Cash kiosk
    PAYPAL: 2,
    STRIPE: 3,
    CARD: 4,              // ← NOUVEAU
    TICKET_RESTAURANT: 5, // ← NOUVEAU
};

// resources/js/enums/modules/sourceEnum.js
export const sourceEnum = {
    WEB: 1,
    ANDROID: 2,
    IOS: 3,
    POS: 4,
    KIOSK: 5,  // ← NOUVEAU
};
```

### D.4.2 Constants PHP

```php
// app/Enums/OrderType.php
const KIOSK = 25;

// app/Enums/PaymentGateway.php
const CARD = 4;
const TICKET_RESTAURANT = 5;

// app/Enums/OrderStatus.php
const PENDING = 1;
const ACCEPT = 4;
const PREPARING = 7;
const PREPARED = 8;
const DELIVERED = 13;
const CANCELED = 16;
```

## D.5 Liste Complète Fichiers Créés/Modifiés

### Créés (from scratch)

```
resources/js/components/frontend/kiosk/KioskLoginComponent.vue              [R9]
resources/js/components/frontend/kiosk/KioskAppComponent.vue
resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue
resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue
resources/js/components/frontend/kiosk/KioskProductListComponent.vue
resources/js/components/frontend/kiosk/KioskWizardComponent.vue
resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue
resources/js/components/frontend/kiosk/KioskCartComponent.vue
resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue
resources/js/components/frontend/kiosk/KioskUpsellComponent.vue
resources/js/components/frontend/kiosk/KioskPaymentComponent.vue
resources/js/components/frontend/kiosk/KioskWaitingComponent.vue
resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue
resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue
resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue
resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue
resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue
resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue
resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue
resources/js/store/modules/kioskCart.js
resources/js/router/modules/kioskRoutes.js
```

### Modifiés (backend)

```
app/Http/Controllers/Auth/KioskMachineLoginController.php                     [R9]
app/Http/Controllers/Frontend/OrderController.php
app/Http/Controllers/Frontend/LoyaltyController.php                         [R7]
app/Http/Controllers/Frontend/ItemController.php
app/Http/Resources/KDSOrderDetailsResource.php                                [R6]
app/Http/Resources/CDSOrderDetailsResource.php                                [R6]
app/Listeners/AwardLoyaltyPointsOnDelivery.php                                [R8]
app/Services/FrontendOrderService.php
app/Services/OrderStatusScreenOrderService.php                              [R6]
app/Services/OrderService.php
app/Enums/OrderType.php
app/Enums/PaymentGateway.php                                                  [R6]
```

### Modifiés (frontend)

```
resources/js/app.js                                                           [R9]
resources/js/store/index.js                                                     [R9]
resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue [R6]
resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue [R6]
resources/js/components/admin/pos/PosComponent.vue                            [R9]
resources/js/config/env.js
```

---

**Suite :** Voir `05-ROADMAP.md` pour ce qui est déjà implémenté vs ce qui reste à faire.
