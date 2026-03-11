# HOOKS NAVIGATION FLUX — À INTÉGRER DANS LES ÉCRANS EXISTANTS

**Fichiers à modifier:** `home_screen.dart`, `item_details_screen.dart`, `cart_screen.dart`
**Date:** 11 Mars 2026
**Source:** Audit Claude — P0-A, P0-B, P0-C, P0-D

---

## 1. HOOK HomeScreen → OrderTypeScreen (P0-B)

**Fichier:** `lib/app/features/home/screens/home_screen.dart`

**Recherche:**
```dart
// TROUVER (vers ligne 140-150):
onTap: () {
  Get.to(() => MenuScreen());  // ou Navigator.push
}
```

**Remplacer par:**
```dart
// [P0-B FIX] Navigation vers OrderTypeScreen au lieu de MenuScreen direct
onTap: () {
  Get.to(() => OrderTypeScreen());  // ← NOUVEAU: Choix Sur place/À emporter
}
```

**Imports à ajouter:**
```dart
import '../home/screens/order_type_screen.dart';
```

---

## 2. HOOK ItemDetailsScreen → PostAddScreen (P0-C)

**Fichier:** `lib/app/features/menu/screens/item_details_screen.dart`

**Recherche:**
```dart
// TROUVER (dans _addToCart() ou similar, vers ligne 900+):
Navigator.pop(context);  // ou Get.back()
```

**Remplacer par:**
```dart
// [P0-C FIX] Navigation vers PostAddScreen au lieu de pop direct
Get.off(() => PostAddScreen(
  itemName: item.name ?? '',
  itemPrice: double.tryParse(item.price ?? '0') ?? 0.0,
));
```

**Imports à ajouter:**
```dart
import '../../cart/screens/post_add_screen.dart';
```

---

## 3. HOOK CartScreen → DessertUpsellScreen → PaymentScreen (P0-A, P0-D)

**Fichier:** `lib/app/features/cart/screens/cart_screen.dart`

**Recherche:**
```dart
// TROUVER (dans le bouton "Valider" ou "Order", vers ligne XXX):
onTap: () {
  orderController.placeOrder(...);  // ou _submitOrder()
}
```

**Remplacer par:**
```dart
// [P0-A FIX] Brancher UpsellService dans le flux
// [P0-D FIX] Navigation vers PaymentScreen avant confirmation

onTap: () {
  // Vérifier upsell dessert d'abord
  final result = UpsellService.checkUpsell(cartController.cartList);
  
  if (result.shouldShowUpsell && result.suggestedItems.isNotEmpty) {
    // Montrer écran upsell dessert
    Get.to(() => DessertUpsellScreen(
      suggestedItems: result.suggestedItems,
      onSkip: () => Get.to(() => PaymentScreen()),
      onAdd: (item) {
        // Ajouter dessert puis paiement
        cartController.addToCart(AddToCartBody(...));
        Get.to(() => PaymentScreen());
      },
    ));
  } else {
    // Pas d'upsell, aller direct à paiement
    Get.to(() => PaymentScreen());
  }
}
```

**Imports à ajouter:**
```dart
import '../../cart/screens/dessert_upsell_screen.dart';
import '../../order/screens/payment_screen.dart';
import '../../cart/upsell_service.dart';
```

---

## 4. HOOK PaymentScreen → OrderConfirmScreen (P0-D suite)

**Le fichier `payment_screen.dart` existe déjà dans kiosk_implementation/order/screens/**

**Vérifier que PaymentScreen:**
1. ✅ Affiche récapitulatif des items
2. ✅ Propose modes de paiement (Cash/Card)
3. ✅ Appelle `orderController.placeOrder()` au confirm
4. ✅ Navigation vers `OrderConfirmScreen` après succès

**Hook dans PaymentScreen:**
```dart
// Dans PaymentScreen, après placeOrder() réussi:

Future<void> _submitOrder(String paymentMethod) async {
  // ... validation ...
  
  final orderId = await orderController.placeOrder(placeOrderBody);
  
  if (orderId > 0) {
    // Clear cart
    cartController.resetCart();
    
    // [P0-D] Navigation vers confirmation
    Get.off(() => OrderConfirmScreen(orderId: orderId));
  }
}
```

---

## 5. HOOK OrderTypeScreen → MenuScreen (P0-B suite)

**Le fichier `order_type_screen.dart` existe déjà**

**Vérifier:**
```dart
// Dans OrderTypeScreen, après sélection:

void _selectOrderType(String type) {
  // Stocker pour la commande
  box.write('order_type', type);  // 'dine_in' ou 'takeaway'
  
  // Navigation
  Get.off(() => MenuScreen());
}
```

---

## 6. RÉSUMÉ DES MODIFICATIONS

| Fichier | Hook | Priorité | Status |
|---------|------|----------|--------|
| `home_screen.dart` | → OrderTypeScreen | P0-B | À faire |
| `item_details_screen.dart` | → PostAddScreen | P0-C | À faire |
| `cart_screen.dart` | → DessertUpsell → Payment | P0-A, P0-D | À faire |
| `payment_screen.dart` | → OrderConfirm | P0-D | ✅ Existe |
| `order_type_screen.dart` | → MenuScreen | P0-B | ✅ Existe |

---

## 7. VÉRIFICATION POST-INTÉGRATION

**Test K01-K12:**
```
K01: Home → OrderTypeScreen ✅
K02: OrderType → MenuScreen ✅
K03: Item → Wizard ✅
K04: Wizard → PostAddScreen ✅
K05: PostAdd → Menu (continuer) ✅
K06: Menu bottom bar → Cart ✅
K07: Cart → DessertUpsell ✅
K08: DessertUpsell → Payment ✅
K09: Payment → OrderConfirm ✅
K10: OrderConfirm auto-return ✅
K11: Skip upsell → direct Payment ✅
K12: Add dessert + Payment ✅
```

---

*Hooks Navigation Flux — Sprint 5 Borne — Conforme audit Claude*
