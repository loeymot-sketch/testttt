# EXTENSIONS CartController — À AJOUTER AU FICHIER EXISTANT

**Fichier:** `lib/app/features/cart/controllers/cart_controller.dart`
**Date:** 11 Mars 2026
**Source:** Audit Claude — Manque-Borne-001/005

---

## 1. EXTENSION: Exposer cartTotalPrice réactif

**Bug identifié (BUG-BORNE-001):** `cartTotalPrice` n'est pas exposé comme getter réactif.

**À ajouter dans la classe CartController:**

```dart
// ═══════════════════════════════════════════════════════════════════════════
// GETTERS RÉACTIFS POUR UI (Ajoutés pour Sprint 5)
// ═══════════════════════════════════════════════════════════════════════════

/// Prix total du panier (réactif pour Obx)
/// [BUG-BORNE-001 FIX] Expose le total calculé pour la bottom bar et autres UI
RxDouble get cartTotalPriceRx => totalCartValue.obs;

/// Nombre d'articles dans le panier (réactif pour badge)
/// [BUG-BORNE-001 FIX] Expose le count pour les badges
RxInt get cartItemCountRx => cartList.length.obs;

/// Vérifie si le panier est vide
bool get isCartEmpty => cartList.isEmpty;

/// Vérifie si le panier a des items
bool get hasItems => cartList.isNotEmpty;
```

---

## 2. EXTENSION: Méthode calculateTotal() complète

**Bug identifié:** `calculateTotal()` est vide ou incomplet.

**À ajouter/remplacer:**

```dart
// ═══════════════════════════════════════════════════════════════════════════
// CALCULS PRIX (Complété pour Sprint 5)
// ═══════════════════════════════════════════════════════════════════════════

/// Calcule le total du panier avec tous les extras et variations
/// [BUG-BORNE-001 FIX] Implémentation complète du calcul
void calculateTotal() {
  double subtotal = 0.0;
  
  for (var cartItem in cartList) {
    // Prix de base
    double itemPrice = cartItem.price ?? 0.0;
    int quantity = cartItem.quantity ?? 1;
    
    // Prix des variations (si applicable)
    double variationsPrice = 0.0;
    if (cartItem.itemVariations != null) {
      cartItem.itemVariations!.forEach((key, variation) {
        if (variation is Map && variation['price'] != null) {
          variationsPrice += double.tryParse(variation['price'].toString()) ?? 0.0;
        }
      });
    }
    
    // Prix des extras
    double extrasPrice = 0.0;
    if (cartItem.itemExtras != null) {
      for (var extra in cartItem.itemExtras!) {
        if (extra is Map && extra['price'] != null) {
          extrasPrice += double.tryParse(extra['price'].toString()) ?? 0.0;
        }
      }
    }
    
    // Total par item
    double itemTotal = (itemPrice + variationsPrice + extrasPrice) * quantity;
    subtotal += itemTotal;
  }
  
  // Mise à jour des valeurs
  totalCartValue = subtotal;
  total = subtotal; // Sans livraison pour borne
  
  update(); // Notification GetX
}
```

---

## 3. EXTENSION: Reset cart complet

**À ajouter:**

```dart
/// Reset complet du panier pour nouveau client
/// [IdleService] Appelé automatiquement après timeout 3min
void resetCart() {
  cartList.clear();
  cart.clear();
  totalCartValue = 0.0;
  total = 0.0;
  deliveryCharge = 0.0;
  
  // Reset selections
  selectedTable = -1;
  tableID = -1;
  tableNo = '';
  orderType = 0;
  
  calculateTotal();
  update();
  
  debugPrint('[CartController] Panier reset complet');
}
```

---

## 4. EXTENSION: Formatage prix pour UI

**À ajouter:**

```dart
/// Formate un prix pour affichage (ex: "15.50 €")
/// [BUG-BORNE-001 FIX] Helper pour affichage cohérent
String formatPrice(double price) {
  return '${price.toStringAsFixed(2)} €';
}

/// Raccourci pour total du panier formaté
String get formattedCartTotal => formatPrice(totalCartValue);
```

---

## 5. EXTENSION: Gestion du thumb (image)

**Bug identifié (BUG-BORNE-002):** Cart model n'a pas de champ `thumb`.

**À ajouter dans le modèle (si contrôle du modèle):**

```dart
// Dans Cart ou dans la logique d'ajout:
// Stocker l'image de l'item dans le cart

/// Ajoute un item avec son image
Future<void> addToCartWithImage(AddToCartBody body, {String? thumb}) async {
  // Appel existant
  await addToCart(body);
  
  // Ajouter l'image au dernier item ajouté
  if (thumb != null && cartList.isNotEmpty) {
    cartList.last.thumb = thumb; // Si le modèle supporte
    // OU stocker dans une map séparée
    _itemThumbs[cartList.last.itemId] = thumb;
  }
}

// Map privée pour stocker les images
final Map<int, String> _itemThumbs = {};

/// Récupère l'image d'un item du panier
String? getItemThumb(int itemId) {
  return _itemThumbs[itemId];
}
```

---

## 6. RÉSUMÉ DES AJOUTS À FAIRE

| Méthode/Getter | Ligne | Usage |
|----------------|-------|-------|
| `cartTotalPriceRx` | Getter | Bottom bar panier, badges |
| `cartItemCountRx` | Getter | Badge nombre articles |
| `calculateTotal()` | Méthode | Calcul complet des prix |
| `resetCart()` | Méthode | IdleService timeout |
| `formatPrice()` | Méthode | Affichage cohérent |
| `formattedCartTotal` | Getter | Raccourci UI |
| `addToCartWithImage()` | Méthode | Stockage thumb |
| `getItemThumb()` | Méthode | Affichage image dans cart |

---

## 7. INSTRUCTIONS D'AJOUT

1. **Ouvrir** `lib/app/features/cart/controllers/cart_controller.dart`

2. **Ajouter** les getters réactifs après les déclarations de variables existantes

3. **Remplacer** la méthode `calculateTotal()` vide par l'implémentation complète

4. **Ajouter** la méthode `resetCart()` avant la fin de la classe

5. **Ajouter** les helpers de formatage et gestion d'images

6. **Sauvegarder** et tester avec:
   ```bash
   flutter analyze
   ```

---

*Extension CartController — Sprint 5 Borne — Conforme audit Claude*
