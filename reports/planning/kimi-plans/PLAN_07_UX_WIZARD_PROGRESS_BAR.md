# PLAN_07 — UX-03 : Barre de Progression Wizard POS
**Phase :** P2 — Moyenne
**Test-Type :** Playwright / E2E verification (test visuel navigateur)
**Impact :** 🟡 UX — Caissier ne sait pas à quelle étape il se trouve
**Fichiers :**
- `public/js/pos-wizard.js`
- `public/css/pos-wizard.css`

---

## 1. Contexte & Problème

**Benchmark :** GUR KEBAB et McDo affichent "Étape 3/7" avec icônes numérotées.
FoodKing a un `wizard-progress-bar` visuel (icônes + step-line), mais **pas de texte
"Étape X sur Y"** visible pour le caissier, surtout sur écran limité.

**État actuel :** La barre d'icônes existe dans `renderWizard()` mais aucun indicateur
textuel clair du numéro d'étape.

**Objectif :** Ajouter un badge compact "Étape 2/4" dans l'en-tête du wizard.

---

## 2. Fichiers à Modifier

| Fichier | Zone | Action |
|---------|------|--------|
| `public/js/pos-wizard.js` | `renderWizard()` | Ajouter badge étape dans le header |
| `public/css/pos-wizard.css` | `.wizard-step-badge` | Style du badge |

---

## 3. Implémentation

### 3.1 pos-wizard.js — Ajouter le badge dans renderWizard()

Localiser la fonction `renderWizard()` (ligne ~641).
Dans la section `wizard-item-header`, ajouter le badge d'étape :

```javascript
function renderWizard() {
    var html = '<div class="pos-wizard">';
    
    var activeSteps = getActiveSteps();
    var activeIdx   = getActiveStepIndex();
    var totalSteps  = activeSteps.length;
    var currentNum  = activeIdx + 1;
    var step        = steps[currentStep];
    
    // [UX] Ne pas afficher le badge sur la page Récap (dernière étape)
    var isRecap = step.type === 'recap';

    // === HEADER ITEM ===
    if (lastItemData) {
        html += '<div class="wizard-item-header">';
        if (lastItemData.thumb) {
            html += '<img src="' + lastItemData.thumb + '" alt="item" class="wizard-item-img">';
        }
        html += '<div class="wizard-item-info">';
        html += '<h2>' + lastItemData.name + '</h2>';
        html += '<p class="wizard-item-price">' + fmtPrice(basePrice) + '</p>';
        html += '</div>';
        
        // [NEW UX-03] Badge étape — affiché sauf sur le récap
        if (!isRecap) {
            html += '<div class="wizard-step-badge">';
            html += '<span class="step-badge-current">' + currentNum + '</span>';
            html += '<span class="step-badge-sep">/</span>';
            html += '<span class="step-badge-total">' + (totalSteps - 1) + '</span>';
            // totalSteps - 1 exclut le récap du compte visible
            html += '</div>';
        }
        html += '</div>'; // wizard-item-header
    }
    
    // ... reste de la fonction inchangé
```

> **Note :** Si l'étape récap est incluse dans `activeSteps`, `totalSteps - 1` montre
> le bon count (ex: "Étape 1/3" pour 4 étapes dont le récap).

### 3.2 pos-wizard.css — Style du badge

Ajouter à la fin de `pos-wizard.css` (ou créer une section spécifique) :

```css
/* ── Wizard Step Badge (UX-03) ── */
.wizard-step-badge {
    display: flex;
    align-items: center;
    gap: 2px;
    background: rgba(233, 60, 60, 0.09);
    border: 1.5px solid rgba(233, 60, 60, 0.25);
    border-radius: 20px;
    padding: 3px 10px;
    font-family: 'Rubik', sans-serif;
    margin-left: auto;
    flex-shrink: 0;
}

.step-badge-current {
    font-size: 15px;
    font-weight: 800;
    color: #E93C3C;
}

.step-badge-sep {
    font-size: 11px;
    color: #C0C0C0;
    margin: 0 2px;
}

.step-badge-total {
    font-size: 13px;
    font-weight: 600;
    color: #6E6E8A;
}
```

---

## 4. Résultat Attendu (Visuel)

```
┌─────────────────────────────────────┐
│ [IMG] Tacos L           [  2 / 3  ] │  ← badge
│       8.50€                         │
└─────────────────────────────────────┘
```

- Étape 1/3 sur "Viande & Sauce"
- Étape 2/3 sur "Personnalisation"  
- Étape 3/3 sur "Formule"
- Pas de badge sur le Récap

---

## 5. Tests Playwright / E2E verification

**Scénario navigateur :**
1. POS → cliquer sur "Tacos L"
2. Observer l'en-tête du wizard → doit afficher `1/3` (ou le nombre d'étapes actives)
3. Cliquer "Suivant" → badge passe à `2/3`
4. Cliquer "Suivant" → badge passe à `3/3`
5. Récap → badge disparaît

---

## 6. Critères de Succès

- [ ] Badge visible "1/3" dès l'ouverture du wizard
- [ ] Badge se met à jour à chaque étape (goToNextActiveStep)
- [ ] Badge absent sur l'étape Récap
- [ ] Le compte exclut l'étape Récap (ex: 3 questions + récap = badge "X/3")
- [ ] Compile `npm run dev` → 0 erreur

---

## 7. NE PAS Toucher

- La logique `getActiveSteps()` et `getActiveStepIndex()` — ne pas modifier
- La barre de progression existante (step-item, step-line) — elle reste
- Les animations CSS existantes
