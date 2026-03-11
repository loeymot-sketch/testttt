# 🚨 AUDIT POS WIZARD — 4 BUGS + PLAN FLOW COMPLET
**Auteur :** Claude (Lead Architect)  
**Date :** 11 Mars 2026 | 16h00  
**Fichier audité :** `public/js/pos-wizard.js` (2361 lignes)

---

## 🔴 PARTIE 1 — DIAGNOSTIC DES 4 BUGS CONSTATÉS EN PROD

### 🐛 BUG-POS-001 : Côté SAUCE affiche des noms de VIANDES

**Screenshot :** La colonne droite "Sauce (1ère gratuite)" affiche Poulet, Cordon Bleu, Kebab, Viande Hachée, Merguez, Nuggets, Tenders, Algérienne, Samouraï.

**Cause racine — ligne 279-311 `pos-wizard.js` :**
```javascript
// PROBLÈME : boucle sur TOUS les itemAttributes sans filtre
data.itemAttributes.forEach(function (attr) {
    var attrId = attr.id.toString();
    var vars = data.variations[attrId] ?? [];
    vars.forEach(function (v) {
        dbSauces[v.name.toLowerCase()] = { ... };
    });
});
// → Résultat : "Viande 1" + "Sauce" → TOUTES les variations entrent dans dbSauces
// → La sauce list reçoit : Poulet, Merguez, Kebab... (viandes du Tacos L)
```

**Fix — Filtrer uniquement les attributs de type SAUCE :**
```javascript
data.itemAttributes.forEach(function (attr) {
    // FILTRE : Ne prendre QUE les attributs sauce (pas viande, pas garniture)
    var attrName = (attr.name || '').toLowerCase();
    var isSauceAttr = attrName.includes('sauce') || attrName.includes('assaisonnement');
    if (!isSauceAttr) return; // ← LIGNE À AJOUTER
    
    var attrId = attr.id.toString();
    var vars = data.variations && data.variations[attrId] ? data.variations[attrId] : [];
    vars.forEach(function (v) {
        dbSauces[v.name.toLowerCase()] = {
            id: v.id,
            name: v.name,
            attributeId: attr.id,
            dbPrice: parseFloat(v.convert_price) || 0
        };
    });
});
```

---

### 🐛 BUG-POS-002 : Menu complet affiché à €6 au lieu de €3

**Screenshot :** "Oui, Menu complet — €6.00"

**Cause racine — ligne 342 `pos-wizard.js` :**
```javascript
addonItems = data.addons.map(function (ad) {
    return {
        id: ad.id,
        itemId: ad.addon_item_id || ad.item_addon_id,
        name: ad.addon_item_name,
        price: parseFloat(ad.total_convert_price) || 0,  // ← PROBLÈME
        // total_convert_price = prix de l'addon item (frites €3) × qté dans les addons
        // En réalité l'addon seeder stocke "En Menu (Frites + Boisson)" = €3.00
        // mais addonItemConvertPrice vs total_convert_price se confondent
```

**Vérification dans la DB :**
```sql
SELECT addon_item_name, addon_item_convert_price, total_convert_price
FROM item_addons WHERE addon_item_name LIKE '%Menu%';
-- Résultat probable :
-- addon_item_name: "En Menu (Frites + Boisson)"
-- addon_item_convert_price: 3.00   ← CORRECT
-- total_convert_price: 6.00        ← BUG : doublement (qté × 2?)
```

**Fix — utiliser `addon_item_convert_price` pas `total_convert_price` :**
```javascript
addonItems = data.addons.map(function (ad) {
    // Priorité : prix unitaire de l'addon, pas le total (qui peut être multiplié)
    var unitPrice = parseFloat(ad.addon_item_convert_price)
                 || parseFloat(ad.addonItem && ad.addonItem.convert_price)
                 || parseFloat(ad.total_convert_price)
                 || 0;
    return {
        id: ad.id,
        itemId: ad.addon_item_id || ad.item_addon_id,
        name: ad.addon_item_name,
        price: unitPrice,           // ← CORRIGER ICI
        currencyPrice: '€' + unitPrice.toFixed(2),
        thumb: (ad.addonItem && ad.addonItem.thumb) ? ad.addonItem.thumb : (ad.thumb || ad.cover || '')
    };
});
```

