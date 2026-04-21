# V14 #4 — T02 + T20 (FUSED) — `P14_POS_VARIATION_MULTI_QTY_UI` + `P14_DEFENSIVE_TYPE_NORMALIZATION`

## Header

```
TASK_ID: V14_04_T02_T20_POS_UI_MULTI_QTY_FUSED
WAVE: B — POS UI Multi-Quantity + Defensive Normalization
GATE_REFERENCE: aucun (UI uniquement, pas de zone gelée backend)
PRIMARY_MODEL: GPT-5.4 (foodking-complex-implementer)
RUNNER_MODE: single-session
PARALLEL_WITH: V14_05_T06_FORM_REQUEST_MULTI_QTY, V14_06_T04_FIXTURES_REPAIR_DRY_RUN
DEPENDS_ON: V14 Vague A déjà mergée + working tree (T01 schéma, T05 SSOT, T07 snapshot — tous validés et fixés). T20 fusionné avec T02 car ils touchent les MÊMES fichiers (posCart.js, ItemComponent.vue) → atomicité requise pour éviter conflits Git.
BLOCKS: T03 (parité POS↔Kiosk), T22 (E2E full flow)
SEVERITY: P0
EFFORT_EST: 5h
```

## Contexte

Le bug fonctionnel originel : **en POS un opérateur ne peut sélectionner qu'UNE viande pour un tacos `max_select=4 / allow_repeat=true`**, parce que `ItemComponent.vue` POS utilise `<select>` / `<radio>` single-select sur `temp.item_variations.variations[attrId] = varId`. Le Kiosk a déjà l'UI compteur multi-qté (`KioskStepViandeComponent.vue`).

Vague A a livré le **backend prêt** (schéma + pricing SSOT + snapshot NF525) qui accepte déjà le format `[{id, quantity}]` — il ne reste qu'à câbler le POS.

T20 est fusionné parce que normaliser les types `attrId / varId / itemId / branchId` (string DOM ↔ int DB) DOIT se faire dans les MÊMES fichiers que T02 (`posCart.js`, `ItemComponent.vue`, `PaymentComponent.vue`). Les exécuter séparément créerait des conflits Git inévitables.

## SUBSYSTEMS_TOUCHED

