# 📚 DOCUMENTATION - POS Wizard (Grill House)

> **Projet:** FoodKing - Caisse (POS)  
> **Fichier:** `public/js/pos-wizard.js`  
> **Date:** 10 Mars 2026  
> **Pour:** Équipe de développement & Support

---

## 🎯 OBJECTIF DU WIZARD

Transformer le modal de sélection d'items standard en un **parcours guidé multi-étapes** style McDonald's/KFC, adapté aux spécificités du menu "Le Grill House".

---

## 🏗️ ARCHITECTURE GÉNÉRALE

### Principe de Fonctionnement

```
┌─────────────────────────────────────────────────────────┐
│           INTERCEPTION DU MODAL STANDARD                │
│                                                         │
│  Modal Vue.js standard ──► Interception XHR ──► WIZARD  │
│                                                         │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│              DÉTECTION CATÉGORIE                        │
│                                                         │
│  Analyse du nom de l'item + catégorie active           │
│  Détermine quel parcours appliquer                     │
│                                                         │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│              CONSTRUCTION ÉTAPES DYNAMIQUES             │
│                                                         │
│  Selon la catégorie, construit 1 à 7 étapes             │
│  Chaque étape = une question spécifique                 │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

---

## 🍔 CATÉGORIES SUPPORTÉES

| Catégorie | Détection | Étapes Wizard | Exemples |
|-----------|-----------|---------------|----------|
| **Tacos** | Nom contient "tacos" | 7 étapes | Tacos M, L, XL, XXL |
| **Sandwich** | Catégorie ou nom | 6 étapes | Terminator, Méga, Suprême |
| **Burger** | Nom contient "burger" | 6 étapes | Cheese, Big, Grill, Fish |
| **Assiette** | Nom contient "assiette" | 4 étapes | Mixte, Poulet, Kefta |
| **Salade** | Nom contient "salade" | 3 étapes | César, Chèvre, Royale |
| **Omelette** | Nom contient "omelette" | 2 étapes | Nature, Fromage |
| **Ojja** | Nom contient "ojja" | 1 étape | Bœuf, Poulet |
| **Snacking** | Mots-clés | 1 étape | Wings, Tenders, Frites |
| **Menu Enfant** | Nom contient "enfant" | 0 étape | Sans wizard |
| **Dessert** | Mots-clés | 0 étape | Sans wizard |
| **Boisson** | Mots-clés | 0 étape | Sans wizard |

---

## 📝 DÉTAIL DES ÉTAPES

### Étape 1: Sélection Viandes (TACOS UNIQUEMENT)

**Détection nombre de viandes:**
```javascript
// Algorithme dans detectViandeCount(name)
"Tacos M (1 Viande)"     → 1 viande
"Tacos L (2 Viandes)"    → 2 viandes
"Tacos XL (3 Viandes)"   → 3 viandes
"Tacos XXL (4 Viandes)"  → 4 viandes
```

**Interface:**
- Compteurs "Viande 1", "Viande 2", etc.
- Liste des 10 viandes disponibles (Merguez, Poulet, etc.)
- Emojis associés à chaque viande

**Validation:**
- Bloquer si pas assez de viandes sélectionnées
- Message: "Veuillez choisir X viandes"

---

### Étape 2: Sélection Sauce

**Règle métier importante:**
```
1ère sauce  → GRATUITE (0€)
2ème sauce  → +0.50€
3ème sauce  → +1.00€
etc.
```

**Sauces disponibles (17 total):**
- Ketchup, Mayo, Algérienne, Curry
- Andalouse, Burger, Samouraï, Barbecue
- Cocktail, Américaine, Hannibal, Harissa
- Blanche, Poivre, Biggy, BBQ
- Sans sauce

**Interface:**
- Checkboxes multi-sélection
- Badge "Gratuit" sur première sélection
- Prix dynamique mis à jour

---

### Étape 3: Garnitures (Crudités)

**Items pré-cochés par défaut:**
- ✅ Salade
- ✅ Tomate
- ✅ Oignon

**Client peut modifier:**
- ❌ Sans Oignon
- ❌ Sans Tomate
- ❌ Sans Salade
- ❌ Aucune Crudité

**Règle:** Toujours gratuites, modifications autorisées

---

### Étape 4: Suppléments (Extras Payants)

**Liste des suppléments avec prix:**

| Supplément | Prix |
|------------|------|
| Supplément Cheddar | 1.00€ |
| Supplément Jambon | 1.00€ |
| Supplément Poulet | 2.00€ |
| Supplément Kebab | 2.00€ |
| Supplément Viande Hachée | 2.00€ |
| Supplément Œuf | 1.00€ |
| Supplément Raclette | 1.00€ |
| Supplément Boursin | 1.00€ |
| Supplément Chèvre | 1.00€ |
| Sauce supplémentaire (toutes) | 0.50€ |

---

### Étape 5: Menu (Formule)

**Options:**
1. **"En Menu"** (+3.00€)
   - Inclut: Frites + Boisson
   - Sauce frites gratuite (1 choix)
   
2. **"Frites Seules"** (+1.50€)
   - Juste les frites
   - Option sauce frites (+0.50€ par sauce)
   - Option Cheddar (+1.00€)
   
3. **"Boisson Seule"** (+1.50€)
   - Juste la boisson

4. **Aucun**
   - Item seul

---

### Étape 6: Sauce Frites (Si Menu/Frites sélectionnés)

**Règle identique à Étape 2:**
- 1ère sauce gratuite
- Supplémentaires: +0.50€

**Options:**
- Mêmes sauces que pour l'item principal
- Option "Cheddar fondu" (+1.00€ ou +1.50€ selon taille)

---

### Étape 7: Récapitulatif

**Affichage:**
- Nom de l'item
- Toutes les sélections détaillées
- Prix unitaire calculé
- Quantité (modifiable)
- Instructions spéciales (textarea)
- **TOTAL** (prix item + extras × quantité)

**Actions:**
- Bouton "Ajouter au Panier"
- Retour possible aux étapes précédentes

---

## 💰 CALCUL DES PRIX

### Formule:
```javascript
Prix Total = (Prix Base Item + Extras) × Quantité