---

### 🐛 BUG-POS-003 : Garnitures demandées en PAGE 1 (avec Viande & Sauce)

**Constat :** Sur la première page (split screen), la question Garnitures (Salade/Tomate/Oignon) apparaît déjà. L'utilisateur préfère que page 1 = **Viande + Sauce uniquement**, page 2 = **Garnitures + Suppléments**.

**Cause racine — Le step `viande_sauce` (ligne 351-373) inclut `freeExtras` indirectement via le rendu `renderViandeSauceStep()`**

**Analyse du flux actuel (Tacos) :**
```
Page 1 : viande_sauce  → Viande (gauche) + Sauce (droite) + GARNITURES (bas)
Page 2 : perso         → Garnitures incluses + Suppléments optionnels
Page 3 : menu          → Voulez-vous le menu ?
Page 4 : recap
```

**Fix — supprimer les garnitures de la page 1 :**
Le step `viande_sauce` ne doit afficher que viandes + sauce. Les garnitures restent uniquement dans l'étape `perso` (page 2).

Vérifier dans `renderViandeSauceStep()` qu'aucune référence à `freeExtras` ou `garnitureItems` n'arrive sur la colonne droite. Si c'est le cas, nettoyer le rendu.

---

### 🐛 BUG-POS-004 : Suppléments non fonctionnels (prix ne change pas)

**Constat :** Dans la page "Personnalisation", on coche un supplément (+€1.00) mais le total provisoire ne change pas.

**Cause racine — `calculateRunningTotal()` ne prend pas en compte `selections.supplements` :**
```javascript
// Dans calculateRunningTotal() (ligne ~1800+) :
// Il manque le calcul des supplements sélectionnés
// Probable bug : selections.supplements n'est pas itéré
```

**Fix à vérifier et appliquer :**
```javascript
function calculateRunningTotal() {
    var total = basePrice * itemQuantity;
    
    // Sauces extra
    if (selections.sauceOrder && selections.sauceOrder.length > 1) {
        total += (selections.sauceOrder.length - 1) * SAUCE_EXTRA_PRICE * itemQuantity;
    }
    
    // ← SUPPLÉMENTS — PROBABLEMENT MANQUANT
    if (selections.supplements) {
        Object.keys(selections.supplements).forEach(function(id) {
            if (selections.supplements[id]) {
                // Trouver le prix du supplément
                var paidItems = steps.reduce(function(acc, s) {
                    return acc.concat(s.paidItems || s.items || []);
                }, []);
                var found = paidItems.find(function(p) { return p.id == id; });
                if (found) total += found.price * itemQuantity;
            }
        });
    }
    
    // Menu addon
    if (selections.menuChoice === 'full') {
        var menuStep = steps.find(function(s) { return s.type === 'menu'; });
        if (menuStep && menuStep.items && menuStep.items[0]) {
            total += menuStep.items[0].price * itemQuantity; // ← €3 si BUG-002 corrigé
        }
    }
    
    return total;
}
```

---

### 🐛 BUG-POS-005 (BONUS) : Sauces supplémentaires en tant qu'extras dans les Suppléments

**Déjà documenté comme BUG-WIZ-001 dans l'audit seeder.**  
Dans la page "Personnalisation" → Suppléments, on voit : "Sauce supplémentaire: Algérienne +€0.50", "Sauce supplémentaire: Samouraï +€0.50", etc.  
**Cause :** Le seeder `attachSupplements()` ajoute toutes les sauces comme `ItemExtra` pour les Tacos.  
**Fix :** Filtrer dans `paidExtras` : exclure tout extra dont le nom contient "sauce supplémentaire".

```javascript
var paidExtras = [];
if (data.extras && data.extras.length > 0) {
    data.extras.forEach(function (ex) {
        var price = parseFloat(ex.convert_price) || 0;
        var name = (ex.name || '').toLowerCase();
        // FILTRE : exclure les sauces supplémentaires (gérées par leur propre étape)
        if (name.includes('sauce supplémentaire') || name.includes('sauce supplementaire')) return;
        var obj = { id: ex.id, name: ex.name, price: price, currencyPrice: ex.currency_price || fmtPrice(price) };
        if (price <= 0) { freeExtras.push(obj); } else { paidExtras.push(obj); }
    });
}
```

