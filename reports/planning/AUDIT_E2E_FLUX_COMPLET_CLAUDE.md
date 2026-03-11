# 🔬 AUDIT E2E PROFOND — FLUX COMPLET POS : WIZARD → KDS → TICKET
**Auteur :** Claude (Lead Architect)  
**Date :** 11 Mars 2026 | 17h30  
**Scope :** Simulation complète + Audit code + Format ticket + KDS

---

## 🎬 SIMULATION COMPLÈTE : Commande "Tacos L (2 Viandes)"

### 👤 Scénario : Client commande un Tacos L avec menu complet

```
Caissier clique : [Tacos L (2 Viandes)] €8.50
```

---

### 📡 ÉTAPE 0 : Capture de l'item (XHR Intercept)

```javascript
// pos-wizard.js ligne 117-129
// Quand le POS charge les détails d'un item via XHR :
// GET /admin/item/{id} ou /admin/setting/item/{id}
//
// L'intercept lit la réponse JSON et stocke :
lastItemData = {
    id: 42,
    name: "Tacos L (2 Viandes)",
    convert_price: 8.50,
    category_name: "Nos Tacos",

    // [ATTRIBUTS] = Sauce + Viandes (dans la DB)
    itemAttributes: [
        { id: 10, name: "Sauce" },           // ← type sauce
        { id: 11, name: "Viande 1" },        // ← type viande (ignoré pour sauces)
        { id: 12, name: "Viande 2" }         // ← type viande (ignoré pour sauces)
    ],

    // [VARIATIONS] par attribut
    variations: {
        "10": [
            { id: 101, name: "Algérienne", convert_price: 0.00 },
            { id: 102, name: "Samouraï",  convert_price: 0.00 },
            ...
        ],
        "11": [
            { id: 201, name: "Poulet",    convert_price: 0.00 },
            { id: 202, name: "Merguez",   convert_price: 0.00 },
            ...
        ]
    },

    // [EXTRAS = garnitures + suppléments] depuis item_extras DB
    extras: [
        // Garnitures (prix = 0.00)
        { id: 301, name: "Complet (Salade, Tomate, Oignon)", convert_price: 0.00 },
        { id: 302, name: "Sans Oignon",                      convert_price: 0.00 },
        { id: 303, name: "Sans Tomate",                      convert_price: 0.00 },
        { id: 304, name: "Sans Salade",                      convert_price: 0.00 },
        { id: 305, name: "Aucune Crudité",                   convert_price: 0.00 },
        // Suppléments (prix > 0)
        { id: 401, name: "Supplément Cheddar",     convert_price: 1.00 },
        { id: 402, name: "Supplément Jambon",      convert_price: 1.00 },
        { id: 403, name: "Supplément Poulet",      convert_price: 2.00 },
        ...
        // ❌ POLLUTION (si seeder non corrigé) :
        { id: 501, name: "Sauce supplémentaire: Algérienne", convert_price: 0.50 },
        { id: 502, name: "Sauce supplémentaire: Samouraï",   convert_price: 0.50 },
    ],

    // [ADDONS = formules] depuis item_addons DB
    addons: [
        {
            id: 601,
            addon_item_name: "En Menu (Frites + Boisson)",
            addon_item_convert_price: 3.00,   // ← CORRECT
            total_convert_price: 6.00          // ← BUG-POS-002 : doublement
        },
        {
            id: 602,
            addon_item_name: "Frites Seules",
            addon_item_convert_price: 1.50,
            total_convert_price: 3.00          // ← idem doublement?
        }
    ]
}
```

---

### 📺 ÉTAPES DU WIZARD (4 pages pour Tacos)