Où:
- Prix Base Item = Prix configuré dans admin
- Extras = Somme de:
  * Sauces supplémentaires (n-1) × 0.50€
  * Menu (+3.00€) ou Frites (+1.50€) ou Boisson (+1.50€)
  * Sauce frites supplémentaires (n-1) × 0.50€
  * Cheddar frites (+1.00€ ou +1.50€)
  * Tous les suppléments sélectionnés
```

### Exemple:
```
Tacos L (2 viandes) :        8.50€
+ Sauce supplémentaire:     +0.50€ (2ème sauce)
+ Menu (Frites+Boisson):     +3.00€
+ Cheddar fondu:             +1.50€
───────────────────────────────────
Total unitaire:             13.50€
× Quantité 2:              × 2
───────────────────────────────────
TOTAL PANIER:               27.00€
```

---

## 🎨 STYLES ET UX

### Classes CSS Principales:
```css
.wizard-container      /* Conteneur principal modal */
.wizard-step          /* Chaque étape */
.wizard-step-title    /* Titre "Étape X/Y" */
.viande-grid          /* Grille sélection viandes */
.sauce-grid           /* Grille sauces avec prix */
.garniture-list       /* Liste garnitures */
.supplement-grid      /* Grille suppléments */
.recap-container      /* Récap final */
```

### Emojis Utilisés:
| Type | Emojis |
|------|--------|
| Viandes | 🌶️ 🥩 🍗 🔵 🟡 🌭 |
| Sauces | 🍅 🥚 🌶️ 🍛 🍔 ⚔️ 🔥 🍹 |
| Garnitures | 🥬 🍅 🧅 |
| Suppléments | 🥚 🧀 🫕 🥓 |
| Addons | 🍟 🥤 🍊 💧 |

---

## 🔧 CONFIGURATION

### Modifier les Viandes:
Dans `pos-wizard.js`, ligne 37-48:
```javascript
var VIANDES = [
    { key: 'merguez', name: 'Merguez', emoji: '🌶️' },
    // Ajouter/modifier ici
];
```

### Modifier les Sauces:
Dans `pos-wizard.js`, ligne 53-71:
```javascript
var ALL_SAUCES = [
    { key: 'ketchup', name: 'Ketchup', emoji: '🍅' },
    // Ajouter/modifier ici
];
```

### Modifier le Prix des Extras:
```javascript
var SAUCE_EXTRA_PRICE = 0.50;  // Prix sauce supplémentaire
```

### Modifier les Suppléments:
Dans `GrillHouseMenuSeeder.php`, lignes 76-92:
```php
$supplements = [
    'Supplément Cheddar' => 1.00,
    // Ajouter/modifier ici
];
```

---

## 🐛 DÉBOGAGE

### Activer le Mode Debug:
Ouvrir Console DevTools (F12), le wizard log automatiquement:
```javascript
[POS-WIZARD] Intercepted item data: {...}
[POS-WIZARD] detectCategory: {domCat: "Nos Tacos", name: "Tacos L"}
[POS-WIZARD] detectViandeCount: "Tacos L (2 Viandes)" → 2
[POS-WIZARD] Building steps for: tacos with 2 meats
```

### Problèmes Courants:

#### Le Wizard ne s'affiche pas:
- Vérifier URL contient `/admin/pos`
- Vérifier pas d'erreur JavaScript dans console
- Vérifier API retourne bien `itemAttributes` et `variations`

#### Prix incorrect:
- Vérifier `detectCategory()` retourne bonne catégorie
- Vérifier `fmtPrice()` fonctionne correctement
- Vérifier extras sont bien dans `item.extras`

#### Viandes mal détectées:
- Vérifier format nom: "Tacos L (2 Viandes)" ou "Tacos L"
- Tester regex dans console: `"Tacos L (2 Viandes)".match(/\((\d+)\s*viande/)`

---

## 🔌 INTEGRATION BACKEND

### Structure JSON Envoyée au Panier:
```javascript
{
  "item_id": 123,
  "name": "Tacos L (2 Viandes)",
  "price": 8.50,
  "quantity": 2,
  "item_variations": [
    {"name": "Viande 1", "value": "Poulet"},
    {"name": "Viande 2", "value": "Kebab"},
    {"name": "Sauce", "value": "Algérienne"},
    {"name": "Sauce 2", "value": "Blanche"}
  ],
  "item_extras": [
    {"name": "Supplément Cheddar", "price": 1.00},
    {"name": "Menu (Frites+Boisson)", "price": 3.00}
  ],
  "instruction": "Sans oignon svp"
}
```

### Stockage en Base de Données:
```php
// Table: order_items
// item_variations → JSON string
// item_extras → JSON string
```

---

## 📝 CHECKLIST MISE À JOUR MENU

Quand vous ajoutez un nouvel item dans le menu:

- [ ] Créer l'item dans Admin (Catégorie correcte)
- [ ] Vérifier nom suit convention: "Tacos X (N viandes)" ou "Item Name"
- [ ] Tester wizard s'affiche correctement
- [ ] Vérifier étapes adaptées (tacos=sandwich?)
- [ ] Tester calcul prix avec extras
- [ ] Vérifier impression ticket détaille variations
- [ ] Tester sur KDS si commande apparaît avec bon nom

---

## 📞 SUPPORT

### Contacts:
- **Dev Backend:** Voir `ItemService.php`, `OrderService.php`
- **Frontend Vue:** Voir `PosComponent.vue`, `ItemComponent.vue`
- **Wizard JS:** Ce fichier (`pos-wizard.js`)

### Logs Importants:
```bash
# Voir logs commandes
tail -f storage/logs/laravel.log | grep "Order"

# Voir erreurs
tail -f storage/logs/laravel.log | grep -i "error\|exception"
```

---

**Documentation complète du POS Wizard.**

*Pour toute question, référez-vous aux commentaires dans le code source.*