---

## 🏗️ PARTIE 2 — NOUVEAU FLUX POS OPTIMAL (14 ÉTAPES INTELLIGENTES)

### Design général : Flux adaptatif selon les choix

```
┌──────────────────────────────────────────────────────────────────┐
│   ÉTAPE 1 : Viande(s)                                            │
│   [Uniquement si l'item a des slots viandes]                     │
│   • Compteur N/N + liste avec +/− par viande                    │
│   • Badge "✅ Complet" quand quota atteint                       │
│   • "Suivant →" activé seulement si quota atteint               │
└────────────────────┬─────────────────────────────────────────────┘
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│   ÉTAPE 2 : Sauce (1ère gratuite)                                │
│   [Uniquement si l'item has_sauce]                               │
│   • Grille 3 colonnes des sauces RÉELLES (filtré par attr.sauce)│
│   • 1ère sélection = gratuite, suivantes = +€0.50/sauce          │
│   • Badge compteur "2 sauces = +€0.50"                          │
│   • Option "Sans Sauce" toujours présente                        │
└────────────────────┬─────────────────────────────────────────────┘
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│   ÉTAPE 3 : Garnitures                                           │
│   [Uniquement si l'item has_crudites]                            │
│   • Options radio : Complet / Sans Oignon / Sans Tomate /        │
│     Sans Salade / Aucune Crudité                                 │
│   • Pré-sélectionné : "Complet" par défaut                      │
│   • Étape rapide (1 clic et Suivant)                            │
└────────────────────┬─────────────────────────────────────────────┘
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│   ÉTAPE 4 : Suppléments                                          │
│   [Uniquement si extras payants existent]                        │
│   • Liste checkboxes : Cheddar +€1 / Jambon +€1 / Œuf +€1 /…   │
│   • SANS les "Sauce supplémentaire" (filtrés)                    │
│   • "Passer →" visible (étape facultative)                       │
└────────────────────┬─────────────────────────────────────────────┘
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│   ÉTAPE 5 : Formule Repas ?                                      │
│   [Uniquement si addons existent]                                │
│   3 choix à GRANDE CARTE :                                       │
│                                                                  │
│   [🍟🥤 Menu Complet   ]  [🍟 Frites seules]  [🚫 Rien]         │
│   [     +€3.00        ]  [    +€1.50        ]  [               ] │
│                                                                  │
│   → Si Menu Complet ou Frites seules : ALLER ÉTAPE 6            │
│   → Si Rien : ALLER ÉTAPE 9 (Boisson seule proposée)           │
└────────────────────┬─────────────────────────────────────────────┘
          ┌──────────┘
          ▼
┌─────────────────────────────────────────────────────────────────┐
│   ÉTAPE 6 : Grande Frite ? (si Menu ou Frites choisi)           │
│   [Upsell taille + supplément]                                   │
│                                                                  │
│   [🍟 Frites Normales]   [🍟🍟 Grande Frites +€1.00]            │
│   [    incluses     ]   [          Upgrade         ]            │
│                                                                  │
│   + Option Cheddar Fondu sur frites ? [Oui +€1.00] [Non]      │
└────────────────────┬────────────────────────────────────────────┘
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│   ÉTAPE 7 : Sauce pour les frites (1ère gratuite)               │
│   [Si frites sélectionnées]                                      │
│   • Même grille que sauces classiques                            │
│   • 1ère sauce = gratuite, extras = +€0.50                       │
│   • "Sans sauce frites" option                                  │
└────────────────────┬─────────────────────────────────────────────┘
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│   ÉTAPE 8 : Choix Boisson                                        │
│   [Si Menu Complet choisi à l'étape 5]                           │
│   Grille 2-3 colonnes des boissons disponibles :                 │
│   [🥤 Coca-Cola]  [🥤 Fanta]  [🥤 Sprite]  [💧 Eau]  [🚫 Sans] │
│   + Taille si applicable (33cl / 50cl)                          │
└────────────────────┬─────────────────────────────────────────────┘
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│   ÉTAPE 9 : Boisson Seule ? (si "Rien" choisi à étape 5)        │
│   [Upsell final boisson]                                         │
│                                                                  │
│   [🥤 Oui, une boisson   ]   [🚫 Non merci]                     │
│   [       +€1.50          ]   [             ]                    │
│                                                                  │
│   → Si Oui : Afficher grille boissons (comme étape 8)           │
│   → Si Non : ALLER directement Récap                            │
└────────────────────┬─────────────────────────────────────────────┘
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│   ÉTAPE 10 : Dessert ? (Upsell fin de parcours)                 │
│   [Si aucun dessert dans cette commande]                         │
│                                                                  │
│   "🍰 Et pour finir ?"                                           │
│   Grille desserts (filtrés depuis DB) :                         │
│   [Tiramisu +€X] [Tarte +€X] [Glace +€X] [Non merci →]        │
│                                                                  │
│   → Si skippé : ALLER Récap directement                         │
└────────────────────┬─────────────────────────────────────────────┘
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│   ÉTAPE 11 : Récapitulatif + Validation                          │
│   • Nom item + image                                             │
│   • Quantité (modifiable)                                        │
│   • Toutes les sélections avec ✏️ bouton retour par section      │
│   • Total final en gras                                          │
│   • Instructions spéciales (textarea)                            │
│   • CTA "Ajouter au Panier →" (rose/rouge, pleine largeur)     │
└──────────────────────────────────────────────────────────────────┘
```

