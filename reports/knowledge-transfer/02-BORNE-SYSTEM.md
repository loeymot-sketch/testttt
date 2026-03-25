# B. Système Borne - Documentation Ultra-Détaillée

## B.1 Vue d'Ensemble des 12 Écrans

| # | Écran | Route | Fonction Principale | État Persisté |
|---|-------|-------|---------------------|---------------|
| 1 | **Login Machine** | `/kiosk/login` | Auth borne (user/pass) → token | `kioskToken`, `kioskMachineId` |
| 2 | **Idle** | `/kiosk/idle` | Accueil avec vidéo/anim + CTA | - (cart reset) |
| 3 | **Categories** | `/kiosk/categories` | Grille catégories produits | - |
| 4 | **Products** | `/kiosk/products/:categoryId` | Liste produits paginée | - |
| 5 | **Wizard** | `/kiosk/wizard/:itemId` | Personnalisation produit | `selections` (temp) |
| 6 | **Cart** | `/kiosk/cart` | Panier + édition | `items`, `loyaltyDiscount` |
| 7 | **Loyalty** | `/kiosk/loyalty` | Fidélité check/remise | `loyaltyCustomer` |
| 8 | **Upsell** | `/kiosk/upsell` | Max-selling dessert/boisson | `upsellShown` flag |
| 9 | **Payment** | `/kiosk/payment` | Choix paiement + TPE | - |
| 10 | **Waiting** | `/kiosk/waiting/:orderId` | Attente préparation | `orderRef`, `queueNumber` |
| 11 | **Confirmation** | `/kiosk/confirmation` | Ticket final + print | - |

## B.2 Idle Screen - Premier Contact Client

**Objectif UX :** Attirer l'attention, créer envie, invitation claire à interagir.

### B.2.1 Design

```
┌─────────────────────────────────────────┐
│  [Vidéo en loop ou gradient animé]     │
│                                         │
│         [Logo Restaurant]              │
│                                         │
│    ┌─────────────────────────┐        │
│    │   ┌───┐   ┌───┐   ┌───┐ │        │
│    │   │ ○ │ → │ ○ │ → │ ○ │ │        │ ← 3 anneaux pulsants
│    │   └───┘   └───┘   └───┘ │        │
│    │                           │        │
│    │      ☝️ Toucher          │        │
│    │   "Toucher l'écran       │        │
│    │    pour commander"       │        │
│    └─────────────────────────┘        │
│                                         │
│        [Panier: 0 | Total: 0€]         │ ← Hidden sur idle
└─────────────────────────────────────────┘
```

### B.2.2 Logique

```javascript
// KioskIdleScreenComponent.vue - mounted()
mounted() {
    this.loadSettings();  // Charge company_name, logo, kiosk_idle_video
    this.$store.dispatch('kioskCart/reset');  // CRITIQUE: reset panier
}

// Data dynamique
restaurantName: settings.company_name || settings.site_name
restaurantLogo: settings.logo_full_path || settings.logo
videoSrc: settings.kiosk_idle_video || null

// Fallback si pas de vidéo: CSS gradient animé
background: linear-gradient(160deg, #0f0f1a 0%, #1a1a2e 100%)
animation: gradientShift 15s ease infinite
```

### B.2.3 Interaction

```javascript
// Toute interaction (click/touch/key) → startOrder()
startOrder() {
    this.$emit('start-order');  // Capturé par KioskAppComponent
    // → resetIdleTimer() + navigate to kiosk.categories
}
```

## B.3 Categories - Navigation Visuelle

### B.3.1 Layout

```
┌─────────────────────────────────────────┐
│  [←]  Choisissez une catégorie         │
│                                         │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐   │
│  │   🌮    │ │   🍔    │ │   🥪    │   │
│  │  Tacos  │ │ Burgers │ │Sandwichs│   │
│  └─────────┘ └─────────┘ └─────────┘   │
│                                         │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐   │
│  │   🍟    │ │   🥤    │ │   🍰    │   │
│  │  Frites │ │Boissons │ │ Desserts│   │
│  └─────────┘ └─────────┘ └─────────┘   │
│                                         │
│  [Spinner si loading...]                 │
│  [📡 Erreur + Réessayer si échec]       │ ← Retry button (Round 7)
└─────────────────────────────────────────┘
```

