# 📡 Le Cayenne Mobile App — Plan de connexion backend
**Date** : 2026-05-10
**Cible** : Phase 6+ post-V0 standalone — connexion DB + SMS + auth réelle.
**Statut** : V0 100% fonctionnel en local, prêt à brancher.

---

## §0 — TL;DR

L'app mobile actuelle est **100% fonctionnelle en standalone** et **alignée 1:1 avec le kiosk Le Cayenne** (cf. `config/menu.php` SSOT) :
- 13 catégories réelles + 60 produits + 9 viandes + 15 sauces + 3 crudités + 7 suppléments + 3 formules
- Logique de prise de commande **identique au kiosk** : pick viandes selon item.viandes, sauce 1 gratuite (sup 0,50 €), crudités toggle (default ON), suppléments à 1 €, formule menu/frites/boisson en addon
- **Aucune box inventée** : items réels Tacos M/L/XL/XXL, Sandwichs Méga/Terminator/Suprême/Cayenne, Burgers Cheese/Fish/Double/Big/Grill, Assiettes, Ojja, Omelettes, Salades, Wings/Tenders, Menus enfants, Frites, Desserts, Boissons

**Deux chemins possibles** :
- **Chemin A — Supabase** (recommandé pour mobile B2C).
- **Chemin B — Backend FoodKing existant** (Laravel + Sanctum, déjà utilisé par Kiosk/POS).

Les deux sont compatibles avec la data layer actuelle (même schéma).

---

## §1 — Inventaire de l'existant V0

### Fichiers data (à remplacer par appels API)
| Fichier | Contenu | Endpoint cible |
|---|---|---|
| `mobile/data/menu.js` | branch + 13 catégories Le Cayenne + 60 items + meats[9] + sauces[15] + crudites[3] + supplements[7] + formules[3] (cf. `config/menu.php` SSOT) | `GET /menu` |
| `mobile/data/loyalty.js` | config + 6 rewards + balance + 7 history | `GET /loyalty/balance`, `/history`, `/rewards` |
| `mobile/data/orders.js` | 1 active + 5 history | `GET /order?status=...` |
| `mobile/data/user.js` | profil mock | `GET /profile` |
| `mobile/api/storage.js` | localStorage helpers (auth/cart/onboarding) | **conserve** (cache local + offline) |

### Schéma data layer = schéma backend (kiosk-aligned)
Le schéma JSON utilisé par la mobile app **est aligné 1:1 avec le kiosk** (cf. `config/menu.php` Single Source Of Truth + `KioskWizardComponent.vue`). Chaque item porte les flags qui pilotent l'UI :

```js
{
  id, slug, category_id, name, description, price, time, kiosk_emoji, tags,
  is_featured, is_new, is_spicy, is_halal, is_vegetarian,
  // Composition flags (cf. config/menu.php)
  viandes,            // 0-4 → si > 0, étape "Choisis N viandes" obligatoire
  has_sauce,          // bool → étape "Sauce" (1 gratuite, sup 0,50 €/sauce additionnelle)
  has_crudites,       // bool → étape "Crudités" (Salade/Tomate/Oignon toggle, default ON)
  has_supplements,    // bool → suppléments toggleable (jambon/œuf/raclette/galette/etc.)
  has_menu_addon,     // bool → étape "Faire un menu" (Menu+3€ / Frites+2€ / Boisson+2€)
  allergens,
}
```

**Mapping vers le backend Eloquent** :
- `Item.is_featured` → projection JSON
- `Item.is_halal/is_spicy/is_vegetarian/is_gluten_free` → flags diététiques (déjà dans `KioskMenuService::projectItems`)
- `viandes` → `ItemAttribute(name='Viande', min_select=N, max_select=N, allow_repeat=true)` + `ItemVariation` lié
- `has_sauce` → `ItemAttribute(name='Sauce', min_select=1, max_select=1, allow_repeat=false)`
- `has_crudites` → `ItemAttribute(name='Crudités', min_select=0, max_select=3, allow_repeat=false)`
- `supplements[]` → `ItemExtra` (group_label, price)
- `formules[]` → `ItemAddon(role='menu_component')`