---

## 🔧 PARTIE 3 — PLAN D'IMPLÉMENTATION KIMI (Fichier unique : pos-wizard.js)

### TÂCHE P0-A : Corriger BUG-POS-001 — Filtrage sauce (5 lignes)

**Localisation :** `pos-wizard.js` ligne 280, dans `buildSteps()`, bloc `data.itemAttributes.forEach()`

```javascript
// AVANT (ligne 280) :
data.itemAttributes.forEach(function (attr) {
    var attrId = attr.id.toString();

// APRÈS :
data.itemAttributes.forEach(function (attr) {
    // [FIX BUG-POS-001] : Ne prendre QUE les attributs de type SAUCE
    var attrName = (attr.name || '').toLowerCase();
    var isSauceAttr = attrName.includes('sauce') || attrName.includes('assaisonnement');
    if (!isSauceAttr) return;   // ← AJOUTER CETTE LIGNE
    var attrId = attr.id.toString();
```

---

### TÂCHE P0-B : Corriger BUG-POS-002 — Prix menu €3 (2 lignes)

**Localisation :** `pos-wizard.js` ligne 342, dans `buildSteps()`, bloc `addonItems`

```javascript
// AVANT :
price: parseFloat(ad.total_convert_price) || 0,
currencyPrice: ad.total_currency_price || fmtPrice(ad.total_convert_price),

// APRÈS :
price: parseFloat(ad.addon_item_convert_price)
     || parseFloat(ad.addonItem && ad.addonItem.convert_price || 0)
     || parseFloat(ad.total_convert_price) || 0,
currencyPrice: '€' + (parseFloat(ad.addon_item_convert_price)
     || parseFloat(ad.total_convert_price) || 0).toFixed(2),
```

---

### TÂCHE P0-C : Corriger BUG-POS-005 — Filtrer sauces supplémentaires des extras

**Localisation :** `pos-wizard.js` ligne 317, dans `buildSteps()`, bloc `data.extras.forEach()`

```javascript
// AVANT :
data.extras.forEach(function (ex) {
    var price = parseFloat(ex.convert_price) || 0;

// APRÈS :
data.extras.forEach(function (ex) {
    // [FIX BUG-POS-005] : Exclure les "Sauce supplémentaire" des extras
    var exName = (ex.name || '').toLowerCase();
    if (exName.includes('sauce suppl') || exName.startsWith('sauce suppl')) return;
    var price = parseFloat(ex.convert_price) || 0;
```

---

### TÂCHE P0-D : Corriger BUG-POS-004 — Suppléments non calculés dans le total

