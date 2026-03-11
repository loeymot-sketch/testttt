# ITEMWIDGET STOCK INTEGRATION — Overlay rupture temps réel

**Fichier:** `lib/app/features/menu/widgets/item_widget.dart`
**Date:** 11 Mars 2026
**Source:** Audit Claude — MANQUE-BORNE-005

---

## 1. PROBLÈME IDENTIFIÉ

**[MANQUE-BORNE-005]** `stockStatus` du SocketService est calculé mais **NON utilisé** dans `ItemWidget`.

**Conséquence:** Les items en rupture restent cliquables dans la grille menu.

---

## 2. SOLUTION: Intégration Obx réactive

**À ajouter dans `item_widget.dart`:**

### Imports
```dart
import '../../core/socket_service.dart';  // ← AJOUTER
```

### Widget ItemWidget modifié
```dart
class ItemWidget extends StatelessWidget {
  final Item item;
  final VoidCallback? onTap;
  
  const ItemWidget({
    Key? key,
    required this.item,
    this.onTap,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    // [MANQUE-BORNE-005 FIX] Obx réactif sur stockStatus
    return Obx(() {
      final socketService = SocketService.to;
      
      // Vérifier si l'item est en stock
      final bool inStock = socketService?.stockStatus[item.id] ?? true;
      final bool isOutOfStock = !inStock;
      
      return GestureDetector(
        // Désactiver tap si rupture
        onTap: isOutOfStock ? null : onTap,
        
        child: Stack(
          children: [
            // Item card existante (inchangée)
            _buildItemCard(context, isOutOfStock),
            
            // [MANQUE-BORNE-005] Overlay rupture
            if (isOutOfStock)
              _buildOutOfStockOverlay(),
          ],
        ),
      );
    });
  }

  Widget _buildItemCard(BuildContext context, bool isOutOfStock) {
    return Opacity(
      // Griser si rupture
      opacity: isOutOfStock ? 0.5 : 1.0,
      child: Container(
        // ... votre implémentation existante ...
        decoration: BoxDecoration(
          // ...
        ),
        child: Column(
          // ... children existants ...
        ),
      ),
    );
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // OVERLAY RUPTURE (Nouveau)
  // ═══════════════════════════════════════════════════════════════════════════
  Widget _buildOutOfStockOverlay() {
    return Positioned.fill(
      child: Container(
        decoration: BoxDecoration(
          color: Colors.black.withOpacity(0.6),
          borderRadius: BorderRadius.circular(16.r),
        ),
        child: Center(
          child: Container(
            padding: EdgeInsets.symmetric(horizontal: 20.w, vertical: 12.h),
            decoration: BoxDecoration(
              color: Colors.red.shade700,
              borderRadius: BorderRadius.circular(12.r),
              boxShadow: [
                BoxShadow(
                  color: Colors.red.withOpacity(0.4),
                  blurRadius: 8,
                  spreadRadius: 2,
                ),
              ],
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  Icons.block,
                  color: Colors.white,
                  size: 40.sp,
                ),
                SizedBox(height: 8.h),
                Text(
                  'ÉPUISÉ',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 20.sp,
                    fontWeight: FontWeight.bold,
                    letterSpacing: 2,
                  ),
                ),
                SizedBox(height: 4.h),
                Text(
                  'Temporairement indisponible',
                  style: TextStyle(
                    color: Colors.white70,
                    fontSize: 14.sp,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
```

---

## 3. ALTERNATIVE: Badge discret (plus léger)

Si l'overlay complet est trop intrusif:

```dart
Widget _buildOutOfStockBadge() {
  return Positioned(
    top: 8.h,
    right: 8.w,
    child: Container(
      padding: EdgeInsets.symmetric(horizontal: 12.w, vertical: 6.h),
      decoration: BoxDecoration(
        color: Colors.red.shade600,
        borderRadius: BorderRadius.circular(8.r),
      ),
      child: Text(
        'RUPTURE',
        style: TextStyle(
          color: Colors.white,
          fontSize: 12.sp,
          fontWeight: FontWeight.bold,
        ),
      ),
    ),
  );
}
```

---

## 4. SOCKETSERVICE HELPER (si manquant)

**Si `isItemInStock()` n'existe pas dans SocketService:**

**À ajouter dans `socket_service.dart`:**

```dart
// ═══════════════════════════════════════════════════════════════════════════
// HELPERS POUR UI (Sprint 5)
// ═══════════════════════════════════════════════════════════════════════════

/// Vérifie si un item est en stock
/// [MANQUE-BORNE-005] Helper pour ItemWidget
bool isItemInStock(int itemId) {
  return stockStatus[itemId] ?? true;  // Par défaut: en stock
}

/// Stream pour écouter les changements de stock spécifiques
Stream<bool> stockStream(int itemId) {
  return stockStatus.stream
    .map((map) => map[itemId] ?? true)
    .distinct();
}
```

---

## 5. MISE À JOUR DU STOCK (si pas déjà fait)

**Vérifier que SocketService reçoit les mises à jour:**

```dart
// Dans SocketService._initPusher():

channel.bind('stock.updated', (data) {
  final itemId = data['item_id'];
  final isAvailable = data['is_available'];
  
  // [MANQUE-BORNE-005] Mettre à jour la map réactive
  stockStatus[itemId] = isAvailable;
  stockStatus.refresh();  // ← IMPORTANT: Notifier les Obx
  
  debugPrint('[Socket] Stock item $itemId: $isAvailable');
});
```

---

## 6. TESTS STOCK

**Test manuel:**
1. Ouvrir menu sur borne
2. Admin change stock d'un item → "rupture"
3. Vérifier que l'item apparaît avec overlay/badge
4. Essayer de cliquer → ne doit pas ouvrir ItemDetails
5. Admin remet en stock
6. Vérifier que l'overlay disparaît automatiquement

---

*ItemWidget Stock Integration — Sprint 5 Borne — Conforme audit Claude*
