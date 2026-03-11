# 🧠 PLAN D'ARCHITECTURE — POS WIZARD REFACTOR
**Version :** 1.0 — Sprint 4  
**Date :** 11 Mars 2026 | 15h15  
**Auteur :** Claude (Lead Architect)  
**Destinataire :** Kimi (Builder)  
**Fichier source :** `public/js/pos-wizard.js` (1632 lignes)  
**Config source :** `config/menu.php` (711 lignes)

---

## 📐 1. ANALYSE DE L'EXISTANT — DIAGNOSTIC COMPLET

### 1.1 Architecture actuelle (ce qui fonctionne bien — NE PAS TOUCHER)
- **Interception XHR** (lignes 109-129) : Mécanisme solide qui capture les données item depuis l'API. ✅
- **`detectCategory()`** (lignes 178-203) : Logique correcte, couvre tous les cas. ✅
- **`detectViandeCount()`** (lignes 143-159) : Parsing du nom robuste (M=1, L=2, XL=3, XXL=4). ✅
- **`syncAndSubmit()`** (lignes 1041-1193) : Le bridge vers Vue.js est fragile mais fonctionnel. ✅ Ne pas modifier.
- **`calculateRunningTotal()`** (lignes 632-670) : Calcul correct. ✅
- **Système de sauces** : Logique 1ère gratuite + extras à €0.50 correcte. ✅

### 1.2 Problèmes identifiés — FRICTION POINTS CRITIQUES

#### 🔴 PROBLÈME 1 : Trop d'étapes séquentielles pour Tacos (7 clics minimum)
**Actuel :** Viande → Sauce → Garnitures → Suppléments → Menu → Sauce Frites → Récap  
**Impact :** ~25-35 secondes par commande. Inacceptable pour caisse en heure de pointe.  
**Règle métier :** Les 3 questions centrales (viande/sauce/garnitures) sont toujours posées ensemble dans tous les restaurants rapides pro.

#### 🔴 PROBLÈME 2 : Étape `sauce_frites` toujours ajoutée, jamais visible avant Menu
**Code :** Lignes 432-441 — `sauce_frites` est ajouté aux steps dès `buildSteps()` mais filtré via `getActiveSteps()`.  
**Impact :** Le caissier ne sait pas d'avance si l'étape Sauce Frites viendra. Surprise UX.  
**Fix :** La décision Sauce Frites doit être visible dans l'étape Menu elle-même.

#### 🟡 PROBLÈME 3 : Étape Garnitures et Suppléments sont séparées sans raison
**Actuel :** 2 étapes distinctes (garnitures=free extras, supplements=paid extras).  
**Fix :** Une seule étape "Personnalisation" avec sections visuelles distinctes.

#### 🟡 PROBLÈME 4 : Pas de raccourcis clavier pour caissier rapide
**Actuel :** Navigation 100% souris/tactile.  
**Fix :** `Enter`/`→` = Suivant, `←`/`Backspace` = Retour, `1-9` = sélection dans les listes.

#### 🟡 PROBLÈME 5 : Récap non modifiable — retour impossible sans perdre les sélections
**Actuel :** `closeWizard()` remet tout à zéro. Le caissier ne peut pas corriger une erreur.  
**Fix :** Boutons "Modifier" dans le récap qui naviguent vers l'étape concernée sans reset.

#### 🟡 PROBLÈME 6 : Détection catégorie depuis DOM fragile
**Actuel :** Ligne 183-186 — Fallback sur `.db-product-filter.active` qui peut changer avec les mises à jour Vue.  
**Fix :** Lire `data.category_name` ou `data.item_category_name` depuis la réponse API en priorité absolue.

---

## 🎯 2. NOUVEAU FLUX OPTIMISÉ PAR CATÉGORIE

> **Règle d'or caisse rapide :** Max 3 étapes actives pour 90% des commandes.

### 2.1 TACOS (cas le plus complexe → modèle pour les autres)

**AVANT :** 7 étapes (Viande→Sauce→Garnitures→Suppléments→Menu→SauceFrites→Récap)  
**APRÈS :** 4 étapes maximum

```
Étape 1: VIANDE + SAUCE (combiné sur split-screen)
Étape 2: PERSONNALISATION (garnitures toggle + suppléments)
Étape 3: MENU (formule/individuel + sauce frites INLINE si frites)
Étape 4: RÉCAP (modifiable, quantité, instructions)
```