#### Page 1 : Viande & Sauce (step: `viande_sauce`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  🥩 Viande                    🥄 Sauce (1ère gratuite)              │
│  ┌──────────────────┐         ┌─────────────────┐                   │
│  │ 2 / 2 ✅ Complet│         │ 1 sauce = Gratis │                   │
│  └──────────────────┘         └─────────────────┘                   │
│                                                                     │
│  🌶️ Merguez      [−] 1 [+]   [🌶️ Algérienne  ] ✓ Gratuit           │
│  🥩 Kefta        [−] 0 [+]   [🍛 Curry       ] +€0.50              │
│  🍗 Poulet       [−] 0 [+]   [🔥 Harissa     ] +€0.50              │
│  🔵 Cordon Bleu  [−] 0 [+]   [⚔️ Samouraï    ] +€0.50              │
│  🥩 Viande Hachée[−] 1 [+]   [🍅 Ketchup     ]                     │
│                                                                     │
│  Sélections : viandes={merguez:1, viande_hachee:1}                  │
│               sauces={101:true}, sauceOrder=[101]                   │
└─────────────────────────────────────────────────────────────────────┘
```

**État `selections` après page 1 :**
```javascript
selections.viandes    = { merguez: 1, kefta: 0, poulet: 0, viande_hachee: 1, ... }
selections.totalViandes = 2  // ✅ quota atteint → bouton Suivant activé
selections.sauces     = { 101: true }  // Algérienne sélectionnée
selections.sauceOrder = [101]
selections.sauceAttrId = 10
```

---

#### Page 2 : Personnalisation (step: `perso`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  🥗 Garnitures incluses (coché par défaut)                          │
│  ◉ Complet (Salade, Tomate, Oignon)                                 │
│  ○ Sans Oignon                                                      │
│  ○ Sans Tomate                                                      │
│  ○ Sans Salade                                                      │
│  ○ Aucune Crudité                                                   │
│                                                                     │
│  ➕ Suppléments (payants)                                            │
│  □ Supplément Cheddar     +€1.00                                    │
│  □ Supplément Jambon      +€1.00                                    │
│  ☑ Supplément Poulet      +€2.00  ← caissier l'ajoute              │
│  □ Supplément Raclette    +€1.00                                    │
│                                                                     │
│  ❌ NE DOIT PAS AFFICHER : "Sauce supplémentaire: Algérienne +€0.50"│
│     → Bug si seeder non corrigé                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**État après page 2 :**
```javascript
selections.garnitures  = { 301: true }   // Complet sélectionné (pré-coché)
selections.supplements = { 403: true }   // Supplément Poulet +€2.00
```

---

#### Page 3 : Formule (step: `menu`)

```
┌─────────────────────────────────────────────────────────────────────┐
│              🍔 Voulez-vous le menu ?                                │
│                                                                     │
│  ┌─────────────────────┐  ┌─────────────────────┐                  │
│  │   ✅ Oui, Menu      │  │   ❌ Non merci       │                  │
│  │   Complet           │  │   Individuellement   │                  │
│  │   +€3.00  ← CORRECT │  │                     │                  │
│  │   (si BUG-002 fixé) │  │                     │                  │
│  └─────────────────────┘  └─────────────────────┘                  │
│                                                                     │
│  → Caissier click "Oui, Menu Complet"                               │
└─────────────────────────────────────────────────────────────────────┘
```

**État après page 3 :**
```javascript
selections.menuChoice = 'full'  // Menu complet +€3.00
```

```
Total courant : 8.50 (base) + 2.00 (suppl poulet) + 3.00 (menu) = €13.50
```

---

#### Page 4 : Récap (step: `recap`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  🧾 Récapitulatif                                                    │
│                                                                     │
│  Tacos L (2 Viandes)                              €8.50             │
│  🥩  Viandes : Merguez, Viande Hachée                               │
│  🥄  Sauce    : Algérienne (gratuite)                               │
│  🥗  Garnitures : Complet (Salade, Tomate, Oignon) ✓               │
│  ➕  Supplément Poulet                             +€2.00            │
│  🍟🥤 En Menu (Frites + Boisson)                  +€3.00            │
│                                                                     │
│  Quantité : [−] 1 [+]                                               │
│  Instructions spéciales : [________________________]                │
│                                                                     │
│  ┌──────────────────────────────────────────────────┐               │
│  │  ✅ AJOUTER AU PANIER — Total : €13.50           │               │
│  └──────────────────────────────────────────────────┘               │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 🔌 ÉTAPE CRITIQUE : syncAndSubmit() — Le pont wizard → API

### Analyse complète de ce que fait `syncAndSubmit()` :

```javascript
// [1] Quantité → Dispatche event React/Vue dans le DOM original
qtyInput.value = itemQuantity → dispatchEvent('input')

