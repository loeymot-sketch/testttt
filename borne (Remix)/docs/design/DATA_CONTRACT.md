# DATA_CONTRACT.md

> Contrat data frontend ↔ backend pour chaque composant du design FoodKing V1.
> Les schémas ci-dessous sont **fermes** (§5-B du brief validé).
>
> Convention : toute `<BadgeHalal :item="item" />` doit pouvoir se rendre avec
> les props listées ci-dessous SANS fallback inventé côté Vue.

---

## 1. Principes

### 1.1 Règle SSOT (Single Source of Truth)

- **Le frontend ne calcule JAMAIS un prix.** Jamais. Même pas pour un
  "aperçu" de total. L'endpoint `POST /api/frontend/pricing/preview`
  existe pour ça, il prend le brouillon de commande et renvoie les prix.
- **Le frontend ne filtre JAMAIS par branche.** Toute donnée arrive déjà
  scopée par `branch_id` côté serveur.
- **Le frontend n'émet JAMAIS un montant en CB** à `tpeCharge()` — il
  prend le `total_cents` fourni par `/checkout/init`.

### 1.2 Unités

- **Tous les prix en `*_cents`** (int). Jamais de float.
- **Timestamps en ISO 8601 UTC** (string).
- **Locale** : `'fr' | 'en' | 'ar'`.

### 1.3 Nullables vs optionnels

- `field?: T` = peut être absent de la réponse JSON
- `field: T | null` = toujours présent, peut valoir `null`

---

## 2. Endpoints consommés

| Endpoint | Méthode | Retour |
|---|---|---|
| `/api/frontend/menu` | GET | `MenuResponse` — catégories + items scopés branche |
| `/api/frontend/pricing/preview` | POST | `PricingPreviewResponse` — recalcule un brouillon |
| `/api/frontend/order` | POST | `OrderResponse` — validation finale |
| `/api/frontend/kiosk-event` | POST | `204 No Content` |
| `/api/frontend/loyalty/scan` | POST | `LoyaltyScanResponse` |
| `/api/frontend/order/{id}/status` | GET | `OrderStatusResponse` |
| `/api/frontend/upsell?basket_state=…` | GET | `UpsellResponse` |

---

## 3. Schémas globaux

### 3.1 `MenuResponse`

```ts
interface MenuResponse {
  branch: {
    id: string;
    name: string;
    available_locales: Locale[];   // ex: ['fr','en','ar']
    default_locale: Locale;
    timezone: string;              // ex: 'Europe/Paris'
    currency: 'EUR';
    is_rush: boolean;              // flag live, pour mode rush
    is_night: boolean;             // flag live, après 22h
  };
  categories: Category[];
  promos: KioskPromo[];            // filtrées déjà actives + scopées branche
  upsell_rules_loaded: boolean;    // juste un flag confort, pas de règles ici
}
```

### 3.2 `Category`

```ts
interface Category {
  id: string;
  name: LocaleString;              // { fr: '…', en: '…', ar: '…' }
  slug: string;
  emoji?: string;                  // placeholder visuel en l'absence d'image
  image_path: string | null;
  parent_id: string | null;        // racine = null
  depth: 0 | 1;                    // max 2 niveaux (§5-B)
  display_order: number;
  items: Item[];
  children: Category[];            // sous-catégories (depth 1 uniquement)
}
```

### 3.3 `Item`

```ts
interface Item {
  id: string;
  category_id: string;
  name: LocaleString;
  description: LocaleString | null;
  emoji: string | null;
  image_path: string | null;

  base_price_cents: number;        // prix de base, PUISÉ CÔTÉ BACKEND

  // Flags d'affichage
  is_active: boolean;              // inactif = exclus de la réponse, ne jamais voir true/false côté front
  is_available: boolean;           // false = affiché avec overlay "EN RUPTURE"
  is_new: boolean;
  is_chef_pick: boolean;
  chef_pick_order: number | null;  // 0 = premier, null si pas chef_pick
  is_spicy: boolean;
  is_vegetarian: boolean;
  is_halal: boolean;
  is_pork_free: boolean;
  is_gluten_free: boolean;

  // Allergènes (cf. §4)
  allergens: AllergenCode[];

  // Structure wizard
  template: WizardTemplate;         // 'simple' | 'sandwich' | 'tacos' | 'burger' | 'boisson' | 'dessert'
  wizard_config: WizardConfig | null;

  // Métadonnées
  display_order: number;
  tags: string[];                   // libre, pour recherche full-text
}
```

