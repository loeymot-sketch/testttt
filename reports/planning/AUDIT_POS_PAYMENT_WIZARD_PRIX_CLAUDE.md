# 🔍 AUDIT COMPLET POS — PAIEMENT, WIZARD & PRIX
**Auteur :** Claude (Lead Architect)  
**Date :** 11 Mars 2026 | 15h35  
**Scope :** `PaymentComponent.vue` · `PosOrderRequest.php` · `MenuSeeder.php` · `pos-wizard.js` · `config/menu.php`

---

## ✅ PARTIE 1 — AUDIT PAIEMENT (Cash + Carte)

### 1.1 Architecture — VALIDÉE ✅

Le flux est correctement implémenté de bout en bout :

```
[Caissier saisit montant]
        │
        ▼
PaymentComponent.vue
├── Cash : lit DOM cashInput.value → form.pos_received_amount
└── Carte : lit $refs.cardInput.value → form.pos_payment_note
        │
        ▼
PosOrderRequest.php (validation)
├── Cash : pos_received_amount REQUIS + ≥ total ✅
├── Carte : pos_payment_note REQUIS, 4 chiffres exactement ✅
└── Erreur si reçu < total → message clair ✅
        │
        ▼
OrderService.php
├── DB::transaction() → crée commande
├── SendOrderGotPush::dispatch() → KDS ✅
└── Return order avec ID
        │
        ▼
ReceiptComponent → affiche ticket avec rendu monnaie / ****XXXX
```

### 1.2 BUGS TROUVÉS dans PaymentComponent.vue

#### 🔴 BUG-PAY-001 : `confirmOrder` ne set pas `loading.isActive = true` au début
**Localisation :** `PaymentComponent.vue` ligne 171  
**Impact :** Le spinner de chargement n'apparaît pas → l'utilisateur peut double-cliquer et envoyer 2 commandes.  
**Fix :**
```javascript
confirmOrder: function () {
    this.loading.isActive = true; // ← MANQUANT — ajouter en première ligne
    try {
        ...
```

#### 🟡 BUG-PAY-002 : Cas CARTE — pos_payment_note mis à `""` si cardInput vide
**Localisation :** `PaymentComponent.vue` lignes 183-187  
**Actuel :**
```javascript
if (this.$props.props.form.pos_payment_method === this.posPaymentMethodEnum.CARD && this.$refs.cardInput.value) {
    this.$props.props.form.pos_payment_note = this.$refs.cardInput.value;
} else {
    this.$props.props.form.pos_payment_note = ""; // ← "" quand carte mais champ vide
}
```
**Conséquence :** La validation `PosOrderRequest` va rejeter avec erreur générique au lieu d'un message clair.  
**Fix :** Pas de changement nécessaire dans le Vue — la validation Laravel côté serveur attrape l'erreur. C'est un design acceptable. ✅

#### 🟢 CONFIRMATION : la lecture DOM directe `document.getElementById('cashInput')` fonctionne
La reason pour laquelle la valeur était lue du DOM (ligne 175) plutôt que depuis le binding Vue est documentée dans le commentaire : contournement d'un bug de binding Vue.js. C'est volontaire et correct.

### 1.3 Flux complet — Résumé paiement
| Étape | Cash | Carte | Status |
|-------|------|-------|--------|
| Saisie montant | Clavier numérique → cashInput | 4 chiffres → cardInput | ✅ |
| Lecture valeur | DOM direct (workaround Vue bug) | $refs.cardInput | ✅ |
| Validation frontend | pos_received_amount≥total | 4 chiffres | ✅ |
| Validation backend | PosOrderRequest.php | PosOrderRequest.php | ✅ |
| Création commande | OrderService.php | OrderService.php | ✅ |
| KDS notification | SendOrderGotPush | SendOrderGotPush | ✅ |
| Impression ticket | ReceiptComponent | ReceiptComponent | ✅ |
| Rendu monnaie | reçu - total affiché | N/A (****XXXX) | ✅ |
| Loading spinner | **MANQUANT** 🔴 | **MANQUANT** 🔴 | ❌ BUG-PAY-001 |

