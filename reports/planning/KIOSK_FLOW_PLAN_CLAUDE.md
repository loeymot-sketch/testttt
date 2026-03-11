# 🧠 PLAN KIOSK ANDROID — PARCOURS DE COMMANDE COMPLET
**Auteur :** Claude (Lead Architect)  
**Version :** 1.0 — Sprint 4 Borne  
**Date :** 11 Mars 2026 | 15h25  
**Cible :** Flutter Android — App Kiosk "Le Grill House"

---

## 📐 1. ÉTAT DU CODE EXISTANT (Audit complet)

### Ce qui EXISTE et FONCTIONNE — NE PAS RETOUCHER
| Fichier | Status | Note |
|---------|--------|------|
| `item_details_screen.dart` (1356 lignes) | ✅ COMPLET | Wizard PageView + WizardStep + CategoryHelper |
| `cart_screen.dart` | ✅ COMPLET | Affichage panier + delete items |
| `order_confirm_screen.dart` | ✅ COMPLET | Bluetooth print + auto-return |
| `menu_screen.dart` | ✅ COMPLET | Catégories + items grid |
| `home_screen.dart` | ✅ COMPLET | Écran d'accueil + clock |
| `upsell_service.dart` + `upsell_modal_widget.dart` | ✅ COMPLET | Upsell engine |
| `idle_service.dart` | ✅ COMPLET | Timeout 3 min → HomeScreen |

### Ce qui MANQUE — À IMPLEMENTER

1. **Flux global non orchestré** : HomeScreen → MenuScreen → Cart → Payment se fait par navigation fragmentée. Il n'y a pas de "journey controller" central.
2. **Écran de décision post-ajout panier** : Après avoir ajouté un item, rien ne propose de "Continuer mes achats" vs "Voir mon panier".
3. **Upsell Dessert en fin de parcours** : Avant paiement, pas de suggestion dessert/boisson.
4. **Écran de sélection du mode de paiement** : `cart_screen.dart` envoie directement sans UI de choix.
5. **Écran de confirmation de commande optimisé** : L'actuel est fonctionnel mais le flow retour est abrupt.

---

## 🏗️ 2. ARCHITECTURE DU PARCOURS COMPLET

### Diagramme de flux global (style McDo/BK)

```
┌─────────────────────────────────────────────────────────────┐
│  ÉCRAN 1: ATTRACT SCREEN (HomeScreen existant)              │
│  • Vidéo/Animation plein écran                              │
│  • "Touchez pour commander"                                 │
│  • Choix langue (FR/EN/AR)                                 │
│  • Lien Admin discret (coin bas-droit)                     │
└────────────────────┬────────────────────────────────────────┘
                     │ TAP ANYWHERE
                     ▼
┌─────────────────────────────────────────────────────────────┐
│  ÉCRAN 2: ORDER TYPE (NOUVEAU)                              │
│  • "Sur place" [🍽️] ou "À emporter" [🛍️]                   │
│  • Grandes cartes tactiles (>150px)                        │
│  • Animation de sélection                                  │
└────────────────────┬────────────────────────────────────────┘
                     │ CHOIX
                     ▼
┌─────────────────────────────────────────────────────────────┐
│  ÉCRAN 3: MENU SCREEN (MenuScreen existant amélioré)       │
│  • Catégories en haut (scroll horizontal)                  │
│  • Items en grille (images grandes)                        │
│  • Bouton panier flottant (badge quantité + montant)       │
│  • Barre bas avec panier                                   │
└────────────────────┬────────────────────────────────────────┘
                     │ TAP ITEM
                     ▼
┌─────────────────────────────────────────────────────────────┐
│  ÉCRAN 4: CONFIGURATEUR WIZARD (ItemDetailsScreen existant) │
│  • Wizard par étapes (déjà fonctionnel)                    │
│  • Footer fixe avec prix courant + CTA                     │
└────────────────────┬────────────────────────────────────────┘
                     │ "AJOUTER AU PANIER"
                     ▼
┌─────────────────────────────────────────────────────────────┐
│  ÉCRAN 5: DÉCISION POST-AJOUT (NOUVEAU — PostAddScreen)     │
│  • Animation "✅ Ajouté !" (1 seconde)                     │
│  • 2 boutons CTA :                                         │
│    [🛒 Voir mon panier (X articles - XX.XX€)]              │
│    [+ Ajouter un autre produit]                            │
│  • Auto-avance vers panier si inactif 8 secondes           │
└─────────────────┬──────────────────┬───────────────────────┘
          VOIR PANIER          AJOUTER PRODUIT
                │                    │
                │              → MenuScreen
                ▼
┌─────────────────────────────────────────────────────────────┐
│  ÉCRAN 6: PANIER (CartScreen existant amélioré)            │
│  • Liste items avec image/nom/prix/quantité                │
│  • Modifier / Supprimer chaque item                        │
│  • Sous-total, remise, total TVA                           │
│  • CTA "Valider ma commande →"                             │
└────────────────────┬────────────────────────────────────────┘
                     │ VALIDER
                     ▼
┌─────────────────────────────────────────────────────────────┐
│  ÉCRAN 7: UPSELL DESSERT/BOISSON (NOUVEAU — DessertUpsell) │
│  • "Et pour finir ? 🍰"                                    │
│  • Grille: Desserts + Boissons populaires (4-6 items)     │
│  • "Non merci →" en haut à droite                         │
│  • Auto-skip si aucun item dessert en DB                   │
└────────────────────┬────────────────────────────────────────┘
              SÉLECTION OU SKIP
                     ▼
┌─────────────────────────────────────────────────────────────┐
│  ÉCRAN 8: RÉCAPITULATIF + PAIEMENT (NOUVEAU — PaymentScreen)│
│  • Récapitulatif compact de la commande                    │
│  • TOTAL final bien visible                                │
│  • 2 modes de paiement (si configuré) :                   │
│    [💳 Carte Bancaire]  [💵 Espèces au Comptoir]          │
│  • Note: Dans la borne, souvent "Espèces au comptoir"     │
│    est juste pour informer le personnel                    │
└────────────────────┬────────────────────────────────────────┘
              CONFIRMER PAIEMENT
                     ▼
┌─────────────────────────────────────────────────────────────┐
│  ÉCRAN 9: CONFIRMATION COMMANDE (OrderConfirmScreen existant│
│  avec améliorations)                                        │
│  • Numéro de commande GRAND (ex: A047)                     │
│  • Animation de succès                                     │
│  • Impression ticket Bluetooth automatique                 │
│  • "Merci ! Votre commande est en préparation 🍔"          │
│  • Compte à rebours 15 secondes → MenuScreen              │
└────────────────────┬────────────────────────────────────────┘
                     │ AUTO-RETOUR
                     ▼
                 HomeScreen
```

