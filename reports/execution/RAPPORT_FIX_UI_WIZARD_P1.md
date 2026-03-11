# RAPPORT DE CORRECTION — UI WIZARD P1 (Menu + Frites + Boisson)

**Date:** 11 Mars 2026 | 17h30  
**Agent:** Kimi (Builder)  
**Problème:** UX non claire sur les étapes Formule et Options Frites  
**Source:** Captures utilisateur montrant manque de feedback visuel

---

## 🔴 PROBLÈMES IDENTIFIÉS

### Capture 1 — Étape "Formule"
- ❌ Aucune option visuellement sélectionnée par défaut
- ❌ Pas de feedback visuel clair (bordure/background) sur la sélection
- ❌ L'utilisateur ne sait pas ce qui est choisi

### Capture 2 — Étape "Options Frites"  
- ❌ Les options par défaut (Normale / Sans Cheddar) non visuellement marquées
- ❌ Pas de style "selected" distinct
- ❌ Difficile de comprendre l'état actuel

---

## ✅ CORRECTIONS APPLIQUÉES

### 1. Valeurs par défaut (JS)

**Fichier:** `public/js/pos-wizard.js`

```javascript
// L579-584 — Avant:
selections.menuChoice = null;  // Aucune sélection

// Après:
selections.menuChoice = 'none';  // Par défaut: "Non merci" sélectionné
selections.fritesGrande = false;  // Portion normale par défaut
selections.fritesCheddar = false;  // Sans cheddar par défaut
```

---

### 2. Styles CSS visuels (P1)

**Fichier:** `public/css/pos-wizard.css`

#### Menu Choice Cards (3 options)
```css
.menu-choice-card {
    border: 2px solid #E5E5F0;
    border-radius: 16px;
    padding: 20px 12px;
    text-align: center;
    cursor: pointer;
    transition: all 0.25s ease;
}

.menu-choice-card.selected {
    border-color: #E93C3C;
    background: linear-gradient(135deg, #FFF0F0, #FFE8EC);
    box-shadow: 0 4px 15px rgba(233, 60, 60, 0.15);
}
```

#### Frites Options
```css
.frites-option {
    border: 2px solid #E5E5F0;
    border-radius: 12px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.25s ease;
}

.frites-option.selected {
    border-color: #E93C3C;
    background: linear-gradient(135deg, #FFF0F0, #FFE8EC);
    box-shadow: 0 4px 12px rgba(233, 60, 60, 0.12);
}
```

#### Boisson Choice
```css
.wizard-option.boisson-opt.selected {
    border-color: #E93C3C;
    background: linear-gradient(135deg, #FFF0F0, #FFE8EC);
    box-shadow: 0 4px 12px rgba(233, 60, 60, 0.12);
}
```

---

### 3. Mise à jour UI dynamique (JS)

**Fichier:** `public/js/pos-wizard.js` (updateWizardUI)

```javascript
// [P1] Update menu choice cards (3 options: full/frites/none)
wizardEl.querySelectorAll('.menu-choice-card').forEach(function (card) {
    var value = card.getAttribute('data-value');
    var isSelected = (selections.menuChoice === value);
    if (isSelected) card.classList.add('selected');
    else card.classList.remove('selected');
});

// [P1] Update frites options
wizardEl.querySelectorAll('.frites-option').forEach(function (opt) {
    var action = opt.getAttribute('data-action');
    var value = opt.getAttribute('data-value');
    var isSelected = false;
    if (action === 'frites-size') {
        isSelected = (value === 'grande') ? selections.fritesGrande : !selections.fritesGrande;
    } else if (action === 'frites-cheddar') {
        isSelected = (value === 'yes') ? selections.fritesCheddar : !selections.fritesCheddar;
    }
    if (isSelected) opt.classList.add('selected');
    else opt.classList.remove('selected');
});

// [P1] Update boisson choice options
wizardEl.querySelectorAll('.boisson-opt').forEach(function (opt) {
    var value = opt.getAttribute('data-value');
    var id = parseInt(opt.getAttribute('data-id'));
    var isSelected = false;
    if (value === 'none') {
        isSelected = (selections.boissonChoice === 'none');
    } else if (id) {
        isSelected = (selections.boissonChoice === id);
    }
    if (isSelected) opt.classList.add('selected');
    else opt.classList.remove('selected');
});
```

---

## 📊 BUILD

```bash
$ npx mix --production

✔ Compiled Successfully in 19263ms
┌───────────────────────────────────┬──────────┐
│                              File │ Size     │
├───────────────────────────────────┼──────────┤
│                        /js/app.js │ 3.9 MiB  │
│            /js/app.js.LICENSE.txt │ 5.01 KiB │
│                       css/app.css │ 128 KiB  │
└───────────────────────────────────┴──────────┘
```

✅ **Build réussi**

---

## 🎯 RÉSULTAT ATTENDU

### Étape "Formule" (Menu Choice)
```
┌─────────────────────────────────────────────────┐
│  🍟🥤         🍟           🚫                  │
│  En Menu     Frites       Non merci            │
│  +€3.00     +€1.50       —                    │
│                                                 │
│  [SÉLECTIONNÉ par défaut: bordure rouge]       │
└─────────────────────────────────────────────────┘
```

### Étape "Options Frites"
```
┌─────────────────────────────────────────────────┐
│ 🍟 Taille des frites                            │
│ ┌──────────────┐ ┌──────────────┐              │
│ │🍟 Normale    │ │🍟🍟 Grande   │              │
│ │  [SÉLECTIONNÉ]│ │  +€1.00      │              │
│ └──────────────┘ └──────────────┘              │
│                                                 │
│ 🧀 Supplément Cheddar                           │
│ ┌──────────────┐ ┌──────────────┐              │
│ │ Sans Cheddar│ │🧀 Avec       │              │
│ │  [SÉLECTIONNÉ]│ │  Cheddar     │              │
│ └──────────────┘ └──────────────┘              │
└─────────────────────────────────────────────────┘
```

**Effet visuel:**
- Bordure rouge (`#E93C3C`) sur l'option sélectionnée
- Background dégradé rose clair
- Ombre portée subtile
- Transition douce (0.25s)

---

## ✅ CHECKLIST VALIDATION

- [x] `menuChoice = 'none'` par défaut
- [x] `fritesGrande = false` par défaut  
- [x] `fritesCheddar = false` par défaut
- [x] Styles `.selected` pour menu-choice-card
- [x] Styles `.selected` pour frites-option
- [x] Styles `.selected` pour boisson-opt
- [x] Mise à jour UI dans `updateWizardUI()`
- [x] Build réussi sans erreurs

---

## 🧪 TEST MANUEL SUGGÉRÉ

1. **Ouvrir le wizard** → Tacos L
2. **Étape 1-2** → Choisir viandes et sauce
3. **Étape "Formule"** → Vérifier:
   - "Non merci" est visuellement sélectionné (rouge)
   - Cliquer sur "En Menu" → devient rouge
   - Cliquer sur "Frites Seules" → devient rouge
4. **Étape "Options Frites"** (si menu/frites choisi) → Vérifier:
   - "Portion Normale" sélectionné (rouge)
   - "Sans Cheddar" sélectionné (rouge)
   - Cliquer sur "Grande Portion" → devient rouge (+€1.00)
   - Cliquer sur "Cheddar" → devient rouge (+€1.00)
5. **Total** → Doit refléter les choix

---

*Rapport Kimi — Fix UI Wizard P1 — Build OK*