// [2] Sauce (1ère uniquement) → Click le radio/select dans le modal caché
var sauceIdToSync = selections.sauceOrder[0];  // → ID 101 (Algérienne)
radio[value=101].click();   // ← Enregistre la variation sauce

// [3] Extras (garnitures + suppléments) → DOM checkboxes
allSelectedExtras = {
    301: true,   // Complet (Salade, Tomate, Oignon)
    403: true    // Supplément Poulet
}
// Pour chaque checkbox dans .extra .custom-checkbox-field :
//   si cb.value dans allSelectedExtras → click pour cocher
//   sinon → click pour décocher si coché

// [4] Addons (menu) → Click les .addon cards
//   menuChoice === 'full' → card[0].click()

// [5] Instruction text → textarea.value = fullInstruction
fullInstruction = "VIANDES: Merguez, Viande Hachée. "
// Sauces extra (2ème, 3ème...) → ajoutées dans instruction
// Sauce frites → "SAUCE FRITES: Algérienne. "

// [6] Click "Add to Cart" button → setTimeout 200ms → submit
```

### 🔴 BUGS DÉTECTÉS dans syncAndSubmit()

#### SYNC-BUG-001 : Sauces secondaires cherchées dans le mauvais step
**Ligne 1834-1843 :**
```javascript
// PROBLÈME : cherche le step de type 'sauce', mais le nouveau wizard utilise
// 'viande_sauce' → sauceStep sera undefined → TypeError
var sauceStep = steps.find(function (s) { return s.type === 'sauce'; });
// Pour Tacos, le step sauce est inclus dans 'viande_sauce', pas dans 'sauce' seul
// → extraSauceNames sera toujours vide pour les Tacos avec le nouveau wizard !
```

**Fix :**
```javascript
// Chercher dans tous les steps qui ont des sauceItems
var allSauceItems = [];
steps.forEach(function(s) {
    if (s.sauceItems) allSauceItems = allSauceItems.concat(s.sauceItems);
    if (s.items && s.type === 'sauce') allSauceItems = allSauceItems.concat(s.items);
});
// Puis utiliser allSauceItems pour trouver les noms
```

#### SYNC-BUG-002 : Suppléments non inclus dans l'instruction text
**Problème :** `syncAndSubmit()` construit l'instruction avec VIANDES + EXTRA SAUCES + SAUCE FRITES mais **PAS les suppléments payants** sélectionnés (Cheddar, Jambon, Poulet...).

**Impact :** Un "Supplément Poulet +€2.00" est envoyé comme extra (checkbox coché) vers l'API, mais sur le ticket et KDS l'instruction ne mentionne pas explicitement les suppléments.

**Partiellement compensé par :** Les extras cochés apparaissent dans `item_extras` sur le ticket. ✅ Mais le KDS reçoit les extras dans `item_extras` → cuisiniers le voient quand même.

**Amélioration recommandée :**
```javascript
// Ajouter dans fullInstruction :
if (selections.supplements) {
    var supplNames = [];
    Object.keys(selections.supplements).forEach(function(id) {
        if (!selections.supplements[id]) return;
        // Chercher le nom du supplément dans les étapes
        steps.forEach(function(s) {
            (s.paidItems || []).concat(s.items || []).forEach(function(p) {
                if (String(p.id) === String(id)) supplNames.push(p.name);
            });
        });
    });
    if (supplNames.length > 0) fullInstruction += 'SUPPLÉMENTS: ' + supplNames.join(', ') + '. ';
}
```

#### SYNC-BUG-003 : Garnitures non radio — toutes cochées peuvent passer
**Problème :** Les garnitures sont en `freeExtras` (prix = 0). Toutes sont cochées dans le DOM par défaut. Quand le caissier choisit "Sans Oignon", le wizard met `garnitures = { 302: true }` (302 = Sans Oignon). Mais dans `syncAndSubmit()`, **la checkbox 301 (Complet) peut rester cochée dans le DOM** si elle n'a pas été décochée.

**Risque :** Le serveur reçoit DEUX garnitures : "Complet" ET "Sans Oignon" — contradiction. La DB stockerait les deux.

**Fix :** Avant `extraCheckboxes.forEach(click)`, décocher TOUTES les garnitures d'abord, puis cocher seulement la sélectionnée.

---

## 🍽️ FORMAT TICKET — Ce qui est imprimé

### Ticket actuel (ReceiptComponent.vue analyse) :

```
═══════════════════════════════
         LE GRILL HOUSE
       123 Rue de la Paix
        Tel: 01 23 45 67