---

## ⚠️ PARTIE 2 — AUDIT WIZARD LOGIQUE (Sauces/Viandes/Catégories)

### 2.1 BUGS CRITIQUES DANS LE WIZARD (`pos-wizard.js`)

#### 🔴 BUG-WIZ-001 : Sauces proposées comme extras (ItemExtras) avec `sauce_frites`

**Problème central :** Dans le seeder (ligne 553-560 MenuSeeder), chaque item reçoit des extras nommés `"Sauce supplémentaire: Algérienne"`, `"Sauce supplémentaire: Samouraï"` etc. comme **ItemExtra**.  
Dans `pos-wizard.js`, les **extras** sont affichés dans l'étape `garnitures` (extras gratuits) ou `supplements` (extras payants).  
**Résultat :** Un sandwich affiche des "Sauce supplémentaire" dans les suppléments → C'est ce que l'utilisateur voyait.

**Cause :** Dans `attachSupplements()` (MenuSeeder ligne 540+), chaque item (Sandwich, Burger, Assiette…) reçoit:  
1. Les suppléments classiques (Cheddar, Jambon, Œuf…) → OK  
2. **Toutes les sauces comme extras payants à +€0.50** → PROBLÈME

Cela crée des entrées `item_extras` comme :
```
"Sauce supplémentaire: Algérienne" → +€0.50
"Sauce supplémentaire: Samouraï"  → +€0.50
...×12 sauces
```
Ces extras apparaissent dans l'étape "Suppléments" du wizard → CONFUSION TOTALE.

**Fix requis dans MenuSeeder.php :** Séparer les extras sauce des suppléments alimentaires.

#### 🔴 BUG-WIZ-002 : Frites et boissons reçoivent des suppléments (Cheddar, Jambon…)
**Localisation :** `MenuSeeder.php` ligne 444-447  
```php
// Add supplements/extras (for most items except simple ones)
if (!isset($data['is_frites']) || !$data['is_frites']) {
    $this->attachSupplements($item);
}
```
**Problème :** Les items `ojja`, `omelette`, `boisson`, `dessert` reçoivent aussi les suppléments (Cheddar, Jambon, Œuf, Raclette…).  
Une omelette n'a pas besoin de "Supplément Kebab +€2.00".

#### 🔴 BUG-WIZ-003 : Viandes attachées aux Sandwichs dans le seeder, mais pas cohérent avec le wizard

**Dans `config/menu.php`:**
```php
'Le Terminator (2 Viandes)' => viandes: 2, has_sauce: true, has_crudites: true
```
**Dans `MenuSeeder.php`** (ligne 430-433):
```php
if ($data['viandes'] > 0) {
    $this->attachMeatVariations($item, $data['viandes']);
}
```
**Dans `pos-wizard.js`** (ligne 190-191):
```javascript
if (cat.includes('sandwich') || name.includes('sandwich')) return 'sandwich';
// → getAllowedSteps('sandwich') = ['sauce', 'garnitures', 'supplements', 'menu', 'sauce_frites', 'recap']
// VIANDE n'est PAS dans les étapes sandwich!
```
**Résultat :** Le sandwich "Le Terminator" a 2 slots viandes dans la DB mais le wizard POS ne les affiche PAS. La viande n'est jamais sélectionnée via le wizard pour les Sandwichs.

---

## 💰 PARTIE 3 — AUDIT PRIX COMPLET

### 3.1 Prix dans `config/menu.php` — État actuel

**Addons (Formules) :**
```php
'addons' => [
    ['name' => 'En Menu (Frites + Boisson)', 'price' => 3.00],  // ✅ €3 = CORRECT
    ['name' => 'Frites Seules',              'price' => 1.50],  // ✅ €1.50 = CORRECT
    ['name' => 'Boisson Seule',              'price' => 1.50],  // ✅ €1.50 = CORRECT
]
```
> Menu frites+boisson = **€3.00** ✅ Conforme à la spec utilisateur.

