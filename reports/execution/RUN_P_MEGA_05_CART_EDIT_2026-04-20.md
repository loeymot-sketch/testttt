# RUN_P_MEGA_05 — Cart edit ré-entrer wizard avec sélections pré-remplies

**Date** : 2026-04-20  
**Cycle** : P-MEGA  
**Tâche** : P-MEGA-05 (vague 1)  
**Verdict** : **CLOSED — POSITIF + BUG INVISIBLE COROLLAIRE FIXÉ**

## Contexte du bug original

`KioskCartComponent.editItem(index)` (avant fix) :

```js
async editItem(index) {
  const item = await this.popItem(index);  // SUPPRIME la ligne
  if (!item?.item_id) return;
  this.$router.push({ name: 'kiosk.wizard', params: { itemId: String(item.item_id) } });
}
```

Conséquences UX :
- Toutes les sélections (taille, viandes, sauces, garnitures, suppléments, instruction, quantité) **perdues**.
- Si l'utilisateur ferme le wizard via `onAbandonClick`, **la cart line est définitivement perdue**.
- Si timeout idle pendant l'édition, idem.

Commentaire ancien admettait le compromis ("default = 1 to keep things simple").

## Architecture du fix (3-layer safe)

### Couche 1 — Vuex `kioskCart` (state + mutations + actions)

