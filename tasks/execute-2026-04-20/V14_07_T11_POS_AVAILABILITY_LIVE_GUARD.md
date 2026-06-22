# V14 #7 — T11 — `P14_POS_AVAILABILITY_LIVE_GUARD`

## Header

```
TASK_ID: V14_07_T11_POS_AVAILABILITY_LIVE_GUARD
WAVE: C-α — Finalisation caisse opérateur (sub-vague α)
GATE_REFERENCE: aucun (UI guard, pas de zone gelée NF525)
PRIMARY_MODEL: Composer (foodking-routine-implementer)
RUNNER_MODE: single-session
PARALLEL_WITH: V14_08_T10_POS_SEARCH_BARCODE, V14_09_T08_POS_PARK_HOLD_RECALL
DEPENDS_ON: V1 (pruneUnavailable déjà OK), V14 Vague A+B (terminé)
SEVERITY: P1
EFFORT_EST: 2-3h
```

## Contexte

Le V1 a couvert le **pruning panier post-broadcast** (`pruneUnavailable` dans `posCart.js`). Le PosComponent souscrit déjà à `ItemAvailabilityChanged` via Echo et met `is_available` à jour dans `itemsRaw`. **MAIS** :

1. La **grille de tuiles produits** n'a pas de désactivation visuelle quand `is_available === false` → l'opérateur peut cliquer un item indispo et ouvrir le modal.
2. Le **modal `ItemComponent`** ne tient pas compte de `is_available` dans `canAddToCart` → le bouton "Ajouter au panier" reste actif si l'item devient indispo pendant la saisie.
3. **Aucun toast / feedback** quand un item devient indispo pendant l'édition → l'opérateur le découvre uniquement lors du checkout (422).

T11 ferme cette boucle UX : gel visuel grille + gel bouton modal + toast informatif + suggestion alternative (variations restantes).

## SUBSYSTEMS_TOUCHED

- `resources/js/components/admin/pos/PosComponent.vue` (EDIT — étendre `_onItemAvailabilityChanged` pour émettre un toast + propager au modal ouvert ; ajouter classe CSS `is-unavailable` sur tuiles dont `item.is_available === false`)
- `resources/js/components/admin/pos/ItemComponent.vue` (EDIT — étendre `canAddToCart` pour inclure `props.item.is_available !== false` ; ajouter écoute prop `frozenItemId` ou réactivité sur `is_available`)
- `resources/js/store/modules/posCart.js` (EDIT minimal — ajouter état `frozenItemIds: []` + mutation `freezeItem` / `unfreezeItem` ; OU exposer juste un getter qui consulte le catalogue)
- `tests/js/posItemAvailabilityHandler.spec.js` (EDIT ou EXTEND — ajouter cas "item devient indispo pendant modal ouvert")
- `tests/js/posAvailabilityLiveGuard.spec.js` (CREATE — sentinel dédié T11)

## SUBSYSTEMS_OFF_LIMITS

- `app/Events/ItemAvailabilityChanged.php` (frozen — backend contrat OK)
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` (frozen)
- `app/Services/Pricing/PricingService.php` (frozen)
- `resources/js/components/admin/pos/PaymentComponent.vue` (frozen — fix B-6 récent)
- Composants Kiosk (parité déjà OK via cycle V1)
- Toute migration / changement de schéma (out of scope pure UI guard)

## INVARIANTS_AT_RISK

1. **Backward compat `is_available` absent** : si la prop n'existe pas (legacy item), considérer disponible (`true`).
2. **Pas de double-toast** : si plusieurs broadcasts arrivent dans la même seconde, debounce le toast (1 toast par item, pas N).
3. **Modal ouvert sur item indispo** : ne PAS fermer brutalement, mais geler le bouton + bandeau d'info (l'opérateur peut consulter / fermer manuellement).
4. **Pruning panier déjà actif** : ne PAS dupliquer le mécanisme de `pruneUnavailable` ; T11 = couche UI seulement.
5. **i18n FR/EN/AR** : tout texte ajouté doit avoir une clé i18n.
6. **Pas de régression `posItemAvailabilityHandler.spec.js`** : suite existante doit rester verte.

## TÂCHES À EXÉCUTER

### 1. Étendre `_onItemAvailabilityChanged` dans `PosComponent.vue`

Localiser la méthode (probablement vers la souscription Echo). Après mise à jour de `is_available`, si `is_available === false` :
- Émettre un toast informatif via `this.$toast` (ou helper existant) : "« {nom} » n'est plus disponible".
- Si l'item est actuellement ouvert dans le modal (via `this.$store.state.itemDetails` ou ref équivalente), notifier le composant enfant via prop ou event bus.
- Debouncer : maintenir `this._availabilityToastDebounce = {}` pour éviter spam.

### 2. Désactiver visuellement les tuiles indispo

Dans le `<template>` de la grille produits, ajouter :
```vue
<div :class="{ 'pos-item-tile': true, 'is-unavailable': item.is_available === false }"
     :aria-disabled="item.is_available === false"
     @click="item.is_available === false ? null : openItemModal(item)">
  <span v-if="item.is_available === false" class="pos-item-86-badge">{{ $t('pos.item_86_d') }}</span>
  ...
