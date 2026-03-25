# Plan — Affichage panier + restauration édition produit

**Date :** 2026-03-10  
**Priorité :** P0  
**Scope :** POS cart display + ItemComponent edit flow

---

## Problème 1 : Ordre d'affichage dans le panier (extras avant menu)

### Diagnostic

Actuellement, le template `PosComponent.vue` affiche :

```
Nom produit
Variations (pain, viande, etc.)
Extras (sauces, suppléments, garnitures)  ← AVANT
Instruction
+ Menu (Frites + Boisson) (+3.00€)        ← APRÈS
```

**Demande utilisateur :** Les extras **liés aux frites du menu** (sauce frites, grande portion, cheddar) doivent apparaître **sous** le menu, pas au-dessus. Sinon, confusion entre les extras du sandwich et les extras du menu.

### Solution

Séparer les extras en **deux catégories** :

1. **Extras produit principal** : sauces sandwich, garnitures, suppléments viande, etc.
2. **Extras menu** : sauce frites, grande portion, cheddar, etc. (identifiés par nom ou par un flag futur)

**Ordre d'affichage cible :**

```
Nom produit
Variations
Extras produit principal (sauces sandwich, garnitures)
Instruction
+ Menu (Frites + Boisson) (+3.00€)
  ↳ Extras menu (sauce frites, grande portion, cheddar)  ← SOUS le menu
```

### Implémentation

**Fichier :** `resources/js/components/admin/pos/PosComponent.vue`

**Changements template :**

1. Avant `<ul v-if="cart.pos_line_addons...">`, afficher uniquement les extras **non liés au menu**.
2. Après chaque ligne `+ Menu (...)`, afficher les extras **liés au menu** (identifiés par nom : `"frites"`, `"cheddar"`, `"grande"`, etc.).

**Méthode helper :**

```javascript
isMenuRelatedExtra(extraName) {
    const n = (extraName || '').toLowerCase();
    return n.includes('frites') || n.includes('cheddar') || n.includes('grande portion') || n.includes('sauce frites');
}
```

**Template modifié :**

```vue
<!-- Extras produit principal (hors menu) -->
<ul v-if="cart.item_extras.extras.length > 0">
    <li class="leading-4">
        <span class="capitalize text-xs leading-4 font-rubik text-heading">
            {{ $t('label.extras') }}:
        </span>
        <p class="capitalize text-xs leading-4 font-rubik">
            <span v-for="(extra, index) in cart.item_extras.names.filter(e => !isMenuRelatedExtra(e))">
                {{ extra }}
                <span v-if="index + 1 < cart.item_extras.names.filter(e => !isMenuRelatedExtra(e)).length">, &nbsp;</span>
            </span>
        </p>
    </li>
</ul>

<!-- Instruction -->
<li v-if="cart.instruction !== ''" class="leading-4">...</li>

<!-- Menu bundled -->
<ul v-if="cart.pos_line_addons && cart.pos_line_addons.length > 0" class="mt-1.5 space-y-0.5">
    <li v-for="(bundled, bi) in cart.pos_line_addons" :key="'b-' + index + '-' + bi"
        class="text-[11px] font-semibold font-rubik text-[#1AB759] leading-snug">
        + {{ bundled.name }}
        <span v-if="bundledLineUnitTotal(bundled) > 0" class="font-rubik text-[#1AB759]">
            (+{{ currencyFormat(...) }})
        </span>
        
        <!-- Extras menu (sous le menu) -->
        <ul v-if="cart.item_extras.names.filter(e => isMenuRelatedExtra(e)).length > 0" class="ml-4 mt-0.5">
            <li class="text-[10px] text-[#8E8EA9]">
                <span v-for="(extra, ei) in cart.item_extras.names.filter(e => isMenuRelatedExtra(e))">
                    ↳ {{ extra }}
                    <span v-if="ei + 1 < cart.item_extras.names.filter(e => isMenuRelatedExtra(e)).length">, </span>
                </span>
            </li>
        </ul>
    </li>
</ul>
```

