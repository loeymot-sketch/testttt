# ULTRA-PLAN — POS V5 Design Convergence

| Champ | Valeur |
|---|---|
| TASK_ID (proposé) | `CV1-POS-DESIGN-CONVERGENCE-001` |
| Date | 2026-05-02 |
| Auteur | Claude (orchestrator, session Cursor) |
| Cycle parent | Caisse V1 — Masterplay |
| Mission | Aligner le POS (toutes surfaces hors wizard) sur la qualité design du wizard kiosk pour battre la concurrence |
| Wizard | **GELÉ** — `KioskWizardComponent.vue`, `kiosk-wizard.css`, `kiosk/tokens.css`, `KioskStep*Component.vue` non touchés |
| INVARIANTS_AT_RISK | Aucun (CSS + templates UI uniquement, zéro logique pricing / OrderStatus / branch_id / dispatch / auth) |
| GATE_CONDITIONS | Aucun anticipé (non frozen, non auth, non schema) |
| TEST_STRATEGY | `local-validation` (Vitest visuels existants doivent rester verts) + `human-verification` (revue UI screenshots) |

---

## 0. Diagnostic — pourquoi le POS perd contre la concurrence (et contre son propre wizard)

### Dissonance visuelle interne (caisse ≠ wizard)

| Dimension | Wizard kiosk (perfect) | POS actuel (à refondre) | Verdict |
|---|---|---|---|
| Fond | `#FFFBF5` crème warm appétissant | `#f3f5f8` gris-bleu froid SaaS générique | ❌ **Dissonant** |
| Police | `Inter` 18-32px généreuse | `Rubik` 11-13px serrée | ❌ **2 systèmes** |
| Rouge brand | `#E8001C` unique, signal d'action | 3 rouges concurrents : `#E8001C` + `#B0004D` + `#FB4E4E` | ❌ **Confus** |
| Photos | Rondes 80px, hero, vivantes | Background blurry minuscule (24px) | ❌ **Plat** |
| Hiérarchie | Stepper visuel + composition live + total chip | Bandeau gradient écrasant + cart serré | ❌ **Bruit** |
| Microcopy | "Vous composez", "Choisi", "Abandonner l'article" | "Cart", "Park", "Apply" | ❌ **Tonal mismatch** |
| Motion | `cubic-bezier(0.34, 1.56, 0.64, 1)` bounce, pulse vert AAA | Aucune transition unifiée | ❌ **Statique** |

### Le verdict
Le wizard est un **système design cohérent et émotionnel**. Le POS est un **patchwork technique** : 4 ans de patches Tailwind ad hoc, 3 namespaces CSS coexistants (`fk-pos-v4`, `db-*`, `cv1-*`), couleurs hardcodées 200+ fois, headers en gradient noir-bordeaux écrasants qui imitent un dashboard SaaS générique. Aucun caissier ne ressent que c'est un produit FoodKing — il pourrait penser que c'est SquarePOS ou Toast.

### La concurrence et où on doit la battre

| Concurrent | Force | Faiblesse exploitable |
|---|---|---|
| **Toast POS** | Onboarding clair, kanban tracker mature | Palette froide grise/bleue, aucune chaleur émotionnelle, polices génériques |
| **Square** | Minimalisme propre | Manque de personnalité, zero brand identity sur l'écran caissier |
| **SumUp / Lightspeed** | Workflow rapide | Densité info trop chargée, mauvaise lisibilité chiffres |
| **Tigerbird (Restoflash) / Ottimate** | Spécialisation FR | UI vieillote, fiscaux à l'arrache |

**Notre angle gagnant** : un POS **éditorial chaleureux** qui reflète la marque FoodKing (fast-food premium chaleureux), avec **densité info opérateur** (multitasking caissier) sans sacrifier la **respiration tactile** héritée du wizard. Aucun concurrent ne fait ça aujourd'hui.

---

## 1. Direction esthétique — POS V5 "Editorial Warm"

### 1.1 Parti pris (one-line manifesto)
> **« Un ticket caisse vivant, pas un dashboard. »**
> Chaque pixel du POS doit donner au caissier la même satisfaction sensorielle que le wizard donne au client : matière chaude, hiérarchie claire, accent rouge **réservé à l'action**, jamais à la décoration.

### 1.2 Palette unifiée (single source of truth)

```css
/* resources/css/foundations/pos-v5-tokens.css */
:root {
  /* === Brand (héritage kiosk, pas de redéfinition) === */
  --pos-v5-brand-red:        #E8001C;  /* CTA primaire pay/order */
  --pos-v5-brand-red-dark:   #B8000F;  /* Hover/active */
  --pos-v5-brand-red-soft:   #FFF0F2;  /* Sélection tinted */
  --pos-v5-brand-red-faint:  #FFF8F9;  /* Background row hover */

  /* === Surfaces warm (héritage kiosk-wizard) === */
  --pos-v5-bg-app:           #FFFBF5;  /* Fond global crème */
  --pos-v5-bg-panel:         #FFFFFF;  /* Cartes / panels */
  --pos-v5-bg-subtle:        #F7F3EC;  /* Zones secondaires (header, search) */
  --pos-v5-bg-strong:        #1A1A1A;  /* Inversé (pour table head ticket) */
  --pos-v5-bg-receipt:       #FCF9F4;  /* Ticket caisse (+ texture optionnelle) */

  /* === Borders === */
  --pos-v5-border:           #EEE6D9;  /* Bordure standard warm */
  --pos-v5-border-strong:    #D9C9B8;  /* Bordure renforcée */
  --pos-v5-border-emphasis:  #E8001C;  /* Bordure focus rouge */

  /* === Texte (contraste AA renforcé) === */
  --pos-v5-ink:              #1A1A1A;  /* Texte primaire — 15.8:1 sur bg */
  --pos-v5-ink-soft:         #5A5A5A;  /* Texte secondaire — 7.4:1 */
  --pos-v5-ink-muted:        #8A8278;  /* Texte tertiaire (meta) */
  --pos-v5-ink-on-red:       #FFFFFF;
  --pos-v5-ink-on-dark:      #FFFFFF;

  /* === Sémantique === */
  --pos-v5-success:          #1B8A3A;  /* Encaissé, prêt */
  --pos-v5-success-soft:     #ECFDF5;  /* Background success */
  --pos-v5-warning:          #B8730B;  /* Attention */
  --pos-v5-warning-soft:     #FFFBEB;
  --pos-v5-danger:           #C21E2F;  /* Annulation, blocker */
  --pos-v5-danger-soft:      #FEF2F2;
  --pos-v5-info:             #2563EB;  /* Focus ring */

  /* === Typographie (Inter, échelle dense opérateur) === */
  --pos-v5-font-sans:        'Inter', system-ui, -apple-system, sans-serif;
  --pos-v5-font-mono:        'JetBrains Mono', ui-monospace, monospace; /* totaux fiscaux */

  --pos-v5-text-eyebrow:     11px;   /* labels meta UPPER */
  --pos-v5-text-caption:     12px;   /* meta secondaire */
  --pos-v5-text-body:        14px;   /* corps standard */
  --pos-v5-text-body-lg:     15px;   /* listes, prix tile */
  --pos-v5-text-h6:          16px;   /* titres carte */
  --pos-v5-text-h5:          18px;   /* titres section */
  --pos-v5-text-h4:          22px;   /* titre cart "Commande en cours" */
  --pos-v5-text-h3:          26px;   /* hero panneau */
  --pos-v5-text-display:     34px;   /* total final, numéros queue */

  --pos-v5-letter-caps:      0.08em; /* labels UPPER */
  --pos-v5-letter-tight:     -0.01em;

  /* === Spacing (4px grid) === */
  --pos-v5-space-1:          4px;
  --pos-v5-space-2:          8px;
  --pos-v5-space-3:          12px;
  --pos-v5-space-4:          16px;
  --pos-v5-space-5:          20px;
  --pos-v5-space-6:          24px;
  --pos-v5-space-8:          32px;
  --pos-v5-space-10:         40px;

  /* === Radius (warm generous) === */
  --pos-v5-radius-sm:        8px;
  --pos-v5-radius-md:        12px;   /* default cards / inputs */
  --pos-v5-radius-lg:        16px;   /* tiles produits, cart panel */
  --pos-v5-radius-xl:        20px;   /* hero panneaux */
  --pos-v5-radius-pill:      999px;

  /* === Ombres warm (jamais grises froides) === */
  --pos-v5-shadow-sm:        0 1px 2px rgba(26, 26, 26, 0.04);
  --pos-v5-shadow-md:        0 4px 14px rgba(26, 26, 26, 0.06);
  --pos-v5-shadow-lift:      0 10px 28px rgba(26, 26, 26, 0.10);
  --pos-v5-shadow-modal:     0 24px 48px rgba(26, 26, 26, 0.20);
  --pos-v5-shadow-cta:       0 8px 20px rgba(232, 0, 28, 0.24);
  --pos-v5-shadow-success:   0 8px 20px rgba(27, 138, 58, 0.20);

  /* === Motion === */
  --pos-v5-duration-fast:    140ms;
  --pos-v5-duration-base:    220ms;
  --pos-v5-duration-slow:    360ms;
  --pos-v5-ease-standard:    cubic-bezier(0.4, 0, 0.2, 1);
  --pos-v5-ease-bounce:      cubic-bezier(0.34, 1.56, 0.64, 1);

  /* === Tactile (caissier sur écran 13-22") === */
  --pos-v5-tap-min:          40px;   /* WCAG AA */
  --pos-v5-tap-comfort:      48px;   /* défaut CTA */
  --pos-v5-tap-large:        56px;   /* paiement, confirm */

  /* === Focus ring (WCAG 2.4.7) === */
  --pos-v5-focus-width:      3px;
  --pos-v5-focus-color:      #2563EB;
  --pos-v5-focus-offset:     2px;
}

/* Reduced motion (héritage kiosk) */
@media (prefers-reduced-motion: reduce) {
  :root {
    --pos-v5-duration-fast:  1ms;
    --pos-v5-duration-base:  1ms;
    --pos-v5-duration-slow:  1ms;
  }
}
```