</div>
```

CSS minimal (inline ou fichier dédié) : `opacity:0.45; pointer-events:none; filter:grayscale(0.7);` + badge "Épuisé" coin haut-droit.

### 3. Étendre `canAddToCart` dans `ItemComponent.vue`

Localiser le `computed canAddToCart`. Modifier :
```js
canAddToCart() {
    const baseOk = /* logique existante : prix + erreurs sélection */;
    const available = this.props && this.props.item
        ? (this.props.item.is_available !== false)
        : true;
    return baseOk && available;
}
```

Ajouter sous le bouton "Ajouter au panier", si non disponible :
```vue
<div v-if="props.item && props.item.is_available === false" class="alert alert-warning mt-2" role="status">
  {{ $t('pos.item_unavailable_during_edit') }}
</div>
```

### 4. Mutation Vuex (optionnelle, only if needed)

Si la propagation grille→modal nécessite un canal explicite (pas suffisant via prop reactive), ajouter dans `posCart.js` :
```js
state: { frozenItemIds: [] },
mutations: {
  FREEZE_ITEM(state, id) { if (!state.frozenItemIds.includes(id)) state.frozenItemIds.push(id); },
  UNFREEZE_ITEM(state, id) { state.frozenItemIds = state.frozenItemIds.filter(x => x !== id); },
},
```
Utiliser uniquement si la voie reactive prop ne marche pas.

### 5. i18n

Ajouter dans `resources/js/languages/fr.json`, `en.json`, `ar.json` :
```json
{
  "pos.item_86_d": "Épuisé",
  "pos.item_unavailable_during_edit": "Cet article vient de devenir indisponible. Demandez à un manager ou choisissez un autre produit."
}
```
EN : "Sold out" / "This item just became unavailable. Ask a manager or pick another product."
AR : "نفذ" / "أصبح هذا المنتج غير متاح للتو. اطلب من المدير أو اختر منتجًا آخر."

### 6. Tests Vitest sentinel

CREATE `tests/js/posAvailabilityLiveGuard.spec.js` couvrant 5 cas :
1. Tuile dispo → click ouvre modal normalement.
2. Tuile indispo → classe CSS `is-unavailable` + click ne déclenche pas le modal.
3. Modal ouvert avec item dispo → bouton "Ajouter" enabled.
4. Modal ouvert + simulate `is_available` flip à false → bouton "Ajouter" disabled + bandeau visible.
5. Backward compat : `item.is_available === undefined` → considéré dispo.

EDIT `tests/js/posItemAvailabilityHandler.spec.js` : ajouter 1 cas "after broadcast, modal still open with frozen button" (ne pas casser les tests existants).

### 7. Régression

```bash
npx vitest run tests/js/posItemAvailabilityHandler.spec.js \
  tests/js/posAvailabilityLiveGuard.spec.js \
  tests/js/posCart.spec.js \
  tests/js/posCartScoped.spec.js \
  tests/js/PosComponent.spec.js
```
→ Tous verts (zéro régression).

## ACCEPTANCE

- [ ] Tuiles grille POS : désactivation visuelle + classe CSS si `is_available === false`
- [ ] Modal `ItemComponent` : bouton "Ajouter" disabled + bandeau d'info si item indispo
- [ ] Toast émis sur broadcast `ItemAvailabilityChanged` quand item passe indispo (avec debounce)
- [ ] i18n FR + EN + AR pour les 2 nouvelles clés
- [ ] Sentinel `posAvailabilityLiveGuard.spec.js` créé : 5/5 verts
- [ ] Suite POS existante : 0 régression
- [ ] Backward compat : items sans `is_available` traités comme dispo

## RUN_REPORT

`reports/execution/RUN_V14_T11_POS_AVAILABILITY_LIVE_GUARD_2026-04-20.md`

Doit contenir : diff complet, résultat Vitest, screenshot mental flow (texte) du parcours grille→tuile indispo→modal ouvert→broadcast→gel.

## NOTES AUDITEUR

- Le pruning panier (`pruneUnavailable`) **ne doit pas être touché** — T11 vient en amont (UI), pas en aval (panier).
- Si l'app n'a pas de helper toast standard, utiliser `console.warn` + commentaire `TODO: standardize toast` plutôt que d'introduire une nouvelle lib.
- Vérifier la prop `props.item` n'est pas reactive-broken par Vue 3 si le composant est en Composition API (utiliser `toRefs` si nécessaire).
- Si `is_available` est typé comme `0/1` dans certains payloads, étendre la garde : `item.is_available === false || item.is_available === 0`.