---

## 🔨 3. PLAN D'IMPLÉMENTATION KIMI — 6 TÂCHES

> **Test type :** `Anti-Gravity` pour tous les écrans visuels  
> **Scope :** Uniquement les fichiers Flutter du dossier `projet kiosk/`  
> **Règle :** `syncAndSubmit()` et le wizard existant sont INTOUCHABLES

---

### TÂCHE 1 — [P0] Nouvel écran OrderTypeScreen (Sur place / À emporter)

**Fichier à créer :** `lib/app/features/home/screens/order_type_screen.dart`

```dart
// Design :
// - Fond dégradé brand color
// - Logo restaurant en haut centré
// - Question "Comment souhaitez-vous commander ?"
// - 2 cartes larges côte à côte (min 200px hauteur)
// - Card "SUR PLACE" : icon 🍽️, titre, sous-titre
// - Card "À EMPORTER" : icon 🛍️, titre, sous-titre  
// - Animation scale au tap (0.95 → 1.0 bounce)
// - Stocke le choix dans GetStorage('order_type')
// - Navigue vers MenuScreen

// Modifier home_screen.dart :
// Le bouton principal navigue vers OrderTypeScreen, pas MenuScreen
```

**Champs GetStorage à créer :**
```dart
const kOrderType = 'order_type'; // 'dine_in' | 'takeaway'
```

---

### TÂCHE 2 — [P0] Écran PostAddScreen — Décision post-ajout panier

**Fichier à créer :** `lib/app/features/cart/screens/post_add_screen.dart`

```dart
// Design :
// - Fond semi-transparent sur MenuScreen (Bottom Sheet ou Dialog)
// - Animation check vert 1 seconde au centre
// - Nom de l'item ajouté + prix
// - 2 CTAs (côte à côte, pleine largeur) :
//     [🛒 Mon panier (N articles — XX.XX€)] → CartScreen
//     [+ Continuer mes achats]              → Navigator.pop() → retour MenuScreen
// - Timer 8s → auto-navigate vers CartScreen (progress bar visible)

// À intégrer dans item_details_screen.dart :
// Remplacer Navigator.pop() dans _addToCart() par :
// Get.to(PostAddScreen(itemName: item.name, cartTotal: cartTotal))
```

---

### TÂCHE 3 — [P0] Écran DessertUpsellScreen — Upsell dessert fin de parcours

**Fichier à créer :** `lib/app/features/cart/screens/dessert_upsell_screen.dart`