### 3.4 `LocaleString`

```ts
type LocaleString = {
  fr: string;
  en: string;
  ar: string;
};
```

> Si une traduction manque, le serveur renvoie la chaîne `default_locale`
> de la branche. Le front ne tombe JAMAIS sur `undefined`.

### 3.5 `AllergenCode` (14 allergènes UE, enum dur)

```ts
type AllergenCode =
  | 'gluten' | 'crustaces' | 'oeufs' | 'poissons' | 'arachides'
  | 'soja' | 'lait' | 'fruits_a_coque' | 'celeri' | 'moutarde'
  | 'sesame' | 'sulfites' | 'lupin' | 'mollusques';
```

Icône + label i18n côté front via une mapping statique
`resources/js/i18n/{fr,en,ar}.json` sous la clé `allergens.{code}`.

### 3.6 `WizardTemplate` & `WizardConfig`

```ts
type WizardTemplate =
  | 'simple'       // ajout direct panier, pas de wizard
  | 'sandwich'     // pain → viande → sauce → garniture → supplément → menu → upsell
  | 'tacos'        // size → viande → sauce → garniture → supplément → menu → upsell
  | 'burger'       // size → viande → sauce → supplément → menu → upsell
  | 'boisson'      // size → upsell
  | 'dessert';     // size → upsell

interface WizardConfig {
  steps: WizardStep[];
  meat_slots: number;              // ex: 1 pour M, 2 pour L, 3 pour XL
  max_sauces: number;
  max_sauces_extra: number;
  allow_no_sauce: boolean;
  supplement_meat_options: SupplementMeatOption[];
}

interface WizardStep {
  key: 'size' | 'pain' | 'meat' | 'sauce' | 'garniture' | 'supplement' | 'menu' | 'boisson' | 'sauce_frite' | 'upsell';
  required: boolean;
  auto_advance_on_unique_choice: boolean;
}

interface SupplementMeatOption {
  id: string;
  name: LocaleString;
  extra_price_cents: number;
}
```

---

## 4. `PricingPreviewResponse`

```ts
// REQUEST
interface PricingPreviewRequest {
  branch_id: string;
  lines: DraftLine[];
  dine_in: boolean;
  promo_code: string | null;
  loyalty_token: string | null;
}

interface DraftLine {
  item_id: string;
  qty: number;
  selections: {
    size?: string;
    pain?: string;
    meat?: { id: string; qty: number }[];
    sauce?: string[];                      // inclut '__NOSAUCE__' si opt-out
    garniture?: string[];
    supplement?: string[];
    supplement_meat?: { supp_id: string }[];
    menu?: string;
    frite_upgrade?: string;
    boisson?: string;
    sauce_frite?: string[];
  };
}

// RESPONSE
interface PricingPreviewResponse {
  lines: PricedLine[];
  subtotal_ht_cents: number;
  tva_cents: number;
  discount_cents: number;
  total_cents: number;
  promo_applied: { code: string; label: LocaleString } | null;
  loyalty_discount_cents: number;
  errors: PricingError[];                  // vide si OK
}

interface PricedLine {
  client_line_id: string;                  // renvoyé par le front, echo
  item_id: string;
  qty: number;
  base_price_cents: number;
  extras_total_cents: number;
  line_total_cents: number;
}

interface PricingError {
  line_index: number;
  code: 'item_unavailable' | 'invalid_selection' | 'out_of_stock';
  message: LocaleString;
}
```

