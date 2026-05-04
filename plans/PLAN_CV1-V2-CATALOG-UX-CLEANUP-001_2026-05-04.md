# PLAN — Catalog Studio UX Cleanup 001

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V2-CATALOG-UX-CLEANUP-001` |
| Date | 2026-05-04 00:40 UTC+2 |
| Auteur | Claude (orchestrateur — décisions techniques déléguées) |
| Précédents | `CV1-V2-CATALOG-BETA1-FRONTEND-001` (CLOSED) |
| RUNNER_MODE | single-session |
| PHASE | EXECUTE |
| EXECUTION_TIER | mixed (N=complex bug data flow, O=routine nav cleanup) |
| EXECUTE_DELEGATION | sub-agent `foodking-complex-implementer` (N) + `foodking-routine-implementer` (O) parallèles |

---

## 0. TL;DR mission

Corriger 3 problèmes UX/bug remontés par l'utilisateur après tour manuel du dashboard :
1. **P0 bug fonctionnel** : Toggle "Marquer indisponible" ne reflète pas l'état après clic (silencieux).
2. **UX cleanup** : "Attributs d'articles" affiché sous Paramètres alors qu'il appartient au domaine Articles → cacher de Paramètres, garder via Articles.
3. **UX cleanup** : Page legacy `ItemCategoryShowComponent` (fiche vide redondante avec Catalog Studio) → rediriger vers Studio.

---

## 1. Diagnostic technique racine (Problème 1)

### Cause vraie

```
[Toggle clic UI] → POST /api/admin/menu/availability/toggle
                    → AvailabilityController::toggle()
                    → ItemBranchAvailability updated (par branch_id)
                    → 200 OK ✅

[Reload liste UI] → store/modules/item.js::lists()
                    → axios.get('/admin/items') SANS branch_id ❌
                    → ItemService::applyBranchAvailabilityOverlay()
                       n'exécute pas (condition: branch_id >= 1 absent)
                    → SimpleItemResource utilise items.is_available (colonne globale)
                    → UI affiche ancien état ❌
```

### Référence code (sub-agent doit relire)

- `resources/js/components/admin/items/AvailabilityToggleComponent.vue` L34-46 — `catch{}` vide qui avale les erreurs.
- `resources/js/store/modules/itemAvailability.js` L18-27 — POST OK.
- `resources/js/store/modules/item.js` L35-41 — action `lists()` qui n'envoie pas `branch_id`.
- `app/Services/ItemService.php` L160-164 — `applyBranchAvailabilityOverlay()` conditionnée à `$request->get('branch_id') >= 1`.
- `app/Http/Resources/SimpleItemResource.php` L22-25 — fallback sur `items.is_available` global si pas d'overlay.
- `resources/js/store/modules/auth.js` — où trouver `branch_id` du user admin connecté.

---

## 2. Diagnostic Problème 2 — Attributs mal placés

- `resources/js/components/admin/settings/MenuComponent.vue` L85-88 : router-link "Attributs d'articles" SANS `v-if="!isSettingHidden(...)"`.
- `resources/js/components/admin/settings/MenuComponent.vue` L131-143 : mapping `HIDDEN_KEY_TO_LOCAL_SETTING` à étendre.
- `resources/js/config/v1-hidden-modules.js` L27 : ajouter `'settings.item-attributes'`.
- `resources/js/components/layouts/backend/BackendMenuComponent.vue` L86-90 : déjà câblé sous Articles → vérifier seulement.

## 3. Diagnostic Problème 3 — ItemCategoryShow page legacy

- `resources/js/components/admin/settings/ItemCategory/ItemCategoryShowComponent.vue` : composant minimal, 1 carte read-only.
- `resources/js/router/modules/settingRoutes.js` L354-363 : route `admin.settings.itemCategory.show` à rediriger vers `admin.items.studio`.
- Vérifier `ItemCategoryListComponent.vue` pour bouton "voir" → soit cacher, soit le faire pointer vers Studio.

---

## 4. SUBSYSTEMS_TOUCHED

| Subsystem | Files | Read/Write |
|---|---|---|
| **Vuex item store** | `resources/js/store/modules/item.js` | WRITE (passer branch_id dans lists() action) |
| **AvailabilityToggle UI** | `resources/js/components/admin/items/AvailabilityToggleComponent.vue` | WRITE (surfacer erreurs au lieu de catch silencieux) |
| **Auth helper** | `resources/js/store/modules/auth.js` | READ (récupérer branch_id user) |
| **Settings menu** | `resources/js/components/admin/settings/MenuComponent.vue` | WRITE (cacher attributs) |
| **Hidden modules config** | `resources/js/config/v1-hidden-modules.js` | WRITE (ajouter clé) |
| **Settings routes** | `resources/js/router/modules/settingRoutes.js` | WRITE (rediriger ItemCategoryShow) |
| **Item category list** | `resources/js/components/admin/settings/ItemCategory/ItemCategoryListComponent.vue` | WRITE (bouton voir → studio) |
| **Tests Vitest** | `tests/js/itemListBranchAvailability.spec.js` (NEW), `tests/js/v1HiddenMenuModules.spec.js` (UPDATE), `tests/js/itemCategoryShowRedirect.spec.js` (NEW), `tests/js/availabilityToggleErrorSurfacing.spec.js` (NEW) | WRITE |
| **Test PHPUnit** | éventuellement test backend pour confirmer overlay | WRITE si pertinent |

## SUBSYSTEMS_OFF_LIMITS

- `app/` (sauf si ajouter test) — backend déjà correct, c'est le frontend qui ne l'appelle pas correctement.
- Frozen zones (Pricing, Order Lifecycle, Fiscal).
- Tous les composants/services Composer (cycle β1 récemment livré).

## GATE_CONDITIONS

- Aucun gate anticipé.
- C2 (branch overrides matrice) reste **en attente de réponse utilisateur** : sa question business "as-tu vraiment besoin de wizards différents par filiale ?" n'a pas encore de réponse. **Pas un gate de ce cycle**, juste une dépendance externe.

## INVARIANTS_AT_RISK

- **I3 branch_id** : ⚠️ on **renforce** l'isolation branche en passant `branch_id` au backend, c'est dans le bon sens. Le sub-agent doit s'assurer qu'on envoie `auth.branch_id` (pas une autre source).
- Autres invariants : RAS.

---

## 5. STRATÉGIE — 2 sub-agents parallèles

```
┌─────────────────────────────┐ ┌─────────────────────────────┐
│ Sub-agent N (complex)       │ │ Sub-agent O (routine)       │
│ Fix toggle indispo silencieux│ │ Cleanup nav : Attributs     │
│ + branch_id dans lists()    │ │ + redirect ItemCategoryShow │
│ + erreurs surfacées         │ │                             │
└─────────────────────────────┘ └─────────────────────────────┘
            │                                  │
            └──────────────┬───────────────────┘
                           │
                           ▼
                  AUDIT CONSOLIDÉ
                  Vitest + PHPUnit + Playwright
                           │
              ┌────────────┴────────────┐
              │                         │
          PASS │                     REWORK
              │                         │
         CLOSE cycle              correction
                                  (max 5 rounds)