═══════════════════════════════
 Commande #045     11/03/2026
                       17:35
───────────────────────────────
 Qté  Description          Prix
───────────────────────────────
  1   Tacos L (2 Viandes) 8.50€
      Sauce: Algérienne           ← item_variations (seulement 1ère sauce)
      Extras: Complet (Salade,    ← item_extras
              Tomate, Oignon),
              Supplément Poulet
      Instruction: VIANDES:       ← instruction field (texte brut)
        Merguez, Viande Hachée.
═══════════════════════════════
 SOUS-TOTAL                10.50€
 TVA 10%                   1.05€
 TOTAL                    11.55€
───────────────────────────────
 Type: Sur place
 Paiement: Espèces
 Reçu: 15.00€   Rendu: 3.45€
───────────────────────────────
 TOKEN #7
═══════════════════════════════
```

### 🔴 PROBLÈMES TICKET ACTUELS

#### TICKET-BUG-001 : Menu complet (Frites + Boisson) non visible sur ticket
**Problème :** L'addon "En Menu (Frites + Boisson) +€3.00" est sélectionné mais le ticket ne l'affiche PAS explicitement. Il apparaît dans le total mais pas dans le détail des articles.

**Cause :** Les addons sont ajoutés au prix dans le `OrderService.php` mais ne sont pas stockés comme `item_extras` séparés.

**Impact :** La cuisine ne sait pas que le client a commandé le menu → elle ne préparera peut-être pas les frites !

**Fix dans `syncAndSubmit()` — ajouter dans l'instruction :**
```javascript
if (selections.menuChoice === 'full') {
    fullInstruction += 'FORMULE: Menu Complet (Frites + Boisson). ';
} else if (selections.menuChoice === 'frites') {
    fullInstruction += 'FORMULE: Frites Seules. ';
}
// + grande frite si sélectionnée
if (selections.frites_grande) {
    fullInstruction += 'FRITES: Grande portion +€1. ';
}
if (selections.frites_cheddar) {
    fullInstruction += 'FRITES CHEDDAR: +€1. ';
}
if (selections.sauceFritesOrder && selections.sauceFritesOrder.length > 0) {
    // déjà géré mais vérifier que le step sauce_frites existe
}
```

#### TICKET-BUG-002 : Sauces supplémentaires (2ème, 3ème) non affichées
**Cause :** Seule la 1ère sauce est dans `item_variations`. Les sauces extra sont dans l'instruction. Sur le ticket, elles apparaissent dans le bloc "Instruction:" — peu lisible.

**Fix recommandé :** Améliorer le template du ticket pour parser l'instruction et afficher les sections VIANDES / SAUCES SUPPL / SAUCE FRITES / FORMULE séparément avec des icônes.

#### TICKET-BUG-003 : Suppléments "Supplément Cheddar" affiché sans emoji
Le ticket montre `Extras: Supplément Cheddar, Supplément Jambon` en texte brut sans distinction visuelle avec les garnitures.

---

## 🖥️ FORMAT KDS — Ce qui reçoit la cuisine

### KitchenDisplaySystemOrderService.php — Analyse

```php
// La KDS groupe les items par :
// item_id + item_variations (JSON trié) + item_extras (JSON trié) + instruction
//
// Ce que la cuisine voit pour notre commande :
[
    'item_id'         => 42,
    'item_name'       => "Tacos L (2 Viandes)",
    'quantity'        => 1,
    'item_variations' => [
        { 'variation_name': 'Sauce', 'name': 'Algérienne' }
    ],
    'item_extras'     => [
        { 'name': 'Complet (Salade, Tomate, Oignon)' },
        { 'name': 'Supplément Poulet' }
    ],
    'instruction'     => "VIANDES: Merguez, Viande Hachée."
]
```

### 🔴 PROBLÈMES KDS ACTUELS

#### KDS-BUG-001 : Viandes dans l'instruction, pas dans les champs structurés
**Impact :** La cuisine lit "VIANDES: Merguez, Viande Hachée." comme texte dans le champ instruction. Ce n'est pas mis en évidence visuellement sur l'écran KDS. Un cuisinier distrait peut ne pas voir les viandes.

**Fix idéal :** Passer les viandes comme `item_extras` également (en plus de l'instruction),  afin qu'elles remontent dans le champ structuré de la KDS.

Dans `syncAndSubmit()` :
```javascript
// Ajouter les viandes comme extras dans le DOM via checkboxes fictives... 
// → Pas possible facilement avec DOM sync
// Solution : Mettre les viandes dans instruction (déjà fait) ET améliorer
// l'affichage KDS pour parser l'instruction dans le frontend KDS
```

#### KDS-BUG-002 : Menu complet (frites) non visible en cuisine
**Impact critique :** Si le caissier choisit "Menu Complet", la cuisine ne reçoit pas l'info explicite "préparer des frites". L'instruction FORMULE n'est générée que si on ajoute le fix recommandé dans TICKET-BUG-001.

---

## 💰 CALCUL DES PRIX — Flux complet

### Calcul côté wizard (front) :

```javascript
// calculateRunningTotal() — affiche le "Total provisoire" au caissier
var total = 8.50;  // base Tacos L

