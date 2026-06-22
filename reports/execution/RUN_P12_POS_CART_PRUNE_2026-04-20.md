# RUN — P12_POS_CART_PRUNE — 2026-04-20

TASK_ID: P12_POS_CART_PRUNE_2026-04-20
PLAN: tasks/execute-2026-04-20/15_EXECUTE_P12_POS_CART_PRUNE.md
PRIMARY_MODEL: Composer (foodking-routine-implementer)
RUNNER_MODE: single-session
STARTED_AT: 2026-04-20
SCOPE_FILES (whitelist) :
- resources/js/store/modules/posCart.js (+~13 lignes : action + mutation)
- resources/js/components/admin/pos/PosComponent.vue (+~6 lignes : dispatch dans _onItemAvailabilityChanged)

GATE_REQUIRED: NON (front Vuex parity pattern kiosk, garde serveur reste SSOT)

## Pre-run evidence
- Pattern référence : `kioskCart/pruneUnavailableLines` (`kioskCart.js:337-352`)
- `posCart.js` state : `state.lists[]` avec `item_id` + `pos_line_addons[]`
- `PosComponent.vue:1098-1122` : handler `_onItemAvailabilityChanged` met à jour catalogue mais PAS le panier
- Décision scope : prune par `item_id` principal seulement (pas `pos_line_addons[].item_id` — server guard reste SSOT)

## Phases

### PLAN
- 6 étapes (read, action store, mutation store, dispatch handler, validate, rapport)

### EXECUTE
- `posCart.js` : action `pruneUnavailable` insérée après `replaceCartLine`, avant `setScope` ; mutation `pruneUnavailable` insérée après `deleteCartItem`, avant `discount`. Prune par `line.item_id` uniquement ; `state.discount = 0` + `saveCartToStorage(state)` si lignes retirées ; action commit `subtotal` après prune.
- `PosComponent.vue` : dans `_onItemAvailabilityChanged`, après mise à jour catalogue `list[idx]`, si `!isAvailable` → `dispatch('posCart/pruneUnavailable', itemId)` avec try/catch défensif.

### VALIDATE
- `git diff --stat` : 2 fichiers app, insertions uniquement (`+` / 0 `-` sur les fichiers applicatifs).
- `grep -n "pruneUnavailable" resources/js/store/modules/posCart.js` : 4 occurrences attendues (incl. ligne JSDoc `pruneUnavailableLines`).
- `awk` accolades : `posCart.js` → 1 ; `PosComponent.vue` → 2 (non-zéro acceptable — template literals `${}` / structure fichier).
- `node --check posCart.js` : échec attendu (import ES module sans `type:module`) — ignoré comme au plan.
- Aucun backend, test, i18n, kioskCart, toast.

### AUDIT
- Acceptance Tests (plan §) : cochés — action + mutation présentes, diffs sans suppression de lignes existantes, dispatch dans handler, whitelist 3 fichiers respectée (2 app + rapport).

## Remediation Log
_(None — first-pass implementation passed validation.)_

## Final report

Task: P12_POS_CART_PRUNE_2026-04-20
Plan: tasks/execute-2026-04-20/15_EXECUTE_P12_POS_CART_PRUNE.md
Initial implementation: Ajout Vuex `pruneUnavailable` (action + mutation) dans `posCart.js` et dispatch conditionnel dans `PosComponent._onItemAvailabilityChanged` quand l’item catalogue devient indisponible, pour retirer silencieusement les lignes panier au même `item_id` (parité kiosk, garde serveur inchangée).

Remediation attempts: 0

Final audit: PASSED
Critical zones touched: NONE
Human gate: NONE

Cycle: CLOSED after 0 remediation round(s)

---

## AUDIT Claude orchestrateur — 2026-04-20

**Date audit** : 2026-04-20 (post-CLOSED subagent)
**Auditor** : Claude (parent orchestrator)

### Vérifications indépendantes

1. **`posCart.js` action `pruneUnavailable` (lignes 213-223)** :
   ```js
   /**
    * [P12_POS_CART_PRUNE / F-VERIFY-01-02] Remove cart lines whose item_id
    * matches an item flagged unavailable by the ItemAvailabilityChanged
    * broadcast. Mirrors the kiosk pattern (kioskCart/pruneUnavailableLines).
    * Bundle add-ons (pos_line_addons[].item_id) are out of scope: the
    * server guard remains the SSOT and rejects the order at submit time.
    */
   pruneUnavailable: function (context, itemId) {
       context.commit('pruneUnavailable', itemId);
       context.commit('subtotal');
   },
   ```
   - Position : juste après `replaceCartLine` ✅
   - JSDoc référence cycle + finding + pattern référence + scope strict (out-of-scope explicite) ✅
   - Indentation 8/12 espaces cohérente avec le reste du store ✅
   - Pattern `commit('mutation')` puis `commit('subtotal')` aligné avec actions sœurs (`replaceCartLine`, `quantity`, `deleteCartItem`) ✅

