# PLAN — CV1-V1.5D-E2E-HEAL-E-CENTRAL-MANAGEMENT-SYNC

**Date** : 2026-05-04
**Auteur** : Claude (PLAN)
**TASK_ID** : `CV1-V1.5D-E2E-HEAL-E-CENTRAL-MANAGEMENT-SYNC`
**PRIMARY_EXECUTION_MODEL** : `gpt-5.5-pro` (Codex extension)
**REASONING_EFFORT** : `xhigh`
**EXECUTION_TIER** : `complex` — multi-surface (admin → POS, Kiosk, KDS, stock), invariants critiques (`branch_id`, OrderService symmetry, dispatch après commit, projection menu).
**EXECUTE_DELEGATION** : `codex-extension`.
**PLAN_REVIEW** : pending GPT-5.5-pro.

---

## 1. Contexte (rapport source)

`reports/e2e-massive/RAPPORT_CONSOLIDE_E2E_COMPLET_2026-05-04.md` §3 lignes 3, 4 ; log `~280-380`.

| Spec | Échec | Hint log |
|---|---|---|
| `tests/e2e/central-management-dashboard-crud.spec.js:502` (×2 ret. ~1.1 min) | « admin UI creates category, product, photo, variation, extra, addon, composer profile and syncs to POS/Kiosk/KDS/stock » | Timeout long → likely projection ou WS qui ne propagent pas, ou un intermédiaire (création photo, addon) qui bloque. |
| `tests/e2e/central-management-va-sys05.spec.js:346` (×2 ret. 30 s) | `await expect(adminPage.getByTestId('admin-composer-root')).toBeVisible({ timeout: 20000 })` après navigation `/admin/items/show/{fixture.item_id}/composer` | Drawer Composer pas monté. Possible **lien avec PLAN-D** (Studio testids). |

> **Couplage** : ces deux specs partagent l’hypothèse que le pipeline **central admin → projection → consommateur (POS/Kiosk/KDS/stock)** propage en quasi-temps-réel. Si **un seul** maillon casse, les deux échouent.

## 2. SUBSYSTEMS_TOUCHED

| Sous-système | Fichier(s) | Intent |
|---|---|---|
| Backend projections | `app/Services/Composer/ComposerProfileProjection.php` ; `app/Services/Stock/ChoiceAvailabilityResolver.php` ; `app/Services/Menu/*Projection*` | **read-only** (audit invariants 4 et 5). |
| Events / Listeners | `app/Events/IngredientAvailabilityChanged.php`, `app/Listeners/InvalidateMenuProjectionOnIngredientChange.php`, `app/Providers/EventServiceProvider.php` | **read-only** sauf si dispatch hors transaction détecté → write minimal. |
| Admin UI | `resources/js/components/admin/centralManagement/*` ; `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` | **read** ; **write** uniquement testid ou wait. |
| Specs | `tests/e2e/central-management-dashboard-crud.spec.js` ; `tests/e2e/central-management-va-sys05.spec.js` | **write** : ajouter `waitForResponse` ciblés ; pas de `waitForTimeout`. |
| WebSocket | `resources/js/services/WebSocketService.js` (cf. cycle CV1-V1.5C R2) | **read-only** (vérifier reconnect menu refresh). |

## 3. SUBSYSTEMS_OFF_LIMITS

Pricing, fiscal, auth, schema (sauf si lecture). **Aucune** modification de migration, **aucune** logique pricing.

## 4. INVARIANTS_AT_RISK (CRITIQUE)

| Invariant | Risque | Vérification obligatoire |
|---|---|---|
| **3. branch_id isolation** | CRUD central peut potentiellement traverser des branches. | Auditer `branch_id` sur **tous** les services touchés ; logger sous `INVARIANTS_AT_RISK` du plan. |
| **4. Dispatch après DB commit** | Cycle V1.5C R2 a aligné WS reconnect, mais `central-management` peut dispatch dans une transaction. | Lire `IngredientAvailabilityService::toggle` + tout job CRUD admin ; `grep` `DB::afterCommit` vs dispatch direct. |
| **5. OrderService / FrontendOrderService symmetry** | Aucun changement attendu, mais audit obligatoire. | `SYMMETRY_NOTE: validated read-only` dans le rapport. |

## 5. GATE_CONDITIONS

- **Gate Hard** si `branch_id` cross-branch leak détecté → `GATE_CV1-V1.5D-CENTRAL-BRANCH-ISOLATION_*`.
- **Gate Hard** si dispatch avant commit détecté → `GATE_CV1-V1.5D-DISPATCH-COMMIT-VIOLATION_*`.
- **Gate Soft** si symmetry note non validée.