**Pas de wizard composition box** — la mobile app reproduit exactement le flow kiosk : produit → suppléments en page produit → ajout panier direct.

---

## §2 — Chemin A : Supabase (recommandé V1 mobile B2C)

### Pourquoi Supabase
- Auth téléphone OTP **out-of-the-box** (Twilio/MessageBird intégré).
- Postgres + RLS pour multi-tenant (1 row = 1 branche).
- Realtime gratuit pour suivi commande live (orders.status push).
- Edge Functions pour HMAC loyalty QR + webhook Stripe.
- Pas de serveur PHP à maintenir côté mobile (l'API existante reste pour POS/Kiosk).
- Coût ~25 €/mois jusqu'à 500K MAU (plan Pro).

### Schéma DB Supabase à créer

```sql
-- Multi-tenant
create table branches (
  id uuid primary key default gen_random_uuid(),
  name text not null, city text, zip text, phone text,
  hours_text text, is_open boolean default true,
  created_at timestamptz default now()
);

create table users (
  id uuid primary key references auth.users on delete cascade,
  phone text unique not null, first_name text, last_name text,
  member_number text unique,
  loyalty_points int default 0,
  language text default 'fr',
  created_at timestamptz default now()
);

-- Catalog (mirrors FoodKing schema)
create table item_categories (
  id uuid primary key default gen_random_uuid(),
  branch_id uuid references branches on delete cascade,
  slug text not null, name text, icon text, sort int, parent_id uuid
);

create table items (
  id uuid primary key default gen_random_uuid(),
  branch_id uuid references branches on delete cascade,
  category_id uuid references item_categories,
  slug text not null, name text, description text,
  price numeric(10,2), thumb text, kiosk_emoji text,
  time_minutes int, tags text[],
  is_featured bool, is_new bool, is_spicy bool, is_halal bool,
  is_vegetarian bool, is_gluten_free bool, is_pork_free bool,
  is_available bool default true,
  unique(branch_id, slug)
);

create table item_attributes (
  id uuid primary key default gen_random_uuid(),
  name text, min_select int default 1, max_select int default 1,
  allow_repeat bool default false
);

create table item_variations (
  id uuid primary key default gen_random_uuid(),
  item_id uuid references items on delete cascade,
  attribute_id uuid references item_attributes,
  name text, price numeric(10,2), sort int default 0
);

create table item_extras (
  id uuid primary key default gen_random_uuid(),
  item_id uuid references items on delete cascade,
  name text, price numeric(10,2), group_label text,
  is_default bool default false, is_pork bool default false, is_spicy bool default false
);

create table item_addons (
  id uuid primary key default gen_random_uuid(),
  item_id uuid references items on delete cascade,
  role text check (role in ('drink','side','dessert','menu_component','upsell')),
  addon_item_id uuid references items
);

create table item_wizard_profiles (
  id uuid primary key default gen_random_uuid(),
  item_id uuid references items on delete cascade,
  version int default 1, is_published bool default true,
  branch_id_scope uuid references branches
);

create table item_wizard_steps (
  id uuid primary key default gen_random_uuid(),
  profile_id uuid references item_wizard_profiles on delete cascade,
  step_key text, label text, position int,
  source_type text, addon_role text,
  min_select int, max_select int, allow_repeat bool default false,
  options jsonb            -- denormalized for read perf : [{id,name,price,kiosk_emoji}]
);

-- Orders
create table orders (
  id uuid primary key default gen_random_uuid(),
  number serial,                            -- C-1234 readable
  user_id uuid references users on delete restrict,
  branch_id uuid references branches,
  status text check (status in ('pending','in_progress','ready','delivered','cancelled')),
  total numeric(10,2),
  payment_status text check (payment_status in ('pending','paid','refunded')),
  payment_method text check (payment_method in ('cash_at_counter','card_at_counter','stripe')),
  pickup_code text, qr_value text,
  created_at timestamptz default now(),
  ready_at_estimate timestamptz,
  delivered_at timestamptz
);

create table order_items (
  id uuid primary key default gen_random_uuid(),
  order_id uuid references orders on delete cascade,
  item_id uuid references items,
  variation_id uuid references item_variations,
  qty int default 1,
  composition_snapshot jsonb,                -- WIZARD selections + extras frozen at create
  line_total numeric(10,2)
);

-- Loyalty
create table loyalty_transactions (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references users on delete cascade,
  type text check (type in ('earn','redeem','adjustment','expire')),
  amount int,
  reason text,
  order_id uuid references orders,
  reward_id uuid,
  created_at timestamptz default now()
);

create table loyalty_rewards (
  id uuid primary key default gen_random_uuid(),
  branch_id uuid references branches,
  name text, points_cost int, type text, payload jsonb, is_active bool default true
);
```

### Row-Level Security (RLS)
```sql
alter table users enable row level security;
create policy "users_self_read" on users for select using (auth.uid() = id);
create policy "users_self_update" on users for update using (auth.uid() = id);

alter table orders enable row level security;
create policy "orders_self_read" on orders for select using (auth.uid() = user_id);
create policy "orders_self_create" on orders for insert with check (auth.uid() = user_id);

alter table loyalty_transactions enable row level security;
create policy "lty_self_read" on loyalty_transactions for select using (auth.uid() = user_id);

-- Catalog : public read (filtered by branch)
alter table items enable row level security;
create policy "items_public_read" on items for select using (is_available = true);
```

### Edge Functions à écrire
1. **`loyalty-qr-sign`** : retourne `LECAY-LOYALTY-{user_id}-{hmac}` signé (TTL 5 min).
2. **`order-create`** : transaction atomique (insert order + order_items + loyalty earn).
3. **`stripe-webhook`** : reconciliation paiement → `orders.payment_status = 'paid'`.
4. **`order-cancel`** : refund Stripe + delete order si `pending`.

### Mapping endpoints mobile → Supabase

| Mobile data file | Avant (V0) | Après (V1 Supabase) |
|---|---|---|
| `data/menu.js` | hardcoded JS object | `supabase.from('items').select('*, item_variations(*), item_extras(*), item_addons(*), item_wizard_profiles(*, item_wizard_steps(*))').eq('branch_id', $branchId)` |
| `data/loyalty.js account` | hardcoded 347 | `supabase.from('users').select('loyalty_points').eq('id', auth.uid())` |
| `data/loyalty.js history` | hardcoded 7 entries | `supabase.from('loyalty_transactions').select('*').eq('user_id', auth.uid()).order('created_at', { ascending: false })` |
| `data/loyalty.js QR` | `generateMockQR()` | Edge fn `loyalty-qr-sign` (HMAC server-side) |
| `data/orders.js active` | hardcoded 1 | `supabase.from('orders').select('*, order_items(*)').eq('user_id', auth.uid()).in('status', ['pending','in_progress','ready'])` |
| `data/orders.js history` | hardcoded 5 | `supabase.from('orders').select('*').eq('user_id', auth.uid()).eq('status', 'delivered')` |
| `data/user.js current` | hardcoded Ikyes | `supabase.from('users').select('*').eq('id', auth.uid()).single()` |

### Auth OTP Supabase

```js
// 1. Login screen → "Recevoir le code"
const { error } = await supabase.auth.signInWithOtp({ phone: '+33642799884' });

// 2. OTP screen → tape 1234
const { data, error } = await supabase.auth.verifyOtp({ phone, token: '1234', type: 'sms' });
// data.session.access_token → stocké via storage.setAuth(...)

// 3. Toutes les requêtes suivantes incluent automatiquement le bearer token
```

Côté provider SMS : Twilio / MessageBird / Vonage activable dans le dashboard Supabase Auth → Phone provider. Coût ≈ 0.05 €/SMS.

### Realtime (orders status push)

```js
// Sur ScreenOrders quand un order est "in_progress" :
supabase
  .channel('order-' + orderId)
  .on('postgres_changes', { event: 'UPDATE', schema: 'public', table: 'orders', filter: `id=eq.${orderId}` },
    (payload) => updateOrderStatus(payload.new))
  .subscribe();
```

Quand le KDS marque l'order `ready`, le mobile reçoit le push et anime le ticket de "EN PRÉPARATION" → "PRÊT À RÉCUPÉRER".

---

## §3 — Chemin B : Backend FoodKing existant (Laravel + Sanctum)

### Pourquoi pas par défaut
Le `MenuController::kiosk()` exige l'ability `kiosk:order`. Un user mobile ordinaire n'a pas cette ability. Il faut :
1. **Soit** créer un nouveau endpoint `/api/v1/frontend/menu/customer` qui requiert `auth:sanctum` simple sans ability check (résout via `request()->user()->branch_id` ou `branch_id` query param).
2. **Soit** assigner `mobile:order` ability au token user mobile (analogue à `kiosk:order`).

Recommandation : option 1 (nouveau endpoint customer-facing).

### Endpoints à créer côté FoodKing

```php
// routes/api.php
Route::prefix('v1/frontend')->middleware(['auth:sanctum'])->group(function () {
    // Menu (NEW — sans ability check kiosk:order)
    Route::get('/menu/customer/{branch}', [Frontend\MenuController::class, 'customer']);
    Route::get('/branches', [Frontend\BranchController::class, 'index']);

    // Order (existant — ajustement pour mobile_app channel)
    Route::post('/order', [Frontend\OrderController::class, 'store'])
        ->middleware(['throttle:mobile-orders', 'idempotency']);
    Route::get('/order', [Frontend\OrderController::class, 'index']);
    Route::get('/order/{frontendOrder}', [Frontend\OrderController::class, 'show']);

    // Loyalty (existant)
    Route::get('/loyalty/balance', [Frontend\LoyaltyController::class, 'balance']);
    Route::get('/loyalty/history', [Frontend\LoyaltyController::class, 'history']);
    Route::get('/loyalty/rewards', [Frontend\LoyaltyController::class, 'rewards']);
    Route::post('/loyalty/redeem', [Frontend\LoyaltyController::class, 'redeem']);
    Route::get('/loyalty/qr', [Frontend\LoyaltyController::class, 'generateQr']); // HMAC signé
});

// auth/signup OTP — existant déjà :
Route::post('/auth/signup/otp', [Auth\SignupController::class, 'otp']);
Route::post('/auth/signup/verify', [Auth\SignupController::class, 'verify']);
```

### Channel separation
Ajouter `'mobile_app'` dans la projection `Item.channels` pour distinguer du `kiosk`. La mobile app filtre les items où `channels` contient `'mobile_app'` ou est null.

### Frozen-zone constraints
- **Pricing SSOT** : aucun calcul de prix client-side ne fait foi. La mobile app envoie `{ item_id, variation_id, extra_ids[], wizard_selections{}, qty }` au backend qui calcule le total via `PricingService::calculateOrder()`.
- **NF525** : `composition_snapshot` JSON gelé à la création — la mobile app n'envoie que les références, jamais les snapshots.
- **Idempotency** : header `X-Idempotency-Key: <uuid>` à chaque POST `/order` pour éviter double-création réseau.

---

## §4 — Plan de migration en 6 phases

### Phase 6 — Wireup auth (1-2j)
1. Décider Supabase ou FoodKing (recommandé : **Supabase**).
2. Créer `mobile/api/api.js` (fetch wrapper + bearer token + base URL).
3. Remplacer `screens-onboarding.jsx::ScreenLogin::onNext` mock par `await api.signupOtp(phone)`.
4. Remplacer `screens-onboarding.jsx::ScreenOTP::onNext` mock par `await api.verifyOtp(phone, code)`.
5. Stocker token dans `storage.setAuth({ token, user_id, expires_at })`.
6. Test E2E réel : reçoit un SMS, valide, atterrit sur Home.

### Phase 7 — Wireup catalogue (1-2j)
1. Remplacer `data/menu.js` hardcoded par `await api.getMenu(branch_id)` → cache 60s in-memory.
2. Conserver le shape EXACT (les screens consomment `window.LC.menu.items`, etc.).
3. Test : Home + Menu + Item Detail rendent les vraies données Le Cayenne.

### Phase 8 — Wireup commandes (2j)
1. `addToCart` reste localStorage (panier offline-friendly).
2. À "VALIDER MA COMMANDE", `await api.createOrder({ items: cart, payment_method })` avec idempotency-key UUID v4.
3. Confirmation reçoit `{ order_id, pickup_code, qr_value, ready_at_estimate }`.
4. `ScreenOrders` fetch real `/order` paginated (active + history).
5. Realtime subscription sur active order pour status updates.

### Phase 9 — Wireup loyalty (1-2j)
1. `data/loyalty.js account` → `await api.getLoyaltyBalance()`.
2. `data/loyalty.js history` → `await api.getLoyaltyHistory(page)`.
3. QR → `await api.getLoyaltyQR()` (refresh auto toutes les 4 min, TTL 5 min).
4. `ModalRedeem onConfirm` → `await api.redeemReward(reward_id)`.
5. Card link → `await api.linkPlasticCard(qr_value_scanned)`.

### Phase 10 — Wireup paiement Stripe (1-2j)
1. Intégrer Stripe React Native ou Stripe.js (pour V0 web prototype).
2. `ModalPayChoice::onPickCard` → crée `PaymentIntent`, ouvre Stripe Sheet.
3. Sur succès Stripe → backend webhook met à jour `orders.payment_status = paid`.
4. Realtime push → mobile passe au screen Confirm.

### Phase 11 — Build mobile natif (2-3 sem)
1. Wrapper Capacitor + Vue 3 (selon ULTRAPLAN §4 Option A) ou rester en React.
2. Reprend tous les screens 1:1 (le design est validé).
3. Push notifications natives via OneSignal ou Firebase.
4. Submit App Store + Play Store.

**Total estimé** : ~3-4 semaines pour atteindre une V1 production-ready post-V0 standalone.

---

## §5 — Audit avec le système global FoodKing (POS+Kiosk+KDS+OSS+Admin)

### Points de cohérence à vérifier avant V1 production

| Concern | À vérifier | Owner check |
|---|---|---|
| **Pricing SSOT** | `mobile/api.js::createOrder` envoie SEUL `{item_id, qty, variation_id, extra_ids, wizard_selections}` ; le total est recalculé serveur-side via `PricingService::calculateOrder` | ⏳ |
| **NF525 fiscal** | À création d'order payée mobile (Stripe), allocation `fiscal_sequence_no` via `FiscalSequenceService` | ⏳ |
| **Branch isolation** | `BranchScope` global appliqué sur Order/OrderItem/OrderPayment garantit qu'un user mobile ne voit que ses orders dans sa branche | ⏳ |
| **Idempotency** | `X-Idempotency-Key` UUID par submit ; `idempotency` middleware backend dédoublonne (cf. iter11) | ⏳ |
| **Sanctum ability** | `mobile:order` ability créée et assignée au token mobile (analogue `kiosk:order`) ; réviser `tokenCan()` dans les controllers | ⏳ |
| **Allergen FR** | `Item::allergens` exposé via `KioskMenuService` est consommé par la mobile app (cf. iter10 baseline) | ⏳ |
| **i18n FR-lock V1** | Mobile FR-only par ADR-007 ; pas de switcher locale en V1 | ⏳ |
| **Stock concurrency** | `ChoiceAvailabilityResolver` filtré par branch garantit que les items out-of-stock ne s'affichent pas dans la mobile app (parité kiosk iter12+13) | ⏳ |
| **Loyalty consent** | `LoyaltyConsent` opt-in RGPD doit s'appliquer au flow mobile (modal après 1ʳᵉ commande) | ⏳ |
| **Outbox + sync** | Si on confirme la commande mobile dans la branche, l'event doit traverser `Outbox + Pusher + polling 5s fallback` jusqu'au KDS (cf. §7 #1 BRAIN.md) | ⏳ |

### Risques identifiés (à mitiger en Phase 6+)

1. **Double earn loyalty** : si l'app mobile crédite des points client-side ET le backend crédite via `AwardLoyaltyPointsOnDelivery`, on a un risque de double-comptage. **Mitigation** : ne JAMAIS toucher `loyalty_points` côté client ; tout passe par les listeners backend.
2. **Cart desync** : panier localStorage peut diverger du backend si l'utilisateur change de device. **Mitigation Phase 8** : optionnel — sync cart serveur (table `cart_drafts`) ou accepter que le cart soit device-local.
3. **Variation/extra renommés** : si l'admin modifie le catalogue après que l'utilisateur a chargé le menu, son cart peut référencer des IDs périmés. **Mitigation** : à `createOrder`, le backend renvoie 409 si `composition_snapshot` invalide → l'app re-fetch le menu et invite à re-composer.
4. **Frozen-zone breach** : la mobile app NE DOIT PAS modifier les fichiers du kiosk (KioskWizardComponent, etc.). Elle est une couche entièrement séparée. (cf. CLAUDE.md §7.)

---

## §6 — Décisions ouvertes (owner gate)

Avant de lancer Phase 6, l'owner doit trancher :

- **D1** : Supabase (chemin A, recommandé) ou Backend FoodKing (chemin B) ? → impacte ~50% de la dette technique mobile.
- **D2** : Provider SMS (Twilio €0.05/SMS, MessageBird €0.04, Vonage €0.045) — coût total ≈ 50€/mois si 1000 OTP envoyés.
- **D3** : Build mobile natif Capacitor (Phase 11) ou rester web PWA Add-to-Home-Screen ? → coût + délai.
- **D4** : Stripe vs PayPlug vs LCL pour paiement card mobile ? Compatibilité NF525.
- **D5** : Carte plastique fidélité — production batch de cartes pré-imprimées avec QR (table `loyalty_physical_cards` à ajouter, cf. ULTRAPLAN §5).

---

## §7 — État actuel V0 (vérifié 2026-05-10)

✅ **15 écrans rendus pixel-perfect** :
splash, onb1-4, login, OTP, home, menu, item-detail (3 variantes : simple/variations/wizard-box), cart, pay-choice modal, confirm, +25-points modal, orders (active+historique), order-detail, profile, loyalty (3 tabs), redeem modal, card-link modal, stripe placeholder.

✅ **Wizard composition box** : 8 étapes pour Box Familiale (4 burgers × 6 options + 4 boissons × 7 options = 240 combinaisons possibles), validation min_select bloque le bouton.

✅ **Variations + addons** : Tacos M/L/XL + choix viande, Frites M/L/XXL + topping, Wings BBQ/Nashville, etc.

✅ **Extras groupés** : Garniture/Fromage/Charcuterie/Épicé/Sauce avec defaults pre-toggled.

✅ **Audit white-on-white** : 0 offender détecté (CSS contrast safety net actif sur `[data-screen-label]`).

✅ **Console clean** : aucune erreur runtime sur les 15 écrans.

✅ **localStorage state** : auth persistant, cart persistant, onboarding-seen flag.

✅ **35 produits / 9 catégories Le Cayenne** alignés sur design (Hénin-Beaumont 62210, Abdoullah en cuisine, "du peuple, pour le peuple").

✅ **Schéma data layer = schéma backend FoodKing** (Item, Variation, Extra, Addon, Wizard*) → migration mécanique, pas de rewrite.

---

## §8 — Quick reference

```bash
# Lancer l'app mobile en local
php -S 127.0.0.1:8081 -t mobile/

# Ou via Claude Preview MCP
preview_start name=mobile
```

```js
// Console DevTools mobile :
window.LC.menu.items                  // 35 items
window.LC.menu.findItem('box-familiale')   // wizard 8 steps
window.LC.loyalty.account.balance     // 347
window.LC.user.current.first_name     // "Ikyes"
window.LC.storage.clearAuth()         // reset → splash au reload
```

---

— *Plan rédigé après livraison V0 standalone validée. Reviens avec "OK on lance Phase 6 chemin X" pour commencer le wireup réel.*