```dart
// Design :
// - Header : "🍰 Et pour finir ?" avec sous-titre "Craquez pour une petite douceur"
// - Grille 2×N items de catégories : desserts + boissons
//   * Items filtrés depuis menuController (catégories 'dessert', 'boisson')
//   * Max 6 items (les plus populaires selon sort_order)
// - Chaque carte : image grande, nom, prix, bouton +
// - "Non merci, continuer →" en bas (texte + flèche)
// - Si 0 item dessert en DB → skipé automatiquement

// À intégrer dans cart_screen.dart :
// Bouton "Valider" → DessertUpsellScreen → PaymentScreen
// (au lieu d'appeler directement _submitOrder)

// Logique de filtrage :
// menuController.categoryList.where((c) => 
//   c.name.toLowerCase().contains('dessert') || 
//   c.name.toLowerCase().contains('boisson'))
```

---

### TÂCHE 4 — [P0] Écran PaymentScreen — Récapitulatif + choix paiement

**Fichier à créer :** `lib/app/features/order/screens/payment_screen.dart`

```dart
// Design :
// - Section haute (40%) : Récapitulatif compact
//     * Liste scrollable des items (nom + qté + prix)
//     * Sous-total, TVA, TOTAL en gras rouge/vert
// - Section basse (60%) : Choix du paiement
//     * Bouton 1 — "💳 Carte Bancaire" (si CB activée en config)
//     * Bouton 2 — "💵 Espèces au comptoir" (toujours disponible)
//     * Option Loyauté (si loyalty activée)
// - Architecture :
//     * Tapper CB → appelle _submitOrder(paymentMethod: 'card')
//     * Tapper Espèces → appelle _submitOrder(paymentMethod: 'cash')
//     * Résultat → OrderConfirmScreen

// Récupère la logique existante de _submitOrder depuis cart_screen.dart
// IMPORTANT : Ne pas dupliquer le code ! Extraire _submitOrder dans OrderController public method
```

---

### TÂCHE 5 — [P1] Améliorer MenuScreen — Header panier persistant

**Fichier à modifier :** `lib/app/features/menu/screens/menu_screen.dart`

```dart
// Ajouts :
// 1. Bottom bar persistante avec :
//    • Icône panier + badge nombre d'items
//    • Montant total panier
//    • Bouton "Mon panier →"
// 2. Animation bottom bar : slide-in quand panier non vide, slide-out quand vide
// 3. Categories : scroll amélioré avec highlight catégorie active au scroll

// Code bottom bar :
// Obx(() {
//   final count = cartController.cartList.length;
//   if (count == 0) return SizedBox.shrink();
//   return AnimatedSlide(
//     offset: count > 0 ? Offset.zero : Offset(0, 1),
//     child: CartBottomBar(count: count, total: cartController.cartTotalPrice),
//   );
// })
```

---

### TÂCHE 6 — [P1] Améliorer OrderConfirmScreen — Meilleure UX confirmation

**Fichier à modifier :** `lib/app/features/order/screens/order_confirm_screen.dart`

```dart
// Modifications :
// 1. Numéro de commande affiché en très grand (100sp) avec couleur brand
// 2. Animation Lottie ou AnimatedCheckmark au centre
// 3. Remplacement timer auto-return :
//    • Countdown visuel (cercle progress) 15 secondes visible
//    • Bouton "Nouvelle commande" pour skip le timer
//    • Bouton "Ajouter d'autres items" → MenuScreen (sans reset panier)
//    ⚠️ ATTENTION : timer actuel existe (CANCELABLE) — utiliser le même pattern
// 4. Message de gratitude personnalisé par heure de la journée :
//    • Matin → "Bon appétit ! ☀️"
//    • Midi → "Bonne dégustation ! 😋"
//    • Soir → "Bonne soirée ! 🌙"
```

---

## 🎨 4. DESIGN SYSTEM ENRICHI — GUIDELINES POUR KIMI

### 4.1 Typographie (cohérent avec le code existant)
```dart
// Titres d'écran : MediumStyles.s56s (existant)
// Sous-titres : RegularStyles.s38s (existant)
// CTA Buttons : MediumStyles.s42s (existant)
// Prix : MediumStyles.s42s en AppColors.primaryColor
// Labels petits : RegularStyles.s28s
```

### 4.2 Couleurs (cohérent avec AppColors existant)
```dart
// Bouton primaire : AppColors.primaryColor (orange existant)
// Fond : Colors.white + AppColors.searchBgColor (#F4F4F4)
// Succès : AppColors.success (green)
// Prix : AppColors.primaryColor
// Texte principal : Colors.black87
// Texte secondaire : Colors.grey.shade600
```

### 4.3 Animations standards à utiliser pour cohérence
```dart
// Transitions entre pages : Duration(milliseconds: 350), Curves.easeInOut
// Scale au tap : ScaleTransition 0.95 → 1.0
// Slide-in bottom bar : AnimatedPositioned ou SlideTransition
// Check success : AnimatedScale avec Curves.bounceOut
// Progress countdown : CircularProgressIndicator avec Timer.periodic
```