**Sauces — PROBLÈME TROUVÉ :**
```php
'supplement_sauce_price' => 0.50,  // ✅ €0.50 extra sauces
```
Mais dans `pos-wizard.js` ligne 73:
```javascript
var SAUCE_EXTRA_PRICE = 0.50; // ✅ Synchronisé avec config
```

**Suppléments — PROBLÈME TROUVÉ :**
```php
'supplements' => [
    'Supplément Cheddar'       => 1.00,  // ✅
    'Supplément Jambon'        => 1.00,  // ✅
    'Supplément Poulet'        => 2.00,  // ✅
    'Supplément Kebab'         => 2.00,  // ✅
    'Supplément Viande Hachée' => 2.00,  // ✅
    'Supplément Œuf'           => 1.00,  // ✅
    'Supplément Raclette'      => 1.00,  // ✅
    'Supplément Boursin'       => 1.00,  // ✅
    'Supplément Chèvre'        => 1.00,  // ✅
]
```
**Les prix des suppléments sont corrects dans la config.** ✅

**Items Tacos — Vérification:**
```php
'Tacos M (1 Viande)'   => 6.50  ✅
'Tacos L (2 Viandes)'  => 8.50  ✅
'Tacos XL (3 Viandes)' => 10.50 ✅
'Tacos XXL (4 Viandes)'=> 12.50 ✅
```

**Items Sandwich:**
```php
'Le Terminator (2 Viandes)' => 9.00  ✅
'Le Méga (2 Viandes)'       => 8.00  ✅
'Le Suprême (1 Viande)'     => 7.00  ✅
'Le Cayenne (1 Viande)'     => 7.00  ✅
'Panini (1 Viande)'         => 5.00  ✅
```

**Items Burgers:**
```php
'Cheese Burger'   => 5.50  ✅
'Double Cheese'   => 7.00  ✅
'Fish Burger'     => 6.00  ✅
'Chicken Burger'  => 6.00  ✅
'Grill Burger'    => 8.00  ✅
'Big Burger'      => 6.50  ✅
```

**Conclusion Prix :** Les prix dans `config/menu.php` sont corrects. Le problème est que les addons (formules) dans le wizard POS sont calculés côté serveur avec des prix différents si la DB n'a pas été re-seedée après la correction de config.

---

## 🔧 PARTIE 4 — STRUCTURE CORRECTE POUR AJOUTER/MODIFIER UN PRODUIT

### 4.1 Où agir et dans quel ordre

```
┌─────────────────────────────────────────────────────────────────┐
│              CONFIG/MENU.PHP — Source de Vérité Unique          │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ NOUVELLES SAUCES → 'sauces' array                       │   │
│  │ NOUVEAUX SUPPLÉMENTS → 'supplements' array avec prix    │   │
│  │ NOUVELLES VIANDES → 'meats' array                       │   │
│  │ NOUVELLE CATÉGORIE → 'categories' array                 │   │
│  │ NOUVEAU PRODUIT → 'items' > [category-slug] > [...]     │   │
│  └──────────────────────────────────────────────────────────┘   │
│                           │                                     │
│                  php artisan menu:reset                         │
│                    (re-seed la DB)                             │
└─────────────────────────────────────────────────────────────────┘
```

### 4.2 Template pour un produit personnalisé

```php
// Dans config/menu.php → 'items' → 'ma-categorie'
[
    'name'         => 'Mon Burger Spécial',
    'price'        => 8.50,            // Prix de base en EUR
    'description'  => 'Description',
    
    // Combien de viandes choisit le client ? (0 = pas de slot viande)
    'viandes'      => 2,               // 0, 1, 2, 3 ou 4
    
    // Est-ce que ce produit a une sauce ?
    'has_sauce'    => true,            // false pour desserts/boissons
    
    // Est-ce que ce produit a des garnitures (STO) ?
    'has_crudites' => true,            // false pour frites/boissons
    
    // Sauces spéciales (si null → utilise 'sauces' globales)
    // 'sauce_special' => ['Sauce César', 'Sans Sauce'],
    
    // Si c'est une simple portion de frites (pas de suppléments standards)
    // 'is_frites'    => true,
]
```