### B.3.2 États de Chargement

```javascript
data() {
    return {
        categories: [],
        loading: true,
        loadError: false,  // Round 7
    }
}

// Succès
categories = res.data.data  // API retourne uniquement actives

// Erreur réseau
catch (_) {
    categories = [];
    loadError = true;  // Affiche bouton Réessayer
}
```

## B.4 Product List - Navigation Produits

### B.4.1 Layout

```
┌─────────────────────────────────────────┐
│  [←]  🌮 Tacos              [🛒 3]     │ ← Panier flottant si items
│                                         │
│  ┌─────────────────────────────────┐    │
│  │ [IMG] Tacos Poulet    8.50€   → │    │
│  └─────────────────────────────────┘    │
│  ┌─────────────────────────────────┐    │
│  │ [IMG] Tacos Bœuf      9.50€   → │    │
│  └─────────────────────────────────┘    │
│                                         │
│  [     Voir plus de produits      ]     │ ← Pagination client 20 items
│                                         │
│  [Spinner si chargement...]             │
└─────────────────────────────────────────┘
```

### B.4.2 Pagination

```javascript
// Chargement par tranche de 20
loadProducts(append = false) {
    if (!append) {
        this.page = 1;
        this.products = [];
    }
    
    dispatch('frontendItem/lists', {
        item_category_id: this.categoryId,
        paginate: 20,
        page: this.page,
    });
    
    // Détection fin de liste
    this.hasMore = (this.page < meta.last_page) || 
                    (newItems.length === 20);
}

// "Voir plus" → loadProducts(true)  // append
```

## B.5 Wizard - Cœur du Système de Personnalisation

### B.5.1 Architecture Wizard

```
┌─────────────────────────────────────────────────────────────┐
│  [← Retour]  Étape X/Y                          [Suivant →] │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│                    [CONTENU ÉTAPE]                         │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│  Récap: [Item] - [Options sélectionnées]         Total: X€  │
│  [−] Quantité: 2 [+]                        [Ajouter]       │
└─────────────────────────────────────────────────────────────┘
```

### B.5.2 Steps Dynamiques par Type Produit

```javascript
// buildActiveSteps() - logique de génération des étapes
switch(item.type_key) {
    case 'tacos':
    case 'sandwich':
    case 'burger':
    case 'assiette':
        return [
            { type: 'pain', component: 'KioskStepPainComponent' },
            { type: 'viande', component: 'KioskStepViandeComponent' },
            { type: 'sauce', component: 'KioskStepSauceComponent' },
            { type: 'garnitures', component: 'KioskStepGarnituresComponent' },
            { type: 'supplements', component: 'KioskStepSupplementsComponent' },
            { type: 'menu', component: 'KioskStepMenuComponent' },
            { type: 'recap', component: 'KioskOrderSummaryComponent' },
        ];
    
    default:
        return [
            { type: 'recap', component: 'KioskOrderSummaryComponent' }
        ];
}
```

### B.5.3 Étape Pain

**UX :** Sélection visuelle avec fallback si API vide.

```javascript
// Si pas de pains API → fallback par défaut
getDefaultPainList() {
    return [
        { id: null, name: 'Pain classique', key: 'classique' },
        { id: null, name: 'Pain complet', key: 'complet' },
        { id: null, name: 'Pain pita', key: 'pita' },
    ];
}

// Sélection
selectPain(pain) {
    this.selectedPain = pain;
    // Émet avec métadonnées pour buildCartItem
    this.$emit('update', {
        pain: pain.id || pain.key,
        _painMeta: { realId: pain.id, attrId: pain.attribute_value_id, name: pain.name }
    });
}
```

### B.5.4 Étape Viande

**UX :** Sélection multi-viandes avec quantités.

```javascript
// Structure
viandes: [
    { id: 1, name: 'Poulet', key: 'poulet', count: 0 },
    { id: 2, name: 'Bœuf', key: 'boeuf', count: 0 },
    { id: 3, name: 'Merguez', key: 'merguez', count: 0 },
]

// Incrémentation
toggleViande(viande) {
    if (this.maxReached && viande.count === 0) return;
    viande.count = (viande.count + 1) % 4;  // 0→1→2→3→0
    this.emitUpdate();
}

// Émission métadonnées complètes
emitUpdate() {
    const selected = this.viandes.filter(v => v.count > 0);
    this.$emit('update', {
        viandes: selected.map(v => v.id || v.key),
        _viandeMeta: selected.map(v => ({
            id: v.id, key: v.key, name: v.name, count: v.count
        }))
    });
}
```