### 4.4 Taille des zones tactiles (borne = doigts larges)
```dart
// Boutons CTA minimum : height 100.h, width double.infinity
// Cards items minimum : 180.h de hauteur
// Boutons navigation +/- : 64x64.r minimum
// Espacement inter-éléments : 24.h minimum
```

---

## 🧪 5. SCÉNARIOS DE TEST ANTI-GRAVITY

| ID | Scénario | Résultat attendu |
|----|----------|-----------------|
| K01 | Tap écran accueil → OrderTypeScreen visible | 2 cartes sur place/à emporter ✅ |
| K02 | Tap "Sur place" → MenuScreen avec catégories | Navigation fluide ✅ |
| K03 | Tap item Tacos L → Wizard 4 étapes s'ouvre | Étape 1: viande visible ✅ |
| K04 | Compléter wizard → Ajouter au panier → PostAddScreen | Animation ✅ + 2 boutons ✅ |
| K05 | "Ajouter produit" → retour MenuScreen | Panier conservé ✅ |
| K06 | "Mon panier" → CartScreen avec bottom bar visible | Total correct ✅ |
| K07 | "Valider" depuis CartScreen → DessertUpsellScreen | 4-6 desserts/boissons affichés ✅ |
| K08 | Skip dessert → PaymentScreen avec récapitulatif | Total = Cart total ✅ |
| K09 | Tap "Espèces" → OrderConfirmScreen avec numéro | Numéro grand, impression lancée ✅ |
| K10 | Timer 15s → retour automatique HomeScreen | Countdown visible ✅ |
| K11 | Idle 3min pendant MenuScreen → HomeScreen | Idle service actif ✅ |
| K12 | Sélectionner dessert dans K07 → Panier mis à jour | +1 item dans panier ✅ |

---

## ⚠️ 6. RÈGLES DE NON-RÉGRESSION

1. **`idle_service.dart`** : Ne pas toucher — utiliser le même pattern `IdleService.to.reset()` dans chaque nouveau écran.
2. **`_submitOrder()`** dans `cart_screen.dart` : Extraire en méthode publique d'`OrderController` — ne pas dupliquer la logique d'envoi commande.
3. **`WizardStep` / `CategoryHelper`** : Ne pas modifier — le wizard est validé.
4. **`order_confirm_screen.dart`** : Le Bluetooth print `initializeBluetoothAndConnect()` est fragile — ajouter les nouveaux boutons sans toucher au flow BT.
5. **Navigation** : Toujours utiliser `Get.to()` / `Get.back()` (GetX routing) — ne pas mixer avec `Navigator.push()` sauf dans les modaux.

---

## 📋 7. FICHIERS À CRÉER / MODIFIER

### Fichiers NOUVEAUX (créer)
| Fichier | Description |
|---------|-------------|
| `lib/app/features/home/screens/order_type_screen.dart` | Sur place / À emporter |
| `lib/app/features/cart/screens/post_add_screen.dart` | Décision post-ajout |
| `lib/app/features/cart/screens/dessert_upsell_screen.dart` | Upsell dessert |
| `lib/app/features/order/screens/payment_screen.dart` | Paiement |

### Fichiers MODIFIÉS (modifier MINIMALEMENT)
| Fichier | Modification |
|---------|-------------|
| `home/screens/home_screen.dart` | → OrderTypeScreen au lieu de MenuScreen |
| `menu/screens/item_details_screen.dart` | `_addToCart()` → PostAddScreen |
| `cart/screens/cart_screen.dart` | "Valider" → DessertUpsellScreen |
| `menu/screens/menu_screen.dart` | + Bottom bar panier persistante |
| `order/screens/order_confirm_screen.dart` | + Countdown + bouton Nouvelle Commande |
| `order/controllers/order_controller.dart` | Extraire `submitOrder()` en méthode publique |

---

## 🏁 8. DÉFINITION DU DONE (Sprint 4 Borne)

- [ ] OrderTypeScreen : 2 cartes tactiles, stockage GetStorage
- [ ] PostAddScreen : animation succès + 2 CTAs + timer 8s
- [ ] DessertUpsellScreen : grille desserts/boissons filtré depuis DB + skip
- [ ] PaymentScreen : récapitulatif + 2 modes paiement + appel orderController
- [ ] MenuScreen bottom bar : badge panier + montant + slide-in/out
- [ ] OrderConfirmScreen : countdown 15s + bouton Nouvelle Commande
- [ ] Tests K01 à K12 validés par Anti-Gravity
- [ ] Idle timer reset dans chaque nouvel écran
- [ ] 0 duplication de `_submitOrder()` — dans OrderController uniquement

---

*Document Claude — Sprint 4 Borne — Conforme AGENTS.md*