### 4.3 Structure par type de produit — Règles du Wizard POS

| Type | `viandes` | `has_sauce` | `has_crudites` | Étapes Wizard |
|------|-----------|-------------|----------------|---------------|
| Tacos | 1-4 | true | true | Viande → Sauce → Garnitures → Suppl → Menu → Récap |
| Sandwich | 0-2 | true | true | Sauce → Garnitures → Suppl → Menu → Récap |
| Burger | 0 | true | true | Sauce → Garnitures → Suppl → Menu → Récap |
| Assiette | 1-3 | true | true | Sauce → Accompagnement → Suppl → Récap |
| Salade | 0 | true | false | Sauce → Récap |
| Omelette | 0 | true | false | Sauce (radio) → Récap |
| Ojja | 0 | false | false | Récap seulement |
| Chicken/Tenders | 0 | true | false | Sauce (radio) → Récap |
| Dessert | 0 | false | false | **PAS DE WIZARD** |
| Boisson | 0 | false | false | **PAS DE WIZARD** |

### 4.4 Nouvelle catégorie — Procédure complète

```php
// ÉTAPE 1 : Ajouter la catégorie dans config/menu.php
'categories' => [
    // ... catégories existantes ...
    ['name' => 'Ma Nouvelle Catégorie', 'sort' => 12, 'description' => 'Description'],
],

// ÉTAPE 2 : Ajouter les items de cette catégorie
'items' => [
    // ... items existants ...
    'ma-nouvelle-categorie' => [  // slug = kebab-case du nom
        ['name' => 'Mon Item', 'price' => 5.00, 'viandes' => 0,
         'has_sauce' => false, 'has_crudites' => false],
    ],
],

// ÉTAPE 3 : Ajouter la règle dans le wizard POS si catégorie inédite
// Dans pos-wizard.js → detectCategory() :
// if (cat.includes('ma-nouvelle-categorie')) return 'ma_categorie';
// Dans pos-wizard.js → getAllowedSteps() :
// case 'ma_categorie': return ['sauce_single', 'recap'];

// ÉTAPE 4 : Pour la borne Flutter → CategoryHelper.dart
// Ajouter la catégorie dans detectCategory() et buildDynamicSteps()

// ÉTAPE 5 : Re-seeder
// php artisan menu:reset
```

---

## 🚀 PARTIE 5 — PLAN D'ACTION KIMI (Corrections Prioritaires)

### TÂCHE CRITIQUE 1 — [P0] Corriger `attachSupplements()` dans MenuSeeder.php

**Problème :** Toutes les items reçoivent des "Sauce supplémentaire" en extras → chaos dans le wizard.  
**Fix :**

```php
protected function attachSupplements(Item $item, string $categorySlug = ''): void
{
    // Produits qui N'ont PAS de suppléments alimentaires
    $noSupplementCategories = ['ojja', 'omelettes', 'nos-salades', 'nos-desserts', 
                                'nos-boissons', 'frites-accompagnements'];
    
    // Produits qui N'ont PAS de sauces supplémentaires dans les extras
    // (ils ont la sauce via ItemAttribute/Variation = gratuite)
    // Les sauces supplémentaires ne doivent apparaître QUE pour les produits
    // où une 2ème sauce est logique
    $hasSauceExtras = in_array($categorySlug, ['nos-tacos', 'nos-sandwichs', 'nos-burgers']);

    if (!in_array($categorySlug, $noSupplementCategories)) {
        // Suppléments alimentaires classiques
        foreach ($this->config['supplements'] as $name => $price) {
            ItemExtra::create([
                'item_id' => $item->id,
                'name'    => $name,
                'price'   => $price,
                'status'  => 1,
            ]);
        }
    }

    // Sauces supplémentaires SEULEMENT pour Tacos/Sandwich/Burger
    if ($hasSauceExtras) {
        foreach ($this->config['sauces'] as $sauce) {
            if ($sauce !== 'Sans Sauce') { // Ne pas proposer "Sauce suppl: Sans Sauce"
                ItemExtra::create([
                    'item_id' => $item->id,
                    'name'    => "Sauce supplémentaire: {$sauce}",
                    'price'   => $this->config['supplement_sauce_price'],
                    'status'  => 1,
                ]);
            }
        }
    }
}
```

