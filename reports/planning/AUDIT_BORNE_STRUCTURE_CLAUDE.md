# 🔬 AUDIT PROFOND BORNE — ÉTAT & FEUILLE DE ROUTE
**Auteur :** Claude (Lead Architect)  
**Date :** 11 Mars 2026 | 15h40  
**Fichiers lus :** 46 fichiers Dart + config

---

## 📊 1. SCORE GLOBAL PAR MODULE

| Module | Implémenté | Qualité | À Faire |
|--------|-----------|---------|---------|
| App Shell (main.dart) | ✅ 100% | ⭐⭐⭐⭐⭐ | Rien |
| Idle/Timeout | ✅ 100% | ⭐⭐⭐⭐⭐ | Rien |
| WebSocket temps réel | ✅ 100% | ⭐⭐⭐⭐⭐ | Config Pusher côté serveur |
| CategoryHelper/Wizard | ✅ 100% | ⭐⭐⭐⭐⭐ | Rien |
| Menu Screen | ✅ 90% | ⭐⭐⭐⭐ | Bottom bar panier |
| Item Details / Wizard UI | ✅ 100% | ⭐⭐⭐⭐⭐ | Rien |
| Cart Controller | ✅ 85% | ⭐⭐⭐⭐ | calculateTotal(), resetCart() |
| UpsellService | ✅ 60% | ⭐⭐⭐ | Branché à rien pour l'instant |
| Order Flow | ✅ 70% | ⭐⭐⭐ | PaymentScreen manquante |
| OrderConfirmScreen | ✅ 80% | ⭐⭐⭐⭐ | Countdown, bouton Nouvelle Commande |
| Loyalty | ✅ 50% | ⭐⭐⭐ | API ready, UI partielle |
| **Écrans manquants** | ❌ 0% | — | OrderType, PostAdd, DessertUpsell, Payment |

---

## ✅ 2. CE QUI FONCTIONNE PARFAITEMENT (Ne pas toucher)

### 2.1 Architecture globale — SOLIDE
```
main.dart (designSize: 1080×1920 — Format borne portrait)
├── IdleService (permanent) — 3 min inactivité → HomeScreen + reset panier + clear loyalty ✅
├── SocketService (permanent) — Pusher temps réel (stock + commandes) ✅
└── SplashScreen → HomeScreen → MenuScreen → ItemDetails → Cart → Order
```

### 2.2 CategoryHelper.dart — EXCELLENT (342 lignes)
```
- detectCategory() : 11 catégories reconnues automatiquement ✅
- buildDynamicSteps() : Lit les VRAIS ItemAttributes de la DB ✅
- Classe WizardStep : 5 sections (attribute_slot, attributes, extras, addons) ✅
- Enum AttributeSlotType : meat / sauce / garnish / other ✅
- _classifyAttribute() : Triage intelligent par nom d'attribut ✅
- skipConfigurator() : ojja, dessert, boisson, menu_enfant → 0 étape ✅
- isExtrasRadioMode() : assiette → sélection unique accompagnement ✅
```

### 2.3 IdleService.dart — PARFAIT (62 lignes)
```
- Timer 3 minutes glissant ✅
- Idle → clear cart + clear loyalty session + fade to HomeScreen ✅
- Reset via IdleService.to.reset() depuis n'importe quel écran ✅
```

### 2.4 SocketService.dart — AVANCÉ (212 lignes)
```
- WebSocket Pusher (cloud) ou Soketi (self-hosted) ✅
- Canal kiosk.{branchId} → stock.updated (rupture temps réel) ✅
- Canal kiosk.{branchId} → order.placed (confirmation borne) ✅
- Canal order.{orderId} → order.status.changed (commande prête) ✅
- stockStatus: Map<itemId, bool> → Obx réactif dans la grille menu ✅
- Silent fail si Pusher non configuré ✅
```

### 2.5 UpsellService.dart — LOGIQUE PRÊTE (45 lignes)
```dart
// Vérifie si dessert absent du panier → shouldShowUpsell = true
UpsellResult.checkUpsell(cart) → bool
```
**Problème :** La logique est correcte mais elle n'est branchée à AUCUN écran. La `UpsellModalWidget` appelle le service mais le déclenchement (après "Valider") n'est pas dans le flux principal.

---

## 🔴 3. BUGS ET MANQUES CRITIQUES