// Sauces extra : 1ère gratuite → seulement les suivantes
// sauceOrder.length = 1 → pas de supplément sauce
total += 0;   // €0

// Suppléments (après BUG-POS-004 fix)
// supplements[403] = true, price = 2.00
total += 2.00;   // Supplément Poulet

// Menu addon (après BUG-POS-002 fix)
// menuChoice = 'full', addonItems[0].price = 3.00 (corrigé)
total += 3.00;

// TOTAL AFFICHÉ AU CAISSIER : €13.50 ✅
```

### Calcul côté serveur (Laravel OrderService) :

```php
// POST admin/pos avec les données du formulaire Vue
// Le serveur recalcule indépendamment :
$itemTotal = $itemPrice + sum($selectedVariationPrices) + sum($selectedExtraPrices) + sum($selectedAddonPrices);

// Variaiton = Sauce Algérienne → €0.00 (la variation a un prix = 0 en DB)
// Extras = Complet (€0.00) + Supplément Poulet (€2.00)
// Addon = En Menu (€3.00 si unit price correct, €6.00 si bug)
```

**Risque de désynchronisation :** Si le wizard affiche €13.50 mais le serveur calcule €16.50 (à cause du bug addon €6), le caissier encaisse le mauvais montant.

---

## 📋 PLAN D'ACTION FINAL POUR KIMI

### Phase 1 — Critical Fixes (aujourd'hui, pos-wizard.js seulement)

| Fix | Localisation | Code |
|-----|-------------|------|
| BUG-POS-001 : Sauce list filtrée | `buildSteps()` L280 | Si attr.name ≠ 'sauce' → return |
| BUG-POS-002 : Prix menu = €3 | `buildSteps()` L342 | Utiliser `addon_item_convert_price` |
| BUG-POS-004 : Suppléments dans total | `calculateRunningTotal()` | Itérer selections.supplements |
| BUG-POS-005 : Sauces dans extras | `buildSteps()` L317 | Filtrer "Sauce supplémentaire:" |
| SYNC-BUG-001 : Extra sauces step lookup | `syncAndSubmit()` L1834 | Chercher dans tous les steps.sauceItems |
| SYNC-BUG-002 : Suppléments dans instruction | `syncAndSubmit()` L1816 | Ajouter bloc SUPPLÉMENTS: |
| SYNC-BUG-003 : Garnitures radio | `syncAndSubmit()` L1768 | Décocher toutes garnitures avant re-cocher |
| TICKET-BUG-001 : Formule dans instruction | `syncAndSubmit()` L1816 | Ajouter FORMULE: / FRITES: / SAUCE FRITES: |

### Phase 2 — Format Ticket amélioré (ReceiptComponent.vue)

**Objectif :** Parser l'instruction structurée et afficher de façon lisible.

```vue
<!-- Instruction améliorée dans ReceiptComponent.vue -->
<p v-if="item.instruction" class="text-xs">
    <span v-for="section in parseInstruction(item.instruction)" :key="section.key">
        <b>{{ section.icon }} {{ section.label }}:</b> {{ section.value }}<br>
    </span>