### B.5.5 Étape Sauce

**UX :** Multi-sélection avec toggle.

```javascript
// Fallback si API vide
getDefaultSauceList() {
    return [
        { id: null, name: 'Mayonnaise', key: 'mayo' },
        { id: null, name: 'Ketchup', key: 'ketchup' },
        { id: null, name: 'Harissa', key: 'harissa' },
        { id: null, name: 'Algérienne', key: 'algerienne' },
        { id: null, name: 'Blanche', key: 'blanche' },
        { id: null, name: 'Samouraï', key: 'samourai' },
    ];
}

// Toggle avec clé stable
toggleSauce(sauce) {
    const key = this.sauceKey(sauce);  // id ou name
    const idx = this.localSelections.indexOf(key);
    if (idx >= 0) {
        this.localSelections.splice(idx, 1);
        this.sauceOrder.splice(this.sauceOrder.indexOf(key), 1);
    } else {
        this.localSelections.push(key);
        this.sauceOrder.push(key);
    }
    this.emitUpdate();
}
```

### B.5.6 Étape Menu (Boisson)

**UX :** Choix type menu puis sélection boisson.

```javascript
// Types menu
menuTypes: [
    { value: 'seul', label: 'Seul', price: 0 },
    { value: 'frites', label: '+ Frites', price: 2 },
    { value: 'boisson', label: '+ Boisson', price: 3 },
]

// Sélection boisson (uniquement si type=boisson)
boissonList() {
    if (this.selections.menuType !== 'boisson') return [];
    return this.item.extras?.filter(e => e.category === 'boisson') || [];
}

selectBoisson(boisson) {
    this.$emit('update', {
        boisson: boisson.id,
        _boissonMeta: { boissonId: boisson.id, boissonName: boisson.name }
    });
}
```

### B.5.7 Récap avec Quantité

```
┌─────────────────────────────────────────┐
│  Votre commande                         │
│                                         │
│  Tacos Poulet                           │
│  • Pain: Classique                      │
│  • Viande: Poulet x2                    │
│  • Sauces: Harissa, Mayo                │
│  • Garnitures: Salade, Tomate, Oignons│
│  • Supplément: + Fromage                │
│  • Menu: + Boisson (Coca)               │
│                                         │
│  [−]  Quantité: 2  [+]                 │
│                                         │
│                        Total: 21.00€   │
│                                         │
│            [ AJOUTER AU PANIER ]        │
└─────────────────────────────────────────┘
```

### B.5.8 Build Cart Item (Structure Finale)

```javascript
buildCartItem() {
    const variations = [];
    const extras = [];
    const instructions = [];
    
    // Pain → variation (id réel si existe)
    if (this.selections._painMeta?.realId) {
        variations.push({
            id: this.selections._painMeta.realId,
            name: this.selections._painMeta.name
        });
    }
    
    // Viande → instruction texte + première variation si id réel
    if (this.selections._viandeMeta?.length) {
        const viandeText = this.selections._viandeMeta
            .map(v => `${v.name}${v.count > 1 ? ` x${v.count}` : ''}`)
            .join(', ');
        instructions.push(`Viande: ${viandeText}`);
        
        // Première viande avec ID réel → variation
        const firstReal = this.selections._viandeMeta.find(v => v.id);
        if (firstReal) {
            variations.push({ id: firstReal.id, name: firstReal.name });
        }
    }
    
    // Sauces → instruction
    if (this.selections.sauces?.length) {
        instructions.push(`Sauces: ${this.selections._sauceNames.join(', ')}`);
    }
    
    // Garnitures → instruction
    if (this.selections.garnitures?.length) {
        instructions.push(`Garnitures: ${this.selections._garnitureNames.join(', ')}`);
    }
    
    // Suppléments payants → extras
    if (this.selections.supplements?.length) {
        this.selections.supplements.forEach(sup => {
            extras.push({ id: sup.id, quantity: sup.quantity || 1 });
        });
    }
    
    // Menu boisson → variation + instruction
    if (this.selections._boissonMeta?.boissonId) {
        variations.push({
            id: this.selections._boissonMeta.boissonId,
            name: this.selections._boissonMeta.boissonName
        });
        instructions.push(`Boisson: ${this.selections._boissonMeta.boissonName}`);
    }
    
    return {
        item_id: this.item.id,
        name: this.item.name,
        quantity: this.selections.quantity || 1,
        convert_price: parseFloat(this.item.convert_price),
        image: this.item.thumb || this.item.image,
        item_variations: variations,
        item_extras: extras,
        instruction: instructions.join(' | '),
        item_variation_total: 0,  // Calculé côté serveur
        item_extra_total: 0,
        discount: 0,
    };
}
```

