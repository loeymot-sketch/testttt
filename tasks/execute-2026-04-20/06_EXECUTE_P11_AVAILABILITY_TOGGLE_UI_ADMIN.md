# EXECUTE — P11_AVAILABILITY_TOGGLE_UI_ADMIN — 2026-04-20

## Status
**STATUS:** `READY_TO_LAUNCH`
**GATE_REQUIRED:** **NON** (UI bornée, backend déjà gated, aucune logique métier serveur)
**VAGUE:** V1 (parallélisable backend — plan §2 ligne 116)
**BLOCKING:** Aucun

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.1 ligne 40
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-19-01, F-VERIFY-01-06
- `reports/review/VERIFY_19_AVAILABILITY_TOGGLE_ROUTE_2026-04-20.md` §V7 verdict MISSING

## Constat factuel pré-cycle (vérifié read-only)

**Backend production-safe** (preuve VERIFY-19) :
- Route `POST /api/admin/menu/availability/toggle` (`routes/api.php:238-239`)
- `AvailabilityController::toggle(AvailabilityToggleRequest)` (`app/Http/Controllers/Admin/AvailabilityController.php:22+`)
- Permission Spatie `items_edit` + scope `branch_id` (controller L19, L29-35)
- FormRequest validé (item_id, branch_id?, is_available, unavailable_reason?)
- Event `ItemAvailabilityChanged::forBranch()` dispatché APRÈS commit (L63-72)
- Outbox + canal `private-branch.{id}` (`PersistItemAvailabilityChangedToOutbox:25-54`)
- Cache kiosk invalidé (`InvalidateKioskMenuCacheOnItemAvailabilityChanged`)
- Throttle `kiosk-menu` 60/min (`RouteServiceProvider:71-73`)

**Front UI absente** :
- `resources/js/components/admin/menu/` n'existe pas (Glob → 0 fichier)
- Aucun POST front vers `/api/admin/menu/availability/toggle` (grep dans `resources/` → 0 occurrence)
- `resources/js/components/admin/items/ItemListComponent.vue` existe (table item admin) — site potentiel d'intégration
- Consommateurs Echo lecture seule (kiosk, POS) → la chaîne propagation est prête, **manque uniquement l'émetteur côté Admin**

**Stratégie cycle V1** : créer un composant Vue **standalone et minimal** (preuve de concept opérationnelle) qui s'intègre dans `ItemListComponent.vue` (ajout colonne "Disponibilité" + bouton toggle) **sans réécrire** ItemListComponent. Approche additive, scope borné.

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `Composer` (AGENTS.md:16 — "bounded UI changes", no schema, no auth, no pricing)
- **SUBAGENT:** `foodking-routine-implementer`
- **RUNNER_MODE:** `single-session`

## Scope

### SUBSYSTEMS_TOUCHED
- `resources/js/components/admin/items/AvailabilityToggleComponent.vue` (nouveau composant standalone)
- `resources/js/components/admin/items/ItemListComponent.vue` (ajout 1 colonne + import — diff ≤ 15 lignes)
- `resources/js/store/modules/itemAvailability.js` (nouveau module Vuex minimal — toggle + Echo subscriber optionnel)
- `resources/js/store/index.js` ou équivalent (registration du module — diff ≤ 3 lignes)
- `resources/js/languages/fr.json` + `en.json` (ajout 4-6 clés i18n)
- `tests/js/adminAvailabilityToggle.spec.js` (nouveau test Vitest minimal)

### SCOPE_FILES (whitelist stricte)
- `resources/js/components/admin/items/AvailabilityToggleComponent.vue` (création)
- `resources/js/components/admin/items/ItemListComponent.vue` (édition minimale — ajout colonne)
- `resources/js/store/modules/itemAvailability.js` (création)
- `resources/js/store/modules/index.js` **OU** `resources/js/store/index.js` (à arbitrer après lecture — registration nouveau module)
- `resources/js/languages/fr.json` (ajout clés)
- `resources/js/languages/en.json` (ajout clés)
- `tests/js/adminAvailabilityToggle.spec.js` (création)
- `reports/execution/RUN_P11_AVAILABILITY_TOGGLE_UI_ADMIN_2026-04-20.md` (append rapport)