**Design Étape 1 — Split Screen Viande/Sauce :**
```
┌─────────────────────────┬──────────────────────────────────┐
│   🥩 Choisissez 2 viandes│   🥄 Sauce (1ère gratuite)       │
│   ─────────────────────  │   ──────────────────────────────  │
│   [Poulet]  [Kefta]     │   [Algérienne✓] [Samouraï]       │
│   [Merguez] [Cordon Bleu]│   [Mayo]       [Harissa]         │
│   [Viande H] [Nuggets]  │   [Blanche]    [Ketchup]         │
│   [Escalope] [Tenders]  │   [Sans sauce]                   │
│   1/2 sélectionnées     │   ✅ 1 sélectionnée (gratuite)   │
└─────────────────────────┴──────────────────────────────────┘
                    [← Retour]  [Suivant →]
```

**Design Étape 2 — Personnalisation (garnitures + suppléments) :**
```
┌────────────────────────────────────────────────────────────┐
│  🥬 GARNITURES (tout inclus — désélectionnez si refusé)    │
│  [Salade ON●] [Tomate ON●] [Oignon ON●] [Aucune ○]        │
│                                                            │
│  ➕ SUPPLÉMENTS (payants)                                   │
│  [Cheddar +€1] [Jambon +€1] [Œuf +€1] [Raclette +€1]     │
│  [Poulet +€2]  [Kebab +€2]  [V.Hachée +€2]                │
└────────────────────────────────────────────────────────────┘
```

**Design Étape 3 — Menu (avec sauce frites inline) :**
```
┌────────────────────────────────────────────────────────────┐
│  🍔 FORMULE ?                                               │
│  ┌──────────────────┐ ┌─────────────┐ ┌─────────────────┐ │
│  │ Menu Complet +€3 │ │ Frites +€1.5│ │ Boisson +€1.5   │ │
│  │ (Frites+Boisson) │ │             │ │                 │ │
│  └──────────────────┘ └─────────────┘ └─────────────────┘ │
│  ○ Aucun                                                   │
│                                                            │
│  [SI FRITES SÉLECTIONNÉES — apparaît inline ici]          │
│  🍟 Sauce pour vos frites : [Algérienne✓] [Samouraï] ... │
└────────────────────────────────────────────────────────────┘
```

### 2.2 MAPPING FINAL — Toutes catégories

| Catégorie | Étapes AVANT | Étapes APRÈS | Gain |
|-----------|-------------|--------------|------|
| **Tacos** | 7 | 4 | -3 étapes |
| **Sandwich** | 6 | 3 (Sauce+Garnitures∥Suppléments, Menu, Récap) | -3 étapes |
| **Burger** | 6 | 3 | -3 étapes |
| **Assiette** | 4 | 3 (Sauce+Accompagnement, Suppléments, Récap) | -1 étape |
| **Salade** | 3 | 2 (Sauce+Suppléments, Récap) | -1 étape |
| **Omelette** | 2 | 2 (inchangé — déjà optimal) | 0 |
| **Ojja/Snacking** | 1 | 1 (inchangé — déjà optimal) | 0 |

---

## 🔧 3. PLAN D'IMPLÉMENTATION KIMI — DÉTAIL TECHNIQUE

> **RÈGLE AGENTS.md :** Kimi n'invente rien. Kimi suit ce plan à la lettre.  
> **Scope :** UNIQUEMENT `public/js/pos-wizard.js`. Ne pas toucher le PHP.  
> **Type test :** Anti-Gravity (test visuel browser)

### TÂCHE 1 — Refactorer `getAllowedSteps()` vers nouveau flux combiné
**Scope :** Lignes 208-228. Remplacer les étapes séquentielles par les nouvelles étapes combinées.

```javascript
// NOUVEAU getAllowedSteps() :
function getAllowedSteps(category) {
    switch (category) {
        case 'tacos':
            return ['viande_sauce', 'perso', 'menu', 'recap'];
        case 'sandwich':
        case 'burger':
            return ['sauce_garnitures', 'supplements_menu', 'recap'];
        case 'assiette':
            return ['sauce_accompagnement', 'supplements', 'recap'];
        case 'salade':
            return ['sauce_supplements', 'recap'];
        case 'omelette':
            return ['sauce_single', 'recap'];
        case 'ojja':
        case 'snacking':
            return ['recap'];
        default:
            return ['sauce_garnitures', 'supplements_menu', 'recap'];
    }
}
```

### TÂCHE 2 — Créer les nouveaux renderers d'étapes combinées

**2a. `renderViandeSauceStep(step)` — Split-screen Viande+Sauce pour Tacos**
```javascript
// Layout : 2 colonnes côte à côte (flexbox)
// Colonne gauche : renderViandeStep() existant
// Colonne droite : renderSauceStep() existant
// CSS : .wizard-split { display: flex; gap: 1rem; }
// .wizard-split > div { flex: 1; }
```

