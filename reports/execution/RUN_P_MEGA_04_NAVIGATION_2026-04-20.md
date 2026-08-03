# RUN_P_MEGA_04 — Navigation back/forward wizard préserve les sélections

**Date** : 2026-04-20  
**Cycle** : P-MEGA  
**Tâche** : P-MEGA-04 (vague 1)  
**Verdict** : **CLOSED — POSITIF** (audit + test garde-fou, 0 fix code requis)

## Hypothèse de bug auditée

Le wizard utilise `<component :is="currentStepComponent" :key="currentStep.type">` dans une `<transition mode="out-in">`. Chaque navigation **détruit puis remonte** le composant Step. Si un Step réinitialise mal son état local au remount, l'utilisateur perd ses sélections. C'est un risque structurel classique des wizards Vue.

## Audit livré

### Step* enfants — pattern d'init

Tous les Step* (Viande, Sauce, Garnitures, Suppléments, Pain, Taille, Menu) suivent un pattern cohérent :

```js
data() {
  return { localSelections: { ...this.selections.<key> } };  // seed depuis prop
},
watch: {
  'selections.<key>': { deep: true, handler(v) { this.localSelections = { ...v }; } }
}
```

→ Au remount, `data()` re-seed depuis `props.selections` qui est l'**état partagé maintenu par le parent**. Architecture saine.

### Parent `KioskWizardComponent`

`prevStep()` et `nextStep()` n'effacent rien :

```js
nextStep() { if (this.currentStepIndex < this.activeSteps.length - 1) this.currentStepIndex++; }
prevStep() { if (this.currentStepIndex > 0) this.currentStepIndex--; }
```

`updateSelection(key, value, meta)` (ligne 561-606) reset les viandes **uniquement** quand on change de taille (ligne 567-571) — comportement intentionnel et documenté.

### Subtilité identifiée — `KioskStepGarnituresComponent`

Le composant utilise un flag `userInteracted` qui est **reset à false au remount** (puisque c'est dans `data()`). Le watcher `selections.garnitures` n'écrit dans `localSelections` que si `userInteracted === false` ET `localSelections` est vide. Ce double-guard évite l'écrasement silencieux.

**Vérifié** : au remount, `localSelections` est seed avec `{ ...selections.garnitures }` qui contient déjà les choix utilisateur précédents. Le watcher ne fait rien (deuxième condition fausse). Aucun bug.

## Test garde-fou ajouté

`tests/js/kioskWizardNavigation.spec.js` (8 nouveaux tests) :

1. **viandes + sauces survivent à un cycle next → prev → next** : assertion sur `selections.viandes`, `totalViandes`, `_viandeMeta`, `sauceOrder` après navigation
2. **changer de taille reset les viandes** (comportement intentionnel) : prouve que le reset est bien limité au cas légitime
3. **bouton back désactivé sur step 0** : assertion sur `:disabled`
4. **prevStep ne descend jamais sous 0** : guard
5. **nextStep ne dépasse jamais le dernier index** : guard
6. **KioskStepViande seed localSelections depuis props au mount**
7. **KioskStepGarnitures seed localSelections au mount** (faille `userInteracted` lifecycle couverte)
8. **KioskStepSauce seed localSelections depuis props.selections.sauces**

## Métriques

| Mesure | Avant | Après |
|---|---:|---:|
| Tests Vitest navigation wizard | 2 (P0/P1 hint) | **+8** |
| Bugs nav identifiés | 0 | 0 |
| Subtilités documentées | 0 | 1 (`userInteracted` lifecycle) |
| Régression potentielle bloquée | non | **oui** |

## Conformité

- ✅ Aucun code production touché (zone front kiosk verrouillée par P-MEGA-01)
- ✅ Test sur le **vrai composant** (shallowMount KioskWizardComponent + mount Step*)
- ✅ Couvre 3 scénarios métier critiques
- ✅ Bloque toute régression future (refactor wizard, switch v-show vs v-if, etc.)
- ✅ 0 régression sur 503 tests Vitest (495 + 8)