## B.6 Cart - Panier avec Édition

### B.6.1 Layout

```
┌─────────────────────────────────────────┐
│  [←]  Votre panier             [Vider]  │
│                                         │
│  ┌───────────────────────────────────┐ │
│  │ [IMG] Tacos Poulet           ✏️   │ │ ← Bouton edit
│  │     Viande: Poulet x2 | Sauces:..│ │
│  │   8.50€/u    [−] 2 [+]    17.00€│ │
│  └───────────────────────────────────┘ │
│  ┌───────────────────────────────────┐ │
│  │ [IMG] Burger Double           ✏️ │ │
│  │     + Fromage                   │ │
│  │  12.00€/u   [−] 1 [+]    12.00€│ │
│  └───────────────────────────────────┘ │
│                                         │
│  ─────────────────────────────────────  │
│  Sous-total                        29€  │
│  🎁 Réduction fidélité           -3€  │
│  ─────────────────────────────────────  │
│  TOTAL                           26€  │
│                                         │
│      [ CONTINUER → ]                    │
└─────────────────────────────────────────┘
```

### B.6.2 Édition Article (Round 9)

```javascript
// Clic sur ✏️ → editItem(index)
editItem(index) {
    const item = this.popItem(index);  // Retire du panier
    if (!item?.item_id) return;
    
    // Ouvre wizard pour même produit
    this.$router.push({
        name: 'kiosk.wizard',
        params: { itemId: String(item.item_id) }
    });
    // Client re-personnalise et ré-ajoute
}

// Note: La quantité n'est pas pré-remplie (simplification UX)
```

### B.6.3 Merge Articles Identiques

```javascript
// Store: ADD_ITEM mutation
ADD_ITEM(state, item) {
    const existing = state.items.findIndex(i =>
        i.item_id === item.item_id &&
        JSON.stringify(i.item_variations) === JSON.stringify(item.item_variations) &&
        JSON.stringify(i.item_extras) === JSON.stringify(item.item_extras)
    );
    
    if (existing >= 0) {
        // Même personnalisation → incrémente quantité
        state.items[existing].quantity += item.quantity || 1;
    } else {
        // Nouvelle ligne
        state.items.push({ ...item, quantity: item.quantity || 1 });
    }
}
```

## B.7 Upsell - Max-Selling (Round 8)

### B.7.1 Layout

```
┌─────────────────────────────────────────┐
│  Et pour terminer ?                     │
│  Ajoutez quelque chose à votre commande │
│                                         │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐   │
│  │ 🍰      │ │ 🥤      │ │ 🍦      │   │
│  │Tiramisu │ │ Coca L  │ │ Glace   │   │
│  │  4.50€  │ │  3.00€  │ │  3.50€  │   │
│  │    ✓    │ │         │ │    ✓    │   │
│  └─────────┘ └─────────┘ └─────────┘   │
│                                         │
│  [  Ajouter (2) et continuer  +8.50€ ]  │
│                                         │
│  [  Non merci, continuer sans   30s ]   │ ← Auto-skip countdown
│  [===========barre===========]          │ ← Progress bar
└─────────────────────────────────────────┘
```

### B.7.2 Auto-Skip (Round 8)