**Impact si non corrigé :** L'étape Suppléments du wizard affiche 12 sauces en plus des vrais suppléments. Confusion totale caissier ET base de données polluée.

### TÂCHE CRITIQUE 2 — [P0] Corriger `createItem()` — passar categorySlug à `attachSupplements`

```php
protected function createItem(array $data, int $categoryId, string $categorySlug = ''): void
{
    $item = Item::create([...]);
    $this->attachAddons($item);
    if ($data['viandes'] > 0) {
        $this->attachMeatVariations($item, $data['viandes']);
    }
    if ($data['has_sauce']) {
        $this->attachSauceVariations($item, $data['sauce_special'] ?? null);
    }
    if ($data['has_crudites']) {
        $this->attachCruditeVariations($item);
    }
    // DONNER LE SLUG pour filtrer correctement
    if (!isset($data['is_frites']) || !$data['is_frites']) {
        $this->attachSupplements($item, $categorySlug); // ← PASSER LE SLUG
    }
    if (isset($data['is_frites']) && $data['is_frites']) {
        $this->attachFritesExtras($item);
    }
}

// Et dans createItems() :
foreach ($items as $itemData) {
    $this->createItem($itemData, $categoryId, $categorySlug); // ← PASSER LE SLUG
    $itemCount++;
}
```

### TÂCHE CRITIQUE 3 — [P0] Corriger le wizard pour les Sandwichs avec viandes

**Dans `pos-wizard.js` → `getAllowedSteps()`:**

```javascript
case 'sandwich':
case 'burger':
    // Vérifier si l'item a des slots viandes
    var viandeCount = detectViandeCount(lastItemData ? lastItemData.name : '');
    if (viandeCount > 0) {
        return ['viande', 'sauce', 'garnitures', 'supplements', 'menu', 'sauce_frites', 'recap'];
    }
    return ['sauce', 'garnitures', 'supplements', 'menu', 'sauce_frites', 'recap'];
```

### TÂCHE CRITIQUE 4 — [P0] Corriger `BUG-PAY-001` — Loading spinner

**Dans `PaymentComponent.vue`:**

```javascript
confirmOrder: function () {
    this.loading.isActive = true;  // ← LIGNE À AJOUTER
    try {
        // ... reste du code inchangé
```

### TÂCHE 5 — [P1] Re-seeder après corrections

```bash
php artisan menu:reset
php artisan menu:verify
```

---

## ✅ RÉSUMÉ EXÉCUTIF — Tableau de bord des bugs

| ID | Fichier | Sévérité | Description | Fix |
|----|---------|----------|-------------|-----|
| BUG-PAY-001 | PaymentComponent.vue | 🟡 P1 | Loading spinner manquant → double-click possible | +1 ligne JS |
| BUG-WIZ-001 | MenuSeeder.php | 🔴 P0 | Sauces comme extras pour TOUS les items | Filtrer par categorySlug |
| BUG-WIZ-002 | MenuSeeder.php | 🔴 P0 | Ojja/Omelette/Dessert reçoivent Cheddar/Kebab comme suppl | Filtrer par categorySlug |
| BUG-WIZ-003 | pos-wizard.js | 🟡 P1 | Sandwich avec viandes → wizard ne montre pas slot viande | +5 lignes JS |
| PRIX-SYNC | MenuSeeder.php | 🟢 INFO | Prix config corrects. Après fix seeder → re-seeder | menu:reset |

**Bonne nouvelle :** Le flux paiement Cash + Carte est architecturalement solide. Il fonctionne. Le fix P0 concerne uniquement la pollution des données en DB via le seeder.

---

*Audit Claude — Sprint 4 — Ne pas exécuter les corrections sans backup DB.*