### 1.3 Anti-patterns interdits

1. ❌ Aucune couleur hardcodée hors `pos-v5-tokens.css` (sauf 1 exception : print receipt qui exige noir/blanc fiscal)
2. ❌ Aucun gradient sombre noir-bordeaux qui imite un dashboard SaaS — le rouge brand est un **accent**, pas un fond
3. ❌ Aucune ombre grise froide (`rgba(0,0,0,...)` interdit, utiliser warm `rgba(26,26,26,...)`)
4. ❌ Aucun `text-[#XXXXXX]` Tailwind dans les nouveaux templates — passer par `pos-v5-*` classes
5. ❌ Aucune emoji décoratif sur les boutons opérateur (cf. wizard "vous composez" — la chaleur vient du langage et du visuel, pas d'emojis)
6. ❌ Aucun changement de polices Tailwind globales — `font-rubik` reste accessible mais les composants V5 demandent explicitement Inter

### 1.4 Layer ordering CSS (chargement)

```
resources/css/app.css                       # Tailwind base + db-* legacy admin
resources/css/foundations/cv1-tokens.css    # CV1 sémantique (existant)
resources/css/foundations/pos-v5-tokens.css # NOUVEAU — POS warm tokens
resources/css/pos-v5.css                    # NOUVEAU — composants POS V5
resources/css/pos-v4.css                    # SUPPRIMÉ ou renommé deprecated (migration progressive)
resources/css/pos-a11y.css                  # CONSERVÉ — surcouche a11y existante
```

---

## 2. Inventaire complet des surfaces POS (page par page)

### 2.1 Carte des 9 surfaces à refondre + 3 surfaces gelées

```
ADMIN POS — toutes les surfaces
│
├── 🟢 À REFONDRE (9 surfaces produit)
│   │
│   ├── 1. Caisse principale ────────── /admin/pos
│   │       └─ PosComponent.vue
│   │           ├─ Operator bar (header + status + actions)
│   │           ├─ Search bar
│   │           ├─ Categories strip (pills horizontales)
│   │           ├─ Products grid (3 colonnes XL)
│   │           ├─ Cart panel droite (380px)
│   │           ├─ Add Customer modal (inline)
│   │           └─ Kiosk Cash Panel drawer (inline)
│   │
│   ├── 2. Item modal léger (variations simples)
│   │       └─ ItemComponent.vue
│   │           ├─ Tiles grid produits
│   │           └─ #item-variation-modal (pour items SANS wizard)
│   │
│   ├── 3. Payment modal ────────────── ouverture depuis cart
│   │       └─ PaymentComponent.vue
│   │           ├─ Method tabs (cash | card)
│   │           ├─ Cash input + change due
│   │           ├─ Card input (last 4)
│   │           └─ Numpad
│   │
│   ├── 4. Receipt preview modal ─── après paiement
│   │       └─ ReceiptComponent.vue (POS post-paiement)
│   │       └─ PosOrderReceiptComponent.vue (réimpression depuis historique)
│   │
│   ├── 5. Parked Orders drawer ──── from operator bar
│   │       └─ ParkedOrdersComponent.vue
│   │
│   ├── 6. Tracker kanban ─────────── /admin/pos-orders-tracker
│   │       └─ PosOrdersTrackerComponent.vue
│   │           ├─ Header (search + source tabs + nav)
│   │           ├─ Kanban 4 colonnes (accept | preparing | prepared | delivered)
│   │           └─ Cancel-with-reason dialog (inline)
│   │
│   ├── 7. Floorplan ──────────────── /admin/pos/floorplan
│   │       └─ FloorplanComponent.vue
│   │
│   ├── 8. Pos Orders Liste ──────── /admin/pos-orders
│   │       └─ PosOrderListComponent.vue
│   │
│   └── 9. Pos Orders Detail ─────── /admin/pos-orders/show/:id
│           └─ PosOrderShowComponent.vue
│
└── 🔴 GELÉ (NE PAS TOUCHER — déjà parfait)
    ├── KioskWizardComponent.vue (le wizard partagé POS↔kiosk)
    ├── KioskStep*Component.vue (toutes les étapes)
    ├── kiosk-wizard.css
    └── kiosk/tokens.css (palette + typography)
```

### 2.2 Inventaire des composants Vue impactés

| # | Surface | Fichier | Lignes | Action |
|---|---|---|---|---|
| 1 | Caisse principale | `resources/js/components/admin/pos/PosComponent.vue` | 2946 | **Refonte template + style scoped** |
| 2 | Item tile + modal léger | `resources/js/components/admin/pos/ItemComponent.vue` | 1492 | **Refonte template + style scoped** |
| 3 | Payment | `resources/js/components/admin/pos/PaymentComponent.vue` | ~466 | **Refonte template + style scoped** |
| 4 | Receipt POS | `resources/js/components/admin/pos/ReceiptComponent.vue` | ~538 | **Refonte chrome modal (preview), garder paper print intact (fiscal)** |
| 5 | Receipt historique | `resources/js/components/admin/posOrders/PosOrderReceiptComponent.vue` | ~210 | Idem #4 |
| 6 | Parked drawer | `resources/js/components/admin/pos/ParkedOrdersComponent.vue` | ~424 | **Refonte CSS scoped + chips warm** |
| 7 | Tracker kanban | `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue` | ~1414 | **Refonte CSS scoped (template déjà bien structuré)** |
| 8 | Floorplan | `resources/js/components/admin/pos/FloorplanComponent.vue` | ~285 | **Refonte template + ajout scoped** |
| 9 | Orders liste | `resources/js/components/admin/posOrders/PosOrderListComponent.vue` | ~387 | **Pass design léger (table + filters)** |
| 10 | Orders detail | `resources/js/components/admin/posOrders/PosOrderShowComponent.vue` | ~474 | **Pass design (badges + dropdowns)** |
| 11 | Add customer modal | inline dans PosComponent.vue | — | Couvert par #1 |
| 12 | Kiosk Cash Panel | inline dans PosComponent.vue | — | Couvert par #1 |
| 13 | Skeleton grid | `resources/js/components/admin/pos/SkeletonGrid.vue` | — | Aligner shimmer warm |
| 14 | Customer address create | `resources/js/components/admin/pos/CreateCustomerAddressComponent.vue` | — | Pass design léger |

### 2.3 Composants primitives à créer (réutilisables, pos-v5)

| Primitive | Path | Purpose |
|---|---|---|
| `PosV5Button` | `resources/js/components/admin/pos/v5/PosV5Button.vue` | Bouton unifié (variant: primary/secondary/ghost/danger/success, size: sm/md/lg) |
| `PosV5Card` | `resources/js/components/admin/pos/v5/PosV5Card.vue` | Conteneur warm avec bordure / radius / shadow standard |
| `PosV5Pill` | `resources/js/components/admin/pos/v5/PosV5Pill.vue` | Chip / badge (variant: default/success/warning/danger/info) |
| `PosV5StatChip` | `resources/js/components/admin/pos/v5/PosV5StatChip.vue` | Icône + label + valeur compacte (operator bar) |
| `PosV5Numpad` | `resources/js/components/admin/pos/v5/PosV5Numpad.vue` | Pavé numérique 4×4 partagé (paiement + override prix futur) |
| `PosV5TotalRow` | `resources/js/components/admin/pos/v5/PosV5TotalRow.vue` | Ligne sub/discount/total avec tabular nums |
| `PosV5OperatorBar` | `resources/js/components/admin/pos/v5/PosV5OperatorBar.vue` | Bandeau opérateur partagé caisse + tracker |

---

## 3. Refonte page par page (spec détaillée)

### 3.1 SURFACE #1 — Caisse principale (`PosComponent.vue`)

#### 3.1.1 Layout cible

```
┌──────────────────────────────────────────────────────────────────────┐
│  POS-V5 SHELL — bg crème #FFFBF5 — full height                       │
├────────────────────────────────────────────────┬─────────────────────┤
│                                                │                     │
│  ┌─────────────────────────────────────────┐  │  ┌──────────────┐  │
│  │ OPERATOR BAR (warm, 88px max)           │  │  │ CART PANEL   │  │
│  │ ┌──┐  Caissier · Ticket #LIVE  [chips]  │  │  │              │  │
│  │ │FK│  Caisse #1 · Filiale Paris 11e     │  │  │ TICKET       │  │
│  │ └──┘  ───────────────────────────────── │  │  │ #en cours    │  │
│  │       [📋 Suivi 3] [🖥️ Borne 1] [🔓 No]│  │  │              │  │
│  └─────────────────────────────────────────┘  │  │ Client ⌄  +  │  │
│                                                │  │              │  │
│  ┌─────────────────────────────────────────┐  │  │ ⏸ Mettre au  │  │
│  │ 🔍 Rechercher un article du menu...   ✕ │  │  │   chaud  •   │  │
│  └─────────────────────────────────────────┘  │  │ 📦 Tickets   │  │
│                                                │  │   en attente │  │
│  ┌─ Catégories ──────────────────────────────┐│  │   (3)        │  │
│  │ ⊙   ⊙   ⊙   ⊙   ⊙   ⊙   ⊙   ⊙   ⊙       ││  │              │  │
│  │ Tout Tac Bur Bow Sand Sal Des Acc Bois   ││  │ ── Type ──   │  │
│  └───────────────────────────────────────────┘│  │ ◉ Emporter   │  │
│                                                │  │ ○ Livraison  │  │
│  ┌─ Produits (grille 3 colonnes) ───────────┐ │  │              │  │
│  │ ┌─────┐  ┌─────┐  ┌─────┐                │ │  │ ┌──────────┐ │  │
│  │ │ 📷  │  │ 📷  │  │ 📷  │                │ │  │ │  Article │ │  │
│  │ │     │  │     │  │     │                │ │  │ │   ...    │ │  │
│  │ │Tacos│  │Bigb │  │Beef │                │ │  │ └──────────┘ │  │
│  │ │ M   │  │     │  │     │                │ │  │              │  │
│  │ │6,50€│  │8,90€│  │7,50€│                │ │  │ Sous-total   │  │
│  │ └─────┘  └─────┘  └─────┘                │ │  │ Remise       │  │
│  │                                            │ │  │ ┌──────────┐ │  │
│  │ ┌─────┐  ┌─────┐  ┌─────┐                │ │  │ │ TOTAL    │ │  │
│  │ │ 📷  │  │ 📷  │  │ 📷  │                │ │  │ │   8,90€  │ │  │
│  │ │     │  │     │  │     │                │ │  │ └──────────┘ │  │
│  │ └─────┘  └─────┘  └─────┘                │ │  │              │  │
│  └────────────────────────────────────────────┘ │  │ ┌──────────┐ │  │
│                                                  │  │ │ ENCAISSER│ │  │
└──────────────────────────────────────────────────┴──└──────────────┘──┘
```

#### 3.1.2 Refonte de l'Operator Bar (cible)

**Avant** : bandeau gradient noir-bordeaux 112px min-height, couleurs crues, chips blancs translucides → écrasant, 25% du viewport mangé pour des chips info.

**Après** : bandeau **warm 80px** sur fond crème, sans gradient, avec texture subtile + bordure rouge gauche en accent ; titre opérateur en hero non-distractif ; actions à droite groupées dans une "barre d'actions" intégrée.

```html
<!-- POS V5 OPERATOR BAR (cible) -->
<header class="pos-v5-operator-bar" data-pos-v5-operator>
  <div class="pos-v5-operator-bar__brand">
    <div class="pos-v5-operator-bar__crown">👑</div>  <!-- ou img logo FK -->
    <div class="pos-v5-operator-bar__identity">
      <p class="pos-v5-operator-bar__eyebrow">CAISSE · {{ branchName }}</p>
      <h1 class="pos-v5-operator-bar__title">Bonjour {{ operatorFirstName }}</h1>
      <div class="pos-v5-operator-bar__live">
        <PosV5StatChip icon="🟢" :value="`Ticket en cours`" tone="live" />
        <PosV5StatChip :value="`${totalItems} articles`" tone="neutral" />
        <PosV5StatChip v-if="now" :value="formatTime(now)" tone="ghost" />
      </div>
    </div>
  </div>
  <nav class="pos-v5-operator-bar__actions">
    <PosV5Button v-if="kioskCashOrders > 0" variant="kiosk-cash" :badge="kioskCashOrders" icon="🖥️">
      <span class="hidden xl:inline">Borne en attente</span>
    </PosV5Button>
    <router-link :to="trackerRoute" v-slot="{ navigate }">
      <PosV5Button variant="tracker" :tone="readyCount > 0 ? 'ready' : 'neutral'" :badge="activeCount" icon="📋" @click="navigate">
        Suivi commandes
      </PosV5Button>
    </router-link>
    <PosV5Button variant="ghost" icon="🖥️" :as="'router-link'" :to="customerScreenRoute" target="_blank">
      Écran client
    </PosV5Button>
    <PosV5Button variant="ghost" icon="🪑" :as="'router-link'" :to="floorplanRoute">
      Plan de salle
    </PosV5Button>
    <PosV5Button variant="ghost" icon="💵" :disabled="noSaleBusy" @click="triggerNoSale">
      Ouvrir tiroir
    </PosV5Button>
  </nav>
</header>
```

**CSS clé** :
```css
.pos-v5-operator-bar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: var(--pos-v5-space-6);
  padding: var(--pos-v5-space-3) var(--pos-v5-space-5);
  margin-bottom: var(--pos-v5-space-3);
  background: var(--pos-v5-bg-panel);
  border: 1px solid var(--pos-v5-border);
  border-left: 4px solid var(--pos-v5-brand-red);
  border-radius: var(--pos-v5-radius-lg);
  box-shadow: var(--pos-v5-shadow-md);
  /* PAS de gradient, PAS de fond noir */
}
.pos-v5-operator-bar__title {
  font-family: var(--pos-v5-font-sans);
  font-size: var(--pos-v5-text-h4);
  font-weight: 800;
  letter-spacing: var(--pos-v5-letter-tight);
  color: var(--pos-v5-ink);
  margin: 2px 0;
}
.pos-v5-operator-bar__eyebrow {
  font-size: var(--pos-v5-text-eyebrow);
  font-weight: 700;
  letter-spacing: var(--pos-v5-letter-caps);
  text-transform: uppercase;
  color: var(--pos-v5-ink-soft);
  margin: 0;
}
```

#### 3.1.3 Refonte Search Bar

**Avant** : input avec bouton submit `#B0004D` (rouge bordeaux off-brand), placeholder en `#A0A3BD` froid.

**Après** : champ unique large, icône intégrée à gauche, raccourci clavier visible (`⌘K`), validation par Enter (le bouton submit visible disparait — moins d'encombrement, plus de pro look).

```html
<form class="pos-v5-search" role="search" @submit.prevent="search">
  <i class="pos-v5-search__icon" aria-hidden="true">🔍</i>
  <input
    type="search"
    :value="props.search.name"
    @input="onSearchInput"
    placeholder="Rechercher un article, un client, une commande..."
    class="pos-v5-search__input"
    :aria-label="$t('label.search_by_menu_item')"
  />
  <kbd class="pos-v5-search__kbd">⌘K</kbd>
  <button v-if="props.search.name" type="button" class="pos-v5-search__clear" @click="resetName">
    ✕
  </button>
</form>
```

#### 3.1.4 Refonte Categories Strip

**Avant** : pills 96px width, photo 24px misérable, texte 11px serré, hover rose pâle SaaS.

**Après** : pills 112px width, **photo ronde 56px** (comme le wizard), texte 13px medium, sélection avec **anneau rouge brand 2px + ring 4px translucent** (comme stepper du wizard).

```html
<nav class="pos-v5-category-strip" role="tablist" :aria-label="$t('label.categories')">
  <button
    v-for="(category, index) in categories"
    :key="category.id"
    type="button"
    role="tab"
    :aria-selected="isCurrent(category)"
    :class="['pos-v5-category', { 'is-active': isCurrent(category) }]"
    @click="setCategory(category.id)"
  >
    <span class="pos-v5-category__visual">
      <img v-if="category.thumb" :src="category.thumb" :alt="category.name" loading="lazy">
      <span v-else class="pos-v5-category__visual-fallback">{{ category.name.charAt(0) }}</span>
    </span>
    <span class="pos-v5-category__label">{{ category.name }}</span>
    <span class="pos-v5-category__count" v-if="category.items_count">{{ category.items_count }}</span>
  </button>
</nav>
```

```css
.pos-v5-category {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--pos-v5-space-2);
  width: 112px;
  flex-shrink: 0;
  padding: var(--pos-v5-space-3) var(--pos-v5-space-2);
  background: transparent;
  border: 0;
  border-radius: var(--pos-v5-radius-lg);
  cursor: pointer;
  transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.pos-v5-category__visual {
  position: relative;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  overflow: hidden;
  background: var(--pos-v5-bg-subtle);
  border: 2px solid transparent;
  transition: all var(--pos-v5-duration-base) var(--pos-v5-ease-bounce);
}
.pos-v5-category__visual img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.pos-v5-category__label {
  font-size: var(--pos-v5-text-caption);
  font-weight: 600;
  color: var(--pos-v5-ink-soft);
  text-align: center;
  line-height: 1.2;
}
.pos-v5-category:hover .pos-v5-category__visual {
  transform: scale(1.04);
}
.pos-v5-category.is-active .pos-v5-category__visual {
  border-color: var(--pos-v5-brand-red);
  box-shadow: 0 0 0 4px var(--pos-v5-brand-red-soft);
}
.pos-v5-category.is-active .pos-v5-category__label {
  color: var(--pos-v5-brand-red);
  font-weight: 800;
}
```

#### 3.1.5 Refonte Products Grid + Item Tile

**Avant** : tiles 90px min-height, photo en background invisible, juste texte centré + icône bag, prix petit.

**Après** : **tiles avec photo hero 96px en haut** (le caissier reconnait visuellement, comme le client kiosk), nom du produit dessous, prix bold rouge en bas. Hover = lift + ring rouge soft. Disponibilité visible (badge "Indisponible" overlay rouge 80% transparent).

```html
<div class="pos-v5-grid" data-pos-v5-products>
  <button
    v-for="item in items"
    :key="item.id"
    type="button"
    :class="['pos-v5-tile', { 'is-unavailable': isUnavailable(item) }]"
    :aria-disabled="isUnavailable(item)"
    :disabled="isUnavailable(item)"
    @click.prevent="addItem(item)"
  >
    <div class="pos-v5-tile__visual">
      <img v-if="item.thumb" :src="item.thumb" :alt="item.name" loading="lazy">
      <span v-else class="pos-v5-tile__visual-fallback" aria-hidden="true">🍔</span>
      <span v-if="item.is_chef_pick" class="pos-v5-tile__badge pos-v5-tile__badge--chef">★ Coup de cœur</span>
      <span v-if="isUnavailable(item)" class="pos-v5-tile__overlay">{{ $t('pos.item_86_d') }}</span>
    </div>
    <div class="pos-v5-tile__body">
      <h3 class="pos-v5-tile__name">{{ item.name }}</h3>
      <div class="pos-v5-tile__foot">
        <span class="pos-v5-tile__price">{{ itemOfferPrice(item) }}</span>
        <span class="pos-v5-tile__add" aria-hidden="true">+</span>
      </div>
    </div>
  </button>
</div>
```

```css
.pos-v5-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(168px, 1fr));
  gap: var(--pos-v5-space-3);
}
.pos-v5-tile {
  display: flex;
  flex-direction: column;
  background: var(--pos-v5-bg-panel);
  border: 1px solid var(--pos-v5-border);
  border-radius: var(--pos-v5-radius-lg);
  overflow: hidden;
  cursor: pointer;
  transition: transform var(--pos-v5-duration-base) var(--pos-v5-ease-bounce),
              box-shadow var(--pos-v5-duration-base) var(--pos-v5-ease-standard),
              border-color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
  text-align: left;
  appearance: none;
  font-family: inherit;
  color: inherit;
}
.pos-v5-tile:hover:not(.is-unavailable) {
  transform: translateY(-2px);
  border-color: var(--pos-v5-brand-red);
  box-shadow: var(--pos-v5-shadow-lift);
}
.pos-v5-tile__visual {
  position: relative;
  aspect-ratio: 4 / 3;
  background: var(--pos-v5-bg-subtle);
  overflow: hidden;
}
.pos-v5-tile__visual img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform var(--pos-v5-duration-slow) var(--pos-v5-ease-standard);
}
.pos-v5-tile:hover .pos-v5-tile__visual img {
  transform: scale(1.06);
}
.pos-v5-tile__badge--chef {
  position: absolute;
  top: var(--pos-v5-space-2);
  left: var(--pos-v5-space-2);
  padding: 2px 8px;
  border-radius: var(--pos-v5-radius-pill);
  background: var(--pos-v5-warning);
  color: white;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: var(--pos-v5-letter-caps);
  text-transform: uppercase;
  box-shadow: var(--pos-v5-shadow-sm);
}
.pos-v5-tile__overlay {
  position: absolute;
  inset: 0;
  background: rgba(194, 30, 47, 0.86);
  color: white;
  font-size: var(--pos-v5-text-h6);
  font-weight: 800;
  letter-spacing: var(--pos-v5-letter-caps);
  text-transform: uppercase;
  display: flex;
  align-items: center;
  justify-content: center;
  backdrop-filter: blur(2px);
}
.pos-v5-tile__body {
  padding: var(--pos-v5-space-3);
  display: flex;
  flex-direction: column;
  gap: var(--pos-v5-space-2);
  flex: 1;
}
.pos-v5-tile__name {
  font-size: var(--pos-v5-text-body);
  font-weight: 700;
  line-height: 1.25;
  color: var(--pos-v5-ink);
  margin: 0;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.pos-v5-tile__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: auto;
}
.pos-v5-tile__price {
  font-family: var(--pos-v5-font-sans);
  font-feature-settings: "tnum";
  font-size: var(--pos-v5-text-body-lg);
  font-weight: 800;
  color: var(--pos-v5-brand-red);
}
.pos-v5-tile__add {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--pos-v5-bg-subtle);
  color: var(--pos-v5-ink);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  font-weight: 700;
  transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
              color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.pos-v5-tile:hover .pos-v5-tile__add {
  background: var(--pos-v5-brand-red);
  color: white;
}
```

#### 3.1.6 Refonte Cart Panel

**Avant** : 380px panel droit, header "TICKET CAISSE / Commande en cours" avec gradient rose pâle, type commande dans une carte gris clair, table cart avec head noir, actions cancel rouge `#FB4E4E` + order vert `#1AB759` côte à côte.

**Après** : panneau **"Ticket vivant"** qui s'inspire d'un vrai ticket de caisse — bord soft texturé crème, header avec icône reçu + numéro live, segments verticaux clairs (client → type → articles → totaux → paiement), action principale **CTA "Encaisser" pleine largeur 56px** avec ombre rouge soft (comme le wizard "SUIVANT"), action secondaire "Annuler" en lien text discret.

```html
<aside class="pos-v5-cart" id="pos-cart" role="region" :aria-label="$t('a11y.cart_region')">
  <!-- Header -->
  <header class="pos-v5-cart__head">
    <div class="pos-v5-cart__head-titles">
      <p class="pos-v5-cart__eyebrow">Ticket caisse</p>
      <h2 class="pos-v5-cart__title">
        Commande
        <span v-if="liveOrderNumber" class="pos-v5-cart__num">#{{ liveOrderNumber }}</span>
      </h2>
    </div>
    <span class="pos-v5-cart__count" :data-count="totalItems">{{ totalItems }} articles</span>
  </header>

  <!-- Customer + actions -->
  <section class="pos-v5-cart__customer">
    <vue-select v-model="form.customer_id" :options="customers" class="pos-v5-cart__customer-select" />
    <PosV5Button variant="ghost" icon="+" :aria-label="$t('button.add_customer')" @click="addCustomers" />
  </section>

  <!-- Park / Parked actions -->
  <section class="pos-v5-cart__shortcuts">
    <PosV5Button variant="secondary" icon="⏸" :disabled="parkingInFlight" @click="promptParkOrder">
      Mettre au chaud
    </PosV5Button>
    <PosV5Button variant="ghost-counter" icon="📦" :badge="parkedOrdersCount" @click="openParkedOrders">
      Tickets en attente
    </PosV5Button>
  </section>

  <!-- Order type radio group -->
  <fieldset class="pos-v5-cart__type">
    <legend class="pos-v5-cart__type-legend">{{ $t('label.select_order_type') }}</legend>
    <div class="pos-v5-cart__type-segments" role="radiogroup">
      <label v-if="dineInEnabled" class="pos-v5-cart__type-segment">
        <input type="radio" name="orderType" :value="orderTypeEnums.dineIn" v-model="form.order_type" @change="dineInOrder">
        <span>🍽️ Sur place</span>
      </label>
      <label class="pos-v5-cart__type-segment">
        <input type="radio" name="orderType" :value="orderTypeEnums.takeAway" v-model="form.order_type" @change="takeAwayOrder">
        <span>🥡 À emporter</span>
      </label>
      <label class="pos-v5-cart__type-segment">
        <input type="radio" name="orderType" :value="orderTypeEnums.delivery" v-model="form.order_type" @change="deliveryOrder">
        <span>🛵 Livraison</span>
      </label>
    </div>
  </fieldset>

  <!-- Cart items list (table → cards verticales pour densité optimale) -->
  <section class="pos-v5-cart__items">
    <article v-for="(cart, index) in carts" :key="index" class="pos-v5-cart-item">
      <button class="pos-v5-cart-item__visual" @click="editCartLine(index)" :aria-label="$t('button.edit')">
        <img v-if="cart.image" :src="cart.image" :alt="cart.name">
        <span v-else>🍴</span>
      </button>
      <div class="pos-v5-cart-item__body">
        <h4 class="pos-v5-cart-item__name">
          {{ cart.name }}
          <button class="pos-v5-cart-item__edit" @click="editCartLine(index)" :aria-label="$t('button.edit')">✎</button>
        </h4>
        <p v-if="cart.cart_display" class="pos-v5-cart-item__detail">{{ cart.cart_display }}</p>
        <!-- ... bundled menu ... -->
      </div>
      <div class="pos-v5-cart-item__qty">
        <PosV5QtyStepper :modelValue="cart.quantity" @decrement="cartQuantityDecrement(index)" @increment="cartQuantityIncrement(index)" />
      </div>
      <div class="pos-v5-cart-item__price">{{ formatMoney(cart.total) }}</div>
    </article>
    <div v-if="carts.length === 0" class="pos-v5-cart__empty">
      <span class="pos-v5-cart__empty-icon" aria-hidden="true">🍽️</span>
      <p>Aucun article. Sélectionnez un produit dans la grille.</p>
    </div>
  </section>

  <!-- Footer totals + actions -->
  <footer class="pos-v5-cart__foot">
    <PosV5DiscountBlock v-if="carts.length > 0" v-model="discount" :reason="discountReason" />

    <PosV5TotalRow label="Sous-total" :value="subtotal" />
    <PosV5TotalRow v-if="posDiscount" label="Remise" :value="posDiscount" tone="muted" sign="-" />
    <PosV5TotalRow v-if="form.delivery_charge" label="Livraison" :value="form.delivery_charge" tone="info" sign="+" />

    <PosV5TotalRow label="Total" :value="grandTotal" tone="hero" />

    <div class="pos-v5-cart__actions">
      <PosV5Button v-if="carts.length > 0" variant="primary-pay" size="lg" icon="💳" @click="orderSubmit" data-testid="pos-v5-pay">
        Encaisser {{ formatMoney(grandTotal) }}
      </PosV5Button>
      <button v-if="carts.length > 0" type="button" class="pos-v5-cart__cancel-link" @click="resetCart">
        ↻ Vider le ticket
      </button>
    </div>
  </footer>
</aside>
```

**Innovation clé** : **le bouton "Encaisser" affiche le montant directement** (comme Stripe Checkout, comme le wizard "SUIVANT" + total chip → ici condensé en 1 CTA). Le caissier sait toujours combien il valide.

#### 3.1.7 Refonte Add Customer modal (inline)

**Avant** : modal classic `.modal` avec form inline, label/input/select empilés sans hiérarchie tactile.

**Après** : modal V5 avec header rouge brand soft, form en colonnes adaptatives, bouton "Créer le client" plein largeur primary.

#### 3.1.8 Refonte Kiosk Cash Panel (inline)

**Avant** : drawer right 380px avec liste de cartes "kiosk-cash-order-card" border-left rouge épaisse, header gris uppercase.

**Après** : drawer V5 cohérent avec ParkedOrders + ajout d'un état "EN ATTENTE D'ENCAISSEMENT" en pill orange chaleureux, ticket count plus visible.

#### 3.1.9 Spec finale dimensions

| Élément | Avant | Après |
|---|---|---|
| Operator bar height | 112px | **80px** (gain 32px viewport) |
| Search bar | 38px | **44px** |
| Categories strip pill | 76px / w-24 | **96px / w-28** + photo 56px ronde |
| Item tile | 90-112px min, photo 0 | **220-260px** avec photo aspect 4/3 |
| Cart panel width | 290-330px | **360-400px** (lecture confort) |
| Cart action button | 36px | **56px primary, 32px secondary lien** |

---

### 3.2 SURFACE #2 — Item modal léger (`ItemComponent.vue` `#item-variation-modal`)

**Contexte** : ce modal est utilisé pour les produits **sans wizard** (ex: une boisson, un dessert simple avec juste 1 attribut "Taille"). Le wizard prend le relais quand `item.itemAttributes` complexes existent.

**Avant** : modal "ff-modal" `pos-v4-item-wizard-modal` 820px max, header avec image 72px + titre + caution + prix, body avec quantité stepper + attributs (radios) + extras (steppers) + addons swiper + textarea instruction, footer sticky avec CTA "Ajouter au panier - X €".

**Après** : version "compacte mais premium" — la **structure reste IDENTIQUE** (logique métier), mais la couche visuelle adopte les tokens V5 + le langage warm du wizard :

| Aspect | Refonte |
|---|---|
| Modal chrome | Bordure radius `--pos-v5-radius-xl`, shadow `--pos-v5-shadow-modal`, header gradient subtil `linear-gradient(180deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-panel))` |
| Header item | Image **ronde 88px** (comme wizard composition chips), titre Inter Bold 22px, prix monospace tabular nums |
| Quantity stepper | Composant `PosV5QtyStepper` (taille L 48px) |
| Attribute card | Bordure warm `--pos-v5-border`, header avec badge "Min X / Max Y" en pill `--pos-v5-bg-subtle` |
| Variation row | Selected state = **bordure rouge brand 2px + bg `--pos-v5-brand-red-soft`** (mirror exact du wizard) |
| Extras chips | Cards horizontales avec stepper +/- intégré |
| Addons swiper | Maintenu, restyle cards 220px avec photo + variations + prix |
| Footer CTA | Bouton **"Ajouter au panier — 8,90 €"** plein largeur 56px, ombre rouge soft |

**Bonus UX** : ajouter un raccourci `Esc` pour fermer + `Enter` pour valider, déjà présents nativement avec les modals mais à confirmer.

---

### 3.3 SURFACE #3 — Payment modal (`PaymentComponent.vue`)

**Avant** : modal 428px max-width, header `pb-3 border-b`, total card gris pâle, payment methods 2 boutons en rangée (cash + card), input cash + change due en vert, numpad 4×4 grid avec keys gris pâle, CTA "Confirmer et imprimer" pill.

**Après** : refonte **éditoriale "moment de paiement"** — le moment clé du flow.

```
┌────────────────────────────────────────────────────┐
│  💳 Encaisser                                  ✕  │
├────────────────────────────────────────────────────┤
│                                                    │
│  ┌────────────────────────────────────────────┐  │
│  │  À ENCAISSER                                │  │
│  │  ────────────────────────                   │  │
│  │            8,90 €                           │  │  ← display 48px
│  └────────────────────────────────────────────┘  │
│                                                    │
│  Mode de paiement                                  │
│  ┌──────────────┬──────────────┐                  │
│  │   💵 ESPÈCES  │  💳 CARTE TPE│                  │
│  └──────────────┴──────────────┘                  │
│                                                    │
│  ┌────────────────────────────────┐                │
│  │  Reçu : 10,00 €              │                │
│  └────────────────────────────────┘                │
│  ┌────────────────────────────────┐                │
│  │  ✨ Monnaie à rendre  1,10 €  │                │  ← success state
│  └────────────────────────────────┘                │
│                                                    │
│  ┌──────────────────────────────────────────────┐ │
│  │  PosV5Numpad (composant partagé)             │ │
│  │  [1] [2] [3] [⌫]                             │ │
│  │  [4] [5] [6] [⌫]                             │ │
│  │  [7] [8] [9] [C]                             │ │
│  │  [00] [0] [.] [C]                            │ │
│  └──────────────────────────────────────────────┘ │
│                                                    │
│  ┌──────────────────────────────────────────────┐ │
│  │  ✓ Confirmer & Imprimer                      │ │  ← CTA 56px
│  └──────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────┘
```

| Aspect | Refonte |
|---|---|
| Modal | 480px max, radius `--pos-v5-radius-xl`, shadow `--pos-v5-shadow-modal` |
| Total card | Hero **bg `--pos-v5-bg-receipt`** + bordure dashed warm + display 48px monospace tabular |
| Method tabs | Segmented control 2 onglets, sélection = bg rouge brand soft + bordure rouge brand 2px |
| Cash / Card input | Height 56px, font monospace 28px tabular, bordure focus rouge |
| Change due | Carte success vibrante (`--pos-v5-success-soft` + bordure success), texte 24px bold |
| Numpad | Composant `PosV5Numpad` réutilisable (paiement + futurs override prix admin) — keys 56×56px, hover lift, active scale, son optionnel |
| Confirm CTA | Bouton primary 56px, icon ✓ + label, **disabled state** si cash insuffisant (espèces) ou champ vide (carte) |

---

### 3.4 SURFACE #4 — Receipt preview (`ReceiptComponent.vue` + `PosOrderReceiptComponent.vue`)

**Important** : la **partie PAPIER print** (ce qui sort de l'imprimante thermique 80mm) doit rester **identique** (fiscal NF525 + monospace + dashed borders sont des contraintes légales / opérationnelles). Seul le **chrome de la modal preview** change.

**Avant** : modal `#receiptModal`, header avec 4 boutons (Fermer rouge `#FB4E4E`, Imprimer cuisine `#2E2F38`, Imprimer client `#1AB759`), body avec preview ticket dans `print-receipt-client` div.

**Après** : modal V5 avec :
- Header redesigné : titre "Aperçu ticket" + badge order #N + bouton ✕ V5
- Toolbar print groupée : 3 boutons icon-first ("👨‍🍳 Cuisine" "🧾 Client" "📧 Email facultatif"), avec spinner inline pendant impression
- Preview ticket dans cadre **"papier reçu"** (background crème `--pos-v5-bg-receipt`, ombre interne dashed, simulant un vrai ticket)
- Affichage 2 colonnes (cuisine + client) sur écran large pour comparaison rapide
- Bouton "Marquer comme remis" si DELIVERED pas encore enregistré

---

### 3.5 SURFACE #5 — Parked Orders drawer (`ParkedOrdersComponent.vue`)

**Avant** : drawer 380px right slide, header gris uppercase, search inline, cards avec border + 2 boutons (Restore vert / Discard rouge), empty state textuel.

**Après** :

| Aspect | Refonte |
|---|---|
| Overlay | `rgba(26, 26, 26, 0.42)` warm |
| Drawer | Width 420px, bordure radius gauche `--pos-v5-radius-xl`, shadow `--pos-v5-shadow-modal` |
| Header | Eyebrow "📦 EN ATTENTE", titre "Tickets au chaud" + count chip en pill |
| Search | Composant `PosV5SearchInput` partagé |
| Card | Hover lift, bordure ronde, **icône clock heure depuis création + nom client + count items + total monospace** |
| Actions | "Reprendre" primary + "Supprimer" ghost danger lien (pas bouton plein) |
| Empty state | Illustration + microcopy chaleureuse "Aucun ticket en attente. Tous les clients sont servis ! 🎉" |

---

### 3.6 SURFACE #6 — Tracker kanban (`PosOrdersTrackerComponent.vue`)

**Avant** : layout déjà bien structuré (kanban 4 colonnes : ACCEPT / PREPARING / PREPARED / DELIVERED), header avec search + source tabs + nav links, cards avec source icon + queue number + customer + items preview + total + actions (view, reprint, cancel, deliver), cancel-with-reason dialog inline.

**Après** : refonte **CSS only** (template déjà excellent), adoption de la palette V5 :

| Aspect | Refonte |
|---|---|
| Shell bg | `--pos-v5-bg-app` crème |
| Header bar | Pattern identique au PosComponent (warm panel + bordure left rouge) |
| Source tabs | Segmented control V5 |
| Kanban column | Header avec icon + label + count chip, body avec cards |
| Card état "ACCEPT" | Bordure left orange warning subtle |
| Card état "PREPARING" | Bordure left blue info |
| Card état "PREPARED" | Bordure left green success **+ pulse subtle (déjà présent, garder)** |
| Card état "DELIVERED" | Bordure left muted, opacity 0.85 |
| Card hover | Lift 2px + shadow lift |
| Action buttons | Icons-first 36px (view/reprint), primary 36px green pour "Marquer remis", ghost danger pour cancel |
| Cancel dialog | Modal V5 — header rouge soft, textarea autofocus, CTA "Annuler la commande" danger primary |
| Realtime warn banner | Pill warning fixed top center, slide-in motion |
| Empty state column | Illustration + label par colonne |

---

### 3.7 SURFACE #7 — Floorplan (`FloorplanComponent.vue`)

**Avant** : `db-card` admin standard, header avec "Plan de salle / X tables", grille `floorplan-grid` avec cards par table, status colors via `cardClass` (free=green, occupied=red, reserved=orange, cleaning=slate).

**Après** : refonte avec V5 :

| Aspect | Refonte |
|---|---|
| Container | Carte V5 panneau warm |
| Header | Pattern identique (eyebrow + title + count + actions) |
| Tables grid | Auto-fill 200px min, gap 16px |
| Table card | Aspect-ratio 1:1.2, photo iconique selon shape (ronde/carrée/rectangulaire) en background opacity 0.04 |
| Free state | bordure success + fond vert très pâle + label "Libre" |
| Occupied state | bordure danger + fond rouge brand soft + ticket #N + clock elapsed + 3 boutons stack inline (Open / Release / Transfer) |
| Reserved state | bordure warning + label "Réservée" + heure |
| Cleaning state | bordure muted + spinner + label "En nettoyage" |
| Hover (free) | scale 1.02 + shadow lift, cursor pointer évident |

---

### 3.8 SURFACE #8 — Pos Orders Liste (`PosOrderListComponent.vue`)

**Avant** : `db-card` admin avec table standard `db-table stripe`, filters (date range, customer, status), pagination.

**Après** : pass design léger (cette page reste admin classique, pas besoin de refonte radicale) :

| Aspect | Refonte |
|---|---|
| Card container | Adopter `--pos-v5-radius-lg` + shadow md |
| Title | Eyebrow "ADMIN · CAISSE" + title h4 |
| Filters | Inputs height 44px V5, bouton "Rechercher" primary V5 |
| Table | Header bg `--pos-v5-bg-subtle`, row hover bg `--pos-v5-brand-red-faint`, status badges en pills V5 (success/warning/danger selon enum) |
| Pagination | Composants existants conservés mais styles touchés via classes V5 si besoin |

---

### 3.9 SURFACE #9 — Pos Orders Detail (`PosOrderShowComponent.vue`)

**Avant** : `db-card p-4`, layout 2 colonnes (détails commande + items + totaux + delivery boy), 3 dropdowns inline (delivery boy / payment status / order status) avec `dropdown-group` pattern.

**Après** : refonte layout pour cohérence V5 :

| Aspect | Refonte |
|---|---|
| Header summary | Carte hero avec order #N en display 26px, status badges V5, meta en chips horizontaux |
| Action toolbar | Sticky top secondary bar avec dropdowns redessinés en `PosV5DropdownButton` |
| Items list | Format identique au cart (cards verticales avec photo ronde + name + variations + price tabular) |
| Totals panel | Composant `PosV5TotalsCard` partagé avec cart |
| Delivery boy section | Card warm avec avatar + nom + tel + bouton "Changer" |
| Print button | CTA secondary V5 icon "🖨" |
| Customer section | Carte avec avatar + nom + tel + email + adresse |

---

## 4. Composants primitives V5 (à créer)

### 4.1 `PosV5Button` — bouton unifié

**Variantes** :
- `primary` — bg rouge brand, color white, ombre cta
- `primary-pay` — variante CTA paiement avec icône + total
- `secondary` — bg subtle warm, color ink, bordure border
- `ghost` — transparent, bordure default, hover bg subtle
- `ghost-counter` — comme ghost mais avec slot badge count
- `danger` — bg danger, color white
- `danger-ghost` — color danger, hover bg danger soft
- `success` — bg success, color white
- `kiosk-cash` — variante spéciale pour bouton "Borne en attente" (rouge brand soft + animation pulse)
- `tracker` — variante spéciale tracker (avec tone neutral/ready)

**Sizes** : `sm` (32px), `md` (40px), `lg` (48px), `xl` (56px)

**Slots** : `icon`, `default` (label), `badge`

**Props** : `as` (button | router-link | a), `to`, `href`, `disabled`, `loading`, `aria-label`

### 4.2 `PosV5Card` — conteneur unifié

**Props** : `tone` (default | brand | success | warning | danger), `padding` (sm | md | lg), `as` (article | section | div)

### 4.3 `PosV5Pill` — chip / badge

**Variantes** : `default`, `brand`, `success`, `warning`, `danger`, `info`, `ghost`, `inverse`
**Sizes** : `xs` (18px), `sm` (22px), `md` (26px), `lg` (32px)

### 4.4 `PosV5StatChip` — opérateur bar

Format : `[icône] [label] [valeur]` ou `[label] [valeur]`. Utilisé pour "Filiale #1", "0 articles", "1 borne cash".

### 4.5 `PosV5Numpad` — pavé numérique

Réutilisé en paiement. API : `@input="(value) => ..."`, `@clear`, `@back`. Layout 4×4 avec touches dédiées pour `00`, `.`, backspace, clear.

### 4.6 `PosV5TotalRow` — ligne de total

Props : `label`, `value`, `tone` (default | hero | muted | info | success), `sign` (+ | - | none).

### 4.7 `PosV5QtyStepper` — stepper +/-

Props : `modelValue`, `min` (default 1), `max` (default ∞), `size` (sm | md | lg), `disabled`. Boutons pill rouge brand sur fond white avec hover bg rouge brand.

### 4.8 `PosV5SearchInput` — champ recherche

Avec icône loupe gauche, kbd shortcut display, clear button droite.

---

## 5. Plan d'exécution (séquencé pour livraisons incrémentales)

### Phase 0 — Foundation (1-2h)
**Files** : `resources/css/foundations/pos-v5-tokens.css` + import dans `resources/css/app.css`

Livrables :
- [x] Tokens CSS complets (palette, type, spacing, radius, motion, shadows)
- [x] Activation media query reduced-motion
- [x] Vérification non-régression : aucun composant ne consomme encore ces tokens (compatible déploiement)

### Phase 1 — Primitives (2-3h)
**Files** : `resources/js/components/admin/pos/v5/Pos5*.vue`

Livrables (dans cet ordre) :
1. `PosV5Button.vue`
2. `PosV5Card.vue`
3. `PosV5Pill.vue`
4. `PosV5StatChip.vue`
5. `PosV5TotalRow.vue`
6. `PosV5QtyStepper.vue`
7. `PosV5SearchInput.vue`
8. `PosV5Numpad.vue`

Tests Vitest sentinel pour chaque (rendering + variants).

### Phase 2 — Surface #1 Caisse principale (4-6h)
**Files** : `PosComponent.vue` (template + style scoped) + `pos-v5.css` (styles partagés)

Livrables :
- Operator bar refondu (warm 80px sans gradient sombre)
- Search bar V5
- Categories strip avec photos rondes 56px
- Products grid avec tiles V5 (photo aspect 4/3)
- Cart panel "ticket vivant"
- Add Customer modal V5
- Kiosk Cash Panel V5 (drawer)
- **Sentinel Vitest existants tous verts** (`PosComponent.spec.js`, `posComponentA11y.spec.js`, `posReceiptBuilder.spec.js`...)

### Phase 3 — Surface #2 Item modal léger + Surface #3 Payment (3-4h)
**Files** : `ItemComponent.vue`, `PaymentComponent.vue`

Livrables :
- Item modal V5 (chrome + header + body + footer)
- Payment modal V5 avec numpad partagé
- Validation visuelle : aucun changement de logique pricing

### Phase 4 — Surface #4 Receipt + Surface #5 Parked (2-3h)
**Files** : `ReceiptComponent.vue`, `PosOrderReceiptComponent.vue`, `ParkedOrdersComponent.vue`

Livrables :
- Receipt modal V5 (chrome only — papier print intact pour fiscal)
- Parked drawer V5

### Phase 5 — Surface #6 Tracker (2-3h)
**Files** : `PosOrdersTrackerComponent.vue`

Livrables :
- CSS scoped V5 complet (template déjà excellent)
- Cancel-with-reason dialog V5

### Phase 6 — Surface #7 Floorplan + #8 List + #9 Detail (3-4h)
**Files** : `FloorplanComponent.vue`, `PosOrderListComponent.vue`, `PosOrderShowComponent.vue`

Livrables :
- Floorplan refondu
- List + filters V5
- Detail layout V5

### Phase 7 — Polish + tests + screenshots (1-2h)
- Vérification screenshots avant/après pour chaque surface
- Tests Vitest existants tous verts
- A11y audit rapide (focus visible, contraste, aria)
- Documentation rapide dans `docs/design/POS_V5_VISUAL_GUIDE.md`

**Total estimé** : 18-27h de travail concentré (peut être réparti sur 2-3 sessions agent).

---

## 6. Garanties & invariants respectés

| Invariant FoodKing | Statut sur ce cycle |
|---|---|
| Backend Pricing SSOT | ✅ Aucune logique prix touchée — uniquement display |
| OrderStatus enum authoritative | ✅ Aucune string status modifiée |
| branch_id isolation | ✅ Aucune query / mutation touchée |
| Dispatch après DB commit | ✅ Aucun event/job touché |
| OrderService / FrontendOrderService symmetry | ✅ Hors scope (CSS/Vue uniquement) |
| Frozen zones | ✅ Aucune frozen file touchée |
| Tests existants | ✅ Vitest sentinels conservés (data-testid intacts) |
| i18n keys | ✅ Toutes les clés existantes conservées + nouvelles ajoutées dans les 5 langues si besoin |
| ARIA / a11y | ✅ Renforcement (focus ring 3px AAA, aria-live, aria-current sur tabs) |

---

## 7. Risques identifiés & mitigations

| Risque | Impact | Mitigation |
|---|---|---|
| Régression visuelle sur certaines combinaisons (RTL, dark mode kiosk-cash AAA) | Moyen | Tests visuels manuels sur les 3 modes (LTR, RTL, AAA), conservation des `data-kiosk-contrast` hooks |
| Cassure d'un sentinel Vitest qui asserte une classe Tailwind | Moyen | Garder les classes Tailwind sémantiques cibles (`pos-v5-*`) en plus, pas en remplacement, jusqu'à confirmation tests |
| Incompatibilité avec `pos-wizard.js` (vanilla JS shim) | Élevé | Le wizard est gelé — `data-pos-drinks-catalog` et autres data-attrs intacts |
| Performance grille 50+ items | Faible | Lazy-loading images + `content-visibility: auto` sur tiles offscreen |
| Print receipt paper change | Critique | **Aucune modification** sur les blocs `#print-receipt-client` / `#print-receipt-kitchen` (zone fiscale) |

---

## 8. Critères d'acceptation (Definition of Done)

Pour chaque surface :
1. ✅ Adoption complète des tokens `pos-v5-*` (zéro hardcoded color hors tokens.css)
2. ✅ Composants primitives V5 utilisés au lieu de patterns ad hoc
3. ✅ Sentinels Vitest existants verts
4. ✅ Pas de cassure i18n (clés existantes intactes + nouvelles déclarées)
5. ✅ ARIA conservé / amélioré (focus visible 3px, aria-live polite/assertive, aria-current sur tabs)
6. ✅ Reduced-motion supporté
7. ✅ Screenshot avant/après archivé dans `reports/screenshots/`
8. ✅ Lint CSS passé (aucune `!important` non justifié, aucune duplication de tokens)

Pour le cycle global :
9. ✅ Build webpack OK (`npm run dev`)
10. ✅ Tests Vitest globaux verts (`npm run test:js`)
11. ✅ Smoke test manuel : flow caissier complet — login → ajout item → wizard → cart → paiement → ticket → tracker
12. ✅ Aucune régression backend (PHPUnit non touché — sécurité par construction)
13. ✅ Documentation `docs/design/POS_V5_VISUAL_GUIDE.md` à jour

---

## 9. Décisions ouvertes (à valider avec l'humain avant Phase 2)

1. **Polices** : adopter Inter pour tout le POS (cohérence avec wizard) OU garder Rubik pour l'admin et Inter pour le POS uniquement ? → **Recommandation** : Inter pour le namespace `.pos-v5-shell` uniquement, Rubik conservé pour le reste de l'admin.
2. **Microcopy "Mettre au chaud" vs "Mettre en attente"** — préférence chaleureuse confirmée ?
3. **Bouton "Encaisser X €" avec montant intégré** — OK pour mettre le total dans le label du CTA principal ?
4. **Tracker bordure left colorée par status** — OK ou préférence pour bordure complète ?
5. **Photos catégories** : aujourd'hui les `category.thumb` sont small — faut-il prévoir un fallback élégant si absent / pixel ?

---

## 10. Annexes

### 10.1 Fichiers créés (NEW)
```
resources/css/foundations/pos-v5-tokens.css                NEW
resources/css/pos-v5.css                                   NEW
resources/js/components/admin/pos/v5/PosV5Button.vue       NEW
resources/js/components/admin/pos/v5/PosV5Card.vue         NEW
resources/js/components/admin/pos/v5/PosV5Pill.vue         NEW
resources/js/components/admin/pos/v5/PosV5StatChip.vue     NEW
resources/js/components/admin/pos/v5/PosV5TotalRow.vue     NEW
resources/js/components/admin/pos/v5/PosV5QtyStepper.vue   NEW
resources/js/components/admin/pos/v5/PosV5SearchInput.vue  NEW
resources/js/components/admin/pos/v5/PosV5Numpad.vue       NEW
docs/design/POS_V5_VISUAL_GUIDE.md                         NEW
```

### 10.2 Fichiers modifiés (MODIFY)
```
resources/css/app.css                                      MODIFY (import token)
resources/js/components/admin/pos/PosComponent.vue         MODIFY (template + style scoped)
resources/js/components/admin/pos/ItemComponent.vue        MODIFY (template + style scoped)
resources/js/components/admin/pos/PaymentComponent.vue     MODIFY (template + style scoped)
resources/js/components/admin/pos/ReceiptComponent.vue     MODIFY (chrome only)
resources/js/components/admin/pos/ParkedOrdersComponent.vue MODIFY (template + style scoped)
resources/js/components/admin/pos/PosOrdersTrackerComponent.vue MODIFY (style scoped only)
resources/js/components/admin/pos/FloorplanComponent.vue   MODIFY (template + style scoped)
resources/js/components/admin/pos/SkeletonGrid.vue         MODIFY (shimmer warm)
resources/js/components/admin/pos/CreateCustomerAddressComponent.vue MODIFY (style)
resources/js/components/admin/posOrders/PosOrderListComponent.vue MODIFY (light pass)
resources/js/components/admin/posOrders/PosOrderShowComponent.vue MODIFY (template + style)
resources/js/components/admin/posOrders/PosOrderReceiptComponent.vue MODIFY (chrome only)
```

### 10.3 Fichiers GELÉS (DO NOT TOUCH)
```
resources/js/components/frontend/kiosk/KioskWizardComponent.vue        FROZEN
resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue     FROZEN
resources/js/components/frontend/kiosk/steps/Kiosk*Component.vue       FROZEN
resources/css/kiosk-wizard.css                                         FROZEN
resources/css/kiosk/tokens.css                                         FROZEN
resources/css/kiosk/tokens-aaa.css                                     FROZEN
resources/css/kiosk/tokens-pmr.css                                     FROZEN
public/js/pos-wizard.js                                                FROZEN (vanilla JS shim)
```

---

**FIN DE L'ULTRA-PLAN.**
