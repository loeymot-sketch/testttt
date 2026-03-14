# AUDIT CAISSE — AFFICHAGE & PRISE DE COMMANDE
## FoodKing vs McDonald's, KFC, Subway, GUR KEBAB, Butter POS

**Date :** 12 Mars 2026  
**Focus :** Layout panier, total toujours visible, ordre des choix (garniture → sauce → viande)

---

## 1. PROBLÈME RÉSOLU : TOTAL TOUJOURS VISIBLE

### Avant
- Panier avec `overflow-y-auto` sur tout le conteneur
- Quand beaucoup d'items → scroll global → total et boutons sortaient de la vue

### Solution implémentée
- **Layout flex** : `flex flex-col overflow-hidden` sur `#pos-cart`
- **Header** (client, token, type) : `flex-shrink-0` — fixe en haut
- **Zone items** : `flex-1 min-h-0 overflow-y-auto` — **scroll indépendant**
- **Footer** (discount, total, boutons) : `flex-shrink-0` + `border-t` + `shadow` — **toujours visible en bas**

### Résultat
Le caissier peut scroller la liste des articles sans jamais perdre de vue le total et les boutons « Annuler » / « Commander ».

---

## 2. BENCHMARK CONCURRENCE — AFFICHAGE PRISE DE COMMANDE

### 2.1 McDonald's

| Élément | McDo |
|---------|------|
| **Layout** | 3 zones : Menu (gauche), Items (centre), Order Summary (droite) |
| **Order Summary** | Toujours visible, pas de scroll sur le total |
| **Total** | En bas du summary, visible en permanence |
| **Ordre choix** | Item → Meal/À la carte → Customisation (modificateurs) |

### 2.2 KFC

| Élément | KFC |
|---------|-----|
| **Layout** | Catégories + grille items + panier latéral |
| **Panier** | Items scrollables, total fixe en bas |
| **Ordre** | Catégorie → Item → Taille → Accompagnements → Sauces |

### 2.3 Subway (référence simplicité)

| Élément | Subway |
|---------|--------|
| **Layout** | Étapes linéaires, une question à la fois |
| **Principe** | KISS — formation annulée tellement c'est intuitif |
| **Ordre** | Pain → Viande → Fromage → Légumes (salade, tomate, oignon) → Sauce → Payer |

### 2.4 GUR KEBAB (kiosk)

| Élément | GUR KEBAB |
|---------|-----------|
| **Ordre** | Sauce → Crudités → Supplément → Frites → Sauce frites → Boisson |
| **Barre progression** | Étape 1/7, icônes, numérotée |
| **Total** | Toujours visible en bas à chaque étape |

### 2.5 Butter POS (référence technique)

| Élément | Butter POS |
|---------|------------|
| **Layout** | Cart (gauche), Catégories (centre), Items (droite) |
| **Cart** | Items scrollables, total toujours visible en bas |
| **Multi-section** | 3 zones distinctes |

---

## 3. ORDRE DES CHOIX — NOUS vs EUX

### FoodKing actuel (Tacos)

1. Viande(s)
2. Sauce (1ère gratuite)
3. Garnitures (Complet, Sans Oignon, etc.)
4. Suppléments
5. Addons (Menu, Frites, Boisson)
6. Sauce frites (si addon frites)
7. Récap

### Subway (sandwich)

1. Pain
2. Viande
3. Fromage
4. Légumes (salade, tomate, oignon, etc.)
5. Sauce
6. Payer

### GUR KEBAB

1. Sauce
2. Crudités (garnitures)
3. Supplément
4. Frites
5. Sauce frites
6. Boisson

### Analyse

| Logique | Subway | GUR | FoodKing |
|---------|--------|-----|----------|
| **Base d'abord** | Pain, viande | — | Viande |
| **Garnitures** | Après viande | 2e | 3e |
| **Sauce** | À la fin | 1er | 2e |
| **Suppléments** | — | 3e | 4e |
| **Accompagnements** | — | 4e, 5e, 6e | 5e, 6e |

**Conclusion :** L'ordre FoodKing (Viande → Sauce → Garnitures → Suppléments) est cohérent avec une logique « base du produit d'abord, puis personnalisation ». Subway fait Légumes avant Sauce. GUR fait Sauce en premier. Pas de standard unique — notre ordre est valide.

---

## 4. AMÉLIORATIONS APPLIQUÉES

| Correction | Fichier | Description |
|------------|---------|-------------|
| Total toujours visible | PosComponent.vue | Flex layout, zone items scroll, footer fixe |
| text-xs panier | PosComponent.vue | text-[10px] → text-xs (lisibilité) |
| Total mis en avant | PosComponent.vue | bg-[#F7F7FC], font-bold, text-primary |

---

## 5. RÉCAPITULATIF LAYOUT PANIER

```
┌─────────────────────────────────────┐
│ Client / Token / Type (Emporter)   │  ← flex-shrink-0
├─────────────────────────────────────┤
│ ┌─────────────────────────────────┐ │
│ │ Item 1 ................ 12,50 € │ │
│ │ Item 2 ................ 8,00 €  │ │  ← flex-1 overflow-y-auto
│ │ Item 3 ................ 5,50 €  │ │     (SCROLL ICI)
│ │ ...                              │ │
│ └─────────────────────────────────┘ │
├─────────────────────────────────────┤
│ Réduction (%)  [____] [Appliquer]   │
│ Sous-total ................ 26,00 € │  ← flex-shrink-0
│ Remise .................... -2,00 € │     (TOUJOURS VISIBLE)
│ TOTAL .................... 24,00 €  │
│ [Annuler]  [Commander]              │
└─────────────────────────────────────┘
```

---

**FIN AUDIT — 12 Mars 2026**
