# PLAN — CV1-V1.5D-E2E-HEAL-D-CATALOG-STUDIO-CREATE-FLOW

**Date** : 2026-05-04
**Auteur** : Claude (PLAN)
**TASK_ID** : `CV1-V1.5D-E2E-HEAL-D-CATALOG-STUDIO-CREATE-FLOW`
**PRIMARY_EXECUTION_MODEL** : `gpt-5.5-pro` (Codex extension)
**REASONING_EFFORT** : `xhigh`
**EXECUTION_TIER** : `complex` — Studio = composant central catalogue, plusieurs cycles V1 ont contribué.
**EXECUTE_DELEGATION** : `codex-extension`.
**PLAN_REVIEW** : pending GPT-5.5-pro.

---

## 1. Contexte (rapport source)

`reports/e2e-massive/RAPPORT_CONSOLIDE_E2E_COMPLET_2026-05-04.md` §3 ligne 2 ; log `~310-360`.

Échec : `tests/e2e/catalog-studio-create-product-flow.spec.js:26` — 25 s timeout (×2 retries) sur :

> admin can open Studio, create a category, and open the wizard composer drawer on an existing product.

Step bloquant probable (à confirmer via trace) : ouverture du **drawer composer** sur un produit existant après création de catégorie (`admin-composer-root` ou bouton studio item).

## 2. SUBSYSTEMS_TOUCHED

| Sous-système | Fichier(s) | Intent |
|---|---|---|
| Catalog Studio | `resources/js/components/admin/items/CatalogStudioComponent.vue` ; `…/composer/ProductComposerEditorComponent.vue` ; `resources/js/router/modules/itemRoutes.js` | **read** d’abord (frozen check) ; **write minimal** : restaurer un `data-testid` ou un délai d’hydratation manquant. |
| Spec Studio | `tests/e2e/catalog-studio-create-product-flow.spec.js` | **write** possible : `waitForResponse` sur l’API category create avant click drawer ; **NE PAS** ajouter de `waitForTimeout`. |
| Backend (lecture seule) | `app/Http/Controllers/Admin/ComposerProfileController.php`, `routes/api.php` `composer/items/{item}/profile` | **read** uniquement pour comprendre le contrat. |

## 3. SUBSYSTEMS_OFF_LIMITS

Pricing, fiscal, OrderService, KDS/Kiosk/OSS/POS, schema, auth, branch_id, dispatch.

## 4. INVARIANTS_AT_RISK

| Invariant | Risque | Mitigation |
|---|---|---|
| Frozen zones | Studio est piloté par 5+ cycles V1 récents → certains fichiers peuvent être frozen. | Lecture `docs/gates/` AVANT toute modif Vue. |
| Wizard XOR (item vs category owner) | Studio crée des profils ; un test mal écrit pourrait masquer un vrai bug XOR. | Vérifier en EXECUTE que le scénario respecte XOR (cf. `WizardPerItemProfileGuardTest`). |

## 5. GATE_CONDITIONS

- Si la cause est **un bug produit Studio** (pas un bug spec) → ouvrir un **gate REWORK** vers le cycle Studio originel (`CV1-V1-PIVOT-CATALOG-STUDIO-CATEGORY-WIZARD-001`). Ne pas masquer dans la spec.

## 6. Stratégie technique (instructions d’intelligence)

### 6.1 Diagnostic structuré

1. **Trace** : `npx playwright show-trace test-results/e2e-catalog-studio-cr-…/trace.zip`. Identifier l’étape exacte où le timeout survient (catégorie créée OK ? produit listé ? bouton « ouvrir composer » présent ? `getByTestId('admin-composer-root')` jamais visible ?).
2. **Lire** `tests/e2e/catalog-studio-create-product-flow.spec.js` ligne par ligne (focus 26 → 70). Reconstituer la liste des `data-testid` attendus :
   - `admin-composer-root`
   - `admin-composer-template`
   - `admin-composer-step-0-key`
   - `admin-composer-add-step`
3. **Cross-check** dans le code Vue :
   ```bash
   grep -rn "admin-composer-root" resources/js/
   grep -rn "admin-composer-template" resources/js/
   ```
   Si un testid manque ou a été renommé pendant un cycle Studio → c’est la cause.
4. **Vérifier** côté API : `php artisan route:list | grep composer` doit lister la route `composer/items/{item}/profile`. Sinon, middleware `wizard.per_item_demo` peut bloquer (Demo V2 flag off).

### 6.2 Solutions par cas

- **Cas A — testid manquant** : restaurer le testid sur le composant (1 ligne).
- **Cas B — middleware Demo V2** bloque le flux pendant le test → la spec doit poser le flag `kioskUsePosWizard` / `wizardPerItemDemoEnabled` à `true` via `request.fulfill` ou via env de test (selon comment le flag est exposé). À examiner.
- **Cas C — race condition hydratation** : ajouter `await expect(page.getByTestId('catalog-studio-root')).toBeVisible()` AVANT de cliquer sur composer. Pas de timeout magique.
- **Cas D — wizard composer drawer ne s’ouvre que sur produit ayant un `wizard_profile_id` non-null** → la fixture utilisée doit garantir cet état (seed `tests/e2e/__fixtures__/catalog-studio-fixture.json` ou équivalent). Si la fixture est obsolète → la mettre à jour.

### 6.3 Anti-flake

- `waitForResponse` pour `POST /api/admin/composer/categories/.../profile` ou `…/items/.../profile` après création.
- Aucun `waitForTimeout(N)`.

## 7. TEST_STRATEGY

- **Local** :
  - `E2E_BACKEND_AVAILABLE=1 npx playwright test tests/e2e/catalog-studio-create-product-flow.spec.js --retries=0`
  - **Régression Studio** : `tests/e2e/catalog-studio-a11y-axe.spec.js`, `tests/Playwright/critical-flow/v1-category-wizard-affects-products.spec.js`.
- **PHPUnit** : ré-exécuter `tests/Feature/Composer/*` pour s’assurer qu’aucun rename de route/testid n’a cassé le contrat.

## 8. ROLLBACK

Git checkout sur la spec + 1 ligne testid si appliqué.

## 9. ESCALATION

- Bug produit Studio confirmé (cas A/B sévère) → `REWORK` vers cycle Studio.
- Frozen zone touchée → gate brief.
- Si > 3 testids manquants ou renommés → audit complet Studio (cycle séparé).

## 10. SYMMETRY_NOTE

Aucune (pas d’`OrderService`/`FrontendOrderService`).

## 11. Livrables

- Diff spec + (1-2 lignes testid Vue si nécessaire).
- Trace before/after.
- `reports/execution/RUN_CV1-V1.5D-E2E-HEAL-D-CATALOG-STUDIO-CREATE-FLOW_2026-05-04.md`.

---
