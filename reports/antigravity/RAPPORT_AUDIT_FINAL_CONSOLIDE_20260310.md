# 📘 RAPPORT D'AUDIT FINAL CONSOLIDÉ — Caisse & Borne FoodKing

> **Document maître unique** — Assemble toutes les documentations d'audit, benchmarks concurrence, tests éphémères, reverse engineering, et recommandations.  
> **Date:** 10 Mars 2026  
> **Contrainte:** Aucune modification du code — documentation et tests uniquement.  
> **Usage:** Référence pour audit manuel, planification technique et visuelle.

---

## TABLE DES MATIÈRES

1. [Vue d'ensemble et sources](#1-vue-densemble-et-sources)
2. [Résultats des tests exécutés](#2-résultats-des-tests-exécutés)
3. [Architecture et flux de commande](#3-architecture-et-flux-de-commande)
4. [Pages de connexion — Caisse, KDS, OSS](#4-pages-de-connexion--caisse-kds-oss)
5. [Reverse engineering — Caisse (POS)](#5-reverse-engineering--caisse-pos)
6. [Reverse engineering — Borne (Kiosk)](#6-reverse-engineering--borne-kiosk)
7. [Matrice complète des problèmes](#7-matrice-complète-des-problèmes)
8. [Benchmark concurrence (McDo, KFC, GUR KEBAB)](#8-benchmark-concurrence)
9. [Checkers et vérifications](#9-checkers-et-vérifications)
10. [5–10 idées d'amélioration](#10-5-10-idées-damélioration)
11. [Plan d'implémentation priorisé](#11-plan-dimplémentation-priorisé)
12. [Références documentaires](#12-références)
13. [Annexes techniques](#13-annexes-techniques)

---

## 1. VUE D'ENSEMBLE ET SOURCES

### 1.1 Documents assemblés

| Document | Contenu |
|----------|---------|
| RAPPORT_FINAL_AUDIT_CAISSE_BORNE_20260310.md | Audit initial POS + Kiosk, matrice problèmes |
| AUDIT_PROFOND_POST_IMPLEMENTATION_20260312.md | Validation post Plans 1–12 |
| AUDIT_CONSOLIDE_CAISSE_BORNE_GUR_20260310.md | Benchmark GUR KEBAB, 10 idées |
| AUDIT_MASSIF_E2E_POS_KDS_KIOSK_SUITE.md | Bugs Phase 1, plan T01–T20, K01–K10, B01–B15 |
| execution/latest.md | FIX-01, FIX-02 (wizard_template, buildWizardInstruction) |
| execution/PLAN_01_12_COMPLETE.md | Exécution Plans 1–12 |
| docs/ORDER_FLOW.md, DEVICE_FLOW.md | Flux commande et appareils |
| docs/BUSINESS_RULES.md | Règle prix central, coupons |
| docs/API_MAP.md | Routes et auth |
| docs/WIZARD_LOGIC_DOCUMENTATION.md | Étapes wizard par catégorie |
| Recherche web | McDonald's, KFC kiosk UX flow |

### 1.2 Méthodologie d'audit

- **Reverse engineering** : Analyse du code sans modification
- **Tests éphémères** : Exécution des suites existantes (ValidJsonOrder, AntiGravity, POSComprehensive)
- **Comparaison concurrence** : McDonald's, KFC, GUR KEBAB (parcours, UX, logique)
- **Vérification documentaire** : Cohérence docs vs code

---

## 2. RÉSULTATS DES TESTS EXÉCUTÉS

### 2.1 Tests ValidJsonOrder (D-004)

| Test | Résultat |
|------|----------|
| it_rejects_items_without_item_id | ✅ PASS |
| it_rejects_items_with_zero_item_id | ✅ PASS |
| it_rejects_items_with_negative_item_id | ✅ PASS |
| it_rejects_items_without_quantity | ✅ PASS |
| it_rejects_items_with_zero_quantity | ✅ PASS |
| it_rejects_empty_array | ✅ PASS |
| it_rejects_invalid_json | ✅ PASS |
| it_rejects_non_string_input | ✅ PASS |
| it_accepts_valid_item_array | ✅ PASS |
| it_accepts_multiple_valid_items | ✅ PASS |
| it_includes_index_in_error_message | ✅ PASS |

**Verdict :** 11/11 PASS — ValidJsonOrder rejette correctement `[{"quantity":1}]` sans item_id.

### 2.2 Tests AntiGravity

| Module | Tests | Résultat |
|--------|-------|----------|
| Auth Kiosk | t01–t05 | ✅ PASS |
| Isolation Kiosk | t06–t07 | ✅ PASS |
| Prix (D-001, D-002) | t08–t09 | ✅ PASS |
| KDS, OSS | t10–t20 | ✅ PASS |

**Verdict :** 20/20 PASS — Sécurité prix, isolation, auth validés.

### 2.3 Tests POSComprehensive

| Test | Résultat |
|------|----------|
| pos can create order | ✅ PASS |
| pos can list orders | ✅ PASS |
| pos can view order details | ✅ PASS |
| pos can change status to preparing | ✅ PASS |
| pos can change payment status | ✅ PASS |
| pos can delete order | ✅ PASS |
| pos can export orders | ✅ PASS |
| pos can reorder items | ✅ PASS |

**Verdict :** 8/8 PASS — Flux POS complet validé.

### 2.4 Synthèse tests

| Suite | Pass | Fail | Commentaire |
|-------|------|------|-------------|
| ValidJsonOrderTest | 11 | 0 | D-004 mitigé |
| AntiGravityTest | 20 | 0 | Core sécurité OK |
| POSComprehensiveTest | 8 | 0 | Flux POS OK |
| **Total** | **39** | **0** | **100% pass** |

---

## 3. ARCHITECTURE ET FLUX DE COMMANDE

### 3.1 Schéma global

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│  FOODKING — ÉCOSYSTÈME MULTI-SURFACE                                              │
├─────────────────────────────────────────────────────────────────────────────────┤
│  KIOSK (Borne)          │  POS (Caisse)           │  KDS (Cuisine)  │  OSS      │
│  Flutter/GetX            │  Vue.js + pos-wizard   │  Vue.js         │  Écran    │
│  POST /api/frontend/order│  POST /api/admin/pos   │  GET /kds-order │  Statut   │
│  Token machine (Sanctum) │  Auth Manager          │  Auth Chef      │  Token    │
└─────────────────────────────────────────────────────────────────────────────────┘
```

### 3.2 Flux de commande (ORDER_FLOW.md)

| Étape | Statut | Qui écrit | Qui lit |
|-------|--------|-----------|---------|
| 1 | PENDING | Kiosk / POS | Caissier |
| 2 | ACCEPT | Caissier | KDS, OSS |
| 3 | PREPARING | Chef (KDS) | OSS, POS |
| 4 | PREPARED | Chef | Caissier, OSS |
| 5 | DELIVERED | Caissier | Archive |

### 3.3 Device Flow (DEVICE_FLOW.md)

| Appareil | Acteur | Écriture | Lecture |
|----------|--------|----------|---------|
| Kiosk | Client (machine) | POST frontend/order | GET frontend/item |
| POS | Caissier | POST admin/pos, change-status | Firebase push |
| KDS | Chef | change-status | Liste commandes |
| OSS | Passif | Aucune | queue_number |

### 3.4 Règle prix central (BUSINESS_RULES.md)

- **Prix central (SSOT)** : Le frontend n'a PAS le droit de définir le prix d'un produit.
- **Recalcul serveur** : Le backend recalcul toujours les prix depuis la DB.
- **Validation** : ValidJsonOrder exige item_id + quantity obligatoires.

---

## 4. PAGES DE CONNEXION — CAISSE, KDS, OSS

### 4.1 État actuel (reverse engineering)

| Profil | Page de connexion | URL réelle | Redirection après login |
|--------|-------------------|------------|--------------------------|
| **Admin** | LoginComponent | `/login` | `frontend.home` |
| **Manager / Caissier** | LoginComponent | `/login` | `frontend.home` |
| **POS Operator (Caisse)** | LoginComponent | `/login` | `frontend.home` |
| **Chef (KDS)** | LoginComponent | `/login` | `frontend.home` |
| **OSS (écran statut)** | LoginComponent | `/login` | `frontend.home` |

**Constat :** Une seule page de connexion (`/login`) pour tous les profils. Même design (thème frontend : navbar, footer, liens Signup/Forget password).

### 4.2 URLs des surfaces par profil

| Surface | URL réelle | URL documentée (parfois) | Permission |
|---------|------------|---------------------------|------------|
| **Caisse (POS)** | `/admin/pos` | `/admin/pos` ✅ | `pos` |
| **KDS (Cuisine)** | `/admin/kitchen-display-system` | `/admin/kds` ❌ | `kitchen-display-system` |
| **OSS (Écran client)** | `/admin/order-status-screen` | `/admin/oss` ❌ | `order-status-screen` |

### 4.3 Problèmes identifiés

| ID | Type | Description |
|----|------|-------------|
| **LOGIN-01** | Doc | Guide E2E mentionne `/admin/login` — n'existe pas. La route réelle est `/login`. |
| **LOGIN-02** | UX | Redirection après login : tous les profils (y compris staff) sont redirigés vers `frontend.home`. Le backend retourne `defaultPermission` (ex: `{ url: 'pos' }`) mais le frontend ne l'utilise pas pour la redirection. |
| **LOGIN-03** | UX | Pas de page de connexion dédiée par profil. Un caissier et un client voient la même page (email, password, Signup, Guest login). Confusion possible pour le staff. |
| **LOGIN-04** | UX | Mode démo : boutons Admin, Branch Manager, POS Operator, Chef pré-remplissent les identifiants — mais après login, redirection toujours vers `frontend.home`. |
| **LOGIN-05** | Doc | Incohérence URLs : docs parlent de `/admin/kds` et `/admin/oss` alors que les routes sont `/admin/kitchen-display-system` et `/admin/order-status-screen`. |
| **LOGIN-06** | Architecture | OSS : l'écran "Order Status Screen" est une page admin protégée (`auth: true`, `permission: order-status-screen`). Un écran physique au-dessus du comptoir devrait idéalement fonctionner en mode "kiosk" (token dans URL ou page publique) sans login staff. À vérifier si un mode public existe. |

### 4.4 Fichiers concernés

| Fichier | Rôle |
|---------|------|
| `resources/js/components/frontend/auth/LoginComponent.vue` | Page de connexion unique |
| `resources/js/router/modules/authRoutes.js` | Route `/login` |
| `resources/js/store/modules/auth.js` | `authLogin` reçoit `defaultPermission` |
| `app/Http/Controllers/Auth/LoginController.php` | Retourne `defaultPermission`, `defaultMenu` |
| `app/Libraries/AppLibrary.php` | `defaultPermission()`, `defaultMenu()` |
| `resources/js/router/index.js` | Guard : si logged + auth.login → redirect `frontend.home` |

### 4.5 Recommandations

1. **Redirection par profil** : Après login, si `defaultPermission.url` existe (ex: `pos`, `kitchen-display-system`), rediriger vers `/admin/{url}` au lieu de `frontend.home`.
2. **Page /admin/login** : Créer une route `/admin/login` avec un design "back-office" (sans Signup, sans Guest) pour le staff, ou documenter que `/login` est la page unique.
3. **Corriger la doc** : Mettre à jour le guide E2E et les docs pour utiliser `/login` et les URLs réelles (`/admin/kitchen-display-system`, `/admin/order-status-screen`).
4. **OSS mode public** : Vérifier si un mode "écran public" sans login existe (token URL, api-key) pour l'affichage physique.

---

## 5. REVERSE ENGINEERING — CAISSE (POS)

### 5.1 Parcours détaillé étape par étape

| # | Étape | Fichier / Élément | Remarque |
|---|-------|-------------------|----------|
| 1 | Connexion Admin/Manager | Auth | Sanctum |
| 2 | Accès /admin/pos | PosComponent.vue | Route Vue |
| 3 | Recherche + catégories | Swiper, props.search | Navigation menu |
| 4 | Clic item | ItemComponent | XHR `/admin/item/` ou `/admin/setting/item/` |
| 5 | Interception XHR | pos-wizard.js | Capture `lastItemData` avant modal standard |
| 6 | Ouverture wizard | buildSteps(), getAllowedSteps() | Étapes selon detectCategory() |
| 7 | Étapes wizard (Tacos) | viande_sauce → perso → menu → recap | 4 étapes (Sprint 4) |
| 8 | Étapes wizard (Sandwich) | viande_sauce ou sauce_garnitures selon viandeCount | |
| 9 | Récap + instruction | renderRecapStep() | Génère VIANDES:, SUPPLÉMENTS:, FORMULE: |
| 10 | syncAndSubmit | sync DOM → modal caché → Vuex | Envoie item_variations, item_extras |
| 11 | Panier | checkoutProps.form | Client, Token, Type (Emporter/Livraison) |
| 12 | Validation avant paiement | PosComponent:872 | Token obligatoire frontend |
| 13 | Modal paiement | #orderpayment | Cash / CB / Mobile banking |
| 14 | POST /api/admin/pos | PosOrderController::store | PosOrderRequest |
| 15 | OrderService::posOrderStore | Recalcule prix (dbItems), crée Order ACCEPT |

### 5.2 Fichiers techniques clés

| Rôle | Fichier |
|------|---------|
| POS Wizard | `public/js/pos-wizard.js` (~2700 lignes) |
| POS Composant | `resources/js/components/admin/pos/PosComponent.vue` |
| POS Controller | `app/Http/Controllers/Admin/PosOrderController.php` |
| Order Service | `app/Services/OrderService.php` (posOrderStore, tableOrderStore) |
| Validation POS | `app/Http/Requests/PosOrderRequest.php` |
| Validation Items | `app/Rules/ValidJsonOrder.php` |

### 5.3 Points de vigilance identifiés

| ID | Description | Statut |
|----|-------------|--------|
| D-007 | Token : obligatoire frontend (l.872), nullable backend (PosOrderRequest) | À aligner |
| D-008 | Dine-In : masqué (`v-if="false"`) | À documenter ou réactiver |
| UX-03 | Wizard : badge étape X/Y (implémenté PLAN_07) | À valider visuellement |
| D-009 | Wizard : interception XHR fragile | Dette technique |
| D-010 | KDS parsing instruction (implémenté PLAN_06) | À valider |

### 5.4 Structure wizard POS (pos-wizard.js)

| Catégorie | Détection | Étapes |
|-----------|-----------|--------|
| tacos | nom "tacos" | viande_sauce, perso, menu, recap |
| sandwich, burger | catégorie ou nom + viandeCount | viande_sauce ou sauce_garnitures |
| assiette | "assiette" | sauce_accompagnement, supplements, recap |
| salade | "salade" | sauce_supplements, recap |
| omelette, snacking | "omelette", etc. | sauce_single, recap |
| ojja | "ojja" | recap seul |
| menu_enfant, dessert, boisson | mots-clés | recap seul (sans wizard) |

---

## 6. REVERSE ENGINEERING — BORNE (KIOSK)

### 6.1 Parcours détaillé étape par étape

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

### 6.2 Points de vigilance identifiés

| ID | Description | Statut |
|----|-------------|--------|
| D-011 | Confirmation : pas de bip/message fort documenté | À améliorer |
| KUX-01 | Idle 3 min : pas d'avertissement avant reset | À documenter |
| KUX-02 | Pas de MON COMPTE / ALLERGÈNES en header | Gap conformité |
| KUX-03 | Barre progression wizard | À comparer avec GUR |

### 6.3 API Kiosk

| Endpoint | Auth | Usage |
|----------|------|-------|
| POST /api/auth/kiosk-login | Guest | Token machine |
| GET /api/frontend/item | Sanctum | Menu |
| POST /api/frontend/order | Sanctum | Création commande |

---

## 7. MATRICE COMPLÈTE DES PROBLÈMES

### 7.1 Par sévérité

| ID | Type | Sévérité | Description courte | Statut |
|----|------|----------|-------------------|--------|
| D-001 | Sécurité | 🔴 Critique | Fallback prix client si item inexistant | ✅ Mitigé (PLAN_01) |
| D-002 | Sécurité | 🔴 Critique | POS variations/extras = prix client | ✅ Mitigé (PLAN_02) |
| D-003 | Sécurité | 🔴 Haute | Routes table sans auth | À documenter |
| D-004 | Validation | 🔴 Haute | ValidJsonOrder accepte items sans item_id | ✅ Mitigé (PLAN_03) |
| D-005 | Technique | 🔴 | POSComprehensiveTest : items.branch_id | ✅ Fixé (PLAN_05) |
| MA-001 | Technique | 🔴 | payment.blade.php faviconLogo null | ✅ Fixé (PLAN_04) |
| MA-002 | Technique | 🔴 | SettingResource null | ✅ Fixé (PLAN_04) |
| A-002 | Technique | 🟡 | POS export BinaryFileResponse::status | ✅ Fixé |
| D-006 | Technique | 🟡 | Export nom "Online-Order.xlsx" | Basse priorité |
| D-007 | UX | 🟡 | Token frontend/backend incohérent | À aligner |
| D-008 | UX | 🟡 | Dine-In masqué | À documenter |
| D-009 | Architecture | 🟡 | Wizard interception XHR fragile | Dette technique |
| D-010 | UX | 🟡 | KDS instruction non parsée | ✅ Implémenté (PLAN_06) |
| D-011 | UX | 🟡 | Kiosk confirmation faible | À améliorer |
| D-012 | Données | 🟡 | Table order branch_id item | À vérifier |
| UX-01 à UX-05 | UI/UX | 🟡 | Token, client, récap, breadcrumb | Partiel |
| KUX-01 à KUX-05 | UI/UX | 🟡 | Confirmation, idle, temps attente | À améliorer |
| **LOGIN-01** | Doc | 🟡 | Guide E2E : `/admin/login` n'existe pas (réel : `/login`) | À corriger |
| **LOGIN-02** | UX | 🔴 | Redirection après login : staff → `frontend.home` au lieu de defaultPermission | Critique |
| **LOGIN-03** | UX | 🟡 | Page login unique (client + staff) — pas de page dédiée par profil | À améliorer |
| **LOGIN-04** | UX | 🟡 | Mode démo : redirection incorrecte après login staff | À corriger |
| **LOGIN-05** | Doc | 🟡 | Docs : `/admin/kds`, `/admin/oss` vs réels `/admin/kitchen-display-system`, `/admin/order-status-screen` | À corriger |
| **LOGIN-06** | Architecture | 🟡 | OSS : page admin protégée — mode écran public sans login ? | À vérifier |

### 7.2 Par surface (Caisse vs Borne)

| Surface | Problèmes |
|---------|-----------|
| **Caisse** | D-007, D-008, D-009, UX-01 à UX-05 |
| **Borne** | D-011, KUX-01 à KUX-05 |
| **Connexion** | LOGIN-01 à LOGIN-06 |
| **Partagé** | D-003, D-010 (KDS) |

---

## 8. BENCHMARK CONCURRENCE

### 8.1 McDonald's — Parcours kiosk (recherche web)

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

### 8.2 KFC — Parcours kiosk (recherche web)

| Élément | KFC | FoodKing |
|---------|-----|----------|
| Menu Navigation | Catégories + images produits | Catégories + grille ✅ |
| Customisation | Taille, accompagnements, sauces | Wizard multi-étapes ✅ |
| Paiement | CB, Apple Pay, Google Pay, cash | Cash/Card |
| Confirmation | Receipt + temps estimé | Numéro commande |
| Accessibilité | Multi-langue, zones tactiles | À documenter |

### 8.3 GUR KEBAB — Parcours (photos fournies)

| Élément | GUR KEBAB | FoodKing |
|---------|-----------|----------|
| Header | MON COMPTE, ALLERGÈNES | Absent Kiosk |
| Sidebar | Catégories (Baguettes, Faluche, etc.) | Catégories ✅ |
| Barre progression | Étape 1-7, icônes, numérotée | Badge implémenté (POS) |
| Options | Image + nom + prix par option | Emojis / thumbs |
| Rupture stock | "EN RUPTURE" bannière rouge | Non documenté |
| Badge NOUVEAU | "NOUVEAU À CROQUER!" | is_featured items seulement |
| Abandon | ABANDONNER L'ARTICLE vs MA COMMANDE | À clarifier |
| Total | Toujours visible en bas | Partiel |
| Footer wizard | PRÉCÉDENT, SUIVANT, Total | Retour, Suivant, Ajouter |

### 8.4 Synthèse comparaison

| Critère | McDo | KFC | GUR | FoodKing |
|---------|------|-----|-----|----------|
| Type commande en premier | ✅ | ✅ | ✅ | ✅ Kiosk |
| Barre progression | ✅ | ✅ | ✅ | ✅ POS (badge) |
| Rupture stock visible | ❌ (problème) | ? | ✅ | ❌ |
| Allergènes | ? | ? | ✅ | ❌ |
| Numéro unique | ❌ (confusion) | ? | ✅ | ✅ queue_number |
| Total toujours visible | ✅ | ✅ | ✅ | Partiel |

---

## 9. CHECKERS ET VÉRIFICATIONS

### 9.1 Checklist manuelle — POS

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

### 9.2 Checklist manuelle — Kiosk

| # | Vérification | Comment |
|---|--------------|---------|
| K1 | Type en premier | Écran après idle |
| K2 | Barre progression | Wizard item |
| K3 | Confirmation | Après paiement |
| K4 | Idle 3 min | Comportement |
| K5 | MON COMPTE / ALLERGÈNES | Header |
| K6 | Upsell dessert | Si pas dessert |
| K7 | Temps attente | Affiché ou non |

### 9.3 Checklist manuelle — Connexion

| # | Vérification | Comment |
|---|--------------|---------|
| L1 | URL login | `/login` (pas `/admin/login`) |
| L2 | Redirection POS Operator | Login posoperator@example.com → vérifier destination |
| L3 | Redirection Chef | Login chef@example.com → vérifier destination |
| L4 | KDS URL | `/admin/kitchen-display-system` |
| L5 | OSS URL | `/admin/order-status-screen` |

### 9.4 Checklist documentaire

| # | Document | Vérification |
|---|----------|---------------|
| D1 | PosOrderRequest vs PosComponent | token cohérent |
| D2 | ORDER_FLOW.md | Transitions à jour |
| D3 | BUSINESS_RULES.md | Rupture stock, allergènes |
| D4 | WIZARD_LOGIC_DOCUMENTATION.md | Étapes = pos-wizard.js |
| D5 | API_MAP.md | Routes table documentées |

---

## 10. 5–10 IDÉES D'AMÉLIORATION

### Idée 1 : Barre de progression wizard (POS + Kiosk)

**Problème potentiel :** L'utilisateur ne voit pas combien d'étapes restent. GUR KEBAB affiche "Étape 3/7" avec icônes.

**État actuel :** Badge étape implémenté (PLAN_07) dans pos-wizard.js. À valider visuellement.

**Action :** Valider sur staging et étendre au Kiosk Flutter (ItemDetailsScreen).

---

### Idée 2 : Gestion rupture de stock (items, extras, addons)

**Problème potentiel :** Si un supplément (ex. Cheddar) est en rupture, le client peut quand même le sélectionner. GUR KEBAB grise et affiche "EN RUPTURE".

**Vérification :** Les modèles `Item`, `ItemExtra`, `ItemAddon` ont un champ `status`. Documenter le comportement attendu dans `BUSINESS_RULES.md`.

**Action :** Filtrer par status=ACTIVE dans les APIs. Si besoin d'afficher "EN RUPTURE", définir logique métier.

---

### Idée 3 : Badge NOUVEAU / Promo sur items et suppléments

**Problème potentiel :** GUR KEBAB met en avant "NOUVEAU À CROQUER!" sur des suppléments. FoodKing a `is_featured` sur les items.

**Vérification :** `ItemExtra`, `ItemAddon` — vérifier le schéma pour `is_new`, `is_featured`.

**Action :** Audit schéma DB — colonnes `is_new`, `is_featured` sur item_extras, item_addons.

---

### Idée 4 : MON COMPTE / ALLERGÈNES sur Kiosk

**Problème potentiel :** GUR KEBAB propose "MON COMPTE" (fidélité) et "ALLERGÈNES" (conformité) en header. FoodKing Kiosk — ces entrées existent-elles ?

**Action :** Revue des écrans Kiosk Flutter. Si absents, documenter comme gap de conformité (réglementation allergènes en restauration).

---

### Idée 5 : Granularité des garnitures (Avec/Sans par ingrédient)

**Problème potentiel :** GUR KEBAB propose "Sans Oignon", "Seulement Salade", "Sans Tomate" etc. FoodKing a "Complet", "Sans Oignon", "Sans Tomate", "Sans Salade", "Aucune".

**Action :** Comparer la liste des options garnitures dans `pos-wizard.js` avec les écrans GUR. Documenter les combinaisons possibles.

---

### Idée 6 : Double abandon (article vs commande)

**Problème potentiel :** GUR distingue "ABANDONNER L'ARTICLE" (annuler l'item en cours) et "ABANDONNER MA COMMANDE" (vider tout le panier).

**Action :** Vérifier libellés et placement dans le wizard et le panier.

---

### Idée 7 : Total toujours visible

**Problème potentiel :** GUR affiche "Total XX.XX €" à chaque étape du wizard. FoodKing met-il à jour le total en temps réel pendant le wizard ?

**Action :** Test manuel — À l'étape "Sauce", après avoir coché 2 sauces, le total doit afficher +0.50€.

---

### Idée 8 : Idempotency — double soumission

**Problème potentiel :** Réseau lent + double clic sur "Payer" = 2 commandes identiques. Aucune idempotency key documentée.

**Action :** Test — Simuler un délai réseau, double-cliquer "Payer". Documenter le risque dans `docs/SECURITY_NOTES.md`.

---

### Idée 9 : Accessibilité (icône fauteuil roulant)

**Problème potentiel :** GUR affiche une icône accessibilité. Les bornes FoodKing sont-elles conformes (hauteur, contrastes, taille des zones tactiles) ?

**Action :** Revue des tailles de boutons (min 44x44px recommandé), contrastes couleurs (WCAG). Documenter dans `docs/ACCESSIBILITY.md`.

---

### Idée 10 : Ordre des étapes wizard vs logique métier

**Problème potentiel :** GUR : Sauce → Crudités → Supplément → Frites → Sauce frites → Boisson. FoodKing Tacos : Viandes → Sauce → Garnitures → Suppléments → Menu → Sauce frites → Récap.

**Action :** Validation avec un utilisateur terrain — l'ordre des questions est-il naturel ?

---

## 11. PLAN D'IMPLÉMENTATION PRIORISÉ

### Phase P0 — Critique (sécurité) — ✅ COMPLÉTÉ

| Ordre | ID | Tâche | Statut |
|-------|-----|-------|--------|
| 1 | D-001 | Rejeter item inexistant | ✅ PLAN_01 |
| 2 | D-002 | POS : prix DB variations/extras | ✅ PLAN_02 |
| 3 | D-004 | ValidJsonOrder item_id, quantity | ✅ PLAN_03 |

### Phase P1 — Haute

| Ordre | ID | Tâche |
|-------|-----|-------|
| 4 | D-003 | Documenter / sécuriser routes table |
| 5 | D-012 | Table order branch_id depuis order |
| 6 | Idée 2 | Rupture de stock : doc + comportement |

### Phase P2 — Moyenne

| Ordre | ID | Tâche |
|-------|-----|-------|
| 6 | LOGIN-02 | Redirection après login vers defaultPermission |
| 7 | D-007 | Aligner token |
| 8 | D-008 | Message Dine-In |
| 9 | D-011 | Confirmation Kiosk |
| 10 | Idée 4 | Allergènes / MON COMPTE |

### Phase P3 — Basse

| Ordre | ID | Tâche |
|-------|-----|-------|
| 11 | D-006 | Nom fichier export |
| 12 | D-009 | Découpler wizard XHR |
| 13 | Idée 3 | Badge NOUVEAU |
| 14 | Idée 8 | Idempotency key |

---

## 12. RÉFÉRENCES

### 12.1 Documents projet

| Fichier | Rôle |
|---------|------|
| docs/ARCHITECTURE.md | Architecture globale |
| docs/API_MAP.md | Routes et auth |
| docs/ORDER_FLOW.md | Flux commande |
| docs/DEVICE_FLOW.md | Flux appareils |
| docs/BUSINESS_RULES.md | Règles métier |
| docs/WIZARD_LOGIC_DOCUMENTATION.md | Étapes wizard |
| docs/ERROR_HANDLING.md | Gestion erreurs API |

### 12.2 Rapports d'audit

| Fichier | Contenu |
|---------|---------|
| reports/antigravity/AUDIT_COMPLET_CAISSE_BORNE_20260310.md | Audit initial |
| reports/antigravity/AUDIT_PROFOND_CAISSE_BORNE_20260310.md | Audit profond |
| reports/antigravity/AUDIT_CONSOLIDE_CAISSE_BORNE_GUR_20260310.md | Benchmark GUR |
| reports/antigravity/AUDIT_MASSIF_E2E_POS_KDS_KIOSK_SUITE.md | Suite E2E |
| reports/antigravity/AUDIT_PROFOND_POST_IMPLEMENTATION_20260312.md | Post-implémentation |

### 12.3 Sources externes

- McDonald's kiosk UX flow (Self Service Kiosk Review, Flavor365)
- McDonald's kiosk UX case study (Chee Seng Leong, UX Collective)
- KFC Self-Ordering Kiosks (Flying Bisons, KioskSelfOrdering)
- Photos GUR KEBAB (borne, wizard, sauces, crudités, suppléments, frites)

---

## 13. ANNEXES TECHNIQUES

### 13.1 Fichiers techniques clés

| Rôle | Fichier |
|------|---------|
| POS Wizard | `public/js/pos-wizard.js` (~2700 lignes) |
| POS Composant | `resources/js/components/admin/pos/PosComponent.vue` |
| POS Controller | `app/Http/Controllers/Admin/PosOrderController.php` |
| Order Service | `app/Services/OrderService.php` |
| Frontend Order | `app/Services/FrontendOrderService.php` |
| Validation POS | `app/Http/Requests/PosOrderRequest.php` |
| Validation Items | `app/Rules/ValidJsonOrder.php` |
| KDS | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` |

### 13.2 API Endpoints

| Endpoint | Auth | Usage |
|----------|------|-------|
| POST /api/auth/kiosk-login | Guest | Token machine |
| POST /api/admin/pos | Sanctum | Création commande POS |
| POST /api/frontend/order | Sanctum | Création commande Kiosk |
| GET /api/admin/pos-order | Sanctum | Liste commandes |
| GET /api/admin/kds-order | Sanctum | Commandes cuisine |

### 13.3 État des implémentations (Plans 1–12)

| Plan | Statut | Fichiers |
|------|--------|----------|
| PLAN_01 D-001 | ✅ | FrontendOrderService, OrderService |
| PLAN_02 D-002 | ✅ | OrderService.php |
| PLAN_03 D-004 | ✅ | ValidJsonOrder.php |
| PLAN_04 MA-001/002 | ✅ | SettingResource, payment.blade |
| PLAN_05 D-005 | ✅ | POSComprehensiveTest |
| PLAN_06 D-010 | ✅ | KitchenDisplaySystemComponent.vue |
| PLAN_07 UX-03 | ✅ | pos-wizard.js |
| PLAN_08 D-007 | ✅ | Token nullable (déjà) |
| PLAN_09 D-008 | ✅ | Documenté |
| PLAN_10 D-011 | ✅ | Documenté (Flutter) |
| PLAN_11 ARCH-01 | ✅ | Migration ItemCategory |
| PLAN_12 ARCH-02 | ✅ | ItemResource, pos-wizard.js |

---

## RÉSUMÉ POUR DÉVELOPPEUR

**À faire en priorité :**
1. D-003, D-012 (Documenter routes table, branch_id)
2. Idée 2 (Rupture de stock)
3. D-007 (Aligner token)
4. Idée 4 (Allergènes Kiosk)

**Architecture produits :**
5. Documenter structure catégories/items dans docs/
6. Enrichir ItemCategory (wizard_template, has_menu) — ✅ DÉJÀ FAIT
7. Kiosk : menu par défaut si default_menu_kiosk

**À ne pas oublier :**
- Tests POS : 8/8 pass
- Rupture de stock : documenter dans BUSINESS_RULES
- Allergènes : conformité réglementaire Kiosk

---

**Fin du rapport final consolidé.**

*Document maître — Aucune modification de code effectuée. Tests exécutés et documentés. À utiliser pour audit manuel et planification.*