### 3.1 MANQUE-BORNE-001 : Aucun écran de décision post-configuration item
**Flux actuel :**  
Tap item → ItemDetailsScreen → "Ajouter au panier" → `Navigator.pop()` → retour MenuScreen  
**Résultat :** L'utilisateur revient brutalement au menu sans confirmation visuelle. Il ne sait pas si l'item a été ajouté. Pas de CTA "Voir mon panier".

### 3.2 MANQUE-BORNE-002 : Pas d'écran OrderTypeScreen (Sur place / À emporter)
**Flux actuel :**  
HomeScreen → tap → MenuScreen direct  
**Standard industrie :** Toutes les bornes demandent le mode repas en premier.

### 3.3 MANQUE-BORNE-003 : UpsellService non branché au flux commande
**`UpsellModalWidget` existe** (`upsell_modal_widget.dart`) mais n'est montré que depuis `cart_screen.dart` dans un cas très précis. Il n'y a pas d'écran `DessertUpsellScreen` dédié.

### 3.4 MANQUE-BORNE-004 : Pas d'écran PaymentScreen avec récapitulatif
**Flux actuel :**  
CartScreen → bouton "Order" → appel API direct sans écran de confirmation.  
**Standard :** Toujours un récap + choix du mode de paiement avant envoi.

### 3.5 BUG-BORNE-001 : `CartController` n'expose pas `cartTotalPrice`
**Code actuel (cart_controller.dart ligne 25-27) :**
```dart
double totalCartValue = 0;
double deliveryCharge = 0.0;
double total = 0;
```
Mais `calculateTotal()` n'est pas implémentée / vide dans le code lu. La bottom bar du menu (à créer) a besoin de ce total de façon réactive.

### 3.6 BUG-BORNE-002 : `Cart` model n'a pas de champ `thumb` (image)
Dans le récap panier (`cart_screen.dart`) et dans le futur `PostAddScreen`, on voudrait afficher l'image de l'item. Le modèle `Cart` dans `place_order_body.dart` ne stocke probablement pas le `thumb`.

### 3.7 MANQUE-BORNE-005 : `stoxkStatus` du SocketService non utilisé dans la grille menu
Le service calcule `stockStatus[itemId] = false` quand un item est en rupture, mais la `ItemWidget` (dans `item_widget.dart`) n'écoute pas ce signal → les items en rupture restent cliquables.

---

## 🏗️ 4. FEUILLE DE ROUTE COMPLÈTE POUR KIMI

### PRIORITÉ 0 — Immédiate (1-2 jours)

#### [P0-A] Brancher UpsellService dans le flux CartScreen → Order
```dart
// Dans cart_screen.dart, le bouton "Valider" :
// AVANT :  orderController.placeOrder(...)
// APRÈS :
final result = UpsellService.checkUpsell(cartController.cart);
if (result.shouldShowUpsell) {
  Get.to(() => DessertUpsellScreen());  // Nouveau
} else {
  Get.to(() => PaymentScreen());         // Nouveau
}
```

#### [P0-B] Créer OrderTypeScreen
```dart
// Fichier : lib/app/features/home/screens/order_type_screen.dart
// Widgets : 2 cards larges (dine_in / takeaway)
// Storage : box.write('order_type', 'dine_in' | 'takeaway')
// Navigation : Get.to(MenuScreen())
// Design : fond dégradé brand + logo + animation scale au tap
```

#### [P0-C] Créer PostAddScreen (bottom sheet après ajout item)
```dart
// Fichier : lib/app/features/cart/screens/post_add_screen.dart
// Trigger : Remplacer Navigator.pop() dans item_details_screen.dart._addToCart()
// UI : 
//   - AnimatedCheckmark vert 1 seconde
//   - Nom item + prix ajouté
//   - [🛒 Mon panier (N — XX€)]  [+ Continuer mes achats]
//   - Timer 8s auto-navigate vers CartScreen
```

#### [P0-D] Créer PaymentScreen
```dart
// Fichier : lib/app/features/order/screens/payment_screen.dart
// UI :
//   - Récap compact scroll (items + total)
//   - 2 → 3 boutons paiement selon config
//   - Appelle orderController.placeOrder() depuis ici
```

---

### PRIORITÉ 1 — Court terme (3-5 jours)

#### [P1-A] MenuScreen — Bottom bar panier persistante
```dart
// Modifier menu_screen.dart :
// Ajouter un Positioned() en bas qui slide-in quand cart.length > 0
// Obx(() { if (cartController.cart.isEmpty) return SizedBox.shrink(); ... })
// Contenu : "🛒 X articles — XX.XX€ → Voir panier"
```