### T02 (UI multi-qty)
- `resources/js/components/admin/pos/ItemComponent.vue` (EDIT — bloc variations + bloc extras : compteur ± si `attribute.max_select > 1` OU `attribute.allow_repeat = true`, sinon radio legacy)
- `resources/js/store/modules/posCart.js` (EDIT — mutations `addItem` / `updateItemVariations` acceptent format `[{id, quantity}]`)
- `resources/js/components/admin/pos/PosComponent.vue` (EDIT — affichage panier : `3× Steak + 1× Poulet` au lieu d'une seule variation)

### T20 (type normalization helper)
- `resources/js/helpers/posNormalizeIds.js` (CREATE — helper exportant `normalizeId(any): number|null`, `normalizePayload(items): items`)
- Intégration dans `posCart.js`, `ItemComponent.vue`, `PaymentComponent.vue` (3 points d'entrée critiques)

### Tests
- `tests/js/posVariationMultiQty.spec.js` (CREATE — Vitest, 8 cas)
- `tests/js/posNormalizeIds.spec.js` (CREATE — Vitest, 6 cas edge)

## SUBSYSTEMS_OFF_LIMITS

- `app/Services/**` (Vague A frozen — déjà livrée et validée)
- `app/Http/Resources/**` (T07 territoire)
- `database/migrations/**` (Vague A territoire)
- `app/Http/Requests/**` (T06 territoire — autre subagent en parallèle)
- `database/seeders/**` (T04 territoire)
- `resources/js/components/frontend/kiosk/**` (Kiosk déjà multi-qty, ne pas régresser)
- Tout fichier `.cursor/skills/**`, `.cursor/rules/**`

## INVARIANTS_AT_RISK

1. **Backward-compat absolue** : si `attribute.max_select == 1 && !allow_repeat` → comportement radio identique à avant (pas de régression sur les 100+ produits single-select existants).
2. **Payload sortant doit matcher contrat SSOT** : `item_variations: [{id: int, quantity?: int}]`, `item_extras: [{id: int, quantity?: int}]` — VALIDÉ par les Form Requests T06 (en parallèle) et le PricingService (V1 déjà mergé).
3. **Pas de divergence avec Kiosk** : pour la même sélection logique (3 Steak + 1 Poulet), POS et Kiosk doivent produire un payload IDENTIQUE (côté backend ils résolvent au même prix).
4. **Le bouton "Ajouter au panier" doit être désactivé** si `total_qty < min_select` (UX métier).
5. **Modification depuis le panier** doit conserver les quantités multi-qty (pas de perte sur edit).
6. **Type safety cross-surface** : `posNormalizeIds.normalizeId('42') === 42`, `normalizeId('042') === 42`, `normalizeId('') === null`, `normalizeId(null) === null`, `normalizeId(NaN) === null`.

## TÂCHES À EXÉCUTER (ordre strict)

### Phase 1 — T20 helper (créer d'abord, indépendant)
1. CREATE `resources/js/helpers/posNormalizeIds.js` exportant :
   - `normalizeId(value: any): number | null` — `Number(value)` → si `NaN | <= 0 | !Number.isFinite` retourne `null`. Cas spéciaux : `''`, `null`, `undefined`, `'042'`.
   - `normalizeQuantity(value: any, fallback = 1): number` — `Math.max(1, Math.floor(Number(value) || fallback))`.
   - `normalizeVariationsPayload(arr): Array<{id: number, quantity: number}>` — map+filter sur ids invalides.
   - `normalizeExtrasPayload(arr): Array<{id: number, quantity: number}>` — idem.
2. CREATE `tests/js/posNormalizeIds.spec.js` couvrant 6+ cas : `'42'→42`, `42→42`, `'042'→42`, `''→null`, `null→null`, `'-1'→null`, `'abc'→null`, `NaN→null`, `1.5→1` (floor), `quantity '3'→3`, `quantity null + fallback 1→1`.
3. RUN `npx vitest run tests/js/posNormalizeIds.spec.js` → doit passer.

### Phase 2 — T02 store (posCart.js)
4. EDIT `resources/js/store/modules/posCart.js` :
   - Importer `normalizeVariationsPayload, normalizeExtrasPayload, normalizeId, normalizeQuantity` depuis `@/helpers/posNormalizeIds`
   - Le panier interne stocke désormais `item_variations: [{id, quantity}]` (au lieu de `{attrId: varId}` legacy).
   - Mutation `setItemVariations(item, attrId, varId, quantity)` :
     - Si `quantity === 0` → supprime la ligne `{id: varId}` du tableau.
     - Sinon upsert `{id: varId, quantity}` dans le tableau.
   - Getter `getVariationQuantity(item, varId)` retourne 0 si absent.
   - Getter `getAttributeTotalQuantity(item, attrId)` somme les quantités des variations de cet attribut.
   - Avant chaque appel API (création commande), passer `items` à un helper `normalizeCartForApi(items)` qui appelle `normalizeVariationsPayload` / `normalizeExtrasPayload`.
5. **CRITIQUE rétrocompat** : si un item dans le panier a un `item_variations` ancien format (`{attrId: varId}`), le convertir lazy via `migrateLegacyVariations(item)` au premier accès.

### Phase 3 — T02 UI (ItemComponent.vue)
6. EDIT `resources/js/components/admin/pos/ItemComponent.vue` :
   - Pour chaque `attribute` du produit, lire `attribute.max_select` (default 1), `attribute.min_select` (default 0), `attribute.allow_repeat` (default false).
   - **Branche A** (legacy, `max_select === 1 && !allow_repeat`) : conserver le bloc radio existant (zéro régression visuelle).
   - **Branche B** (multi-qty, `max_select > 1 || allow_repeat`) : afficher pour chaque variation un compteur `−  quantity  +`, avec :
     - Désactivation du `+` si `attributeTotalQuantity >= max_select`
     - Désactivation du `−` si `quantity === 0`
     - Badge compteur `total_qty / max_select` au-dessus du bloc
     - Couleur badge rouge si `total_qty < min_select`, vert si valide
   - Inspirer du style et accessibilité de `KioskStepViandeComponent.vue` (mais responsive POS desktop/tablet, pas touch-only).
   - Bouton "Ajouter au panier" `disabled` si **n'importe quel attribut** a `total_qty < min_select`.
   - Idem pour `item_extras` (même pattern).

### Phase 4 — T02 affichage panier
7. EDIT `resources/js/components/admin/pos/PosComponent.vue` (panier latéral / récap) :
   - Si `item_variations` est multi-qty, afficher `3× Steak, 1× Poulet, 1× Sauce Algérienne` (joindre par `, ` avec préfixe quantité si > 1).
   - Si legacy (single), affichage actuel inchangé.
   - Click sur ligne panier → ouvre la modal `ItemComponent` qui restore les quantités multi-qty.

### Phase 5 — Tests Vitest
8. CREATE `tests/js/posVariationMultiQty.spec.js` avec 8 cas :
   1. Add tacos avec 4× Steak (allow_repeat=true) → cart contient `item_variations: [{id: STEAK, quantity: 4}]`
   2. Add tacos avec 2× Steak + 2× Poulet → `[{id: STEAK, quantity: 2}, {id: POULET, quantity: 2}]`
   3. Add tacos avec 3× Steak + 1× Poulet → `[{id: STEAK, quantity: 3}, {id: POULET, quantity: 1}]`
   4. Bouton "Add" disabled si `total < min_select`
   5. Bouton `+` désactivé si `total === max_select`
   6. Decrement à 0 supprime la variation du tableau
   7. Open modal sur ligne panier multi-qty → quantités restaurées
   8. Legacy attribute (max_select=1) → radio comportement identique, payload `[{id: VAR}]` (pas de quantity, ou `quantity: 1`)
9. RUN `npx vitest run tests/js/posVariationMultiQty.spec.js` → 8/8 doit passer.

### Phase 6 — T20 intégration PaymentComponent
10. EDIT `resources/js/components/admin/pos/PaymentComponent.vue` :
    - Avant POST `/admin/order` : appeler `normalizeCartForApi(state.cart.items)` pour garantir des ids `Number`.

### Phase 7 — Régression & validation
11. RUN `npx vitest run` (full suite) → doit rester vert (535/535 actuellement).
12. RUN `php artisan test --filter='Pricing|OrderItem|FrontendOrder|PosOrder|ItemAttribute'` → 97/97 doit rester vert.

## ACCEPTANCE CRITERIA

- [ ] Tacos 4 viandes : opérateur peut sélectionner 4× Steak, ou 2+2, ou 3+1, ou 1×Steak+1×Poulet+1×Cordon+1×Nuggets
- [ ] Récap panier affiche `Tacos 4 viandes : 3× Steak, 1× Poulet`
- [ ] Modification panier conserve les quantités
- [ ] Payload sortant matche contrat SSOT V1 (`[{id, quantity}]`)
- [ ] Vitest 8/8 nouveaux + 6/6 normalize + suite complète verte (535+14 = 549)
- [ ] PHPUnit régression 97/97 vert (rien cassé backend)
- [ ] Aucune régression sur les produits single-select existants (max_select=1, !allow_repeat)
- [ ] `posNormalizeIds.spec.js` 6+ cas verts

## RUN_REPORT (à produire en fin de cycle)

`reports/execution/RUN_V14_T02_T20_POS_UI_MULTI_QTY_FUSED_2026-04-20.md`

Doit contenir :
- Diff résumé : fichiers touchés + count lignes
- Output `npx vitest run` (totaux + nouveaux tests)
- Output `php artisan test --filter=...` régression
- Captures DOM avant/après (si disponibles, sinon snapshots Vue Test Utils)
- Liste des assumptions (notamment sur la résolution des attributs côté frontend — sont-ils déjà servis par l'API menu ?)

## RISQUES & MITIGATIONS

| # | Risque | Mitigation |
|---|---|---|
| R1 | `posCart.js` mutations cassent les tests existants | Branche A (legacy) testée explicitement, fallback `migrateLegacyVariations` |
| R2 | `attribute.min_select`, `max_select`, `allow_repeat` pas exposés par l'API menu | Si absent, defaults `1, 1, false` (legacy mode), rapport doit signaler le besoin d'un T-extra pour exposer |
| R3 | Affichage panier casse sur orders historiques (item_variations legacy `{attrId: varId}`) | Détection format + fallback texte |
| R4 | `normalizeId` trop strict casse des cas réels (`'0'`, `'00042'`) | Spec couvre ces cas explicitement |

## NOTES AUDITEUR (Claude)

- Vague A est solide après le fix critique injection `composition_snapshot` SSOT path (commit `[V14 FUSED] ...` à venir).
- Vérifier en fin de cycle qu'on a bien aucune régression sur les tests `OrderItem|Pricing|FrontendOrder|PosOrder` qui couvrent le contrat backend.
- Si l'API menu n'expose pas encore `min_select / max_select / allow_repeat`, créer une mini-tâche follow-up `T02b — expose multi_select fields in menu API` (mais probablement déjà OK puisque T01 a ajouté les colonnes et l'API utilise probablement `Item::with('attributes')`).
