# C. Logique Métier - Business Rules

## C.1 Modèle de Données Commande

### C.1.1 FrontendOrder (Table: frontend_orders)

```php
// Champs critiques pour borne
- id: bigint
- user_id: bigint FK → users (machine kiosk, pas client réel)
- order_serial_no: string (ex: "ORD-20240325-001")
- queue_number: int (numéro de file atomique, ex: 847)
- order_type: tinyint → OrderType::KIOSK = 25
- order_status: tinyint → 1=PENDING, 4=ACCEPT, 7=PREPARING, 8=PREPARED, 13=DELIVERED, 16=CANCELED
- payment_status: tinyint → 1=UNPAID, 4=PAID
- payment_method: tinyint → 1=CASH, 4=CARD, 5=TICKET_RESTAURANT
- transaction_id: string (depuis TPE, nullable)
- order_amount: decimal (total final)
- total: decimal (total avant remises)
- source: tinyint → 5 = KIOSK_SOURCE
- branch_id: bigint FK → branches
- idempotency_key: string unique (anti-double)
- loyalty_code: string (code client fidélité, nullable)
- loyalty_points_awarded: int (points déjà attribués, nullable)
- created_at, updated_at
```

### C.1.2 FrontendOrderItem (Lignes commande)

```php
- id: bigint
- frontend_order_id: bigint FK
- item_id: bigint FK → items
- item_name: string (denormalisé)
- quantity: int
- convert_price: decimal (prix unitaire)
- item_total: decimal (prix × qty)
- item_variation_total: decimal (suppléments variations)
- item_extra_total: decimal (suppléments payants)
- item_discount: decimal (remise ligne)
- item_variations: json [{id, name}]
- item_extras: json [{id, quantity}]
- instruction: text (personnalisation texte)
```

## C.2 Machine à États Commande

### C.2.1 Flow Kiosk (Différent du Normal)

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   PENDING   │────→│   ACCEPT    │────→│  PREPARING  │
│     (1)     │     │     (4)     │     │     (7)     │
└─────────────┘     └─────────────┘     └─────────────┘
                           ↑                   │
                    (auto après création)       │
                                              ▼
                                      ┌─────────────┐
                                      │  PREPARED   │←── Award Loyalty
                                      │     (8)     │    (borne uniquement)
                                      └─────────────┘
                                              │
                                              ▼
                                      ┌─────────────┐
                                      │  DELIVERED  │←── Award Loyalty
                                      │    (13)     │    (commandes normales)
                                      └─────────────┘
                                              │
                                              ▼
                                      ┌─────────────┐
                                      │   CANCELED   │
                                      │    (16)      │
                                      └─────────────┘
```

### C.2.2 Différences Kiosk vs Normal

| Aspect | Kiosk | Commande Normale |
|--------|-------|------------------|
| Auto-accept | ✅ Oui (création → ACCEPT) | ❌ Non (reste PENDING) |
| Seuil annulation | PREPARING (7) | ACCEPT (4) |
| Award fidélité | PREPARED (8) | DELIVERED (13) |
| Queue number | ✅ Oui | ❌ Non (token) |
| Source | 5 (KIOSK) | 1-4 (WEB/APP/POS) |

### C.2.3 Seuils d'Annulation (Round 6)

```php
// FrontendOrderService::changeStatus()
$isKiosk = $order->order_type === OrderType::KIOSK;

if ($isKiosk) {
    // Kiosk: peut annuler jusqu'à ce que cuisine commence
    $threshold = OrderStatus::PREPARING;  // 7
} else {
    // Normal: peut annuler jusqu'à acceptation
    $threshold = OrderStatus::ACCEPT;  // 4
}

if ($order->order_status > $threshold) {
    throw new \Exception('Cannot cancel: order already in progress');
}
```

## C.3 Système de Numérotation (Queue Number)

### C.3.1 Génération Atomique

```php
// À la création commande kiosk
DB::transaction(function () {
    $lastQueue = FrontendOrder::where('branch_id', $branchId)
        ->where('order_type', OrderType::KIOSK)
        ->whereDate('created_at', today())
        ->max('queue_number') ?? 0;
    
    $order->queue_number = $lastQueue + 1;
    $order->save();
});
```

### C.3.2 Utilisation Queue Number

| Écran/Outil | Affichage |
|-------------|-----------|
| Kiosk Waiting | **N° 847** (gros, visible loin) |
| KDS Colonne Borne | **847** en rouge |
| OSS (écran client) | **N°847** rouge (prep) → vert pulsant (prêt) |
| Ticket impression | **847** (48px) |
| Confirmation | **847** |

## C.4 Pricing & Calculs

### C.4.1 Calcul Panier Côté Client (Référence)

```javascript
// kioskCart.js - getters
subtotal: (state) => {
    return state.items.reduce((sum, i) => {
        const base = parseFloat(i.convert_price) || 0;
        const varExtra = parseFloat(i.item_variation_total) || 0;
        const extras = parseFloat(i.item_extra_total) || 0;
        return sum + (base + varExtra + extras) * i.quantity;
    }, 0);
},