```

Aucun chevauchement de fichier entre N et O.

---

## 6. Sub-agent N — Fix toggle indispo (complex)

### Mission

1. **Lire d'abord** `resources/js/store/modules/auth.js` pour trouver le getter `branch_id` ou similaire (probablement `state.user.branch_id` ou `getters.userBranchId`).

2. **Modifier `resources/js/store/modules/item.js`** action `lists()` (~L35-41) pour ajouter `branch_id` au paramètre query :
   ```js
   async lists({ commit, rootGetters }, query = {}) {
       // ... existing pattern ...
       const branchId = rootGetters['auth/userBranchId'] ?? rootGetters['auth/authUser']?.branch_id ?? null;
       const params = {
           ...query,
           ...(branchId ? { branch_id: branchId } : {}),
       };
       const response = await axios.get('/admin/items', { params });
       // ...
   }
   ```
   **Important** : adapte au pattern Vuex existant du fichier (Options style classique du repo). Lis le fichier avant de modifier.

3. **Modifier `resources/js/components/admin/items/AvailabilityToggleComponent.vue`** — remplacer le catch silencieux (L44-46) par un try/catch propre qui :
   - Stocke l'erreur dans le state local du composant.
   - Émet `@toggle-error` avec le message.
   - Appelle `appService.alertError(message)` pour afficher un toast/alert visible.
   - Bonus : revert visuel du toggle si l'API échoue (pour éviter "ça a l'air activé mais ça ne l'est pas").

4. **Tests Vitest** :
   - `tests/js/itemListBranchAvailability.spec.js` (NEW) :
     - test_item_lists_action_includes_branch_id_when_user_has_one
     - test_item_lists_action_omits_branch_id_when_no_branch_context
   - `tests/js/availabilityToggleErrorSurfacing.spec.js` (NEW) :
     - test_toggle_emits_error_on_axios_failure
     - test_toggle_calls_alert_service_on_error
     - test_toggle_optimistic_revert_on_failure (si optimistic UI implémenté)

5. **Test PHPUnit** (optionnel, seulement si tu as le temps) :
   - `tests/Feature/Items/ItemListBranchOverlayTest.php` (NEW) — vérifier que `GET /admin/items?branch_id=X` retourne `effective_is_available` calculé via overlay quand un override existe.

6. **Rapport** `reports/execution/RUN_UX_CLEANUP_TOGGLE_FIX_2026-05-04.md`.

### Périmètre allowlist

- `resources/js/store/modules/item.js` (write)
- `resources/js/components/admin/items/AvailabilityToggleComponent.vue` (write)
- `tests/js/itemListBranchAvailability.spec.js` (NEW)
- `tests/js/availabilityToggleErrorSurfacing.spec.js` (NEW)
- `tests/Feature/Items/ItemListBranchOverlayTest.php` (NEW, optionnel)
- `reports/execution/RUN_UX_CLEANUP_TOGGLE_FIX_2026-05-04.md` (NEW)

### PASS

- Tests Vitest 4-5 PASS.
- Vitest globale 0 régression.
- Manuellement testable : toggle dans CatalogStudio reflète l'état après clic (selon analyse statique du code).

---

## 7. Sub-agent O — Cleanup nav (routine)

### Mission

1. **Cacher Attributs des Paramètres** :
   - `resources/js/config/v1-hidden-modules.js` : ajouter `'settings.item-attributes'` au tableau `V1_HIDDEN_MENU_MODULES`.
   - `resources/js/components/admin/settings/MenuComponent.vue` :
     - Ajouter `v-if="!isSettingHidden('itemAttributes')"` au router-link L85-88.
     - Ajouter mapping `'itemAttributes': 'settings.item-attributes'` dans `HIDDEN_KEY_TO_LOCAL_SETTING` L131-143.
   - **Garder** l'accès dans `BackendMenuComponent.vue` L86-90 (sous Articles) intact.

2. **Rediriger ItemCategoryShow vers Catalog Studio** :
   - `resources/js/router/modules/settingRoutes.js` L354-363 — modifier la route `admin.settings.itemCategory.show` :
     ```js
     {
         path: 'show/:id',
         name: 'admin.settings.itemCategory.show',
         redirect: to => ({
             name: 'admin.items.studio',
             query: { item_category_id: to.params.id },
         }),
     }
     ```
   - Garder le breadcrumb fonctionnel.

3. **Vérifier `ItemCategoryListComponent.vue`** : si un bouton "voir" pointe vers `admin.settings.itemCategory.show` → garde-le, la redirect fera le job. Si lien direct → idem. Pas de modification nécessaire à ce composant probablement.

4. **Tests Vitest** :
   - `tests/js/v1HiddenMenuModules.spec.js` (UPDATE existant si présent) — ajouter assertion sur `'settings.item-attributes'` dans le tableau hidden.
   - `tests/js/itemCategoryShowRedirect.spec.js` (NEW) — vérifier que la route `admin.settings.itemCategory.show` est bien définie avec un `redirect` qui pointe vers `admin.items.studio` et préserve `item_category_id` dans la query.

5. **Rapport** `reports/execution/RUN_UX_CLEANUP_NAV_2026-05-04.md`.

### Périmètre allowlist

- `resources/js/config/v1-hidden-modules.js` (write)
- `resources/js/components/admin/settings/MenuComponent.vue` (write léger)
- `resources/js/router/modules/settingRoutes.js` (write)
- `tests/js/v1HiddenMenuModules.spec.js` (write si existe, sinon NEW)
- `tests/js/itemCategoryShowRedirect.spec.js` (NEW)
- `reports/execution/RUN_UX_CLEANUP_NAV_2026-05-04.md` (NEW)

### PASS

- Sentinels passent.
- Vitest globale 0 régression.

---

## 8. Audit consolidé (orchestrateur)

1. `npx vitest run` → suite globale.
2. `php artisan test tests/Feature/Composer/ tests/Feature/Items/ tests/Feature/I18n/` → 0 régression backend.
3. `npm run dev` → rebuild bundles.
4. `npx playwright test tests/e2e/catalog-studio-create-product-flow.spec.js` → critical-flow.
5. Rapport final consolidé.

### CLOSE

- Vitest 1080+X PASS.
- Backend 0 régression.
- Playwright critical-flow PASS.
- 0 fichier hors allowlist modifié.

### REWORK

- Tests fail → correction localisée, max 5 rounds.

---

## 9. Hors scope explicite

- **C2 branch overrides matrice** : reste en attente de réponse utilisateur ("as-tu vraiment besoin de wizards différents par filiale ?"). Ne pas implémenter ici.
- Autres pages legacy de Paramètres (Configuration des Commandes, Configuration Borne, OTP, etc.) : pas dans ce cycle. Cleanup général V2.
- Refonte design Paramètres : V2.

---

## 10. Mémoire post-cycle (Graphiti)

À pousser à CLOSE :
- `item/lists` action Vuex passe `branch_id` du user authentifié → permet à `ItemService::applyBranchAvailabilityOverlay()` de calculer `effective_is_available` via `item_branch_availability`.
- Erreurs `AvailabilityToggle` désormais surfacées (toast + revert optimiste).
- `settings.item-attributes` ajouté à `V1_HIDDEN_MENU_MODULES` — accès Attributs uniquement via Articles.
- Route `admin.settings.itemCategory.show` redirigée vers `admin.items.studio?item_category_id=X`.