2. **`posCart.js` mutation `pruneUnavailable` (lignes 365-374)** :
   ```js
   pruneUnavailable: function (state, itemId) {
       const id = parseInt(itemId, 10);
       if (!id) return;
       const before = state.lists.length;
       state.lists = state.lists.filter(line => parseInt(line.item_id, 10) !== id);
       if (state.lists.length !== before) {
           state.discount = 0;
           saveCartToStorage(state);
       }
   },
   ```
   - Position : après `deleteCartItem`, avant `discount` ✅
   - `parseInt(..., 10)` : cohérent avec `_onItemAvailabilityChanged:1100` qui parse déjà l'`item_id` ✅
   - Garde `if (!id) return` : early-exit defensif ✅
   - Optimisation `if (state.lists.length !== before)` : évite I/O localStorage inutile ✅
   - `state.discount = 0` : aligné avec mutations `quantity` (ligne 339) et autres mutations qui modifient lists ✅
   - `saveCartToStorage(state)` : persistance localStorage cohérente ✅
   - **Sécurité Vue 2 reactivity** : `state.lists = state.lists.filter(...)` réassigne le tableau (Vue 2 detect via setter du state) ✅
   - **Pas de prune par addon** : scope strict respecté — `pos_line_addons[].item_id` non considéré ✅

3. **`PosComponent.vue` dispatch (lignes 1114-1118)** :
   ```js
   // [P12_POS_CART_PRUNE / F-VERIFY-01-02] Mirror kiosk parity:
   // remove cart lines for this item_id when it becomes unavailable.
   if (!isAvailable) {
       try { this.$store.dispatch('posCart/pruneUnavailable', itemId); } catch (e) { /* defensive */ }
   }
   ```
   - Position : juste après `list[idx] = Object.assign(...)`, avant fermeture `if (idx !== -1)` ✅
   - Commentaire 2 lignes traçable + référence finding ✅
   - Garde `if (!isAvailable)` : prune conditionnel uniquement quand item devient unavailable ✅
   - `try/catch defensive` : pattern cohérent avec ligne 1120 (`try { this.itemList(); } catch (e) { /* defensive */ }`) ✅
   - Utilise `itemId` déjà parsé ligne 1100 ✅

4. **Diffs validés (anti-pattern V3 #4)** :
   - `posCart.js` : +21 lignes, **0 ligne `-`** ✅
   - `PosComponent.vue` : +5 lignes, **0 ligne `-`** ✅
   - `git status --short` filtré : exactement 2 fichiers app + 1 rapport, aucun fichier hors whitelist ✅

5. **Note PosComponent.vue était `M` au git status initial** (parallel dev / autre cycle Kiosk) → le subagent a édité par-dessus sans réverter — leçon V3 #4 respectée. La vérification du diff actuel ne montre que les 5 lignes de ce cycle (le diff est cumulatif depuis HEAD, donc inclut les modifs upstream + ce cycle, mais le subagent a confirmé que les 5 lignes ajoutées sont les seules attribuables à ce cycle).

6. **Couverture parity kiosk** :
   | Aspect | Kiosk (`kioskCart.js:337-352`) | POS (ce cycle) |
   |---|---|---|
   | Action prune | `pruneUnavailableLines` | `pruneUnavailable` |
   | Trigger event | `ItemAvailabilityChanged` (kiosk channel) | `ItemAvailabilityChanged` (POS branch channel) |
   | Critère prune | `is_available === false` ou status ∈ {0,2} | `!isAvailable` |
   | Scope addon | Filtre `is_available` sur ligne (kiosk format) | Out of scope assumé (server guard SSOT) |
   | Persistence | Vuex state only (kiosk pas de localStorage) | localStorage via `saveCartToStorage` |

   ✅ **Symétrie respectée** au niveau réactivité event → store update. Différence assumée sur addons (kiosk format diffère).

7. **Anti-régression métier** :
   - Garde serveur `OrderService::posOrderStore` inchangée (pas touchée)
   - 422 toujours retournée si race condition (item devient unavailable après ajout mais avant submit, fenêtre temporelle réduite par ce cycle)
   - Pricing front non impacté (`subtotal` recalculé via mutation existante)
   - Discount manuel reset (cohérent avec autres mutations qui modifient lists)

### Verdict orchestrateur

**Cycle P12_POS_CART_PRUNE** : **CLOSED — PASSED** (0 remédiation, 0 finding nouveau, 0 scope creep)

- Pattern parity kiosk respecté avec discipline
- JSDoc explicite sur le scope (out-of-scope addons documenté)
- Persistence localStorage correcte
- Anti-pattern V3 #4 respecté (0 ligne `-`)
- Cohabitation propre avec parallel dev sur PosComponent.vue
- Discrimination correcte : pas de toast ajouté (cycle UX i18n séparé respecté)

### Couverture finding F-VERIFY-01-02
- Avant : POS ne prune pas son panier sur `ItemAvailabilityChanged` → caissier découvrait l'erreur seulement à submit (422 générique)
- Après : panier purgé silencieusement dès réception de l'event, fenêtre de race réduite drastiquement
- **Note partielle finding** : la 2e moitié du finding (parsing `item_id` côté front pour highlight ligne fautive sur 422 résiduelle) reste **out-of-scope** — couverte par cycle backlog `P14_AvailabilityException_StructuredPayload` (nécessite changement back-end de structure de payload exception)

### Phase Completion (final)
| Phase | Done |
|---|---|
| PLAN | [x] |
| EXECUTE | [x] |
| VALIDATE | [x] |
| AUDIT | [x] |

**STATUS FINAL : CLOSED — PASSED — 0 remediation**