---

## 5. `UpsellResponse`

```ts
// REQUEST (GET avec query string)
// ?basket_item_ids=s-kebab,frite-standard&basket_total_cents=890&screen=cart

interface UpsellResponse {
  suggestions: UpsellSuggestion[];   // max 3
}

interface UpsellSuggestion {
  rule_id: string;
  item: Item;                         // inlined, no second fetch
  price_override_cents: number | null; // null = prix normal
  copy: LocaleString;                 // ex: "Complétez avec des frites !"
  priority: number;
}
```

> Le backend applique déjà les règles `upsell_rules` filtrées par :
> - `branch_id` scope
> - `active_from` / `active_to`
> - `trigger_type` ∈ `['item', 'category', 'basket_total_min']`
> - `is_active: true`
>
> Le front ne fait QUE afficher dans l'ordre `priority` desc.

---

## 6. `KioskPromo`

```ts
interface KioskPromo {
  id: string;
  title: LocaleString;
  subtitle: LocaleString | null;
  image_path: string;
  cta_target_type: 'item' | 'category' | 'none';
  cta_target_id: string | null;      // id d'Item ou Category selon type
  display_order: number;
  // start_at / end_at / branch scoping : filtré serveur, jamais côté front
}
```

---

## 7. `LoyaltyScanResponse`

```ts
// REQUEST
interface LoyaltyScanRequest {
  qr_data: string;       // raw data du QR, validation serveur
  branch_id: string;
}

// RESPONSE
interface LoyaltyScanResponse {
  ok: boolean;
  customer_token: string | null;     // opaque, à réinjecter dans pricing/preview
  display_name: string | null;       // "Bonjour Karim" — PAS d'id
  declared_allergens: AllergenCode[];
  loyalty_balance_points: number;
  last_order: {
    order_id: string;
    lines: DraftLine[];              // réutilisable tel quel pour Mode Turbo
    total_cents: number;
  } | null;
  error_code?: 'qr_invalid' | 'customer_not_found' | 'expired';
}
```

---

## 8. `OrderResponse` (commande finale)

```ts
// REQUEST
interface OrderRequest {
  branch_id: string;
  lines: DraftLine[];
  dine_in: boolean;
  promo_code: string | null;
  loyalty_token: string | null;
  payment: { method: 'CB' | 'TR' | 'CB_TR_SPLIT'; tx_ref: string };
  session_id: string;
}

// RESPONSE
interface OrderResponse {
  ok: boolean;
  order_id: string;
  order_number: number;              // numéro affiché au client (ex: 42)
  total_cents: number;
  ticket_buffer: string;             // base64 du buffer ESC/POS
  estimated_wait_min: number | null;
  error?: { code: string; message: LocaleString };
}
```

---

## 9. Contrats par composant

### 9.1 `<ProductCard :item="…">`

**Props consommés** :
- `item.name.{locale}` → titre
- `item.description.{locale}` → sous-titre
- `item.base_price_cents` → prix affiché (formaté via helper `formatEUR`)
- `item.emoji` || `item.image_path` → visuel
- `item.is_available` → overlay "EN RUPTURE"
- `item.is_new` → ruban NOUVEAU
- `item.is_chef_pick` → badge 👨‍🍳 Coup de cœur
- `item.is_spicy`, `is_vegetarian`, `is_halal` → badges verts/rouges

**Emits** :
- `@open(item)` → ouvre wizard OU ajoute direct si `template === 'simple'`

### 9.2 `<CategoryTab :category="…" :active="…">`

**Props** :
- `category.name.{locale}`
- `category.emoji` || `category.image_path`
- `category.children` → sous-catégories (affichées en sous-nav si `active`)

### 9.3 `<FilterChip :filter="…">`

**Props** :
```ts
interface FilterChipProps {
  filter: 'vegetarian' | 'halal' | 'pork_free' | 'gluten_free' | 'spicy' | 'under_10';
  active: boolean;
}
```