</p>

<!-- Script -->
parseInstruction(instruction) {
    const sections = [];
    const patterns = [
        { key: 'viandes',    icon: '🥩', label: 'Viandes',      regex: /VIANDES: ([^.]+)/ },
        { key: 'suppl',      icon: '➕', label: 'Suppléments',  regex: /SUPPLÉMENTS: ([^.]+)/ },
        { key: 'formule',    icon: '🍟', label: 'Formule',      regex: /FORMULE: ([^.]+)/ },
        { key: 'sfrites',    icon: '🥄', label: 'Sauce frites', regex: /SAUCE FRITES: ([^.]+)/ },
        { key: 'frites_opt', icon: '🍟', label: 'Frites',      regex: /FRITES: ([^.]+)/ },
    ];
    patterns.forEach(p => {
        const m = instruction.match(p.regex);
        if (m) sections.push({ key: p.key, icon: p.icon, label: p.label, value: m[1] });
    });
    return sections;
}
```

### Phase 3 — KDS amélioré (Vue KDS component)

```vue
<!-- Dans le composant KDS (commandes en cours) -->
<!-- Instruction : parser et afficher structuré -->
<div class="kds-instruction" v-if="item.instruction">
    <div class="kds-viandes" v-if="extractSection(item.instruction, 'VIANDES')">
        🥩 {{ extractSection(item.instruction, 'VIANDES') }}
    </div>
    <div class="kds-suppl kds-highlight" v-if="extractSection(item.instruction, 'SUPPLÉMENTS')">
        ⚠️ {{ extractSection(item.instruction, 'SUPPLÉMENTS') }}
    </div>
    <div class="kds-formule" v-if="extractSection(item.instruction, 'FORMULE')">
        🍟 {{ extractSection(item.instruction, 'FORMULE') }}
    </div>
</div>
```

---

## ✅ FORMAT TICKET TARGET (Après toutes les corrections)

```
═══════════════════════════════════
         LE GRILL HOUSE
          Paris, France
        Tel: +33 1 23 45 67
═══════════════════════════════════
 Commande #045        11/03/2026
                          17:35
───────────────────────────────────
 Qté  Description             Prix
───────────────────────────────────
  1   Tacos L (2 Viandes)   8.50€
      🥩  Viandes : Merguez,
               Viande Hachée
      🥄  Sauce : Algérienne
      🥗  Garnitures : Complet
      ➕  Supplément Poulet +2.00€
      🍟  Formule : Menu Complet
               (Frites + Boisson)
───────────────────────────────────
 Sous-total                 10.50€
 TVA 10%                    1.05€
───────────────────────────────────
 TOTAL                     11.55€
───────────────────────────────────
 Type : Sur place
 Paiement : Espèces
 Reçu : 15.00€      Rendu : 3.45€
───────────────────────────────────
           TOKEN #7
═══════════════════════════════════
      Merci de votre visite !
═══════════════════════════════════
```

---

## ✅ FORMAT KDS TARGET (Après toutes les corrections)

```
┌──────────────────────────────────┐
│  🔴 N°7 — 17:35                 │
│  TACOS L (2 Viandes)   ×1       │
│                                  │
│  🥩 Merguez + Viande Hachée     │  ← En évidence
│  🥄 Sauce : Algérienne           │
│  🥗 Complet (S, T, O)           │
│  ⚠️ SUPPL: Poulet               │  ← En rouge/jaune
│  🍟 FRITES + BOISSON à préparer  │  ← En bleu
│                                  │
│  [⏳ EN PRÉPA]  [✅ PRÊT]        │
└──────────────────────────────────┘
```

---

*Audit Claude E2E — Sprint 5 — Priorité : Phase 1 = aujourd'hui*
