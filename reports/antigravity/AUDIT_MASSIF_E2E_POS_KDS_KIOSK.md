# 🔬 AUDIT MASSIF E2E — SYSTÈME COMPLET POS / KDS / KIOSK

**ID:** audit-massif-e2e-2026-03-11  
**Date:** 11 Mars 2026 | 18h00  
**Agent:** Playwright / E2E verification (QA)  
**Scope:** Chaîne complète — Wizard → Paiement → Ticket → KDS  
**Statut:** 🟡 Audit en cours — 8 bugs confirmés

---

## 📋 TABLE DES MATIÈRES

1. [Les 8 Bugs Confirmés par Couche](#section-1)
2. [Parcours Réel POS — Anatomie Complète](#section-2)
3. [Parcours Réel KDS — Anatomie Complète](#section-3)
4. [Parcours Réel Borne (Kiosk) — Anatomie Complète](#section-4)
5. [Plan de Tests E2E Massifs](#section-5)
6. [Recommandations pour Claude](#section-6)

---

<a name="section-1"></a>
## 1️⃣ LES 8 BUGS CONFIRMÉS PAR COUCHE

### 🔴 COUCHE WIZARD (Frontend POS)

| ID | Bug | Localisation | Impact | Statut Fix |
|----|-----|--------------|--------|------------|
| **BUG-POS-001** | Sauces affichent viandes | `buildSteps()` L280 | Colonne "Sauce" montre Poulet/Kebab | ✅ Fixé |
| **BUG-POS-002** | Menu à €6 au lieu de €3 | `buildSteps()` L342 | Prix doublé (total vs unit) | ✅ Fixé |
| **BUG-POS-004** | Suppléments non calculés | `calculateRunningTotal()` | Total provisoire incorrect | ✅ Fixé |
| **BUG-POS-005** | "Sauce supplémentaire" dans extras | `buildSteps()` L317 | Confusion suppléments | ✅ Fixé |

### 🔴 COUCHE SYNC (Wizard → Vue Modal)

| ID | Bug | Localisation | Impact | Statut Fix |
|----|-----|--------------|--------|------------|
| **SYNC-BUG-001** | Sauces 2ème/3ème lookup fail | `syncAndSubmit()` L1834 | Extra sauces jamais identifiées | ✅ Fixé |
| **SYNC-BUG-002** | Suppléments absents instruction | `syncAndSubmit()` L1860 | KDS/Ticket ne voit pas Cheddar/etc | ✅ Fixé |
| **SYNC-BUG-003** | Garnitures non exclusives | `syncAndSubmit()` L1768 | "Complet" + "Sans Oignon" cochés | ✅ Fixé |

### 🔴 COUCHE TICKET & KDS

| ID | Bug | Localisation | Impact | Statut Fix |
|----|-----|--------------|--------|------------|
| **TICKET-BUG-001** | Menu/Frites non visible | `syncAndSubmit()` L1816 | Cuisine ignore frites à préparer | ✅ Fixé |

**⚠️ Note:** Le fix TICKET-BUG-001 ajoute l'info dans l'instruction, mais **ReceiptComponent.vue** et **Vue KDS** doivent encore parser cette instruction structurée (Phase 2 & 3).

---

<a name="section-2"></a>
## 2️⃣ PARCOURS RÉEL POS — ANATOMIE COMPLÈTE

### 📊 Schéma de Flux

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        PARCOURS POS — CAISSE WEB                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────┐    ┌─────────────┐   │
│  │   LOGIN     │───▶│   DASHBOARD │───▶│   POS VIEW  │───▶│  WIZARD     │   │
│  │  (Sanctum)  │    │  (Vue.js)   │    │  (Grid)     │    │  (Modal)    │   │
│  └─────────────┘    └─────────────┘    └─────────────┘    └─────────────┘   │
│                                    │                         │               │
│                                    │    ┌─────────────┐      │               │
│                                    └───▶│ ITEM CLICK  │◀─────┘               │
│                                         │ (XHR Item)  │                      │
│                                         └─────────────┘                      │
│                                                 │                            │
│  ┌──────────────────────────────────────────────┼────────────────────────┐  │
│  │           WIZARD STEPS (4-7 pages)          │                        │  │
│  ├──────────────────────────────────────────────┼────────────────────────┤  │
│  │                                                │                        │  │
│  │  STEP 1: VIANDE + SAUCE (Tacos)              │                        │  │
│  │  ├── Compteur viandes (1/2, 2/2, etc.)       │                        │  │
│  │  ├── Sélection sauce (1ère gratuite)         │                        │  │
│  │  └── Prix sauces extra: +€0.50               │                        │  │
│  │                                                │                        │  │
│  │  STEP 2: PERSONNALISATION                     │                        │  │
│  │  ├── Garnitures (radio: Complet/Sans Xxx)    │                        │  │
│  │  └── Suppléments (checkbox: Cheddar +€1...)  │                        │  │
│  │                                                │                        │  │
│  │  STEP 3: FORMULE                            │                        │  │
│  │  ├── Menu Complet (Frites+Boisson) +€3       │                        │  │
│  │  ├── Frites Seules +€1.50                    │                        │  │
│  │  └── Non merci                               │                        │  │
│  │                                                │                        │  │
│  │  STEP 4: OPTIONS FRITES (si choisi)          │                        │  │
│  │  ├── Grande portion +€1                    │                        │  │
│  │  ├── Cheddar fondu +€1                     │                        │  │
│  │  └── Sauce frites (1ère gratuite)           │                        │  │
│  │                                                │                        │  │
│  │  STEP 5: BOISSON (si menu choisi)            │                        │  │
│  │  └── Choix boisson incluse                   │                        │  │
│  │                                                │                        │  │
│  │  STEP 6: RÉCAP                              │                        │  │
│  │  ├── Total provisoire                        │                        │  │
│  │  ├── Quantité modifiable                     │                        │  │
│  │  └── Bouton "Ajouter au panier"              │                        │  │
│  │                                                │                        │  │
│  └────────────────────────────────────────────────┼────────────────────────┘  │
│                                                   │                            │
│  ┌────────────────────────────────────────────────┼────────────────────────┐  │
│  │           SYNC & SUBMIT                       │                        │  │
│  │  ┌───────────────────────────────────────────┐│                        │  │
│  │  │ 1. syncAndSubmit() déclenché            ││                        │  │
│  │  │ 2. Quantity → DOM original             ││                        │  │
│  │  │ 3. Sauce 1ère → Radio/Select DOM       ││                        │  │
│  │  │ 4. Extras (garnitures+suppl) → Checkbox ││                        │  │
│  │  │ 5. Addons (menu) → Card click DOM       ││                        │  │
│  │  │ 6. Instruction text → Textarea         ││                        │  │
│  │  │ 7. setTimeout 200ms → Click "Add"       ││                        │  │
│  │  └───────────────────────────────────────────┘│                        │  │
│  │                   │                          │                        │  │
│  │                   ▼                          │                        │  │
│  │  ┌─────────────────────────────────────────┐ │                        │  │
│  │  │   VUE MODAL (original DOM)            │ │                        │  │
│  │  │   Émet event 'item:add'               │ │                        │  │
│  │  │   Vuex store ajoute au panier         │ │                        │  │
│  │  └─────────────────────────────────────────┘ │                        │  │
│  └──────────────────────────────────────────────┼────────────────────────┘  │
│                                                 │                            │
│  ┌──────────────────────────────────────────────┼────────────────────────┐  │
│  │         PANIER & PAIEMENT                   │                        │  │
│  │  ┌─────────────┐   ┌─────────────┐   ┌──────┼──────────┐             │  │
│  │  │  PANIER     │──▶│  PAIEMENT   │──▶│  VALIDATION      │             │  │
│  │  │  (Vuex)     │   │  (Modal)    │   │  (API POST)      │             │  │
│  │  └─────────────┘   └─────────────┘   └──────┼──────────┘             │  │
│  │                                                │                        │  │
│  │  ┌─────────────────────────────────────────────┘                        │  │
│  │  │   POST /admin/pos                                                      │  │
│  │  │   ├── items[] avec variations/extras/addons                           │  │
│  │  │   ├── total, order_type, branch_id                                    │  │
│  │  │   ├── pos_payment_method (CASH/CARD)                                  │  │
│  │  │   ├── pos_received_amount (si CASH)                                 │  │
│  │  │   └── token (numéro commande)                                        │  │
│  │  └─────────────────────────────────────────────────────────────────────┘  │  │
│  └─────────────────────────────────────────────────────────────────────────┘  │
│                                                                                │
│  ┌─────────────────────────────────────────────────────────────────────────┐  │
│  │         ORDER SERVICE (Laravel)                                        │  │
│  │  ┌─────────────┐   ┌─────────────┐   ┌─────────────┐   ┌────────────┐ │  │
│  │  │  Validation │──▶│  Calcul     │──▶│  DB Insert  │──▶│  Events    │ │  │
│  │  │  Request    │   │  Prix (DB)  │   │  orders     │   │  KDS Push  │ │  │
│  │  └─────────────┘   └─────────────┘   └─────────────┘   └────────────┘ │  │
│  │                                                │                        │  │
│  │  Events dispatchés:                          │                        │  │
│  │  ├── SendOrderGotPush (KDS temps réel)       │                        │  │
│  │  ├── SendOrderGotMail (Notification)         │                        │  │
│  │  └── SendOrderGotSms (Client)                │                        │  │
│  └──────────────────────────────────────────────┼────────────────────────┘  │
│                                                 │                            │
│  ┌──────────────────────────────────────────────┼────────────────────────┐  │
│  │         TICKET IMPRESSION                     │                        │  │
│  │  ┌─────────────┐   ┌─────────────┐   ┌────────┼───────┐                │  │
│  │  │  Receipt    │   │  Bluetooth  │   │  Imprimante     │                │  │
│  │  │  Component  │──▶│  ESC/POS    │──▶│  (80mm/58mm)    │                │  │
│  │  │  (Vue)      │   │  (Library)  │   │                 │                │  │
│  │  └─────────────┘   └─────────────┘   └────────┼───────┘                │  │
│  │                                                │                        │  │
│  │  Ticket contient:                              │                        │  │
│  │  ├── Item name + prix                          │                        │  │
│  │  ├── Variations (Sauce)                        │                        │  │
│  │  ├── Extras (garnitures + suppléments)        │                        │  │
│  │  ├── Instruction text (structurée)             │                        │  │
│  │  └── Total, TVA, Token                        │                        │  │
│  └────────────────────────────────────────────────┼────────────────────────┘  │
│                                                  │                            │
└──────────────────────────────────────────────────┼────────────────────────────┘
                                                   │
                                                   ▼
                                          ┌───────────────┐
                                          │      KDS      │
                                          │   (Firebase   │
                                          │    Push)      │
                                          └───────────────┘
```

### 🔍 Points de Contrôle POS

| # | Étape | Test | Attendu | Critique |
|---|-------|------|---------|----------|
| 1 | Wizard ouvert | Cliquer item | Modal wizard s'affiche | ✅ |
| 2 | Viandes | Choisir 2 viandes | Compteur 2/2 vert | ✅ |
| 3 | Sauce | 1ère gratuite | Badge "Gratuit" affiché | ✅ |
| 4 | Supplément | Cocher Cheddar | Total +€1.00 | ✅ (fix BUG-POS-004) |
| 5 | Formule | Choisir Menu | Option visuellement sélectionnée | ✅ (fix UI) |
| 6 | Frites options | Grande + Cheddar | Deux options cochables | ✅ |
| 7 | Instruction | Voir récap | "VIANDES: X, Y. SUPPLÉMENTS: Z. FORMULE:..." | ✅ (fix SYNC/TICKET) |
| 8 | Ajout panier | Click bouton | Item apparaît dans panier Vuex | ✅ |
| 9 | Paiement | Choisir Cash | Input montant reçu actif | ✅ |
| 10 | Validation | Click Confirmer | API 200, ticket imprimé, KDS notifié | 🟡 À tester |

---

<a name="section-3"></a>
## 3️⃣ PARCOURS RÉEL KDS — ANATOMIE COMPLÈTE

### 📊 Architecture KDS

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     KITCHEN DISPLAY SYSTEM (KDS)                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    FIREBASE CLOUD MESSAGING (FCM)                     │    │
│  │  ───────────────────────────────────────────────────────────────   │    │
│  │  Topic: orders_{branch_id}                                          │    │
│  │                                                                      │    │
│  │  Payload: {                                                          │    │
│  │    order_id: 123,                                                    │    │
│  │    items: [...],                                                     │    │
│  │    status: 'preparing',                                              │    │
│  │    created_at: '2026-03-11T17:30:00'                                 │    │
│  │  }                                                                   │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                         │
│                                    ▼                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    KDS FRONTEND (Vue.js/Web)                        │    │
│  ├─────────────────────────────────────────────────────────────────────┤    │
│  │                                                                      │    │
│  │  ┌───────────────────────────────────────────────────────────────┐   │    │
│  │  │  1. ORDER LIST (Gauche)                                       │   │    │
│  │  │  ┌─────────┐ ┌─────────┐ ┌─────────┐                         │   │    │
│  │  │  │ #045    │ │ #046    │ │ #047    │ ← Cards commandes        │   │    │
│  │  │  │ 17:30   │ │ 17:32   │ │ 17:35   │   (token + heure)        │   │    │
│  │  │  │ ⏳      │ │ 🔥      │ │ ⏳      │   (statut visuel)        │   │    │
│  │  │  └─────────┘ └─────────┘ └─────────┘                         │   │    │
│  │  │                                                                │   │    │
│  │  │  Filtres: Tous | Tacos | Sandwichs | Burgers                   │   │    │
│  │  └───────────────────────────────────────────────────────────────┘   │    │
│  │                                                                      │    │
│  │  ┌───────────────────────────────────────────────────────────────┐   │    │
│  │  │  2. ORDER DETAIL (Centre — Commande sélectionnée)             │   │    │
│  │  │                                                                │   │    │
│  │  │  🔴 COMMANDE #045 — 17:30                                     │   │    │
│  │  │  Type: Sur place | Token: 7                                  │   │    │
│  │  │                                                                │   │    │
│  │  │  ┌──────────────────────────────────────────────────────────┐  │   │    │
│  │  │  │ 🌮 TACOS L (2 VIANDES)                           ×1    │  │   │    │
│  │  │  │                                                            │  │   │    │
│  │  │  │ 🥩 VIANDES: Merguez, Viande Hachée                        │  │   │    │
│  │  │  │ 🥄 SAUCE: Algérienne (+ Samouraï)                         │  │   │    │
│  │  │  │ 🥗 GARNITURES: Complet (S, T, O)                         │  │   │    │
│  │  │  │ ⚠️ SUPPLÉMENTS: Cheddar, Jambon                          │  │   │    │
│  │  │  │ 🍟 FORMULE: Menu Complet (Frites + Boisson)              │  │   │    │
│  │  │  │ 🍟 FRITES: Grande portion                                │  │   │    │
│  │  │  │ 🥄 SAUCE FRITES: Algérienne                              │  │   │    │
│  │  │  │                                                            │  │   │    │
│  │  │  │ 💬 Instruction: "Sans piment svp"                        │  │   │    │
│  │  │  └──────────────────────────────────────────────────────────┘  │   │    │
│  │  │                                                                │   │    │
│  │  │  [⏳ EN PRÉPARATION]  [✅ PRÊTE]  [📢 ANNULÉE]              │   │    │
│  │  └───────────────────────────────────────────────────────────────┘   │    │
│  │                                                                      │    │
│  │  ┌───────────────────────────────────────────────────────────────┐   │    │
│  │  │  3. TIMERS & STATS (Droite)                                   │   │    │
│  │  │                                                                │   │    │
│  │  │  ⏱️ Temps moyen prep: 8 min                                   │   │    │
│  │  │  📊 Commandes en cours: 12                                    │   │    │
│  │  │  🔥 Urgent (>15min): 3                                       │   │    │
│  │  └───────────────────────────────────────────────────────────────┘   │    │
│  │                                                                      │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 🔍 Points de Contrôle KDS

| # | Étape | Test | Attendu | Critique |
|---|-------|------|---------|----------|
| 1 | Réception FCM | Commande POS validée | Notif push reçue < 3s | 🟡 À tester |
| 2 | Affichage liste | Nouvelle commande | Card apparaît en haut | 🟡 À tester |
| 3 | Affichage détail | Click card | Items + instruction visibles | 🟡 À tester |
| 4 | Parsing instruction | Voir détail | VIANDES, SUPPLÉMENTS, FORMULE parsés | 🔴 Besoin Phase 3 |
| 5 | Statut changement | Click "Prête" | API update, notif client | 🟡 À tester |
| 6 | Auto-refresh | Timer | Liste refresh toutes les 30s | 🟡 À tester |

---

<a name="section-4"></a>
## 4️⃣ PARCOURS RÉEL BORNE (KIOSK) — ANATOMIE COMPLÈTE

### 📊 Flux Kiosk Flutter

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      BORNE AUTONOME (Flutter/Android)                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    IDLE MODE (Écran veille)                        │    │
│  │  ───────────────────────────────────────────────────────────────   │    │
│  │                                                                      │    │
│  │     ┌─────────────────────────────────────────┐                       │    │
│  │     │                                         │                       │    │
│  │     │           🍔 LE GRILL HOUSE             │                       │    │
│  │     │                                         │                       │    │
│  │     │         "Touchez pour commander"        │                       │    │
│  │     │                                         │                       │    │
│  │     │         [Animation burger]              │                       │    │
│  │     │                                         │                       │    │
│  │     └─────────────────────────────────────────┘                       │    │
│  │                                                                      │    │
│  │  └──▶ IdleService: Timer 3min reset → Retour ici + clear cart       │    │
│  │                                                                      │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                        │
│                                    │ Tap écran                              │
│                                    ▼                                        │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │              ORDER TYPE SCREEN (Sur place / À emporter)            │    │
│  │                                                                      │    │
│  │           ┌─────────────────┐  ┌─────────────────┐                  │    │
│  │           │                 │  │                 │                  │    │
│  │           │    🪑 SUR       │  │    🛍️ À        │                  │    │
│  │           │    PLACE        │  │    EMPORTER     │                  │    │
│  │           │                 │  │                 │                  │    │
│  │           │   Manger sur    │  │   Emporter      │                  │    │
│  │           │   place         │  │   hors          │                  │    │
│  │           │                 │  │                 │                  │    │
│  │           └─────────────────┘  └─────────────────┘                  │    │
│  │                                                                      │    │
│  │  └──▶ Stockage: box.write('order_type', 'dine_in'|'takeaway')    │    │
│  │                                                                      │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                        │
│                                    │ Sélection type                         │
│                                    ▼                                        │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    MENU SCREEN (Grille catégories)                  │    │
│  │                                                                      │    │
│  │  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐                     │    │
│  │  │ 🌮      │ │ 🥪      │ │ 🍔      │ │ 🍟      │                     │    │
│  │  │ TACOS   │ │SANDWICH │ │ BURGER │ │ FRITES │                     │    │
│  │  │   L     │ │         │ │         │ │         │                     │    │
│  │  │ 8.50€   │ │ 7.00€   │ │ 9.00€   │ │ 3.00€   │                     │    │
│  │  └─────────┘ └─────────┘ └─────────┘ └─────────┘                     │    │
│  │                                                                      │    │
│  │  ┌──────────────────────────────────────────────────────────────┐  │    │
│  │  │  🛒 PANIER (2) — 24.50€                    [Voir panier →]   │  │    │
│  │  └──────────────────────────────────────────────────────────────┘  │    │
│  │                                                                      │    │
│  │  └──▶ SocketService temps réel: Stock épuisé = item grisé       │    │
│  │                                                                      │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                        │
│                                    │ Tap item                               │
│                                    ▼                                        │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │              ITEM DETAILS / WIZARD (Configuration)                  │    │
│  │                                                                      │    │
│  │  ┌──────────────────────────────────────────────────────────────┐  │    │
│  │  │ 🌮 TACOS L (2 VIANDES)                                    8.50€│  │    │
│  │  │                                                             │  │    │
│  │  │ 🥩 CHOISISSEZ 2 VIANDES:                                  │  │    │
│  │  │    [−] 0 [+] Merguez                                       │  │    │
│  │  │    [−] 0 [+] Poulet                                        │  │    │
│  │  │    [−] 0 [+] ...                                           │  │    │
│  │  │                                                             │  │    │
│  │  │ 🥄 SAUCE (1ère gratuite):                                  │  │    │
│  │  │    [Algérienne] [Samouraï] [Ketchup] ...                   │  │    │
│  │  │                                                             │  │    │
│  │  │ 🥗 GARNITURES:                                             │  │    │
│  │  │    ( ) Complet    ( ) Sans Oignon                          │  │    │
│  │  │                                                             │  │    │
│  │  │ ➕ SUPPLÉMENTS:                                            │  │    │
│  │  │    [ ] Cheddar +€1.00                                    │  │    │
│  │  │                                                             │  │    │
│  │  │ 🍟🥤 FORMULE:                                              │  │    │
│  │  │    [Menu Complet +€3] [Frites +€1.50] [Non]                │  │    │
│  │  │                                                             │  │    │
│  │  │                    [AJOUTER AU PANIER — €12.50]            │  │    │
│  │  └──────────────────────────────────────────────────────────────┘  │    │
│  │                                                                      │    │
│  │  └──▶ CategoryHelper: Build steps dynamiques selon item            │    │
│  │                                                                      │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                        │
│                                    │ Ajout au panier                        │
│                                    ▼                                        │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    POST-ADD SCREEN (Feedback)                       │    │
│  │                                                                      │    │
│  │           ┌───────────────────────────────────────┐                  │    │
│  │           │                                       │                  │    │
│  │           │           ✅ AJOUTÉ !                  │                  │    │
│  │           │                                       │                  │    │
│  │           │    Tacos L (2 viandes) ajouté         │                  │    │
│  │           │    Total panier: €12.50               │                  │    │
│  │           │                                       │                  │    │
│  │           │   [🛒 VOIR MON PANIER]               │                  │    │
│  │           │   [+ CONTINUER MES ACHATS]            │                  │    │
│  │           │                                       │                  │    │
│  │           │   (Auto-navigation dans 8s...)        │                  │    │
│  │           │                                       │                  │    │
│  │           └───────────────────────────────────────┘                  │    │
│  │                                                                      │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                        │
│                                    │ 