**Localisation :** Fonction `calculateRunningTotal()` (vers ligne 1800+)

```javascript
// AJOUTER après le calcul des sauces extra :
// Suppléments payants sélectionnés
if (selections.supplements) {
    Object.keys(selections.supplements).forEach(function(supId) {
        if (!selections.supplements[supId]) return;
        // Chercher dans toutes les étapes le supplément avec cet ID
        for (var si = 0; si < steps.length; si++) {
            var step = steps[si];
            var allPaidItems = (step.paidItems || []).concat(step.items || []);
            for (var pi = 0; pi < allPaidItems.length; pi++) {
                if (String(allPaidItems[pi].id) === String(supId)) {
                    total += (allPaidItems[pi].price || 0) * itemQuantity;
                    break;
                }
            }
        }
    });
}
```

---

### TÂCHE P1-A : Nouveau flux Menu avec étapes adaptatives

**Remplacer le step `menu` (ligne 495-508) par la logique suivante :**

```javascript
// NOUVEAU STEP : menu_choice — 3 options claires
if (allowed.indexOf('menu') !== -1 && addonItems.length > 0) {
    // Séparer menu complet, frites seules, boisson seule
    var menuComplet  = addonItems.find(function(a) { return a.name.toLowerCase().includes('menu') || (a.name.toLowerCase().includes('frite') && a.name.toLowerCase().includes('boisson')); });
    var fritesSeules = addonItems.find(function(a) { return a.name.toLowerCase().includes('frite') && !a.name.toLowerCase().includes('boisson') && !a.name.toLowerCase().includes('menu'); });
    var boissonSeule = addonItems.find(function(a) { return (a.name.toLowerCase().includes('boisson') || a.name.toLowerCase().includes('coca') || a.name.toLowerCase().includes('jus')) && !a.name.toLowerCase().includes('frite'); });

    s.push({
        type: 'menu_choice',
        label: 'Formule',
        subtitle: 'Voulez-vous accompagner votre repas ?',
        menuComplet:  menuComplet  || { name: 'Menu Complet (Frites+Boisson)', price: 3.00 },
        fritesSeules: fritesSeules || { name: 'Frites Seules', price: 1.50 },
        boissonSeule: boissonSeule || { name: 'Boisson Seule', price: 1.50 },
        sauceItems: sauceList.filter(function(s) { return s.name.toLowerCase() !== 'sans sauce'; })
    });
    selections.menuChoice = null;         // null | 'full' | 'frites' | 'boisson' | 'none'
    selections.frites_grande = false;
    selections.frites_cheddar = false;
    selections.sauceFrites = {};
    selections.sauceFritesOrder = [];
    selections.boisson = null;
}

// NOUVEAU STEP : frites_options (visible si frites choisies)
if (allowed.indexOf('menu') !== -1) {
    s.push({
        type: 'frites_options',
        label: 'Options Frites',
        subtitle: 'Personnalisez vos frites',
        showCondition: 'hasFrites'   // étape conditionnelle
    });
}

// NOUVEAU STEP : sauce_frites (visible si frites choisies)
if (allowed.indexOf('menu') !== -1) {
    s.push({
        type: 'sauce_frites',
        label: 'Sauce Frites',
        subtitle: '1 sauce gratuite pour vos frites',
        items: sauceList,
        showCondition: 'hasFrites'
    });
}

// NOUVEAU STEP : boisson_choice (visible si menu complet ou boisson seule)
if (allowed.indexOf('menu') !== -1) {
    s.push({
        type: 'boisson_choice',
        label: 'Boisson',
        subtitle: 'Choisissez votre boisson',
        showCondition: 'hasBoisson'
    });
}
```

---

### TÂCHE P1-B : Nouveau renderer `renderMenuChoiceStep()`

