# PLAN_06 — D-010 : KDS — Parser et Afficher les Instructions en Sections
**Phase :** P1 — Haute
**Test-Type :** Anti-Gravity (test visuel navigateur)
**Impact :** 🟡 Moyen — Les chefs voient les instructions brutes → erreurs de préparation
**Fichiers :**
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `public/css/kds.css` ou inline dans le composant

---

## 1. Contexte & Problème

Le wizard POS génère une chaîne d'instructions structurée :
```
VIANDES: Merguez, Poulet. SUPPLÉMENTS: Cheddar. FORMULE: Menu Complet (Frites + Boisson). NOTE: Sans oignon.
```

Le KDS affiche ce texte **brut**, en une seule ligne, sans mise en forme.
Le chef doit le lire entier pour comprendre. Risque d'erreur de préparation.

**Objectif :** Afficher en sections visuellemont distinctes avec icônes et couleurs.

---

## 2. Fichiers à Modifier

| Fichier | Zone | Action |
|---------|------|--------|
| `KitchenDisplaySystemComponent.vue` | Template + `<script>` | Ajouter parseInstruction(), mettre à jour le template |
| Styles CSS | Dans `<style scoped>` du composant | Ajouter `.kds-section` styles |

---

## 3. Implémentation

### 3.1 Ajouter `parseInstruction()` dans `<script>`

Dans la section `methods:` du composant Vue :

```javascript
/**
 * Parse l'instruction wizard POS en sections structurées.
 * Format attendu: "VIANDES: val. SUPPLÉMENTS: val. FORMULE: val. NOTE: val."
 */
parseInstruction(instruction) {
    if (!instruction || typeof instruction !== 'string') return null;

    const sections = {
        viandes:      null,
        supplements:  null,
        formule:      null,
        sauces:       null,
        garnitures:   null,
        note:         null,
        raw:          null,
    };

    // Extraire chaque section avec regex
    const patterns = {
        viandes:     /VIANDES?:\s*([^.]+)/i,
        supplements: /SUPPL[ÉE]MENTS?:\s*([^.]+)/i,
        formule:     /FORMULE:\s*([^.]+)/i,
        sauces:      /SAUCES?:\s*([^.]+)/i,
        garnitures:  /GARNITURES?:\s*([^.]+)/i,
        note:        /NOTE:\s*([^.]+)/i,
    };

    let hasStructured = false;
    Object.keys(patterns).forEach(key => {
        const match = instruction.match(patterns[key]);
        if (match) {
            sections[key] = match[1].trim();
            hasStructured = true;
        }
    });

    // Si pas de format structuré → afficher brut
    if (!hasStructured) {
        sections.raw = instruction;
    }

    return sections;
},
```

### 3.2 Mettre à jour le Template HTML

Trouver la zone où l'instruction est affichée dans le template du KDS.
Remplacer l'affichage brut par :

```html
<!-- AVANT -->
<div class="order-instruction">{{ order.order_note }}</div>

<!-- APRÈS -->
<div class="order-instruction-parsed" v-if="order.order_note">
  <template v-if="parseInstruction(order.order_note) as parsed">
    <!-- Raw (non structuré) -->
    <div v-if="parsed.raw" class="kds-section kds-raw">
      📝 {{ parsed.raw }}
    </div>
    <!-- Viandes -->
    <div v-if="parsed.viandes" class="kds-section kds-viandes">
      🥩 <strong>Viandes:</strong> {{ parsed.viandes }}
    </div>
    <!-- Suppléments -->
    <div v-if="parsed.supplements" class="kds-section kds-supplements">
      ➕ <strong>Suppléments:</strong> {{ parsed.supplements }}
    </div>
    <!-- Formule -->
    <div v-if="parsed.formule" class="kds-section kds-formule">
      🍟 <strong>Formule:</strong> {{ parsed.formule }}
    </div>
    <!-- Sauces -->
    <div v-if="parsed.sauces" class="kds-section kds-sauces">
      🥄 <strong>Sauces:</strong> {{ parsed.sauces }}
    </div>
    <!-- Garnitures -->
    <div v-if="parsed.garnitures" class="kds-section kds-garnitures">
      🥬 <strong>Garnitures:</strong> {{ parsed.garnitures }}
    </div>
    <!-- Note -->
    <div v-if="parsed.note" class="kds-section kds-note">
      💬 <em>{{ parsed.note }}</em>
    </div>
  </template>
</div>
```

> **Note :** Si la syntaxe `v-if="... as parsed"` n'est pas supportée en Vue 2,
> utiliser une computed property ou un `v-bind` intermédiaire.

**Version compatible Vue 2/3 (computed) :**
```javascript
// Dans computed:
parsedInstructions() {
    if (!this.order?.order_note) return null;
    return this.parseInstruction(this.order.order_note);
}
```

### 3.3 CSS — Styles des sections

Dans `<style scoped>` du composant :

```css
/* KDS — Sections d'instruction */
.order-instruction-parsed {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 6px;
}

.kds-section {
    font-size: 12px;
    padding: 4px 8px;
    border-radius: 4px;
    line-height: 1.4;
}

.kds-viandes {
    background: #FDE8E8;
    border-left: 3px solid #E74C3C;
    color: #922B21;
}

.kds-supplements {
    background: #FFF3CD;
    border-left: 3px solid #FFC107;
    color: #7B6E00;
    font-weight: 500;
}

.kds-formule {
    background: #D1ECF1;
    border-left: 3px solid #17A2B8;
    color: #0C6674;
}

.kds-sauces {
    background: #E8F5E9;
    border-left: 3px solid #4CAF50;
    color: #2E7D32;
}

.kds-garnitures {
    background: #F1F8E9;
    border-left: 3px solid #8BC34A;
    color: #33691E;
}

.kds-note {
    background: #F5F5F5;
    border-left: 3px solid #9E9E9E;
    color: #555;
    font-style: italic;
}

.kds-raw {
    background: #FFF;
    border-left: 3px solid #CCC;
    color: #333;
}
```

---

## 4. Compilation

```bash
npm run dev
# Vérifier : 0 erreurs
```

---

## 5. Tests Anti-Gravity

Le test doit être exécuté par Anti-Gravity (test navigateur) :

**Scénario :**
1. Créer une commande POS avec des viandes, suppléments, une formule
2. Ouvrir `/admin/kitchen-display-system`
3. Vérifier que la carte affiche bien :
   - Section `🥩 Viandes:` en rouge
   - Section `➕ Suppléments:` en jaune
   - Section `🍟 Formule:` en bleu
4. Tester avec une instruction non structurée → affichage brut normal

---

## 6. Critères de Succès

- [ ] `parseInstruction("VIANDES: Merguez. SUPPLÉMENTS: Cheddar.")` retourne `{ viandes: 'Merguez', supplements: 'Cheddar' }`
- [ ] Sections colorées visibles dans le KDS
- [ ] Texte brut (sans format) → affiché normalement dans `.kds-raw`
- [ ] Compile sans erreur
- [ ] Anti-Gravity valide visuellement

---

## 7. NE PAS Toucher

- La logique de filtrage des ordres (branch_id, statuts)
- Le polling/WebSocket de mise à jour des ordres
- Les autres composants Vue (ne modifier que KitchenDisplaySystemComponent.vue)