```javascript
const AUTO_SKIP_SECONDS = 30;

mounted() {
    this.loadSuggestions();
}

startAutoSkip() {
    this.autoSkipRemaining = AUTO_SKIP_SECONDS;
    this._autoSkipTimer = setInterval(() => {
        this.autoSkipRemaining--;
        if (this.autoSkipRemaining <= 0) {
            this.skip();  // → payment
        }
    }, 1000);
}

// Reset sur interaction
toggleItem(item) {
    this.clearAutoSkip();
    // ... toggle logic
    this.startAutoSkip();
}
```

### B.7.3 Sélection Upsell

```javascript
// Pas de wizard pour upsell (trop long)
// Items ajoutés directement tels quels
addAndContinue() {
    this.selectedItems.forEach(item => {
        this.addItem({
            item_id: item.id,
            name: item.name,
            quantity: 1,
            convert_price: item.convert_price,
            item_variations: [],
            item_extras: [],
            instruction: '',
            discount: 0,
            item_variation_total: 0,
            item_extra_total: 0,
        });
    });
    this.$router.push({ name: 'kiosk.payment' });
}
```

## B.8 Payment - Paiement avec TPE

### B.8.1 Layout

```
┌─────────────────────────────────────────┐
│  [←]  Choisissez votre paiement         │
│       Total à régler: **26.00€**         │
│                                         │
│  ┌───────────────────────────────────┐  │
│  │  💳      Carte bancaire     │   ✓ │  │ ← Sélection
│  │         Visa · Mastercard · CB     │  │
│  └───────────────────────────────────┘  │
│                                         │
│  ┌───────────────────────────────────┐  │
│  │  💵      Espèces                 │  │
│  │         Paiement à la caisse       │  │
│  └───────────────────────────────────┘  │
│                                         │
│  ┌───────────────────────────────────┐  │
│  │  🎫      Ticket Restaurant       │  │
│  │         Edenred · Swile · Sodexo │  │
│  └───────────────────────────────────┘  │
│                                         │
│  [ Confirmer — 26.00€ → ]               │
└─────────────────────────────────────────┘
```

### B.8.2 Écran TPE (Carte/TR) - Round 8

```
┌─────────────────────────────────────────┐
│                                         │
│       ○  ○  ○  (anneaux pulsants)       │
│                                         │
│          💳  (icone carte)              │
│                                         │
│    Insérez votre carte sur le terminal  │
│    Suivez les instructions sur le TPE   │
│                                         │
│  [===========PROGRESS===========]       │
│                                         │
│    Redirection automatique dans 5s      │
│                                         │
└─────────────────────────────────────────┘
```

### B.8.3 Logique Paiement

```javascript
const TPE_COUNTDOWN_SECONDS = 5;

async confirmPayment() {
    this.submitting = true;
    
    const res = await this.submitOrder({ paymentMethod: this.method });
    const orderId = res.data.data.id;
    const queueNum = res.data.data.queue_number;
    
    if (this.method === 'card' || this.method === 'tr') {
        // Montre écran TPE
        this.startTpeCountdown();
        // Après 5s → navigate waiting
    } else {
        // Cash → direct waiting
        this.$router.push({
            name: 'kiosk.waiting',
            params: { orderId: String(orderId) },
            query: { queue: queueNum, total }
        });
    }
}

startTpeCountdown() {
    this.tpeWaiting = true;
    this.tpeProgressPct = 0;
    const step = 100 / (TPE_COUNTDOWN_SECONDS * 10);
    
    this._tpeTimer = setInterval(() => {
        this.tpeProgressPct += step;
        if (this.tpeProgressPct >= 100) {
            clearInterval(this._tpeTimer);
            this.$router.push(this._pendingNav);
        }
    }, 100);
}
```

## B.9 Waiting - Attente Cuisine

### B.9.1 Layout (Préparation)

```
┌─────────────────────────────────────────┐
│  📡 Connexion perdue - Reconnexion...   │ ← Banner si 3 échecs
├─────────────────────────────────────────┤
│                                         │
│              👨‍🍳                       │
│         ○  ○  ○                        │
│         (vagues animation)              │
│                                         │
│     Votre commande est en préparation   │
│                                         │
│         ┌─────────────┐                │
│         │   NUMÉRO    │                │
│         │    847      │                │ ← Gros, visible loin
│         └─────────────┘                │
│                                         │
│   Présentez-vous au comptoir quand       │
│   votre numéro est appelé              │
│                                         │
│   [=================>]                  │ ← Progress indéterminé
│                                         │
│   [Annuler ma commande] (après 30s)    │ ← Bouton apparait
│                                         │
└─────────────────────────────────────────┘
```