Le filtrage s'applique côté client sur `items` déjà chargés, par matching sur les `is_*` correspondants.
Exception : `under_10` compare `base_price_cents < 1000`.

### 9.4 `<AllergenBadge :allergens="…" :customer_allergens="…">`

**Props** :
- `allergens: AllergenCode[]` — de l'item
- `customer_allergens: AllergenCode[]` — du scan fidélité (vide si pas scanné)

**Rendu** :
- Icône + liste des codes
- Si intersection non vide : badge rouge pulsant avec ⚠
- Tap : modal détail

### 9.5 `<UpsellCard :suggestion="…">`

**Props** : `UpsellSuggestion` entier.

Affiche : image item, `copy.{locale}`, prix (avec `price_override_cents` si présent, sinon `item.base_price_cents`).

**Emits** : `@accept(rule_id)`, `@reject(rule_id, reason)`.

### 9.6 `<PromoCarouselSlide :promo="…">`

**Props** : `KioskPromo`.

Tap CTA → selon `cta_target_type` :
- `'item'` → ouvre wizard sur l'item
- `'category'` → change de catégorie
- `'none'` → visuel uniquement

### 9.7 `<WizardStepX>` (X ∈ `size`, `pain`, `meat`, …)

Chaque step reçoit :
```ts
interface WizardStepProps {
  item: Item;
  config: WizardConfig;
  selections: Selections;      // état courant partagé
  customer_allergens: AllergenCode[];
  locale: Locale;
  a11y_mode: A11yMode;
}
```

**Emits** : `@change(selections)`, `@next()`, `@prev()`, `@abandon()`.

### 9.8 `<MiniRecap :selections="…" :item="…">`

Footer persistant. Affiche un one-liner :
> *Tacos L · Poulet, Kebab · Algérienne · +12,40 €*

Le `+12,40 €` vient de `pricing/preview` rafraîchi à chaque changement
(debounce 200 ms) — **jamais calculé côté front**.

### 9.9 `<VirtualKeyboard :layout="…">`

**Props** :
```ts
interface VirtualKeyboardProps {
  layout: 'azerty_fr' | 'qwerty_en' | 'arabic_ar';
  mode: 'text' | 'numeric' | 'email';
  max_length?: number;
  rtl?: boolean;
}
```

**Emits** : `@input(value)`, `@submit(value)`, `@close()`.

### 9.10 `<ConsentScreen :type="…">`

**Props** :
```ts
interface ConsentScreenProps {
  type: 'heatmap' | 'loyalty_scan' | 'mobile_transfer';
  locale: Locale;
}
```

**Emits** : `@decision(granted: boolean)`.

---

## 10. State machine session client

```ts
interface KioskSession {
  id: string;                          // UUID v4, renouvelé à chaque idle reset
  branch_id: string;
  locale: Locale;
  a11y: {
    pmr: boolean;
    high_contrast: boolean;
    audio_description: boolean;
    reduced_motion: boolean;
  };
  consent: {
    heatmap: boolean | null;            // null = pas encore décidé
    loyalty_scan: boolean | null;
    mobile_transfer: boolean | null;
  };
  cart: DraftLine[];
  dine_in: boolean | null;
  loyalty: {
    token: string | null;
    display_name: string | null;
    allergens: AllergenCode[];
    last_order: LoyaltyScanResponse['last_order'];
  };
  idle: {
    last_interaction_at: number;        // ms epoch
    timeout_s: number;                  // calculé selon a11y mode (§5-C)
    countdown_s: number;
  };
}
```

---

## 11. Migrations backend requises

Référence pour Cursor côté Laravel :

### `categories`
```sql
ALTER TABLE categories ADD COLUMN parent_id CHAR(36) NULL AFTER id;
ALTER TABLE categories ADD COLUMN depth TINYINT NOT NULL DEFAULT 0;
ALTER TABLE categories ADD CONSTRAINT fk_categories_parent
  FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL;
-- Contrainte métier depth <= 1 à faire en validation modèle, pas en DB
```