**2b. `renderPersoStep(step)` — Garnitures toggles + Suppléments**
```javascript
// Section 1 : Garnitures (toggle ON/OFF avec classe .toggle-active)
// Section 2 : Suppléments (checkboxes payantes avec prix visible)
// IMPORTANT : Si aucun extra dans DB → section suppléments cachée
```

**2c. `renderSauceGarnituresStep(step)` — Pour Sandwich/Burger**
```javascript
// Même layout que viande_sauce mais :
// Colonne gauche : Sauce
// Colonne droite : Garnitures
```

**2d. `renderSupplementsMenuStep(step)` — Suppléments + Formule Sandwich/Burger**
```javascript
// Section 1 : Suppléments (payants)
// Section 2 : Menu/Formule (3 cartes : Menu, Frites seules, Boisson seule, Aucun)
// [INLINE] Si frites sélectionnées → affiche grille sauce frites DANS cette étape
//          (pas une étape séparée)
```

**2e. `renderSauceAccompagnementStep(step)` — Pour Assiettes**
```javascript
// Colonne gauche : Sauce (radio ou multi)
// Colonne droite : Accompagnement (radio : Frites/Riz/Bourgoul)
```

**2f. `renderSauceSupplemStep(step)` — Pour Salades**
```javascript
// Section 1 : Sauce (radio - 1 seule gratuite)
// Section 2 : Suppléments si disponibles
```

### TÂCHE 3 — Intégrer Sauce Frites en INLINE dans l'étape Menu
**Scope :** Modifier `renderMenuStep()` et `renderSupplementsMenuStep()`.  
**Logique :** Quand le caissier clique "Menu" ou "Frites", une section sauce se déroule IMMÉDIATEMENT dans la même étape (pas de nouvelle étape).  
```javascript
// Dans le handler click du menu :
if (menuChoice === 'full' || fritesSélectionnées) {
    document.querySelector('.sauce-frites-inline').classList.add('visible');
}
```

### TÂCHE 4 — Raccourcis clavier
**Scope :** Dans `bindEvents()` (vers ligne 1300+), ajouter :
```javascript
document.addEventListener('keydown', function(e) {
    if (!wizardEl) return;
    if (e.key === 'Enter' || e.key === 'ArrowRight') navigateNext();
    if (e.key === 'ArrowLeft' || e.key === 'Backspace') navigateBack();
    // Sélection rapide : touches 1-9 pour choisir option dans liste
    if (e.key >= '1' && e.key <= '9') {
        var opts = wizardEl.querySelectorAll('.wizard-option:not(.selected)');
        var idx = parseInt(e.key) - 1;
        if (opts[idx]) opts[idx].click();
    }
}, true);
```

### TÂCHE 5 — Navigation vers étape depuis Récap (édition sans reset)
**Scope :** Dans `renderRecapStep()`, ajouter des boutons "✏️ Modifier" par section.
```javascript
// Chaque ligne du récap aura : [Viandes ✏️] → click → currentStep = indexOf(viande_sauce)
// Puis re-render l'étape ciblée SANS reset des selections
h += '<button class="edit-step-btn" data-goto="viande_sauce">✏️</button>';

// Dans bindEvents : cliquer edit-step-btn → 
//   currentStep = steps.findIndex(s => s.type === btn.dataset.goto)
//   re-render WITHOUT resetting selections
```

### TÂCHE 6 — Robustesse détection catégorie
**Scope :** `detectCategory()` lignes 178-203.  
**Fix :** Ajouter la lecture de `data.item_category_name` avant le fallback DOM :
```javascript
function detectCategory(data) {
    // Priority 1: From API data directly
    var cat = (data.category_name || data.item_category_name || '').toLowerCase();
    
    // Priority 2: DOM fallback (existing logic)
    if (!cat) {
        var activeTab = document.querySelector(
            '.db-product-filter.active, .nav-link.active .tab-title, [class*="tab"].active'
        );
        if (activeTab) cat = (activeTab.innerText || activeTab.textContent || '').toLowerCase();
    }
    // ... rest unchanged ...
}
```

---

## 🧪 4. TESTS REQUIS (Anti-Gravity)

**Type :** `Anti-Gravity` — tests visuels browser
**Scénarios à valider par Anti-Gravity :**