### SUBSYSTEMS_OFF_LIMITS (strict)
- **TOUT le backend** : `app/`, `database/`, `routes/`, `config/`, `tests/Feature/`, `tests/Unit/`, `tests/Playwright/`, `tests/e2e/`
- Autres composants admin (`resources/js/components/admin/items/Item{Create,Show,Upload}Component.vue` etc. — sauf `ItemListComponent.vue` strict ajout colonne)
- `resources/js/components/kiosk/**`, `resources/js/components/frontend/**`
- `resources/js/services/eventContract.js` (déjà câblé, ne pas toucher)
- `resources/js/router/**`, `resources/js/routes.js` (pas de nouvelle route SPA)
- `webpack.mix.js`, `package.json`, `package-lock.json`
- `docs/`, `.cursor/`, `plans/`, autres `tasks/`
- `master.blade.php` ou autres fichiers Blade

## Invariants at Risk
- **Aucun invariant backend touché** (UI consume l'API existante, pas de nouvelle logique serveur)
- **Risque UI uniquement** :
  - **branch_id** : le composant doit envoyer le bon `branch_id` (selon contexte user). Lire la convention dans `ItemListComponent.vue` ou store user.
  - **Permission `items_edit`** : afficher le bouton uniquement si `permissionChecker('items_edit')` (pattern existant dans `ItemListComponent.vue:18`).
  - **i18n** : 2 langues minimum (fr + en), ne pas casser les autres langues présentes.

## Dependencies
- Aucune (cycle indépendant, parallélisable avec PLAYWRIGHT_THROTTLE_FIX)

## Plan bref

### Étape 1 — Lire (vérité terrain — leçon cycle 05)
- `resources/js/components/admin/items/ItemListComponent.vue` (intégral — 470 lignes — comprendre pattern table, fetch, Vuex usage, permissionChecker)
- `resources/js/store/modules/` listing → identifier convention (axios path, fetch, store.dispatch pattern)
- `resources/js/store/index.js` ou `resources/js/store/modules/index.js` → identifier le pattern de registration de modules
- `resources/js/services/eventContract.js` (juste pour comprendre comment les autres consomment l'event Echo `ItemAvailabilityChanged` — ne pas modifier)
- `resources/js/languages/fr.json` & `en.json` (head 50 lignes pour comprendre structure clés)
- `tests/js/posItemAvailabilityHandler.spec.js` (existant — pattern test Vitest pour availability handler)

### Étape 2 — Créer `resources/js/store/modules/itemAvailability.js`

Module Vuex minimal :
```js
import axios from 'axios';
export default {
    namespaced: true,
    state: () => ({ pending: {}, lastError: null, lastConflictId: null }),
    mutations: {
        SET_PENDING(state, { itemId, value }) { state.pending = { ...state.pending, [itemId]: value }; },
        SET_ERROR(state, msg) { state.lastError = msg; },
        SET_CONFLICT(state, itemId) { state.lastConflictId = itemId; },
    },
    actions: {
        async toggle({ commit }, { itemId, branchId, isAvailable, unavailableReason }) {
            commit('SET_PENDING', { itemId, value: true });
            try {
                const res = await axios.post('admin/menu/availability/toggle', {
                    item_id: itemId,
                    branch_id: branchId,
                    is_available: isAvailable,
                    unavailable_reason: unavailableReason || null,
                });
                return res.data;
            } catch (err) {
                commit('SET_ERROR', err?.response?.data?.message || err.message);
                throw err;
            } finally {
                commit('SET_PENDING', { itemId, value: false });
            }
        },
    },
};
```

### Étape 3 — Enregistrer le module dans le store
Ajouter dans `resources/js/store/index.js` (ou équivalent) :
```js
import itemAvailability from './modules/itemAvailability';
// ... dans modules: { ..., itemAvailability }
```
(2-3 lignes seulement)

### Étape 4 — Créer `AvailabilityToggleComponent.vue`

Composant minimaliste (props: `itemId`, `branchId`, `isAvailable`, `unavailableReason?`) :
- Bouton/toggle visuel (utilise classes Tailwind du projet)
- Sur clic → `store.dispatch('itemAvailability/toggle', {...})`
- État loading via `store.state.itemAvailability.pending[itemId]`
- Émet `availability-changed` au parent pour refresh local
- Affichage badge "rupture" + raison si !isAvailable

### Étape 5 — Intégrer dans `ItemListComponent.vue`
Ajout minimal :
- Import `AvailabilityToggleComponent`
- Ajouter une colonne `<th>{{ $t('label.availability') }}</th>` dans le `<thead>`
- Ajouter une cellule `<td><AvailabilityToggleComponent :item-id="item.id" :branch-id="..." :is-available="item.is_available ?? true" /></td>`
- **Garder le composant invisible (v-if="permissionChecker('items_edit')")** par défense en profondeur

### Étape 6 — i18n
`fr.json` + `en.json` ajouter :
```json
"availability": "Disponibilité" / "Availability",
"available": "Disponible" / "Available",
"out_of_stock": "Rupture" / "Out of stock",
"toggle_unavailable": "Marquer indisponible" / "Mark unavailable",
"toggle_available": "Marquer disponible" / "Mark available",
"availability_changed": "Disponibilité mise à jour" / "Availability updated"
```

### Étape 7 — Test Vitest minimal
`tests/js/adminAvailabilityToggle.spec.js` :
- Stub axios → 200 OK
- Mount AvailabilityToggleComponent
- Click bouton → assert axios appelé avec bon payload
- Stub 403 → assert error stored in Vuex
- 2-3 tests max

### Étape 8 — Rapport
`reports/execution/RUN_P11_AVAILABILITY_TOGGLE_UI_ADMIN_2026-04-20.md` avec gabarit Final report + diff résumé + sortie vitest.

## Acceptance Tests
- [ ] `npx vitest run tests/js/adminAvailabilityToggle.spec.js` → tous tests verts
- [ ] `git diff` strictement dans SCOPE_FILES (vérifié `git status --short`)
- [ ] `resources/js/components/admin/items/AvailabilityToggleComponent.vue` créé (≤ 100 lignes)
- [ ] `resources/js/store/modules/itemAvailability.js` créé (≤ 50 lignes)
- [ ] `ItemListComponent.vue` diff ≤ 15 lignes (ajout colonne uniquement)
- [ ] i18n fr+en synchronisés (mêmes clés présentes)
- [ ] `permissionChecker('items_edit')` utilisé (defense in depth)

## Exit Criteria
- [ ] Composant Vue fonctionnel (testable via Vitest)
- [ ] Module Vuex enregistré et appelable
- [ ] Pas de toucher backend
- [ ] Pas de toucher autres surfaces (kiosk, POS, frontend client)
- [ ] `reports/execution/RUN_P11_AVAILABILITY_TOGGLE_UI_ADMIN_2026-04-20.md` avec Final report

## Scope Pressure Protocol (renforcé)
**STOP IMMÉDIAT** si :
- Toucher `app/`, `database/`, `routes/`, `config/`, `tests/Feature/` → SCOPE_PRESSURE
- Modifier eventContract.js (déjà câblé) → SCOPE_PRESSURE
- Ajouter route Vue Router (nouvelle page admin/menu/availability) → SCOPE_PRESSURE (scope V1 = intégration table existante uniquement, page dédiée = V2)
- Modifier `package.json` ou installer dépendance → SCOPE_PRESSURE
- Diff `ItemListComponent.vue` > 30 lignes → SCOPE_PRESSURE (refactor non prévu)
- Toucher d'autres composants admin/items que ItemListComponent.vue → SCOPE_PRESSURE
- Touche aux Blades, layout, mix manifest → SCOPE_PRESSURE
- Si l'UI exige un sous-component sélecteur de branche (`branch_id` admin global = 0) → utiliser le store user existant ou défaut `null` pour fan-out scope ; si vraiment besoin d'un sélecteur → STOP + escalade (out of V1 scope)
- **Anti-pattern** : `git checkout` pour masquer un diff → STOP + escalade

## Remediation
- Attempt 1 KO (test rouge, vue compilation) → diagnostic + replan
- Attempt 2 KO → analyse Vuex registration / store path
- Attempt 3 même `bug_signature` → HUMAN_GATE

## Deliverables
- 3 fichiers neufs (1 Vue, 1 Vuex module, 1 test Vitest)
- 3 fichiers édités minimal (ItemListComponent, store index, fr.json + en.json)
- `reports/execution/RUN_P11_AVAILABILITY_TOGGLE_UI_ADMIN_2026-04-20.md`

## Communication
Subagent renvoie : liste fichiers + diff stat par fichier, sortie vitest (3+ tests verts), output `git status --short` (preuve scope respect).