### `items`
```sql
ALTER TABLE items ADD COLUMN is_chef_pick BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE items ADD COLUMN chef_pick_order INT NULL;
ALTER TABLE items ADD COLUMN is_new BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE items ADD COLUMN is_available BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE items ADD COLUMN is_spicy BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE items ADD COLUMN is_vegetarian BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE items ADD COLUMN is_halal BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE items ADD COLUMN is_pork_free BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE items ADD COLUMN is_gluten_free BOOLEAN NOT NULL DEFAULT FALSE;
```

### `allergens` + pivot
```sql
CREATE TABLE allergens (
  code VARCHAR(24) PRIMARY KEY,          -- 'gluten', 'crustaces', ...
  display_order TINYINT NOT NULL
);
-- Seed avec les 14 allergènes UE.

CREATE TABLE item_allergen (
  item_id CHAR(36) NOT NULL,
  allergen_code VARCHAR(24) NOT NULL,
  PRIMARY KEY (item_id, allergen_code),
  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  FOREIGN KEY (allergen_code) REFERENCES allergens(code) ON DELETE CASCADE
);

ALTER TABLE customers
  ADD COLUMN declared_allergens JSON NOT NULL DEFAULT (JSON_ARRAY());
```

### `upsell_rules`
```sql
CREATE TABLE upsell_rules (
  id CHAR(36) PRIMARY KEY,
  branch_id CHAR(36) NULL,
  trigger_type ENUM('item','category','basket_total_min') NOT NULL,
  trigger_id CHAR(36) NULL,
  trigger_basket_total_cents INT NULL,
  suggested_item_id CHAR(36) NOT NULL,
  price_override_cents INT NULL,
  priority INT NOT NULL DEFAULT 0,
  active_from DATETIME NULL,
  active_to DATETIME NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  FOREIGN KEY (suggested_item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

### `kiosk_promos` + pivot
```sql
CREATE TABLE kiosk_promos (
  id CHAR(36) PRIMARY KEY,
  image_path VARCHAR(255) NOT NULL,
  cta_target_type ENUM('item','category','none') NOT NULL DEFAULT 'none',
  cta_target_id CHAR(36) NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  display_order INT NOT NULL DEFAULT 0,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE kiosk_promo_branch (
  kiosk_promo_id CHAR(36) NOT NULL,
  branch_id CHAR(36) NOT NULL,
  PRIMARY KEY (kiosk_promo_id, branch_id),
  FOREIGN KEY (kiosk_promo_id) REFERENCES kiosk_promos(id) ON DELETE CASCADE,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

-- Traductions via table locale_strings existante
-- (kiosk_promos.id utilisé en key)
```

### `branches` — locales
```sql
ALTER TABLE branches ADD COLUMN available_locales JSON NOT NULL
  DEFAULT (JSON_ARRAY('fr'));
ALTER TABLE branches ADD COLUMN default_locale VARCHAR(8) NOT NULL DEFAULT 'fr';
```

---

## 12. Invariants testables

Tests que Cursor doit faire passer pour valider l'intégration frontend/backend :

1. `GET /api/frontend/menu` retourne **0 item** avec `is_active = false` (filtrage serveur).
2. Toutes les `LocaleString` dans la réponse ont les clés des locales actives de la branche — pas d'`undefined`.
3. `pricing/preview` avec `price_override_cents` côté frontend ignoré : le serveur recalcule toujours depuis la BDD.
4. `GET /api/frontend/upsell` ne retourne **jamais** plus de 3 `suggestions`.
5. Scan loyalty sans `customer_token` valide → 200 avec `ok: false`, jamais 401/403 (parcours doit continuer).
6. `kiosk_promos` expirées (`end_at < now()`) absentes de la réponse `/menu.promos`.
7. `items[].is_available = false` présent dans la réponse (pour afficher "EN RUPTURE") mais non cliquable côté front.