---

## Problème 2 : Édition produit panier — sélections wizard non restaurées

### Diagnostic

Quand l'utilisateur clique sur "Modifier" (icône crayon) :

1. `PosComponent.editCartLine(index)` → `ItemComponent.openEditFromCart(line, index)`
2. `ItemComponent` restaure :
   - ✅ `temp` (variations, extras, instruction, quantity)
   - ✅ `addons` (menu bundled)
   - ❌ **PAS** les sélections wizard (`selections.viandes`, `selections.sauces`, `selections.menuChoice`, etc.)

**Résultat :** Le modal Vue s'ouvre avec les bonnes données, mais **si l'utilisateur clique sur le bouton wizard**, le wizard s'ouvre **vide** (comme une nouvelle commande).

### Solution

**Pont inverse** : quand `openEditFromCart` est appelé, **reconstruire** `selections` à partir de `cartLine` (variations, extras, addons, instruction) et les **stocker** dans un attribut `data-wizard-restore-selections` sur la modale.

Quand `openWizard` est appelé, **lire** cet attribut et **pré-remplir** `selections` avant de construire les steps.

### Implémentation

#### Étape 1 : `ItemComponent.openEditFromCart` — construire JSON restore

**Fichier :** `resources/js/components/admin/pos/ItemComponent.vue`

**Après** avoir rempli `this.temp`, `this.addons`, etc. :

```javascript
// Construire selections pour wizard restore
const wizardRestore = {
    viandes: {},
    sauces: {},
    sauceOrder: [],
    garnitures: {},
    supplements: {},
    menuChoice: 'none',
    pain: null,
    accompagnement: null,
    instruction: cartLine.instruction || ''
};

// Variations → pain, viande, etc.
if (cartLine.item_variations.names) {
    Object.entries(cartLine.item_variations.names).forEach(([attrName, varName]) => {
        const attrLower = attrName.toLowerCase();
        if (attrLower.includes('pain') || attrLower.includes('galette')) {
            // Trouver l'ID de la variation dans item.variations
            const painAttr = item.itemAttributes.find(a => a.name.toLowerCase().includes('pain'));
            if (painAttr) {
                const painVar = item.variations[painAttr.id]?.find(v => v.name === varName);
                if (painVar) wizardRestore.pain = painVar.id;
            }
        } else if (attrLower.includes('viande') || attrLower.includes('meat')) {
            // Compter les viandes (format wizard : { 'v_123': count })
            const viandeAttr = item.itemAttributes.find(a => a.name.toLowerCase().includes('viande'));
            if (viandeAttr) {
                const viandeVar = item.variations[viandeAttr.id]?.find(v => v.name === varName);
                if (viandeVar) {
                    const key = 'v_' + viandeVar.id;
                    wizardRestore.viandes[key] = (wizardRestore.viandes[key] || 0) + 1;
                }
            }
        } else if (attrLower.includes('sauce')) {
            // Première sauce (gratuite)
            const sauceAttr = item.itemAttributes.find(a => a.name.toLowerCase().includes('sauce'));
            if (sauceAttr) {
                const sauceVar = item.variations[sauceAttr.id]?.find(v => v.name === varName);
                if (sauceVar) {
                    const key = 's_' + sauceVar.id;
                    wizardRestore.sauces[key] = true;
                    wizardRestore.sauceOrder.push(key);
                }
            }
        }
    });
}

// Extras → garnitures, suppléments, sauces extras
if (cartLine.item_extras.names) {
    cartLine.item_extras.names.forEach((extraName) => {
        const extra = item.extras?.find(e => e.name === extraName);
        if (extra) {
            const extraLower = extraName.toLowerCase();
            if (extra.convert_price <= 0 || extraLower.includes('tomate') || extraLower.includes('oignon') || extraLower.includes('salade')) {
                // Garniture gratuite
                wizardRestore.garnitures['c_' + extra.id] = true;
            } else if (extraLower.includes('sauce')) {
                // Sauce extra (payante)
                const key = 's_' + extra.id;
                wizardRestore.sauces[key] = true;
                wizardRestore.sauceOrder.push(key);
            } else {
                // Supplément payant
                wizardRestore.supplements['sup_' + extra.id] = true;
            }
        }
    });
}

// Addons → menuChoice
if (cartLine.pos_line_addons && cartLine.pos_line_addons.length > 0) {
    const firstAddon = cartLine.pos_line_addons[0];
    const addonId = firstAddon.parent_addon_id;
    wizardRestore.menuChoice = 'addon_' + addonId;
}

// Stocker sur la modale
this.$refs.itemVariationModal?.setAttribute('data-wizard-restore-selections', JSON.stringify(wizardRestore));
```

