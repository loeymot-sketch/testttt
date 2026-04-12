# 🔍 AUDIT COMPLET — Caisse (POS) & Borne Android (Kiosk)

> **Date:** 10 Mars 2026  
> **Auditeur:** Agent QA (Playwright / E2E verification / Cursor)  
> **Mission:** Audit exhaustif du parcours de commande pour la caisse et la borne Android — identification des problèmes visuels, techniques, UI/UX, comparaison avec logique humaine et concurrence (McDonald's, KFC, etc.)  
> **Contrainte:** Aucune modification du code — tests éphémères uniquement, rapport et documentation uniquement.

---

## 📊 RÉSUMÉ EXÉCUTIF

### Périmètre audité

| Surface | Technologie | Parcours principal |
|---------|-------------|---------------------|
| **Caisse (POS)** | Vue.js + pos-wizard.js | Menu → Sélection item → Wizard multi-étapes → Panier → Paiement → Ticket |
| **Borne Android (Kiosk)** | Flutter (GetX) | Auth machine → Type commande → Menu → Détail item → Panier → Paiement |

### Score global

| Domaine | Score | Statut |
|---------|-------|--------|
| Tests AntiGravity (auth, isolation, prix, KDS, OSS) | 20/20 | 🟢 |
| Tests POSComprehensive | 6/8 | 🟡 |
| Architecture & flux documentés | OK | 🟢 |
| Parcours utilisateur (reverse engineering) | Voir sections détaillées | 🟡 |

### Problèmes identifiés (sans modification de code)

| ID | Type | Sévérité | Description |
|----|------|----------|-------------|
| A-001 | Technique | 🔴 | POSComprehensiveTest : `items` table n'a pas de colonne `branch_id` — ItemFactory tente d'insérer `branch_id` |
| A-002 | Technique | 🟡 | POSComprehensiveTest : `pos can export orders` — `BinaryFileResponse::status()` n'existe pas |
| A-003 | UX | 🟡 | POS : Token obligatoire côté frontend mais `PosOrderRequest` le définit `nullable` — incohérence |
| A-004 | UX | 🟡 | POS : Dine-In masqué (`v-if="false"`) — option non disponible sans explication |
| A-005 | UI/UX | 🟢 | Wizard POS : dépendance à interception XHR — fragile si API change |
| A-006 | Concurrence | 🟡 | Parcours Kiosk vs McDo/KFC : pas de confirmation vocale/visuelle forte avant paiement |
| A-007 | Sécurité | 🟡 | Source Kiosk = `Source::APP` — indistinguable de l'app mobile (déjà documenté SEC-001) |

---

## 1. ARCHITECTURE COMPRISE

### 1.1 Vue d'ensemble

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         FOODKING — ÉCOSYSTÈME MULTI-SURFACE                   │
├─────────────────────────────────────────────────────────────────────────────┤
│  KIOSK (Borne)     │  POS (Caisse)      │  KDS (Cuisine)   │  OSS (Client)   │
│  Flutter/GetX      │  Vue.js + wizard  │  Vue.js          │  Écran statut   │
│  POST /frontend/*  │  POST /admin/pos  │  GET /kds-order  │  GET /oss-order │
│  Token machine     │  Auth Manager     │  Auth Chef       │  Token display │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Flux de commande (ORDER_FLOW.md)

1. **Création (PENDING)** — Kiosk ou POS crée la commande
2. **Paiement (ACCEPT)** — Caissier valide le règlement
3. **Cuisine (PREPARING)** — Chef démarre la préparation
4. **Prêt (PREPARED)** — Plat sur le comptoir
5. **Livraison (DELIVERED)** — Remis au client

### 1.3 Device Flow (DEVICE_FLOW.md)

- **Kiosk** : Client non loggué, machine reconnue, panier en mémoire, POST `/api/frontend/order`
- **POS** : Caissier authentifié, écriture directe backend, limité à sa branche
- **KDS** : Chef, transitions ACCEPT → PREPARING → PREPARED
- **OSS** : Lecture seule, affichage queue_number

---

## 2. AUDIT CAISSE (POS)

### 2.1 Parcours utilisateur (reverse engineering)

```
1. Connexion Admin/Manager
2. Accès /admin/pos
3. Recherche / navigation catégories (swiper)
4. Clic sur un item → ouverture modal (interceptée par pos-wizard.js)
5. Wizard multi-étapes (selon catégorie) :
   - Tacos : Viandes (1–4) → Sauce → Garnitures → Suppléments → Menu combo → Sauce frites → Récap
   - Sandwich/Burger : 6 étapes
   - Assiette : 4 étapes
   - Salade : 3 étapes
   - etc.
6. Ajout au panier
7. Panier : Client, Token, Type (Emporter/Livraison), Items
8. Clic "Payer" → Modal paiement (Cash/CB/Mobile banking)
9. Envoi POST /api/admin/pos → Création commande
10. Ticket / confirmation
```

### 2.2 Points techniques

| Élément | Fichier | Observation |
|--------|---------|-------------|
| Wizard | `public/js/pos-wizard.js` | Interception XHR sur `/admin/item/` et `/admin/setting/item/` pour capturer `lastItemData` |
| Détection catégorie | `pos-wizard.js` | `detectCategory()`, `detectViandeCount()` — logique basée sur nom + catégorie |
| Récap | `pos-wizard.js` | `renderRecapStep()` — gère `menu_choice` (Frites, Boisson) |
| Token | `PosComponent.vue:75` | `type="text"` — correct pour éviter problèmes numériques |
| Validation token | `PosComponent.vue:872-874` | `if (!this.checkoutProps.form.token) return alertService.error(...)` — **obligatoire côté frontend** |
| API token | `PosOrderRequest.php:31` | `'token' => ['nullable', 'string', 'numeric']` — **nullable côté backend** |
| Dine-In | `PosComponent.vue:84` | `v-if="false"` — option masquée |

### 2.3 Problèmes UI/UX identifiés (POS)

| ID | Problème | Comparaison concurrence |
|----|----------|-------------------------|
| UX-01 | Token obligatoire sans explication claire (numéro de commande ?) | McDo/KFC : numéro affiché après commande, pas avant |
| UX-02 | Dine-In masqué — pas de message "Non disponible" | Concurrence : affiche "Temporairement indisponible" |
| UX-03 | Wizard : pas de breadcrumb visible "Étape X sur Y" | McDo/KFC : barre de progression explicite |
| UX-04 | Pas de récapitulatif prix détaillé avant paiement (sous-total, TVA, total) | Standard QSR : détail avant validation |
| UX-05 | Champ client obligatoire — pas de "Client invité" par défaut | Risque de blocage si pas de client créé |

### 2.4 Logique humaine vs implémentation

- **Attente utilisateur** : "Je choisis mon plat, je valide, je paie."
- **Implémentation** : Client + Token + Type de commande requis avant d'ouvrir le modal paiement.
- **Écart** : Le token (numéro de commande/ticket ?) est demandé en amont — logique de file d'attente ou de pré-commande. Si c'est un numéro de borne kiosk, la caisse ne devrait pas le demander de la même façon.

---

## 3. AUDIT BORNE ANDROID (KIOSK)

### 3.1 Parcours utilisateur (reverse engineering)

```
1. Écran de connexion machine (kiosk-login)
2. Sélection type de commande (Emporter / Sur place / Livraison)
3. Menu : catégories + items (ItemComponent, grille)
4. Clic item → ItemDetailsScreen (wizard Flutter)
5. Wizard multi-étapes (CategoryHelper.detectCategory) :
   - attribute_slot (viandes), attributes, extras, addons
   - Bouton "Même" pour répéter sélection viande précédente
6. Ajout au panier
7. CartScreen : liste items, total, bouton "Commander"
8. Upsell (DessertUpsellScreen, PostAddScreen) possible
9. PaymentScreen → POST /api/frontend/order
10. Écran succès / numéro de file
```

### 3.2 Points techniques (Kiosk Flutter)

| Élément | Fichier | Observation |
|--------|---------|-------------|
| Wizard | `item_details_screen.dart` | `_buildEffectiveSteps()`, `CategoryHelper.buildDynamicSteps()` |
| Multi-viandes | `_meatSlotSelections` | Map attributeId → Variations pour "Même" |
| Panier | `cart_screen.dart` | `CartController`, `OrderController` |
| Idle | `IdleService` | Reset sur `onPointerDown` — retour menu après inactivité |
| API | `POST /api/frontend/order` | OrderRequest avec items, branch_id, etc. |

### 3.3 Problèmes UI/UX identifiés (Kiosk)

| ID | Problème | Comparaison concurrence |
|----|----------|-------------------------|
| KUX-01 | Pas de confirmation vocale/visuelle forte "Commande enregistrée" avant paiement | McDo/KFC : bip + message + numéro affiché en grand |
| KUX-02 | Flux upsell (dessert) — risque de confusion si mal placé | Concurrence : upsell après validation panier, avant paiement |
| KUX-03 | Pas de retour arrière explicite sur certaines étapes | Standard : flèche ou "Retour" visible |
| KUX-04 | Idle 3 min — pas d'avertissement "Session expirée" | McDo : compte à rebours ou message avant reset |
| KUX-05 | Pas d'indication "Temps d'attente estimé" | Concurrence : "~10 min" ou "Prêt dans X min" |

### 3.4 Logique humaine vs implémentation

- **Attente** : "Je touche l'écran, je choisis, je paie, j'ai mon numéro."
- **Implémentation** : Connexion machine → Type commande → Menu → Détail → Panier → Paiement.
- **Écart** : Le type de commande (Emporter/Sur place/Livraison) est demandé en premier — cohérent avec McDo/KFC. Le wizard item est aligné (viandes, sauces, extras).

---

## 4. RÉSULTATS DES TESTS ÉPHÉMÈRES

### 4.1 AntiGravityTest (20 tests)

```
✅ T01  kiosk login valid
✅ T02  kiosk login invalid
✅ T03  kiosk login already logged in
✅ T04  kiosk login inactive
✅ T05  kiosk cannot access admin
✅ T06  kiosk can create order
✅ T07  kiosk cannot read pos orders
✅ T08  order forged price uses db price
✅ T09  order forged total rejected
✅ T10  invalid coupon rejected
✅ T11  order without auth returns 401
✅ T12  pending order visible in pos
✅ T13  pending to accept transitions
✅ T14  pending to prepared rejected
✅ T18  kds sees only own branch
✅ T20  kds cannot mark delivered
✅ T22  oss post rejected
✅ T23  oss without token rejected
✅ T08b pos order forged price uses db price
✅ T08c pos kds notification dispatched
```

**Verdict :** 20/20 passent. (Les problèmes MA-001/MA-002 du rapport-massive-audit-001 semblent résolus ou l'environnement de test est différent.)

### 4.2 POSComprehensiveTest (8 tests)

```
❌ pos can create order     — SQLSTATE: table items has no column named branch_id
✅ pos can list orders
✅ pos can view order details
✅ pos can change status to preparing
✅ pos can change payment status
✅ pos can delete order
❌ pos can export orders   — Call to undefined method BinaryFileResponse::status()
✅ pos can reorder items
```

**Détails :**

- **pos can create order** : `ItemFactory` reçoit `branch_id` en override, mais la table `items` (migration `2022_11_17_110514_create_items_table.php`) n'a pas de colonne `branch_id`. Le schéma items est global (item_category_id, tax_id, etc.), pas par branche.
- **pos can export orders** : Le test appelle `$response->status()` sur une `BinaryFileResponse` qui n'a pas cette méthode (ou assertion incorrecte).

---

## 5. COMPARAISON CONCURRENCE (McDonald's, KFC, etc.)

### 5.1 Parcours type QSR (Quick Service Restaurant)

| Étape | McDo/KFC | FoodKing POS | FoodKing Kiosk |
|-------|----------|--------------|----------------|
| 1 | Choix type (Sur place/Emporter) | Type après items | Type en premier ✅ |
| 2 | Navigation catégories | Swiper catégories ✅ | Grille catégories ✅ |
| 3 | Détail produit (options) | Wizard multi-étapes ✅ | Wizard multi-étapes ✅ |
| 4 | Panier | Panier latéral ✅ | Écran panier dédié ✅ |
| 5 | Récap avant paiement | Modal paiement | PaymentScreen |
| 6 | Paiement | CB/Cash/Touch | CB/Cash/Mobile |
| 7 | Numéro file | Affiché en grand | Via OSS/écran |
| 8 | Ticket | Imprimé | - |

### 5.2 Écarts principaux

1. **Token/N° commande** : FoodKing demande un token avant paiement (POS). La concurrence affiche le numéro après validation.
2. **Client obligatoire** : FoodKing exige un client sélectionné. McDo/KFC en caisse ne demandent pas d'identité client pour une commande simple.
3. **Dine-In** : Masqué dans FoodKing — à clarifier (désactivé par config ?).
4. **Upsell** : Kiosk a un flux upsell (dessert) — aligné avec la concurrence.

---

## 6. PROBLÈMES TECHNIQUES (REVERSE ENGINEERING)

### 6.1 Schéma base de données

- **items** : Pas de `branch_id` dans la migration de base. Les items semblent globaux (partagés entre branches). `ItemFactory` dans POSComprehensiveTest suppose un `branch_id` — incohérence.
- **order_items** : A `branch_id` (migration `create_order_items_table`).

### 6.2 API

- **PosOrderRequest** : `token` nullable, `pos_received_amount` requis si CASH, `pos_payment_note` requis si CARD/MOBILE_BANKING/OTHER.
- **Frontend Order** : Recalcule les prix côté serveur (T08, T08b) — bonne pratique.

### 6.3 Wizard POS (pos-wizard.js)

- Interception XHR : Dépend des URLs `/admin/item/` et `/admin/setting/item/`. Si l'API change, le wizard peut ne plus recevoir les données.
- Config en dur : `VIANDES`, `ALL_SAUCES`, `SAUCE_EXTRA_PRICE` — pas de configuration backend.

---

## 7. RECOMMANDATIONS (SANS MODIFICATION — POUR AUDIT MANUEL)

### Priorité haute

1. **A-001** : Corriger `POSComprehensiveTest` — ne pas passer `branch_id` à `ItemFactory` pour les items, ou ajouter une migration `branch_id` aux items si le modèle métier le requiert.
2. **A-002** : Corriger le test `pos can export orders` — utiliser l'assertion adaptée aux réponses binaires (ex. `assertSuccessful()` ou vérifier les headers).
3. **A-003** : Aligner token POS — soit le rendre optionnel côté frontend, soit documenter pourquoi il est obligatoire (ex. numéro de borne, file d'attente).

### Priorité moyenne

4. **UX-01 à UX-05** : Revue UX POS (token, client, récap, breadcrumb).
5. **KUX-01 à KUX-05** : Revue UX Kiosk (confirmation, idle, temps d'attente).
6. **A-005** : Rendre le wizard POS moins dépendant de l'interception XHR (ex. passer les données via un bus ou une API dédiée).

### Priorité basse

7. **A-006, A-007** : Améliorer feedback Kiosk et source dédiée (SEC-001).

---

## 8. FICHIERS CLÉS AUDITÉS

| Fichier | Rôle |
|---------|------|
| `resources/js/components/admin/pos/PosComponent.vue` | Composant principal POS |
| `public/js/pos-wizard.js` | Wizard multi-étapes POS |
| `public/css/pos-wizard.css` | Styles wizard |
| `app/Http/Requests/PosOrderRequest.php` | Validation commande POS |
| `app/Http/Controllers/Admin/PosOrderController.php` | Contrôleur POS |
| `docs/WIZARD_LOGIC_DOCUMENTATION.md` | Documentation wizard |
| `projet kiosk/extracted_kiosk_app/lib/app/features/menu/screens/item_details_screen.dart` | Wizard Kiosk |
| `projet kiosk/extracted_kiosk_app/lib/app/features/cart/screens/cart_screen.dart` | Panier Kiosk |
| `tests/Feature/AntiGravityTest.php` | Tests E2E critiques |
| `tests/Feature/POSComprehensiveTest.php` | Tests POS |
| `docs/ORDER_FLOW.md` | Flux commande |
| `docs/DEVICE_FLOW.md` | Flux par appareil |

---

## 9. CONCLUSION

L'audit révèle un système globalement cohérent avec une architecture multi-surface bien documentée. Les tests AntiGravity passent tous, confirmant l'intégrité de l'authentification, de l'isolation Kiosk, du recalcul des prix et des flux KDS/OSS.

Les principaux points à traiter concernent :
- Les tests POS (schéma items, assertion export),
- L'alignement token/client entre frontend et backend,
- Les améliorations UX pour se rapprocher des standards QSR (McDo, KFC).

**Aucune modification de code n'a été effectuée.** Ce rapport est destiné à un audit manuel et à la planification des corrections.

---

**Fin du rapport.**

*Prochaine étape recommandée :* Revue manuelle du rapport, priorisation des correctifs, puis exécution selon `workflows/task-routing.md`.