| ID | Scénario | Attendu |
|----|----------|---------|
| W01 | Ouvrir wizard Tacos L → Compteur viandes 0/2 affiché | ✅ Split-screen viande+sauce |
| W02 | Sélectionner 2 viandes → Badge "✅ Complet" apparaît | ✅ Auto-validation |
| W03 | Sélectionner sauce → 1ère gratuite, 2ème +€0.50 | ✅ Prix correct |
| W04 | Avancer vers étape Perso → garnitures pré-cochées | ✅ Salade/Tomate/Oignon ON par défaut |
| W05 | Étape Menu "Frites" → section sauce frites apparaît inline | ✅ Pas de nouvelle étape |
| W06 | Appuyer Enter → passe à l'étape suivante | ✅ Raccourci clavier |
| W07 | Sur Récap → cliquer ✏️ Viandes → revient à étape 1 sans reset | ✅ Sélections conservées |
| W08 | Ajouter au panier Tacos XXL → instruction contient VIANDES + SAUCES | ✅ Données transmises au KDS |
| W09 | Sandwich wizard → 3 étapes max affiché | ✅ Flux raccourci |
| W10 | Assiette Mixte → sauce + accompagnement combinés | ✅ Split-screen adaptatif |

---

## ⚠️ 5. RISQUES ET CONTRAINTES

### 5.1 Contrainte critique — `syncAndSubmit()` NE DOIT PAS CHANGER
La fonction `syncAndSubmit()` (lignes 1041-1193) est le bridge fragile avec Vue.js.  
Elle interagit avec le DOM de Vue (`custom-radio-field`, `extra .custom-checkbox-field`, `button[class*="bg-primary"]`).  
**Kimi ne doit PAS modifier cette fonction.** Les nouvelles étapes doivent alimenter les mêmes `selections.*` qu'avant.

### 5.2 Backward compatibility des `selections`
Les clés `selections.viandes`, `selections.sauces`, `selections.sauceOrder`, `selections.garnitures`, `selections.supplements`, `selections.menuChoice`, `selections.individualAddons`, `selections.sauceFrites`, `selections.sauceFritesOrder` doivent rester identiques.  
Seules les étapes changent — pas la structure de données.

### 5.3 CSS à ajouter (inline dans le JS ou dans un `<style>` injecté)
```css
.wizard-split { display: flex; gap: 16px; }
.wizard-split > .wizard-col { flex: 1; min-width: 0; }
.wizard-split > .wizard-col h4 { font-size: 0.9rem; font-weight: 600; margin-bottom: 8px; }
.sauce-frites-inline { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee; }
.sauce-frites-inline.visible { display: block; }
.garniture-toggle { display: flex; gap: 8px; flex-wrap: wrap; }
.garniture-toggle-btn { padding: 8px 16px; border-radius: 20px; border: 2px solid #ddd; cursor: pointer; }
.garniture-toggle-btn.active { background: #22c55e; border-color: #22c55e; color: white; }
.edit-step-btn { background: none; border: none; cursor: pointer; font-size: 0.8rem; color: #666; padding: 2px 6px; }
.edit-step-btn:hover { color: #2563eb; }
```

---

## 🏁 6. DÉFINITION DU "DONE" POUR SPRINT 4

- [ ] `getAllowedSteps()` retourne les nouvelles étapes combinées
- [ ] `renderViandeSauceStep()` existe et affiche split-screen
- [ ] `renderPersoStep()` existe avec garnitures toggles + suppléments
- [ ] Sauce frites apparaît inline dans étape Menu (pas étape séparée)
- [ ] Raccourcis clavier Enter/←/→ fonctionnels
- [ ] Boutons ✏️ dans récap naviguent sans reset des sélections
- [ ] `syncAndSubmit()` NON MODIFIÉE (vérifier avec git diff)
- [ ] Anti-Gravity valide W01 à W10

---

## 📋 7. RÉSUMÉ EXÉCUTIF POUR KIMI

```
PRIORITÉ  TÂCHE                              TYPE TEST     FICHIER
P0        getAllowedSteps() refactoring       Auto          pos-wizard.js
P0        New step renderers (6 fonctions)   Anti-Gravity  pos-wizard.js
P0        Sauce frites inline dans Menu      Anti-Gravity  pos-wizard.js
P1        Raccourcis clavier                 Anti-Gravity  pos-wizard.js
P1        Édition depuis Récap (goto step)   Anti-Gravity  pos-wizard.js
P2        Robustesse detectCategory()        Auto          pos-wizard.js
```

> **Claude autorise Kimi à démarrer les tâches P0 immédiatement.**  
> **syncAndSubmit() est INTOUCHABLE. Git diff à fournir pour vérification.**

---

*Document généré par Claude Architecte — Sprint 4 — Conforme `AGENTS.md`*  
*Ne pas modifier sans accord Claude. Source de vérité implémentation wizard.*
