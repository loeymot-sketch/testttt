# 📘 RAPPORT FINAL — Audit Complet Caisse & Borne FoodKing

> **Document maître consolidé** — Assemble toutes les documentations d'audit, benchmarks concurrence, et guide d'implémentation.  
> **Date:** 10 Mars 2026  
> **Usage:** Référence unique pour planification technique et visuelle des corrections.

---

# TABLE DES MATIÈRES

1. [Vue d'ensemble et sources](#1-vue-densemble-et-sources)
2. [Architecture FoodKing](#2-architecture-foodking)
3. [Parcours détaillés (Caisse & Borne)](#3-parcours-détaillés)
4. [Matrice complète des problèmes](#4-matrice-complète-des-problèmes)
5. [Benchmark concurrence (McDo, KFC, GUR KEBAB)](#5-benchmark-concurrence)
6. [Problèmes détaillés avec guidance implémentation](#6-problèmes-détaillés)
7. [Checkers et tests de vérification](#7-checkers-et-tests)
8. [Plan d'implémentation priorisé](#8-plan-dimplémentation)
9. [Références documentaires](#9-références)
10. [Architecture produits, catégories et paramètres](#10-architecture-produits-catégories-et-paramètres)

---

## 1. VUE D'ENSEMBLE ET SOURCES

### 1.1 Documents assemblés

| Document | Contenu |
|----------|---------|
| AUDIT_COMPLET_CAISSE_BORNE_20260310.md | Audit initial POS + Kiosk, tests, UX |
| AUDIT_PROFOND_CAISSE_BORNE_20260310.md | Reverse engineering, sécurité, ValidJsonOrder |
| AUDIT_CONSOLIDE_CAISSE_BORNE_GUR_20260310.md | Benchmark GUR KEBAB, 10 idées, checkers |
| AUDIT_MASSIF_E2E_POS_KDS_KIOSK_SUITE.md | Bugs Phase 1, plan T01-T20, K01-K10, B01-B15 |
| report-massive-audit-001.md | MA-001, MA-002, SEC-001 à SEC-007 |
| docs/WIZARD_LOGIC_DOCUMENTATION.md | Étapes wizard par catégorie |
| docs/ORDER_FLOW.md, DEVICE_FLOW.md | Flux commande et appareils |
| docs/BUSINESS_RULES.md | Règle prix central, coupons |
| docs/API_MAP.md | Routes et auth |
| Recherche web | McDo kiosk UX flow, case study |

### 1.2 État des tests (dernière exécution)

| Suite | Résultat | Détails |
|-------|----------|---------|
| AntiGravityTest | 20/20 ✅ | Auth, isolation Kiosk, prix, KDS, OSS |
| POSComprehensiveTest | 6/8 🟡 | Échecs : branch_id (ItemFactory), export (BinaryFileResponse) |

---

## 2. ARCHITECTURE FOODKING

### 2.1 Schéma global

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│  FOODKING — ÉCOSYSTÈME MULTI-SURFACE                                              │
├─────────────────────────────────────────────────────────────────────────────────┤
│  KIOSK (Borne)          │  POS (Caisse)           │  KDS (Cuisine)  │  OSS      │
│  Flutter/GetX            │  Vue.js + pos-wizard   │  Vue.js         │  Écran    │
│  POST /api/frontend/order│  POST /api/admin/pos   │  GET /kds-order  │  Statut   │
│  Token machine (Sanctum) │  Auth Manager          │  Auth Chef      │  Token    │
└─────────────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Flux de commande (ORDER_FLOW.md)

| Étape | Statut | Qui écrit | Qui lit |
|-------|--------|-----------|---------|
| 1 | PENDING | Kiosk / POS | Caissier |
| 2 | ACCEPT | Caissier | KDS, OSS |
| 3 | PREPARING | Chef (KDS) | OSS, POS |
| 4 | PREPARED | Chef | Caissier, OSS |
| 5 | DELIVERED | Caissier | Archive |

### 2.3 Device Flow (DEVICE_FLOW.md)

| Appareil | Acteur | Écriture | Lecture |
|----------|--------|----------|---------|
| Kiosk | Client (machine) | POST frontend/order | GET frontend/item |
| POS | Caissier | POST admin/pos, change-status | Firebase push |
| KDS | Chef | change-status | Liste commandes |
| OSS | Passif | Aucune | queue_number |

### 2.4 Fichiers techniques clés

| Rôle | Fichier |
|------|---------|
| POS Wizard | `public/js/pos-wizard.js` (~2700 lignes) |
| POS Composant | `resources/js/components/admin/pos/PosComponent.vue` |
| POS Controller | `app/Http/Controllers/Admin/PosOrderController.php` |
| Order Service | `app/Services/OrderService.php` (posOrderStore, tableOrderStore) |
| Frontend Order | `app/Services/FrontendOrderService.php` (myOrderStore) |
| Validation POS | `app/Http/Requests/PosOrderRequest.php` |
| Validation Items | `app/Rules/ValidJsonOrder.php` |
| Kiosk Wizard | `projet kiosk/.../item_details_screen.dart` |
| KDS | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` |

---

## 3. PARCOURS DÉTAILLÉS

### 3.1 Parcours CAISSE (POS) — Étape par étape

| # | Étape | Fichier / Élément | Remarque |
|---|-------|-------------------|----------|
| 1 | Connexion Admin/Manager | Auth | Sanctum |
| 2 | Accès /admin/pos | PosComponent.vue | Route Vue |
| 3 | Recherche + catégories | Swiper, props.search | Navigation menu |
| 4 | Clic item | ItemComponent | Déclenche XHR `/admin/item/` ou `/admin/setting/item/` |
| 5 | Interception XHR | pos-wizard.js | Capture `lastItemData` avant modal standard |
| 6 | Ouverture wizard | buildSteps(), getAllowedSteps() | Étapes selon detectCategory() |
| 7 | Étapes wizard (Tacos) | viande_sauce → perso → menu → recap | Sprint 4 : 4 étapes (avant 7) |
| 8 | Étapes wizard (Sandwich) | viande_sauce ou sauce_garnitures selon viandeCount | |
| 9 | Récap + instruction | renderRecapStep() | Génère VIANDES:, SUPPLÉMENTS:, FORMULE: |
| 10 | syncAndSubmit | sync DOM → modal caché → Vuex | Envoie item_variations, item_extras |
| 11 | Panier | checkoutProps.form | Client, Token, Type (Emporter/Livraison) |
| 12 | Validation avant paiement | PosComponent:872 | Token obligatoire frontend |
| 13 | Modal paiement | #orderpayment | Cash / CB / Mobile banking |
| 14 | POST /api/admin/pos | PosController::store | PosOrderRequest |
| 15 | OrderService::posOrderStore | Recalcule prix (dbItems), crée Order ACCEPT |

**Points de vigilance :**
- Token : obligatoire frontend (l.872), nullable backend (PosOrderRequest)
- Dine-In : masqué (`v-if="false"` l.84)
- Wizard : pas de breadcrumb "Étape X/Y" visible

### 3.2 Parcours BORNE (Kiosk) — Étape par étape

| # | Étape | Fichier / Élément | Remarque |
|---|-------|-------------------|----------|
| 1 | Écran idle | IdleService | Animation, tap pour activer |
| 2 | kiosk-login | KioskMachineLoginController | Token machine |
| 3 | OrderTypeScreen | Emporter / Sur place / Livraison | GetStorage |
| 4 | Menu | Catégories + grille items | GET /api/frontend/item |
| 5 | Clic item | ItemDetailsScreen | Wizard Flutter |
| 6 | CategoryHelper.detectCategory | buildDynamicSteps | attribute_slot, extras, addons |
| 7 | Multi-viandes | _meatSlotSelections | Bouton "Même" |
| 8 | Ajout panier | CartController | Feedback visuel |
| 9 | PostAddScreen | Timer 8s | Upsell possible |
| 10 | CartScreen | Liste items, total | getTableList() |
| 11 | DessertUpsellScreen | Si pas dessert | Upsell |
| 12 | PaymentScreen | Cash / Card | POST /api/frontend/order |
| 13 | Succès | Numéro commande | OSS / écran |

**Points de vigilance :**
- Idle 3 min : pas d'avertissement avant reset
- Pas de MON COMPTE / ALLERGÈNES en header
- Confirmation : pas de bip/message fort documenté

### 3.3 Structure wizard POS (pos-wizard.js) — Catégories et étapes

| Catégorie | Détection | Étapes (Sprint 4) |
|-----------|-----------|-------------------|
| tacos | nom "tacos" | viande_sauce, perso, menu, recap |
| sandwich, burger | catégorie ou nom + viandeCount | viande_sauce ou sauce_garnitures selon viandes |
| assiette | "assiette" | sauce_accompagnement, supplements, recap |
| salade | "salade" | sauce_supplements, recap |
| omelette, snacking | "omelette", etc. | sauce_single, recap |
| ojja | "ojja" | recap seul |
| menu_enfant, dessert, boisson | mots-clés | recap seul (sans wizard) |

---

## 4. MATRICE COMPLÈTE DES PROBLÈMES

### 4.1 Par sévérité

| ID | Type | Sévérité | Description courte |
|----|------|----------|-------------------|
| D-001 | Sécurité | 🔴 Critique | Fallback prix client si item inexistant |
| D-002 | Sécurité | 🔴 Critique | POS variations/extras = prix client |
| D-003 | Sécurité | 🔴 Haute | Routes table sans auth |
| D-004 | Validation | 🔴 Haute | ValidJsonOrder accepte items sans item_id |
| A-001, D-005 | Technique | 🔴 | POSComprehensiveTest : items.branch_id |
| MA-001 | Technique | 🔴 | payment.blade.php faviconLogo null |
| MA-002 | Technique | 🔴 | SettingResource null |
| SEC-002 | Sécurité | 🔴 | Table orders sans auth |
| A-002 | Technique | 🟡 | POS export BinaryFileResponse::status |
| D-006 | Technique | 🟡 | Export nom "Online-Order.xlsx" |
| A-003, D-007 | UX | 🟡 | Token frontend/backend incohérent |
| A-004, D-008 | UX | 🟡 | Dine-In masqué |
| D-009 | Architecture | 🟡 | Wizard interception XHR fragile |
| D-010 | UX | 🟡 | KDS instruction non parsée |
| D-011 | UX | 🟡 | Kiosk confirmation faible |
| D-012 | Données | 🟡 | Table order branch_id item |
| UX-01 à UX-05 | UI/UX | 🟡 | Token, client, récap, breadcrumb |
| KUX-01 à KUX-05 | UI/UX | 🟡 | Confirmation, idle, temps attente |
| SEC-001, SEC-003 à SEC-007 | Sécurité | 🟡 | Source, prix, audit, etc. |

### 4.2 Par surface (Caisse vs Borne)

| Surface | Problèmes |
|---------|-----------|
| **Caisse** | D-002, D-005, A-002, D-006, D-007, D-008, D-009, UX-01 à UX-05 |
| **Borne** | D-001 (FrontendOrderService), D-011, KUX-01 à KUX-05 |
| **Partagé** | D-003, D-004, D-010 (KDS), MA-001, MA-002 |

---

## 5. BENCHMARK CONCURRENCE

### 5.1 McDonald's — Parcours kiosk (recherche web)

| Étape | McDo | FoodKing Kiosk |
|-------|------|----------------|
| 1 | Wake + langue | Idle + tap |
| 2 | Dine-In / Takeaway | OrderType (Emporter/Sur place/Livraison) ✅ |
| 3 | Browse menu (catégories) | Catégories + grille ✅ |
| 4 | Select items (meal / à la carte) | Item + wizard ✅ |
| 5 | Customize (size, sides, drinks) | Wizard multi-étapes ✅ |
| 6 | Review order (right-side summary) | CartScreen ✅ |
| 7 | Deals/Rewards (coupon) | Coupon API ✅ |
| 8 | Payment (card, mobile, counter) | Cash/Card ✅ |
| 9 | Receipt + order number | Numéro via OSS |
| 10 | Pickup screen | OSS |

**Problèmes McDo (case study UX Collective) :**
- Scroll non indiqué (boissons)
- Rupture de stock non signalée (butter corn)
- Numéros multiples (écran vs plaque vs ticket) → confusion
- "Pay at counter" → file supplémentaire

**Leçons pour FoodKing :** Indicateur scroll, rupture de stock visible, numéro unique clair.

### 5.2 GUR KEBAB — Parcours (photos fournies)

| Élément | GUR KEBAB | FoodKing |
|---------|-----------|----------|
| Header | MON COMPTE, ALLERGÈNES | Absent Kiosk |
| Sidebar | Catégories (Baguettes, Faluche, etc.) | Catégories ✅ |
| Barre progression | Étape 1-7, icônes, numérotée | Absente |
| Options | Image + nom + prix par option | Emojis / thumbs |
| Rupture stock | "EN RUPTURE" bannière rouge | Non documenté |
| Badge NOUVEAU | "NOUVEAU À CROQUER!" | is_featured items seulement |
| Abandon | ABANDONNER L'ARTICLE vs MA COMMANDE | À clarifier |
| Total | Toujours visible en bas | Partiel |
| Footer wizard | PRÉCÉDENT, SUIVANT, Total | Retour, Suivant, Ajouter |

### 5.3 Synthèse comparaison

| Critère | McDo | KFC | GUR | FoodKing |
|---------|------|-----|-----|----------|
| Type commande en premier | ✅ | ✅ | ✅ | ✅ Kiosk |
| Barre progression | ✅ | ✅ | ✅ | ❌ |
| Rupture stock visible | ❌ (problème) | ? | ✅ | ❌ |
| Allergènes | ? | ? | ✅ | ❌ |
| Numéro unique | ❌ (confusion) | ? | ✅ | ✅ queue_number |
| Total toujours visible | ✅ | ✅ | ✅ | Partiel |

---

## 6. PROBLÈMES DÉTAILLÉS AVEC GUIDANCE IMPLÉMENTATION

### 6.1 D-001 — Fallback prix client (item inexistant)

**Description :** Si `Item::find($item->item_id)` retourne null, le code utilise `$item->item_price` (client). Vulnérabilité : item_id=999999, item_price=0.01.

**Fichiers :** FrontendOrderService.php:127-128, OrderService (orderStore, posOrderStore, tableOrderStore)

**Implémentation recommandée :**
```
1. Remplacer : $itemPrice = $dbItem ? $dbItem->price : $item->item_price;
   Par : if (!$dbItem) throw new \InvalidArgumentException("Item {$item->item_id} not found");
         $itemPrice = $dbItem->price;
2. Idem pour variations/extras : si ItemVariation::find ou ItemExtra::find null → rejeter
3. Test : POST order avec item_id inexistant → 422
```

**Visuel :** Aucun impact UI. Backend uniquement.

---

### 6.2 D-002 — POS variations/extras prix client

**Description :** OrderService::posOrderStore utilise `$variation->price` et `$extra->price` depuis la requête. Kiosk et Table utilisent ItemVariation::find, ItemExtra::find.

**Fichier :** OrderService.php:432-446

**Implémentation recommandée :**
```
1. Pour chaque variation : $dbVar = ItemVariation::find($var->id ?? 0);
   if (!$dbVar) continue ou throw;
   $variationTotal += $dbVar->price;
2. Pour chaque extra : $dbExt = ItemExtra::find($ext->id ?? 0);
   idem
3. Aligner sur tableOrderStore (l.616-628)
```

**Visuel :** Aucun. Intégrité prix.

---

### 6.3 D-004 — ValidJsonOrder structure

**Description :** La règle accepte `[{"quantity":1}]` sans item_id. Risque d'erreur ou comportement indéfini.

**Fichier :** app/Rules/ValidJsonOrder.php

**Implémentation recommandée :**
```
1. Dans passes() : pour chaque élément du tableau,
   vérifier présence de item_id (numeric) et quantity (numeric, > 0)
2. Si absent ou invalide : $this->message = '...'; return false;
3. Test : ValidJsonOrder::passes('items', '[{"quantity":1}]') → false
```

**Visuel :** Aucun. Validation backend.

---

### 6.4 UX-03 / Idée 1 — Barre de progression wizard

**Description :** GUR et McDo affichent "Étape 3/7". FoodKing n'a pas d'indicateur visible.

**Fichier :** pos-wizard.js (renderStep, renderNav), pos-wizard.css

**Implémentation recommandée :**
```
1. Dans renderNav() ou en-tête de chaque étape :
   Ajouter : <div class="wizard-progress">Étape <span id="wiz-current">X</span> sur <span id="wiz-total">Y</span></div>
2. currentStep et steps sont déjà disponibles
3. Style : barre horizontale ou cercles numérotés (comme GUR)
4. Même logique pour Kiosk Flutter (ItemDetailsScreen)
```

**Visuel :** Barre ou indicateur en haut du wizard. Couleur primaire, lisible.

---

### 6.5 Idée 2 — Rupture de stock

**Description :** GUR affiche "EN RUPTURE" et grise l'item. FoodKing a status sur Item, ItemExtra, ItemAddon. Comportement non documenté.

**Implémentation recommandée :**
```
1. Documenter dans BUSINESS_RULES.md :
   - Item/Extra/Addon status=INACTIVE → non proposé au client
   - Si proposé par erreur, backend rejette
2. Frontend : filtrer par status=ACTIVE dans les APIs
3. Si besoin d'afficher "EN RUPTURE" (item connu mais temporairement indispo) :
   - Nouveau champ ou logique métier à définir
   - Sinon, désactiver = masquer
```

**Visuel :** Option grisée + overlay "Indisponible" si on affiche les items en rupture.

---

### 6.6 D-010 — KDS instruction parsée

**Description :** Le wizard génère "VIANDES: Merguez, Poulet. SUPPLÉMENTS: Cheddar. FORMULE: Menu Complet." Le KDS affiche en brut.

**Fichier :** KitchenDisplaySystemComponent.vue

**Implémentation recommandée (Phase 3 — AUDIT_MASSIF_E2E_SUITE) :**
```
1. Créer extractSection(instruction, key) → extrait la valeur après "KEY: "
2. Afficher par sections avec icônes : 🥩 Viandes, ➕ Suppléments, 🍟 Formule
3. Style : .kds-section.supplements { background: #FFF3CD; border-left: 4px solid #FFC107; }
4. .kds-section.formule { background: #D1ECF1; ... }
```

**Visuel :** Sections distinctes, highlight suppléments (jaune), formule (bleu).

---

### 6.7 D-007 — Token POS alignement

**Description :** Frontend bloque si token vide. Backend accepte null. Comportement divergent.

**Implémentation recommandée :**
```
Option A : Token obligatoire partout
  - PosOrderRequest : 'token' => ['required', 'string', 'numeric']
  - Garder validation frontend
Option B : Token optionnel partout
  - Retirer validation PosComponent:872-874
  - Documenter : token = numéro borne kiosk ou file d'attente, optionnel pour commande manuelle
```

**Visuel :** Si optionnel, placeholder "Optionnel - N° borne ou file".

---

### 6.8 D-008 — Dine-In masqué

**Description :** `v-if="false"` masque Dine-In sans message.

**Implémentation recommandée :**
```
Option A : Réactiver si config
  - v-if="settingOrderSetupDineIn" (depuis settings)
Option B : Garder masqué mais expliquer
  - Afficher message : "Sur place temporairement indisponible"
  - Ou lien "Pourquoi ?" vers doc
```

**Visuel :** Message grisé si désactivé.

---

## 7. CHECKERS ET TESTS DE VÉRIFICATION

### 7.1 Checklist manuelle — POS

| # | Vérification | Comment |
|---|--------------|---------|
| C1 | Barre progression | Ouvrir Tacos L, chercher "Étape X sur Y" |
| C2 | Total temps réel | 2 sauces → +0.50€ avant récap |
| C3 | Abandon article | Bouton Annuler/X dans wizard |
| C4 | Token vide | Payer sans token → erreur |
| C5 | Client vide | Comportement |
| C6 | Dine-In | Visible ou message |
| C7 | Instruction ticket | VIANDES, SUPPLÉMENTS, FORMULE imprimés |
| C8 | KDS reçoit | Vérifier écran cuisine |

### 7.2 Checklist manuelle — Kiosk

| # | Vérification | Comment |
|---|--------------|---------|
| K1 | Type en premier | Écran après idle |
| K2 | Barre progression | Wizard item |
| K3 | Confirmation | Après paiement |
| K4 | Idle 3 min | Comportement |
| K5 | MON COMPTE / ALLERGÈNES | Header |
| K6 | Upsell dessert | Si pas dessert |
| K7 | Temps attente | Affiché ou non |

### 7.3 Tests automatisés à créer

| Test | Objectif |
|------|----------|
| ValidJsonOrder_rejects_no_item_id | [{"quantity":1}] → false |
| Order_rejects_invalid_item_id | item_id=999999 → 422 |
| POS_uses_db_price_for_variations | variation price=0 → DB price |
| Table_route_accessible_with_api_key | POST sans auth → 200 ou 422 selon payload |

### 7.4 Checklist documentaire

| # | Document | Vérification |
|---|----------|--------------|
| D1 | PosOrderRequest vs PosComponent | token cohérent |
| D2 | ORDER_FLOW.md | Transitions à jour |
| D3 | BUSINESS_RULES.md | Rupture stock, allergènes |
| D4 | WIZARD_LOGIC_DOCUMENTATION.md | Étapes = pos-wizard.js |
| D5 | API_MAP.md | Routes table documentées |

---

## 8. PLAN D'IMPLÉMENTATION PRIORISÉ

### Phase P0 — Critique (sécurité)

| Ordre | ID | Tâche | Fichier(s) |
|-------|-----|-------|------------|
| 1 | D-001 | Rejeter item inexistant | FrontendOrderService, OrderService |
| 2 | D-002 | POS : prix DB variations/extras | OrderService::posOrderStore |
| 3 | D-004 | ValidJsonOrder item_id, quantity | ValidJsonOrder.php |

### Phase P1 — Haute

| Ordre | ID | Tâche |
|-------|-----|-------|
| 4 | D-003 | Documenter / sécuriser routes table |
| 5 | D-005 | Corriger POSComprehensiveTest (branch_id) |
| 6 | D-012 | Table order branch_id depuis order |
| 7 | MA-001, MA-002 | Null-safe payment.blade, SettingResource |
| 8 | Idée 2 | Rupture de stock : doc + comportement |

### Phase P2 — Moyenne

| Ordre | ID | Tâche |
|-------|-----|-------|
| 9 | UX-03 | Barre progression wizard |
| 10 | D-007 | Aligner token |
| 11 | D-008 | Message Dine-In |
| 12 | D-010 | KDS parsing instruction |
| 13 | D-011 | Confirmation Kiosk |
| 14 | Idée 4 | Allergènes / MON COMPTE |

### Phase P3 — Basse

| Ordre | ID | Tâche |
|-------|-----|-------|
| 15 | D-006 | Nom fichier export |
| 16 | D-009 | Découpler wizard XHR |
| 17 | Idée 3 | Badge NOUVEAU |
| 18 | Idée 8 | Idempotency key |

---

## 9. RÉFÉRENCES

### 9.1 Documents projet

| Fichier | Rôle |
|---------|------|
| docs/ARCHITECTURE.md | Architecture globale |
| docs/API_MAP.md | Routes et auth |
| docs/ORDER_FLOW.md | Flux commande |
| docs/DEVICE_FLOW.md | Flux appareils |
| docs/BUSINESS_RULES.md | Règles métier |
| docs/WIZARD_LOGIC_DOCUMENTATION.md | Étapes wizard |
| docs/ERROR_HANDLING.md | Gestion erreurs API |

### 9.2 Rapports d'audit

| Fichier | Contenu |
|---------|---------|
| reports/antigravity/AUDIT_COMPLET_CAISSE_BORNE_20260310.md | Audit initial |
| reports/antigravity/AUDIT_PROFOND_CAISSE_BORNE_20260310.md | Audit profond |
| reports/antigravity/AUDIT_CONSOLIDE_CAISSE_BORNE_GUR_20260310.md | Benchmark GUR |
| reports/antigravity/AUDIT_MASSIF_E2E_POS_KDS_KIOSK_SUITE.md | Suite E2E |
| reports/antigravity/report-massive-audit-001.md | MA-001, SEC-xxx |

### 9.3 Sources externes

- McDonald's kiosk UX flow (Self Service Kiosk Review, Flavor365)
- McDonald's kiosk UX case study (Chee Seng Leong, UX Collective)
- Photos GUR KEBAB (borne, wizard, sauces, crudités, suppléments, frites)

---

## 10. ARCHITECTURE PRODUITS, CATÉGORIES ET PARAMÈTRES

### 10.1 Structure actuelle (schéma DB)

```
item_categories          items                    item_attributes
├── id                   ├── id                   ├── id
├── name                 ├── item_category_id ────┤  (Sauce, Viande, etc.)
├── slug                 ├── tax_id               └── name
├── description          ├── name
└── status               ├── price
                         └── ...
                                │
        ┌───────────────────────┼───────────────────────┐
        ▼                       ▼                       ▼
item_variations          item_extras             item_addons
├── item_id              ├── item_id             ├── item_id (produit principal)
├── item_attribute_id    ├── name                 ├── addon_item_id (Frites, Boisson)
├── name                 ├── price               └── addon_item_variation
└── price                └── status
```

**Observation :** La catégorie (`item_categories`) ne contient **aucun paramètre** de wizard, menu ou suppléments. Tout est **au niveau item** ou **codé en dur** dans le wizard.

### 10.2 Logique actuelle vs configurable

| Élément | Où c'est défini | Configurable ? |
|---------|-----------------|----------------|
| **Étapes wizard** (viande, sauce, menu, récap) | `pos-wizard.js` getAllowedSteps() | ❌ Hardcodé par chaîne (tacos, sandwich, etc.) |
| **Détection catégorie** | detectCategory() — nom + category_name | ❌ String matching |
| **Sauces** | item_attributes + item_variations (API) | ✅ Par item |
| **Suppléments** | item_extras | ✅ Par item |
| **Menu (frites + boisson)** | item_addons | ✅ Par item (chaque item doit avoir ses addons) |
| **Sauce incluse au menu ?** | Logique wizard (sauce frites si frites) | ❌ Hardcodé |
| **Menu proposé par défaut (Kiosk 80%)** | — | ❌ N'existe pas |
| **Catégorie a un menu ?** | — | ❌ Pas de champ |

### 10.3 Paramètres et contrôles manquants

| Paramètre | Description | Impact |
|-----------|--------------|--------|
| **has_menu** (catégorie) | Cette catégorie propose-t-elle un menu (frites+boisson) ? | Tacos oui, Salade peut-être non |
| **default_menu_kiosk** | En Kiosk, proposer menu par défaut (80% des commandes) ? | Upsell, logique métier |
| **sauce_with_menu** | La sauce frites est-elle incluse ou en supplément ? | Règle métier |
| **wizard_template** (catégorie) | Étapes du wizard pour cette catégorie | Éviter hardcode JS |
| **category_extras** | Suppléments communs à toute la catégorie | Éviter duplication par item |
| **supplements_per_category** | Liste des suppléments autorisés par catégorie | Contrôle admin |

### 10.4 État des lieux — Ce qui fonctionne vs pas encore

| Zone | Fonctionnel | À vérifier / Non implémenté |
|------|-------------|-----------------------------|
| **Backend API** | itemDetails, variations, extras, addons | Recalcul prix (OK), validation items (D-004) |
| **POS Wizard** | Détection catégorie, étapes, sync | Barre progression, paramètres catégorie |
| **Kiosk Wizard** | CategoryHelper, steps dynamiques | Menu par défaut, paramètres |
| **Admin items** | CRUD items, extras, addons | Config catégorie (wizard, menu) |
| **Admin catégories** | CRUD catégories | Aucun paramètre métier |

**Constat :** ~90 % de la logique produit/catégorie est **apportée telle quelle** (wizard, addons) mais **pas pilotée par des paramètres**. Pour ajouter une nouvelle catégorie (ex. "Wrap"), il faut modifier le code JS.

### 10.5 Recommandation : Architecture par catégorie

**Oui, architecturer par catégorie est le bon choix** pour :

1. **Centraliser la config** — Une catégorie "Tacos" = un flux wizard, des suppléments, menu oui/non
2. **Éviter la duplication** — Les suppléments (Cheddar, etc.) peuvent être définis au niveau catégorie et hérités par les items
3. **Piloter le Kiosk** — "En Kiosk, catégories Tacos/Sandwich/Burger : proposer menu par défaut"
4. **Faciliter l'admin** — Créer un item dans "Tacos" = il hérite du wizard et des options

**Schéma cible proposé :**

```
item_categories (enrichi)
├── ... (existant)
├── wizard_template      JSON ou enum : 'tacos'|'sandwich'|'assiette'|'simple'
├── has_menu             bool : cette catégorie propose menu (frites+boisson)
├── default_menu_kiosk   bool : en Kiosk, présélectionner menu (80%)
└── sauce_included_menu  bool : sauce frites incluse ou +0.50€

category_extras (nouvelle table, optionnel)
├── item_category_id
├── item_extra_id ou name, price
└── order
```

**Alternative légère :** Garder la structure actuelle mais ajouter à `item_categories` :
- `wizard_template` (string : tacos, sandwich, assiette, salade, simple)
- `has_menu` (bool)
- `default_menu_kiosk` (bool)

Le wizard lirait ces champs au lieu du string matching sur le nom.

### 10.6 Comparaison concurrence — Structure

| Critère | GUR KEBAB | McDo | FoodKing actuel |
|---------|-----------|------|-----------------|
| **Config par type de produit** | Oui (Menu Baguette = 7 étapes) | Oui (meal vs à la carte) | Détection par nom, hardcodé |
| **Menu proposé par défaut** | Oui (Menu = combo) | Oui (meal) | Non, même flow POS/Kiosk |
| **Suppléments par catégorie** | Oui (suppléments frites) | Oui | Par item uniquement |
| **Sauce incluse/supplément** | 0.00€ première | Variable | 1ère gratuite, +0.50€ (hardcodé) |
| **Rupture de stock** | EN RUPTURE visible | Parfois absent | status, non affiché |

### 10.7 Plan d'évolution architecture

| Phase | Action | Fichiers |
|-------|---------|----------|
| **1. Documenter** | Créer `docs/PRODUCT_CATEGORY_ARCHITECTURE.md` | docs/ |
| **2. Enrichir ItemCategory** | Migration : wizard_template, has_menu, default_menu_kiosk | migrations/ |
| **3. Adapter wizard** | Lire config catégorie au lieu de detectCategory() string | pos-wizard.js, item_details_screen.dart |
| **4. Kiosk menu par défaut** | Si default_menu_kiosk, présélectionner "Menu complet" | Flutter |
| **5. Admin catégories** | Formulaire : wizard_template, has_menu, default_menu_kiosk | ItemCategoryController, vue |

### 10.8 Règles métier à formaliser

| Règle | État actuel | À documenter dans BUSINESS_RULES |
|-------|-------------|-----------------------------------|
| Prix central (SSOT) | ✅ Implémenté | Déjà documenté |
| Sauce 1ère gratuite, +0.50€ | Hardcodé pos-wizard.js | Oui |
| Menu = Frites + Boisson | item_addons | Oui |
| Sauce frites = 1ère gratuite | Hardcodé | Oui |
| Catégorie sans wizard | menu_enfant, dessert, boisson | Oui |
| Rupture de stock (status) | Filtré par ACTIVE | Oui |

---

## ANNEXE A — RÉSUMÉ POUR DÉVELOPPEUR

**À faire en priorité :**
1. D-001, D-002, D-004 (sécurité prix et validation)
2. Barre de progression wizard (UX-03)
3. KDS parsing instruction (D-010)
4. Aligner token (D-007)

**Architecture produits (nouveau) :**
5. Documenter structure catégories/items dans docs/
6. Enrichir ItemCategory (wizard_template, has_menu, default_menu_kiosk)
7. Adapter wizard pour lire config catégorie
8. Kiosk : menu par défaut si default_menu_kiosk

**À ne pas oublier :**
- Tests POS : retirer branch_id de ItemFactory, corriger assert export
- Rupture de stock : documenter dans BUSINESS_RULES
- Allergènes : conformité réglementaire Kiosk

---

**Fin du rapport final.**

*Document maître — À utiliser comme référence unique pour l'implémentation des corrections.*