```javascript
function renderMenuChoiceStep(step) {
    var h = '<div class="wizard-menu-choice-grid">';
    
    // Carte Menu Complet
    var selFull = selections.menuChoice === 'full' ? ' selected' : '';
    h += '<div class="menu-card' + selFull + '" data-action="menu-choice" data-value="full">';
    h += '<div class="menu-card-icon">🍟🥤</div>';
    h += '<div class="menu-card-name">' + step.menuComplet.name + '</div>';
    h += '<div class="menu-card-price">+€' + step.menuComplet.price.toFixed(2) + '</div>';
    h += '</div>';
    
    // Carte Frites seules
    var selFrites = selections.menuChoice === 'frites' ? ' selected' : '';
    h += '<div class="menu-card' + selFrites + '" data-action="menu-choice" data-value="frites">';
    h += '<div class="menu-card-icon">🍟</div>';
    h += '<div class="menu-card-name">Frites Seules</div>';
    h += '<div class="menu-card-price">+€' + step.fritesSeules.price.toFixed(2) + '</div>';
    h += '</div>';
    
    // Carte Rien
    var selNone = selections.menuChoice === 'none' ? ' selected' : '';
    h += '<div class="menu-card' + selNone + '" data-action="menu-choice" data-value="none">';
    h += '<div class="menu-card-icon">🚫</div>';
    h += '<div class="menu-card-name">Non merci</div>';
    h += '<div class="menu-card-sub">Commander sans formule</div>';
    h += '</div>';
    
    h += '</div>';
    return h;
}
```

---

### TÂCHE P1-C : Nouveau renderer `renderFritesOptionsStep()`

```javascript
function renderFritesOptionsStep(step) {
    var h = '<div class="wizard-frites-options">';
    
    // Taille frites
    h += '<h4>🍟 Taille des frites</h4>';
    h += '<div class="frites-size-row">';
    h += '<div class="frites-option' + (!selections.frites_grande ? ' selected' : '') + '" data-action="frites-size" data-value="normal">';
    h += '<span>🍟 Portion Normale</span><span>Incluse</span></div>';
    h += '<div class="frites-option' + (selections.frites_grande ? ' selected' : '') + '" data-action="frites-size" data-value="grande">';
    h += '<span>🍟🍟 Grande Portion</span><span class="upgrade-price">+€1.00</span></div>';
    h += '</div>';
    
    // Cheddar
    h += '<h4 style="margin-top:20px">🧀 Supplément Cheddar</h4>';
    h += '<div class="frites-size-row">';
    h += '<div class="frites-option' + (!selections.frites_cheddar ? ' selected' : '') + '" data-action="frites-cheddar" data-value="no">';
    h += '<span>Sans Cheddar</span><span>Inclus</span></div>';
    h += '<div class="frites-option' + (selections.frites_cheddar ? ' selected' : '') + '" data-action="frites-cheddar" data-value="yes">';
    h += '<span>🧀 Avec Cheddar Fondu</span><span class="upgrade-price">+€1.00</span></div>';
    h += '</div>';
    
    h += '</div>';
    return h;
}
```

---

## ✅ PARTIE 4 — RÉSUMÉ EXÉCUTIF POUR KIMI

| ID | Fichier | Type | Fix | Lignes |
|----|---------|------|-----|--------|
| BUG-POS-001 | pos-wizard.js | 🔴 P0 | Filtre sauce attr par nom | +3 lignes L280 |
| BUG-POS-002 | pos-wizard.js | 🔴 P0 | Prix addon = unit pas total | ~L342 |
| BUG-POS-003 | pos-wizard.js | 🟡 P1 | Garnitures hors page 1 | Étape séparée |
| BUG-POS-004 | pos-wizard.js | 🔴 P0 | Suppléments dans calcul total | ~L1800 |
| BUG-POS-005 | pos-wizard.js | 🔴 P0 | Filtrer sauces des extras | +3 lignes L317 |
| FLUX-MENU | pos-wizard.js | 🟡 P1 | 3 choix + frites options + boisson | 4 nouveaux renderers |

**Priorité d'exécution :**
1. BUG-POS-001 + BUG-POS-002 + BUG-POS-004 + BUG-POS-005 → en 1 seule passe sur `buildSteps()` et `calculateRunningTotal()`
2. FLUX-MENU → rewrite du step `menu` dans `getAllowedSteps()` + 4 renderers
3. `npm run prod` après chaque passe pour voir en direct

---

*Audit Claude — Caisse POS — Ne pas modifier `syncAndSubmit()`*
