# PLAN_09 — D-008 : Dine-In — Réactivation par Config ou Message Explicite
**Phase :** P2 — Moyenne
**Test-Type :** No-test (changement UI simple)
**Impact :** 🟡 UX — Dine-In masqué sans explication génère confusion
**Fichiers :**
- `resources/js/components/admin/pos/PosComponent.vue`

---

## 1. Contexte & Problème

Le type de commande "Dine-In" (sur-place / sur table) est actuellement masqué avec :
```html
<div v-if="false" id="dine-in-option">...</div>
```

Il n'y a aucun message explicatif pour le caissier. Si un client veut manger sur place,
le caissier ne voit pas l'option et doit utiliser Takeaway à la place.

---

## 2. Options disponibles

### Option A (recommandée) — Piloté par setting admin
Afficher Dine-In si un setting `order_setup_dine_in` est activé dans le back-office.

### Option B — Message explicatif
Afficher un bouton grisé "Sur place — Temporairement indisponible" non cliquable.

**Claude recommande Option A** pour permettre à l'admin d'activer sans modifier le code.

---

## 3. Implémentation — Option A

### 3.1 Vérifier le setting existant

Dans le code Vue, chercher si `settingOrderSetupDineIn` ou similaire est déjà disponible
dans les props ou le store Vuex.

```javascript
// Dans le composant, chercher :
this.$store.state.setting
// ou dans les props
this.settingOrderSetupDineIn
```

### 3.2 Modifier le v-if

```html
<!-- AVANT — toujours masqué -->
<div v-if="false" id="dine-in-option">
  ...
</div>

<!-- APRÈS Option A — piloté par setting -->
<div v-if="settingOrderSetupDineIn" id="dine-in-option">
  ...
</div>

<!-- APRÈS Option B — bouton grisé si désactivé -->
<div id="dine-in-option" :class="{ 'dine-in-disabled': !settingOrderSetupDineIn }">
  <button
    class="order-type-btn"
    :disabled="!settingOrderSetupDineIn"
    :title="!settingOrderSetupDineIn ? 'Sur place temporairement indisponible' : ''"
    @click="settingOrderSetupDineIn && setOrderType('dine_in')">
    🍽️ Sur Place
    <small v-if="!settingOrderSetupDineIn" class="text-muted">Indisponible</small>
  </button>
</div>
```

### 3.3 CSS pour état désactivé

```css
.dine-in-disabled {
    opacity: 0.4;
    cursor: not-allowed;
    pointer-events: none;
    filter: grayscale(0.8);
}
```

### 3.4 Valeur par défaut du setting

Si `settingOrderSetupDineIn` n'est pas dans le store, ajouter la récupération :

```javascript
// Dans computed ou créer un getter Vuex
settingOrderSetupDineIn() {
    return this.$store.state.setting?.order_setup_dine_in === '1'
        || this.$store.state.setting?.order_setup_dine_in === true
        || false; // désactivé par défaut si non configuré
},
```

---

## 4. Tests (No-test — Vérification manuelle)

**Checklist manuelle :**
- [ ] Avec setting `order_setup_dine_in = false` (par défaut) : section non visible ou grisée
- [ ] Activer le setting dans l'admin → Dine-In apparaît
- [ ] Cliquer sur Dine-In → flow normal de commande sur table
- [ ] 0 erreur JavaScript dans la console

---

## 5. Critères de Succès

- [ ] Pas de `v-if="false"` codé en dur dans le template
- [ ] Comportement piloté par le setting `order_setup_dine_in`
- [ ] État désactivé visible et explicite pour le caissier
- [ ] Compilation sans erreur

---

## 6. NE PAS Toucher

- La logique de commande "tableOrderStore" (backend)
- Les autres types de commande (Takeaway, Delivery)
- Le flux de sélection des tables (si Dine-In est activé)
