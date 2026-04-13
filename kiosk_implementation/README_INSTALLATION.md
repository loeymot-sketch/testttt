# 📱 KIOSK FLUTTER - Guide d'Installation

## 🎯 Fichiers créés pour implémentation Kiosk Flow

**Date:** 11 Mars 2026  
**Plan source:** `KIOSK_FLOW_PLAN_CLAUDE.md`  
**Statut:** ✅ Prêt pour copie

---

## 📁 Structure des fichiers

```
kiosk_implementation/
├── home/screens/
│   └── order_type_screen.dart          ← [NOUVEAU] Écran Sur place/À emporter
├── cart/screens/
│   ├── post_add_screen.dart            ← [NOUVEAU] Décision post-ajout
│   └── dessert_upsell_screen.dart      ← [NOUVEAU] Upsell dessert
├── order/screens/
│   └── payment_screen.dart             ← [NOUVEAU] Récap + paiement
└── order/controllers/
    └── order_controller_extension.dart  ← [EXTENSION] Méthodes publiques
```

---

## 🚀 Instructions d'installation

### Étape 1: Copier les nouveaux écrans

```bash
# Depuis le dossier foodking-web/web/testttt/
cp kiosk_implementation/home/screens/order_type_screen.dart \
   "/Users/1millnonstop/Downloads/projet/projet kiosk/extracted_kiosk_app/lib/app/features/home/screens/"

cp kiosk_implementation/cart/screens/post_add_screen.dart \
   "/Users/1millnonstop/Downloads/projet/projet kiosk/extracted_kiosk_app/lib/app/features/cart/screens/"

cp kiosk_implementation/cart/screens/dessert_upsell_screen.dart \
   "/Users/1millnonstop/Downloads/projet/projet kiosk/extracted_kiosk_app/lib/app/features/cart/screens/"

cp kiosk_implementation/order/screens/payment_screen.dart \
   "/Users/1millnonstop/Downloads/projet/projet kiosk/extracted_kiosk_app/lib/app/features/order/screens/"
```

### Étape 2: Modifier OrderController

**Fichier:** `lib/app/features/order/controllers/order_controller.dart`

Ajoutez à la fin de la classe `OrderController` (avant la dernière accolade) :

```dart
// ══════════════════════════════════════════════════════════════════════════════
// PUBLIC API — pour PaymentScreen et autres appelants externes (Sprint 4)
// ══════════════════════════════════════════════════════════════════════════════

/// Réponse de la dernière commande créée
OrderDetailsData? orderResponse;

/// Place une commande POS avec paiement (utilisé par PaymentScreen)
Future<int> placeOrder(dynamic placeOrderBody) async {
  // ... (voir order_controller_extension.dart pour le code complet)
}
```

**Copiez le contenu de** `order_controller_extension.dart` **à la fin du fichier.**

### Étape 3: Modifier HomeScreen

**Fichier:** `lib/app/features/home/screens/home_screen.dart`

**Modification 1:** Ajouter l'import
```dart
import 'order_type_screen.dart';  // Ajouter en haut du fichier
```

**Modification 2:** Ligne ~140-150, remplacer la navigation
```dart
// AVANT:
onTap: () {
  Get.to(() => MenuScreen());  // ← REMPLACER
}

// APRÈS:
onTap: () {
  Get.to(() => OrderTypeScreen());  // ← NOUVEAU
}
```

### Étape 4: Modifier ItemDetailsScreen

**Fichier:** `lib/app/features/menu/screens/item_details_screen.dart`

**Modification 1:** Ajouter l'import
```dart
import '../../cart/screens/post_add_screen.dart';  // Ajouter en haut
```

**Modification 2:** Dans `_addToCart()`, remplacer `Navigator.pop()` par:
```dart
// AVANT (vers ligne ~900+):
Navigator.pop(context);

// APRÈS:
Get.off(() => PostAddScreen(
  itemName: item.name ?? '',
  itemPrice: double.tryParse(item.price ?? '0') ?? 0.0,
));
```

### Étape 5: Modifier CartScreen

**Fichier:** `lib/app/features/cart/screens/cart_screen.dart`

**Modification 1:** Ajouter les imports
```dart
import 'dessert_upsell_screen.dart';  // Ajouter en haut
import '../../order/screens/payment_screen.dart';  // Si pas déjà importé
```

**Modification 2:** Dans le bouton "Valider", remplacer `_submitOrder()` par:
```dart
// AVANT:
onTap: () {
  _submitOrder();  // ou similaire
}

// APRÈS:
onTap: () {
  Get.to(() => DessertUpsellScreen());
}
```

### Étape 6: Modifier OrderConfirmScreen (améliorations UX)

**Fichier:** `lib/app/features/order/screens/order_confirm_screen.dart`