total: (state, getters) => Math.max(0, getters.subtotal - state.loyaltyDiscount);
```

### C.4.2 Calcul Côté Serveur (Source de Vérité)

```php
// FrontendOrderService::calculateTotals()
$subtotal = 0;
$itemTotals = [];

foreach ($items as $item) {
    $basePrice = $item['convert_price'];
    $variationTotal = 0;
    $extrasTotal = 0;
    
    // Variations (pain, boisson menu)
    foreach ($item['item_variations'] ?? [] as $variation) {
        $variationModel = ItemVariation::find($variation['id']);
        if ($variationModel && $variationModel->extra_price) {
            $variationTotal += $variationModel->extra_price;
        }
    }
    
    // Extras payants (suppléments)
    foreach ($item['item_extras'] ?? [] as $extra) {
        $extraModel = ItemExtra::find($extra['id']);
        if ($extraModel) {
            $extrasTotal += $extraModel->price * ($extra['quantity'] ?? 1);
        }
    }
    
    $lineTotal = ($basePrice + $variationTotal + $extrasTotal) * $item['quantity'];
    $itemTotals[] = [
        'item_total' => $lineTotal,
        'item_variation_total' => $variationTotal * $item['quantity'],
        'item_extra_total' => $extrasTotal * $item['quantity'],
    ];
    
    $subtotal += $lineTotal;
}

// Remises
$loyaltyDiscount = $this->calculateLoyaltyDiscount($loyaltyCode, $subtotal);
$orderAmount = max(0, $subtotal - $loyaltyDiscount);
```

### C.4.3 Règles Remises Fidélité

```php
// Configurable via Settings
// loyalty_points_per_euro = 10 (défaut)
// loyalty_discount_per_100_points = 1€

// Conversion points → remise
function pointsToDiscount(int $points): float {
    return floor($points / 100);  // 250 pts = 2€
}

