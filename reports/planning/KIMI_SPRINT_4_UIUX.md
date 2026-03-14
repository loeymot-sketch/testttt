# SPRINT 4 — UI/UX OVERHAUL (Photos, Icônes, Flow Sandwich)
**Date :** 12 Mars 2026  
**Agent Cible :** KIMI (Builder)  
**Priorité :** 🟡 P1 — Vitesse de prise de commande et clarté visuelle

---

## 🎯 Objectifs
1. **Intégration d'images omniprésentes :** Les photos des plats doivent être visibles dans la liste des items et **dans le panier**.
2. **Gain de place (Wizard) :** Utiliser des icônes/emojis pour les Sauces et Garnitures (au lieu de grosses images) et rendre les pastilles de couleur/icônes très petites.
3. **Nouveau Flow Sandwich :**
   - Étape 1 : Choix entre "Pain" et "Galette".
   - Étape 2 : Viandes et Sauces principales visibles.
   - Bouton "Voir plus" (`▼ Voir toutes les sauces`) pour afficher les options secondaires et purifier l'interface de base.

---

## 🔧 FIX-UI-01 : Photos dans le Panier POS

**Fichier :** `resources/js/components/admin/pos/PosComponent.vue`

### Action
Actuellement, le tableau de panier (ligne 196+) n'affiche que le nom. Nous allons ajouter l'encart d'image.

**Code ciblé (`<tbody>` L196) :**
```html
<tr v-for="(cart, index) in carts">
    <!-- ... -->
    <td class="pl-3 py-3 last:pr-3 align-top border-b border-[#EFF0F6]">
        <!-- NOUVEAU : Image Thumbnail -->
        <div class="flex gap-2">
            <img v-if="cart.image" :src="cart.image" class="w-10 h-10 rounded-md object-cover flex-shrink-0" />
            <div>
                <h3 class="capitalize text-xs font-rubik text-[#2E2F38]">{{ cart.name }}</h3>
                <p v-if="Object.keys(cart.item_variations.variations).length !== 0">...
```

---

## 🔧 FIX-UI-02 : Icônes Minimalistes pour Garnitures et Sauces

**Fichier :** `public/js/pos-wizard.js` et `public/pos-wizard.css`

### Action
Les options (`option-img`) prennent trop de place. Nous devons utiliser un rendu "Micro" pour les sauces/légumes.

**`pos-wizard.js` (L107 `renderOptionIcon`) :**
```javascript
function renderOptionIcon(thumb, emoji, isMicro = false) {
    if (isMicro) {
        // Mode ultra-compact (icône seule ou petite vignette)
        if (thumb) return '<img src="' + thumb + '" alt="" class="option-img-micro" />';
        return '<span class="option-icon-micro">' + (emoji || '🥄') + '</span>';
    }
    // Mode standard (Plats principaux)
    if (thumb) return '<img src="' + thumb + '" alt="" class="option-img" />';
    return '<span class="option-icon">' + (emoji || '') + '</span>';
}
```

**`pos-wizard.css` (À ajouter) :**
```css
.option-img-micro { width: 20px; h: 20px; border-radius: 50%; object-fit: cover; }
.option-icon-micro { font-size: 16px; line-height: 1; }
.wizard-option.micro-opt { padding: 4px 8px; font-size: 11px; min-height: 30px; }
```

---

## 🔧 FIX-UI-03 : Nouveau Flow Sandwich ("Pain" vs "Galette")

**Base de données (`MenuSeeder.php`) :**
1. Créer un nouvel Attribut d'Item : `Type de Pain`.
2. Lier cet attribut aux produits de catégorie "Sandwichs".
3. Variations : `Pain` (Prix +0€) et `Galette` (Prix +0€).

**Application (`pos-wizard.js`) :**
Détecter que l'attribut est de type "Pain" et le forcer à s'afficher en premier (avant viande/sauce).

---

## 🔧 FIX-UI-04 : Collapse "Voir Plus" pour les Options Secondaires

**Fichier :** `public/js/pos-wizard.js`

### Action
Pour les sandwichs qui ont beaucoup de viandes/sauces, ne montrer que le "Top 4" des viandes et "Top 6" des sauces par défaut.

**Logique de Rendu (`renderSauceStep` etc.) :**
```javascript
var limit = 6; // Afficher max 6 options
var h = '<div class="wizard-options sauce-grid compact">';
step.sauceItems.forEach(function (sauce, index) {
    var isHiddenClass = index >= limit ? ' hidden-opt hidden' : '';
    h += '<div class="wizard-option sauce-opt micro-opt' + sel + isHiddenClass + '">';
    // ...
});
h += '</div>';

// Si array > limit, ajouter le bouton Voir Plus
if (step.sauceItems.length > limit) {
    h += '<button type="button" class="btn-voir-plus" onclick="$(this).prev(\'.wizard-options\').find(\'.hidden-opt\').toggleClass(\'hidden\'); $(this).text($(this).text() == \'▼ Voir tous\' ? \'▲ Masquer\' : \'▼ Voir tous\')">▼ Voir tous</button>';
}
```

---

## ✅ Protocole d'Audit & Tests
- Exécuter la seed (`php artisan db:seed --class=MenuSeeder`) pour vérifier l'injection du "Pain/Galette".
- Lancer le POS POS : `customer_id=1`, `token=test`.
- Sélectionner un Sandwich et vérifier que :
  1. La première question est le "Pain".
  2. Les garnitures/sauces sont en mode "Micro" (gain de place +40%).
  3. Le bouton "▼ Voir tous" masque bien les excédents.
  4. La photo s'affiche bien dans la sidebar du panier à droite. 