#### Étape 2 : `pos-wizard.js` — lire et restaurer dans `openWizard`

**Fichier :** `public/js/pos-wizard.js`

**Dans `openWizard()`, après `steps = buildSteps(lastItemData);` :**

```javascript
// Restaurer selections depuis édition panier
var restoreAttr = modal.getAttribute('data-wizard-restore-selections');
if (restoreAttr) {
    try {
        var restored = JSON.parse(restoreAttr);
        // Fusionner dans selections (priorité à restored)
        Object.assign(selections, restored);
        modal.removeAttribute('data-wizard-restore-selections');
    } catch (e) {
        console.warn('[Wizard] Failed to restore selections:', e);
    }
}
```

#### Étape 3 : Nettoyage

**Dans `variationModalHide` (ItemComponent) :**

```javascript
this.$refs.itemVariationModal?.removeAttribute?.('data-wizard-restore-selections');
```

---

## Risques

### Problème 1 (ordre affichage)
- **Faible** : la détection par nom (`includes('frites')`) est robuste pour les cas actuels.
- Si un extra sandwich s'appelle "Sauce Frites Maison" → sera classé comme menu. Solution : affiner le match ou ajouter un flag `is_menu_related` en base (futur).

### Problème 2 (restore wizard)
- **Moyen** : la reconstruction des `selections` dépend de la correspondance nom ↔ ID dans `item.variations` / `item.extras`.
- Si un extra a été renommé en base entre l'ajout et l'édition → pas de match → sélection perdue.
- **Mitigation :** Stocker aussi les IDs dans `cartLine` (déjà fait pour variations, à vérifier pour extras).

---

## Validation suggérée

### Test 1 (ordre affichage)
1. Ajouter sandwich + menu + sauce frites + grande portion.
2. Vérifier panier :
   ```
   Le Cayenne
   Viande: Kefta
   Sauce: Curry
   Instruction: Kefta TO Curry Menu
   + Menu (Frites + Boisson) (+3.00€)
     ↳ Sauce frites: Ketchup
     ↳ Grande Portion
   ```

### Test 2 (restore édition)
1. Ajouter sandwich + 2 viandes + sauce + menu.
2. Cliquer "Modifier" (crayon).
3. Cliquer bouton wizard → vérifier que les sélections sont pré-remplies (viandes, sauce, menu).
4. Modifier une viande → "Ajouter" → vérifier panier mis à jour.

---

## Fichiers impactés

1. `resources/js/components/admin/pos/PosComponent.vue` — template + méthode `isMenuRelatedExtra`
2. `resources/js/components/admin/pos/ItemComponent.vue` — `openEditFromCart` + nettoyage
3. `public/js/pos-wizard.js` — `openWizard` restore

---

## Délégation Kimi ?

**Problème 1 (ordre affichage) :** Oui, localisé, bien défini.

**Problème 2 (restore wizard) :** Partiellement — la logique de reconstruction des `selections` est complexe (mapping nom → ID, gestion des compteurs viandes, ordre sauces). Je recommande que **Claude** écrive la fonction `buildWizardRestorePayload()` dans `ItemComponent`, puis **Kimi** intègre l'appel + le nettoyage.

**Décision :** Je vais implémenter directement (plus rapide, cohérence avec le pont wizard déjà fait).