#### [P1-B] Intégrer stockStatus dans ItemWidget
```dart
// Dans item_widget.dart :
// Obx(() {
//   final inStock = SocketService.to?.isItemInStock(item.id) ?? true;  // ← AJOUTER
//   return GestureDetector(
//     onTap: inStock ? () => ... : null,  // ← disabled si rupture
//     child: Stack(
//       children: [
//         ItemCard(...),
//         if (!inStock) RuptureOverlay(),  // ← badge "ÉPUISÉ"
//       ]
//     )
//   );
// })
```

#### [P1-C] DessertUpsellScreen — Grille desserts/boissons
```dart
// Fichier : lib/app/features/cart/screens/dessert_upsell_screen.dart
// Logique : Filtrer menuController.categoryList pour 'dessert' + 'boisson'
// UI : grille 2 colonnes, max 6 items, "Non merci →" en bas
// Si 0 dessert/boisson en DB → skip automatique vers PaymentScreen
```

#### [P1-D] OrderConfirmScreen — Améliorer le retour
```dart
// Ajouter dans order_confirm_screen.dart :
// - Timer 15s visible (CircularProgressIndicator overlay)
// - Bouton "🔄 Nouvelle commande" → Get.offAll(HomeScreen()) 
// - Abonnement SocketService.subscribeToOrder(orderId) → afficher "✅ Commande prête" quand prêt
```

---

### PRIORITÉ 2 — Moyen terme (1 semaine)

#### [P2-A] Loyalty Flow complet
```dart
// LoyaltyController + LoyaltyQrScanner existent déjà
// Manque : L'intégration dans le flux de paiement
// Ajouter dans PaymentScreen : 
//   "Avez-vous une carte fidélité ?" + Scan QR ou saisie code 10 chiffres
//   LoyaltyController.check(code) → affiche points + réduction
```

#### [P2-B] Écran "Votre commande est prête" (Pickup Screen)
```dart
// SocketService écoute déjà order.status.changed
// Créer : lib/app/features/order/screens/pickup_screen.dart
// Trigger : SocketService.lastStatusChanged Obx → si status == 'ready_for_pickup'
//   → Get.to(PickupScreen(queueNumber: ...))
// UI : Numéro de commande GÉANT, animation fête, "Retirez votre commande au comptoir"
```

#### [P2-C] Allergènes modal (déjà commencé)
```dart
// lib/app/core/allergenes_modal.dart existe déjà
// L'intégrer dans ItemDetailsScreen via bouton "ⓘ Allergènes"
```

---

## 📋 5. RÉSUMÉ — CE QUI MANQUE VS CE QUI EST FAIT

```
FAIT À 100%                    À CRÉER
─────────────────────────────────────────────────────────────────
✅ Wizard configurateur        ❌ OrderTypeScreen (Sur place/À emp.)
✅ CategoryHelper dynamique    ❌ PostAddScreen (décision post-ajout)
✅ Idle timeout 3min           ❌ DessertUpsellScreen
✅ WebSocket stock temps réel  ❌ PaymentScreen avec récap
✅ Bluetooth ticket print      ❌ Pickup "Commande prête" screen
✅ Loyalty API endpoints       ❌ Bottom bar panier dans MenuScreen
✅ UpsellService logique       ❌ UpsellService branché au flux
✅ Firebase push KDS           ❌ Rupture overlay dans ItemWidget
✅ Multi-viandes wizard        ❌ Loyalty intégrée dans paiement
✅ Allergènes modal (shell)    ❌ Allergènes bouton dans item details
```

---

## 🎯 6. PROCHAINE SESSION — COMMANDES POUR KIMI

```
SPRINT 5 BORNE — Ordre d'exécution recommandé :

1. OrderTypeScreen + HomeScreen hook      → test K01-K02
2. PostAddScreen + ItemDetails hook       → test K04-K05
3. Cart bottom bar dans MenuScreen        → test K06
4. DessertUpsellScreen + Cart hook        → test K07, K12
5. PaymentScreen + Order logic            → test K08-K09
6. OrderConfirmScreen countdown           → test K10
7. ItemWidget rupture overlay (Obx stock) → test K-stock

Tests globaux ensuite : Playwright / E2E verification K01 → K12 full parcours
```

---

*Audit Claude — Sprint 5 Borne — Conforme AGENTS.md*