## 6. Stratégie technique (instructions d’intelligence)

### 6.1 Phase 1 — Identifier le maillon cassé (avant tout patch)

1. **Lire** le test `central-management-dashboard-crud.spec.js` complet ; produire en table « step → endpoint backend → effet visible ». ~10-12 étapes attendues.
2. **Lire** la trace : `npx playwright show-trace test-results/e2e-central-management-dashboard-crud-…/trace.zip`. Identifier la première assertion KO.
3. **Cross-check** côté `va-sys05.spec.js:367` (`admin-composer-root`) : si KO identique → couplé à **PLAN-D** (résoudre PLAN-D **avant** ce plan).
4. **Audit DB live** :
   ```bash
   php artisan tinker --execute="dd(\App\Models\ItemWizardProfile::orderByDesc('id')->first());"
   ```
   pour confirmer que la création persiste réellement.

### 6.2 Phase 2 — Diagnostic invariants

1. `grep -rn "DB::afterCommit\|dispatch(" app/Services/Composer/ app/Services/Ingredients/ app/Services/Menu/`
2. `grep -rn "branch_id" app/Services/Composer/ app/Services/Stock/`
3. Ouvrir `php artisan queue:work --once` en parallèle de la spec pour valider que les events sont bien dispatched **après** commit (pas avant).

### 6.3 Phase 3 — Correctifs (par cas)

- **Cas A — Testid composer manquant (couplé PLAN-D)** : résoudre via PLAN-D ; ce plan E ré-attend le drawer après PLAN-D PASS.
- **Cas B — Projection lente** : ajouter `waitForResponse` sur l’endpoint de projection (`GET /api/admin/menu/projection*` ou `composer/profiles/.../publish`) avant l’assertion sync.
- **Cas C — WS reconnect requis** : la spec consume realtime ; vérifier `BroadcastDriverConfiguredTest` (sentinelle V1.5C). Si broadcast driver non configuré en E2E → poser `BROADCAST_DRIVER=log` ou simulateur.
- **Cas D — Invariant 4 violé** : refactor pour `DB::afterCommit(fn () => Event::dispatch(...))`. **HALT + escalade** si la modification touche un service cœur sans contrat clair.

### 6.4 Anti-flake spécifique central management

- Toujours coupler create + `waitForResponse` 200 + assertion DOM dans cet ordre.
- Pour les flux Realtime (KDS): `waitForEvent('websocket')` est trop fragile ; préférer poll API (`page.waitForResponse(/menu\/projection/, { timeout: 30_000 })`).
- Augmenter `test.setTimeout(180_000)` uniquement si justifié, pas par défaut.

## 7. TEST_STRATEGY

- **Local backend** :
  - `php artisan test --filter=ChoiceAvailabilityResolver` (sentinels propagation).
  - `php artisan test --filter=ComposerProfile` (XOR + projection).
  - `php artisan test --filter=BroadcastDriverConfigured` (V1.5C R3).
- **Local Playwright** :
  - `E2E_BACKEND_AVAILABLE=1 npx playwright test tests/e2e/central-management-dashboard-crud.spec.js tests/e2e/central-management-va-sys05.spec.js --retries=0`
- **Smoke régression** :
  - `tests/e2e/c3-runtime-multi-surface.spec.js` (déjà PASS au run massif — doit rester PASS).
  - `tests/e2e/composer-mega-flow.spec.js`.

## 8. ROLLBACK

Diff localisé + git checkout. **Aucun** rollback DB attendu (read-only patches privilégiés).

## 9. ESCALATION

- Détection violation invariant 3 / 4 / 5 → **HALT + GATE BRIEF** immédiat ; ne pas tenter de fix « rapide » sur un service de domaine.
- Si plus de 3 endpoints centraux à instrumenter → cycle séparé (`CV1-V1.5E-CENTRAL-OBS-001`).

## 10. SYMMETRY_NOTE

À valider en EXECUTE : aucune modif `OrderService` / `FrontendOrderService` ne doit être nécessaire. Si nécessaire → escalade.

## 11. Livrables

- Diff spec(s) + (potentiellement) 1-2 instrumentations testid/wait.
- Audit invariants 3/4/5 dans `reports/audit/CENTRAL_MANAGEMENT_INVARIANTS_AUDIT_2026-05-04.md`.
- `reports/execution/RUN_CV1-V1.5D-E2E-HEAL-E-CENTRAL-MANAGEMENT-SYNC_2026-05-04.md`.

---