// Maximum remise = total commande (pas de crédit négatif)
$maxDiscount = min($pointsToDiscount($user->loyalty_points), $subtotal);
```

## C.5 Système de Fidélité

### C.5.1 Attribution Points

```php
// AwardLoyaltyPointsOnDelivery (Round 8 fix)
public function handle(OrderStatusChanged $event): void
{
    $order = $event->order;
    $isKiosk = $order->order_type === OrderType::KIOSK;
    
    // Kiosk: trigger à PREPARED (8)
    // Normal: trigger à DELIVERED (13)
    $triggerStatus = $isKiosk ? OrderStatus::PREPARED : OrderStatus::DELIVERED;
    
    if ($event->newStatus !== $triggerStatus) {
        return;
    }
    
    // Idempotence
    if (!empty($order->loyalty_points_awarded)) {
        return;
    }
    
    $user = User::find($order->user_id);
    if (!$user || !$user->loyalty_code) {
        return;
    }
    
    $rate = Settings::get('loyalty_points_per_euro', 10);
    $pointsToAward = floor($order->order_amount * $rate);
    
    if ($pointsToAward <= 0) return;
    
    // Incrément atomique SQL
    User::where('id', $user->id)
        ->where('loyalty_code', $user->loyalty_code)
        ->increment('loyalty_points', $pointsToAward);
    
    $order->update(['loyalty_points_awarded' => $pointsToAward]);
}
```

### C.5.2 Check Client Fidélité (Round 7)

```php
// LoyaltyController::check()
public function check(Request $request)
{
    $input = trim($request->input('code'));
    
    // 1. Cherche par code fidélité
    $user = User::where('loyalty_code', $input)->first();
    
    // 2. Sinon cherche par téléphone (normalisé)
    if (!$user) {
        $phone = preg_replace('/[\s\-]/', '', $input);
        $user = User::where('phone', $phone)->first();
    }
    
    if (!$user) {
        return response(['status' => false], 404);
    }
    
    // 3. Génère code si user a téléphone mais pas de code
    if (!$user->loyalty_code) {
        $user->loyalty_code = strtoupper(substr(md5(uniqid()), 0, 8));
        $user->save();
    }
    
    return response([
        'status' => true,
        'data' => [
            'name' => $user->name,
            'points' => $user->loyalty_points,
            'discount_value' => $this->pointsToDiscount($user->loyalty_points),
            'loyalty_code' => $user->loyalty_code,  // Pour que client le note
        ]
    ]);
}
```

### C.5.3 Inscription Nouveau Client (Round 6)

```php
// LoyaltyController::register()
public function register(Request $request)
{
    $validated = $request->validate([
        'phone' => 'required|string|min:8|max:20|unique:users',
        'name' => 'nullable|string|min:2',
        'email' => 'nullable|email',
    ]);
    
    $user = new User();
    $user->name = $validated['name'] ?? 'Client';
    $user->phone = $validated['phone'];
    $user->email = $validated['email'] ?? null;
    $user->loyalty_code = strtoupper(substr(md5(uniqid()), 0, 8));
    $user->password = bcrypt(uniqid());  // Random password
    $user->status = 1;
    $user->save();
    
    return response(['status' => true, 'loyalty_code' => $user->loyalty_code]);
}
```

## C.6 Système Multi-Branch

### C.6.1 Sélection Branche

```javascript
// KioskAppComponent.mounted()
async loadBranch() {
    this.branchLoading = true;
    try {
        const res = await this.loadBranchList({ vuex: false });
        const branch = res?.data?.data?.[0];  // Prend première branche
        if (branch?.id) {
            this.setBranch(branch.id);
        } else {
            this.branchError = 'Aucune branche disponible';
        }
    } catch (err) {
        this.branchError = err.response?.status === 401 
            ? 'Session expirée' 
            : 'Connexion impossible';
    } finally {
        this.branchLoading = false;
    }
}
```

**IMPORTANT :** Actuellement hardcodé sur première branche. Futur: sélection branche au login borne.

### C.6.2 Broadcast par Branche

```javascript
// KDS, OSS, POS écoutent tous le même channel
window.Echo.private(`branch.${branchId}`)
    .listen('.OrderStatusChanged', handler)
    .listen('.OrderCreated', handler);
```

## C.7 Idempotence

### C.7.1 Génération Clé

```javascript
// kioskCart.js - submitOrder()
if (!state.idempotencyKey) {
    state.idempotencyKey = generateUUID();  // Une seule fois par tentative
}
```

### C.7.2 Vérification Backend

```php
// FrontendOrderService::submitOrder()
$existing = FrontendOrder::where('idempotency_key', $idempotencyKey)->first();
if ($existing) {
    return $existing;  // Déjà traité, retourne existant
}

// Création nouvelle commande
$order = new FrontendOrder();
$order->idempotency_key = $idempotencyKey;
$order->save();
```

## C.8 Types de Paiement

### C.8.1 Mapping Frontend → Backend

```javascript
// kioskCart.js - PAYMENT_METHOD_MAP
const PAYMENT_METHOD_MAP = {
    cash: 1,   // PaymentGateway::CASH_ON_DELIVERY
    card: 4,   // PaymentGateway::CARD (NOUVEAU)
    tr: 5,     // PaymentGateway::TICKET_RESTAURANT (NOUVEAU)
};

// Envoyé dans submitOrder payload
payment_method: PAYMENT_METHOD_MAP[paymentMethod]
```

### C.8.2 Confirmation TPE (Webhook)

```php
// OrderController::paymentConfirm()
public function paymentConfirm(FrontendOrder $frontendOrder, Request $request)
{
    // Vérification ownership
    if ($frontendOrder->user_id !== Auth::id()) {
        return response(['status' => false], 403);
    }
    
    // Idempotence
    if ($frontendOrder->payment_status === PaymentStatus::PAID) {
        return response(['status' => true, 'message' => 'Déjà payé']);
    }
    
    $frontendOrder->update([
        'payment_status' => PaymentStatus::PAID,
        'payment_method' => $request->payment_method ?? $frontendOrder->payment_method,
        'transaction_id' => $request->transaction_id,  // Depuis TPE
        'card_type' => $request->card_type,
    ]);
    
    return response(['status' => true]);
}
```

**Note :** Appelé par le terminal TPE (pas par la SPA directement).

---

**Suite :** Voir `04-FILES-REFERENCE.md` pour la liste exhaustive de tous les fichiers créés/modifiés avec leur contenu clé.