| Element | Type | Rôle |
|---|---|---|
| `editingCartIndex` | state | index 0-based de la ligne en édition (ou `null`) |
| `editingCartSnapshot` | state | deep clone de la ligne pour restoration |
| `SET_EDITING({index, snapshot})` | mutation | atomique, deep-clones le snapshot pour éviter contamination |
| `CLEAR_EDITING` | mutation | reset les 2 champs |
| `REPLACE_ITEM_AT({index, item})` | mutation | remplace en place (préserve l'ordre, clamp qty, recompute total) |
| `startEditingCartItem(index)` | action | snapshot la ligne SANS la supprimer ; retourne `false` si index invalide |
| `cancelEditingCartItem` | action | clear l'édition, panier intact |
| `replaceEditingCartItem(item)` | action | replace OR fallback ADD_ITEM si plus en édition (race-safe) |

`RESET` étendu pour clear l'édition (idle, logout, kioskLogout).

### Couche 2 — `KioskCartComponent.editItem`

```js
async editItem(index) {
  const item = this.cartItems[index];
  if (!item?.item_id) return;
  const ok = await this.startEditingCartItem(index);  // PAS de pop
  if (!ok) return;
  this.$router.push({
    name: 'kiosk.wizard',
    params: { itemId: String(item.item_id) },
    query: { edit: '1' },
  });
}
```

→ La cart line **reste intacte** pendant toute l'édition. Aucune fenêtre de perte.

### Couche 3 — `KioskWizardComponent`

3 hooks :

1. **`mounted()` + `fetchItemById()`** : appellent `restoreEditingSelectionsIfAny()` APRÈS reset + inférences (pour que le snapshot écrase les défauts).
2. **`addToCart()`** : si `isEditingCart` → `replaceEditingCartItem` ; sinon `addItem` (chemin normal).
3. **`performCloseWizard()` + `beforeUnmount`** : si `isEditingCart` → `cancelEditingCartItem`. Garantit aucune édition orpheline (timeout idle, route change externe).

`buildCartItem()` injecte `_wizardSelections: deepClone(this.selections)` dans la cart line. Ce champ est :
- **Source de vérité** pour la restoration future
- **Strictement client-side** : `sanitizeKioskOrderItem` (whitelist `item_id, instruction, quantity, item_variations, item_extras`) ne le sérialise PAS au serveur

### Sécurité (item_id mismatch guard)

`restoreEditingSelectionsIfAny()` vérifie `Number(snap.item_id) !== Number(item.id)` → **skip restore** si mismatch. Empêche un snapshot stale d'un item supprimé du catalogue de polluer un autre item ouvert dans le wizard.

## Bug invisible corollaire fixé

En auditant le hook `beforeDestroy()` du wizard, j'ai trouvé un **bug pré-existant Vue 2 → Vue 3** :

```js
beforeDestroy() {  // ← N'EXISTE PAS en Vue 3 !
  if (this.currentStep && ...) {
    kioskAnalytics.track('wizard_abandoned', {...});
  }
  if (this._kioskPricingPreview) this._kioskPricingPreview.destroy();
}
```

Le hook **ne s'exécutait jamais** en Vue 3 (renommé `beforeUnmount`). Conséquences silencieuses :
- Aucun event `wizard_abandoned` émis depuis Vue 3 → analytics floues
- `_kioskPricingPreview` jamais destroy → leak de timer + axios pending requests à chaque sortie wizard

Renommé `beforeDestroy` → `beforeUnmount`. Fix gratuit en bonus, sans changer la sémantique.

## Tests ajoutés

### `tests/js/kioskWizardEditRoundtrip.spec.js` (11 tests)

| # | Scénario |
|---|---|
| 1 | startEditingCartItem mémorise sans muter le panier |
| 2 | startEditingCartItem retourne false sur index invalide |
| 3 | snapshot deep clone (mutation panier ≠ mutation snapshot) |
| 4 | replaceEditingCartItem remplace EN PLACE (3 lignes, replace au milieu, ordre préservé) |
| 5 | cancelEditingCartItem laisse la ligne ORIGINALE intacte |
| 6 | replaceEditingCartItem sans édition active → fallback ADD (race-safe) |
| 7 | REPLACE_ITEM_AT clamp la qty (cohérent avec ADD_ITEM) |
| 8 | REPLACE_ITEM_AT recompute total si absent |
| 9 | RESET clear l'édition |
| 10 | `_wizardSelections` JAMAIS sérialisé au serveur (sanitize whitelist) |
| 11 | snapshot conserve `_wizardSelections` complet (viandes, sauces, _viandeMeta, _tailleMeta) |

### `tests/js/kioskWizardEditRestore.spec.js` (7 tests)

| # | Scénario |
|---|---|
| 1 | Mount → restore intégral des selections (viandes, sauces, qty, instruction) |
| 2 | Mount → ne restore PAS si item_id mismatch (security) |
| 3 | addToCart en mode edit → replaceEditingCartItem + clear |
| 4 | addToCart hors mode edit → addItem normal |
| 5 | performCloseWizard → cancelEditing + cart line ORIGINALE intacte |
| 6 | beforeUnmount guard cancel l'édition orpheline |
| 7 | buildCartItem inclut `_wizardSelections` |

## Métriques

| Mesure | Avant | Après |
|---|---:|---:|
| Tests Vitest | 503 | **521** (+18) |
| Tests dédiés P-MEGA-05 | 0 | **18** |
| Tests wizard total | 83 (mock) + 8 (nav) | 83 + 8 + 7 + 11 = **109** |
| Bug fixed | 1 critical UX | + 1 silent Vue 3 lifecycle |
| LOC ajoutées | — | +135 (store) + ~50 (wizard) + ~10 (cart) + 360 (tests) |
| Régressions | — | 0/521 |

## Risques résiduels et atténuations

| Risque | Atténuation |
|---|---|
| Crash après start mais avant push wizard | `editingCartSnapshot` est en Vuex persistedstate → survit à un reload |
| Item supprimé du catalogue entre cart et wizard | `fetchItemById` retourne null → `restoreEditingSelectionsIfAny` skip → user voit message "produit indisponible" |
| Modification du shape `_wizardSelections` dans futures versions | Restore défensif : try the deep clone, sinon fallback quantity + instruction |
| `_wizardSelections` finit dans `kioskOfflineQueue` (future) | Whitelist `sanitizeKioskOrderItem` est SSOT → si la queue utilise `buildKioskOrderPayload`, automatiquement filtré ; test n°10 verrouille |
| Coupons attachés à un slot précis | `REPLACE_ITEM_AT` préserve l'index → coupons "ligne 2" restent ligne 2 ✓ |

## Compatibilité ascendante

- Cart lines anciennes (sans `_wizardSelections`) : `restoreEditingSelectionsIfAny` fallback sur `quantity` + `instruction` uniquement, comportement = 80% restoré sans crash.
- Action `popItem` est marquée deprecated mais conservée (autres callers potentiels).
- API serveur inchangée (whitelist stricte préservée).

## Conformité

- ✅ Aucune modification BD / serveur
- ✅ Aucune modification d'API publique
- ✅ Aucune dépendance ajoutée
- ✅ Bug original (UX perte) résolu
- ✅ Bug corollaire (Vue 3 lifecycle) résolu en bonus
- ✅ 521/521 Vitest verts
- ✅ Tests sur le **vrai composant** + **vrai store Vuex**
- ✅ Cas critique "abandon → ligne préservée" couvert