### B.9.2 Layout (Prêt)

```
┌─────────────────────────────────────────┐
│                                         │
│            ✓                            │
│         ◎  ◎                           │
│       (double anneau vert)              │
│                                         │
│      Votre commande est prête !         │
│                                         │
│         ┌─────────────┐                │
│         │    847      │                │
│         └─────────────┘                │
│                                         │
│   Venez récupérer votre commande       │
│   au comptoir 🙌                        │
│                                         │
│   [ Nouvelle commande ]                 │
│        (auto dans 20s)                  │
│                                         │
└─────────────────────────────────────────┘
```

### B.9.3 Polling + Network Resilience (Round 7)

```javascript
const POLL_INTERVAL_MS = 5000;
const STATUS_PREPARED = 8;
const STATUS_DELIVERED = 13;

startPolling() {
    this._doPoll();
    this.pollTimer = setInterval(() => this._doPoll(), POLL_INTERVAL_MS);
}

async _doPoll() {
    if (this.isReady) return;
    
    try {
        const res = await this.fetchOrderStatus(this.orderId);
        const numericStatus = parseInt(res.data.data.status, 10);
        
        if (numericStatus === STATUS_PREPARED || numericStatus === STATUS_DELIVERED) {
            this.markReady();
        }
        
        // Reset compteur erreurs
        this.pollFailCount = 0;
        this.networkLost = false;
        
    } catch (_) {
        this.pollFailCount++;
        if (this.pollFailCount >= 3) {
            this.networkLost = true;  // Banner rouge
        }
    }
}
```

### B.9.4 Annulation (Round 6)

```javascript
// Visible après 30s (pas immédiatement pour éviter miss-clicks)
showCancelButton: false → true après 30s

async cancelOrder() {
    this.cancelLoading = true;
    try {
        await this.changeOrderStatus({
            id: this.orderId,
            status: 16  // CANCELED
        });
        this.$router.push({ name: 'kiosk.idle' });
    } catch (err) {
        // Erreur affichée dans modal (ex: déjà en préparation)
        this.cancelError = err.response?.data?.message || 'Impossible d\'annuler';
    }
}

// Backend: OrderStatusRequest@authorize()
// Vérifie: tokenCan('kiosk:order') && order.user_id === Auth::id()
// Seuil: PREPARING (7) pour kiosk (pas ACCEPT comme normal)
```

## B.10 Confirmation + Print

### B.10.1 Layout

```
┌─────────────────────────────────────────┐
│                                         │
│              ✓✓✓                       │
│                                         │
│         Merci de votre visite !         │
│                                         │
│         ┌─────────────┐                │
│         │   847       │                │
│         └─────────────┘                │
│                                         │
│         Total payé: 26.00€             │
│                                         │
│   [ 📃 Imprimer le ticket ]             │
│                                         │
│   [ Nouvelle commande ]                 │
│        (auto dans 20s)                  │
│                                         │
└─────────────────────────────────────────┘
```

### B.10.2 Impression Ticket (Round 6)

```javascript
printReceipt() {
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <style>
                body { font-family: monospace; width: 80mm; margin: 0; padding: 10px; }
                .center { text-align: center; }
                .line { border-top: 1px dashed #000; margin: 10px 0; }
                .queue { font-size: 48px; font-weight: bold; text-align: center; }
            </style>
        </head>
        <body>
            <div class="center">${this.restaurantName}</div>
            <div class="line"></div>
            <div>Commande: ${this.displayNumber}</div>
            <div>Date: ${new Date().toLocaleString()}</div>
            <div class="line"></div>
            <!-- Items -->
            <div class="line"></div>
            <div>TOTAL: ${this.formatPrice(this.displayTotal)}</div>
            <div class="line"></div>
            <div class="queue">${this.displayNumber}</div>
            <div class="center">Merci de votre visite !</div>
        </body>
        </html>
    `);
    printWindow.print();
}
```

---

**Suite :** Voir `03-BUSINESS-LOGIC.md` pour la logique métier détaillée (pricing, états commande, fidélité, multi-branch).