**Modifications suggérées:**

1. **Numéro de commande plus grand:**
```dart
Text(
  'A${widget.orderID}',
  style: TextStyle(
    fontSize: 100.sp,  // ← Augmenter
    fontWeight: FontWeight.bold,
    color: AppColors.primaryColor,
  ),
)
```

2. **Countdown visuel avec bouton "Nouvelle commande":**
```dart
// Ajouter dans le build:
Row(
  mainAxisAlignment: MainAxisAlignment.center,
  children: [
    CircularProgressIndicator(
      value: _countdown / 15,  // 15 secondes
    ),
    SizedBox(width: 20.w),
    Text('Retour accueil dans $_countdown s'),
  ],
),
SizedBox(height: 20.h),
ElevatedButton(
  onPressed: () {
    _timer?.cancel();
    Get.offAll(() => HomeScreen());
  },
  child: Text('Nouvelle commande'),
)
```

3. **Message de gratitude par heure:**
```dart
String getGreetingMessage() {
  final hour = DateTime.now().hour;
  if (hour < 12) return 'Bon appétit ! ☀️';
  if (hour < 18) return 'Bonne dégustation ! 😋';
  return 'Bonne soirée ! 🌙';
}
```

### Étape 7: Modifier MenuScreen (bottom bar)

**Fichier:** `lib/app/features/menu/screens/menu_screen.dart`

**Ajouter en bas du Scaffold:**
```dart
// Dans le build, après le body:
floatingActionButton: Obx(() {
  final count = cartController.cartList.length;
  if (count == 0) return SizedBox.shrink();
  return AnimatedSlide(
    offset: count > 0 ? Offset.zero : Offset(0, 2),
    duration: Duration(milliseconds: 300),
    child: Container(
      // Bottom bar avec badge et montant
      child: GestureDetector(
        onTap: () => Get.to(() => CartScreen()),
        child: Row(
          children: [
            Icon(Icons.shopping_cart),
            Text('$count articles'),
            Text('${cartController.cartTotalPrice}€'),
          ],
        ),
      ),
    ),
  );
}),
floatingActionButtonLocation: FloatingActionButtonLocation.centerFloat,
```

---

## 🧪 Tests Playwright / E2E verification Requis

| ID | Scénario | Attendu |
|----|----------|---------|
| K01 | Home → OrderTypeScreen | 2 cartes sur place/à emporter |
| K02 | Tap "Sur place" → Menu | Navigation fluide, type stocké |
| K03 | Item → Wizard → PostAdd | Animation succès + 2 boutons |
| K04 | "Continuer" → Menu | Panier conservé |
| K05 | "Mon panier" → Cart | Liste items affichée |
| K06 | Cart "Valider" → DessertUpsell | Grille desserts/boissons |
| K07 | Skip dessert → Payment | Récapitulatif correct |
| K08 | Tap "Cash" → OrderConfirm | Commande créée, numéro affiché |
| K09 | Timer 15s → HomeScreen | Countdown visible |
| K10 | Idle 3min → HomeScreen | Idle service actif |

---

## ⚠️ Règles de non-régression

1. ✅ **IdleService** : Déjà intégré via `Listener(onPointerDown: ...)`
2. ✅ **WizardStep** : Non modifié, reste fonctionnel
3. ✅ **CategoryHelper** : Non modifié
4. ✅ **Bluetooth print** : Non modifié dans OrderConfirmScreen
5. ✅ **GetX routing** : Utilisé partout (`Get.to()`, `Get.off()`)

---

## 📋 Checklist post-installation

- [ ] OrderTypeScreen copié et importé dans HomeScreen
- [ ] PostAddScreen copié et intégré dans ItemDetailsScreen
- [ ] DessertUpsellScreen copié et intégré dans CartScreen
- [ ] PaymentScreen copié
- [ ] OrderController modifié avec méthodes publiques
- [ ] OrderConfirmScreen amélioré (numéro grand + countdown)
- [ ] MenuScreen avec bottom bar panier
- [ ] `flutter clean && flutter pub get`
- [ ] Build APK test
- [ ] Tests K01-K10 validés

---

## 🔧 Build et Test

```bash
# Nettoyer
cd "/Users/1millnonstop/Downloads/projet/projet kiosk/extracted_kiosk_app"
flutter clean

# Dépendances
flutter pub get

# Build debug APK
flutter build apk --debug

# Ou lancer sur device connecté
flutter run
```

---

**Signé:** Kimi (Implementation Agent)  
**Date:** 2026-03-11

> ⚠️ **IMPORTANT:** Ces fichiers sont dans le sandbox. Vous devez les copier manuellement vers le projet Flutter car les restrictions de sandbox empêchent l'écriture directe.
