# Rapport consolidé unique — E2E Playwright + correctifs — 2026-05-04

**Fichier SSOT** : ce document remplace la dispersion entre `RAPPORT_CONSOLIDE_P0_P5.md`, logs bruts et messages de session.  
**Journal d’activité** : `reports/AGENT_ACTIVITY_LOG.md` (tâche `E2E-ALL-IN-ONE-2026-05-04`).

---

## 1. Synthèse exécutive

| Exécution | Commande / contexte | Résultat |
| --- | --- | --- |
| **Run massif complet** | `E2E_BACKEND_AVAILABLE=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test --config=playwright.config.js` | **76** tests · **63** passed · **11** failed · **1** flaky · **1** skipped · ~**17,9** min |
| **Reprise ciblée post-correctifs** | Même env, uniquement `v1-sidebar-cleanup` + `v1-ingredient-rupture-propagation` | **2** passed (~19 s) |

**Log brut du run massif** : `reports/e2e-massive/FINAL_2026-05-04/logs/playwright_full.log`

---

## 2. Correctifs appliqués dans le dépôt (post-run massif)

| Fichier | Problème | Correction |
| --- | --- | --- |
| `tests/Playwright/global-setup.js` | Liste ingrédients vide / 403 : Spatie **cache permissions** du process `php artisan serve` pas aligné après seed CLI seul | Enchaîner `IngredientPermissionSeeder` → `E2EPlaywrightIngredientRowsSeeder` → `permission:cache-reset` (H3 gate doc). Message « Unable to flush cache » possible si store non flushable — non bloquant si tests passent. |
| `tests/Playwright/critical-flow/v1-sidebar-cleanup.spec.js` | Échec sur **« Catalogue »** : UI en **EN** affiche **« Catalog »** ; « Commandes » vs **« POS Orders »** | Regex bilingues + timeout 15 s sur les libellés attendus. |
| `tests/Playwright/critical-flow/v1-ingredient-rupture-propagation.spec.js` | Lignes sans switch (API pas prête) ; nom persistant lu sur **première `<td>`** (colonne type) au lieu du **`<th>`** | `waitForResponse` GET `/api/admin/ingredients` **200** avant assertion des lignes ; nom = `row.locator('th').first()`. |

**Seeder données** (inchangé ce passage, rappel) : `database/seeders/E2EPlaywrightIngredientRowsSeeder.php` — attribut + extra de test sur un item actif.

---

## 3. Détail des 11 échecs — run massif (`playwright_full.log`)

| # | Spec (fichier) | Symptôme / cause probable | Piste corrective |
| --- | --- | --- | --- |
| 1 | `tests/e2e/catalog-studio-a11y-axe.spec.js:56` | **1** violation axe **serious** (état catégorie sélectionnée) | Lire `error-context.md` + screenshot ; corriger le nœud signalé (structure / contrast / roles). |
| 2 | `tests/e2e/catalog-studio-create-product-flow.spec.js:26` | Timeout flux Studio → composer drawer | Données fixture, sélecteurs Studio, ou perf ; trace Playwright dans `test-results/`. |
| 3 | `tests/e2e/central-management-dashboard-crud.spec.js:502` | CRUD central → sync runtime (1,1 min) | Données, WS, ou assertion sync ; `trace.zip` joint au log. |
| 4 | `tests/e2e/central-management-va-sys05.spec.js:346` | VA-SYS-05 projection multi-surfaces (~30 s) | Idem ; vérifier seed + services menu projection. |
| 5 | `tests/e2e/design/pos/d2-pos-design-audit.spec.js:27` | **6×** axe **critical** `aria-required-children` sur `.pos-v5-cart__body` **role="list"** sans enfants requis | Retirer `role="list"` si non sémantique, ou peupler avec `role="listitem"` cohérents. |
| 6 | `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts:222` | Locator total panier `#pos-cart li` + `Total` + `span.text-primary` introuvable | DOM POS v5 / i18n libellé « Total » ou structure liste ticket. |
| 7 | `tests/Playwright/critical-flow/v1-ingredient-rupture-propagation.spec.js:51` | **Corrigé** : « No toggleable row » (403 / cache perms + mauvaise cellule nom) | Voir §2 — **re-validé 2/2** en reprise ciblée. |
| 8 | `tests/Playwright/critical-flow/v1-ingredients-a11y.spec.js:53` | Axe **color-contrast** serious (`text-primary` / onglets `#tab-all`) | Ajuster tokens couleur primaire (#ff006b) vs fond pour ratio ≥ 4.5:1. |
| 9 | `tests/Playwright/critical-flow/v1-ingredients-a11y.spec.js:58` | Timeout **120 s** : bouton « voir les détails » introuvable (liste vide / i18n) | Après §7–8, liste peuplée + libellé `view details` / `voir les détails` aligné `IngredientListComponent`. |
| 10 | `tests/Playwright/critical-flow/v1-ingredients-a11y.spec.js:82` | Empty state : mêmes violations **color-contrast** | Même correctif design que #8 (header V1 + tabs). |
| 11 | `tests/Playwright/critical-flow/v1-sidebar-cleanup.spec.js:18` | **Corrigé** : « Catalogue » absent en locale EN | Voir §2 — **re-validé**. |

**Flaky (1)** : `v1-ingredients-a11y.spec.js:67` — toggle clavier Space vs `window.prompt` / état `aria-checked` ; une passe sur retry #1.

**Skipped (1)** : `v1-category-wizard-affects-products.spec.js` — test marqué skip interne.

---

## 4. Résultat global après correctifs (projection)

- Les **2** échecs **Pivot V1** sidebar + rupture ingrédient sont **fermés** localement (preuve §1 reprise ciblée).
- Il reste **9** échecs **hors** ces deux specs (Studio, central-management, design POS, tacos, a11y ingrédients contrast) — **non traités** dans cette passe (hors demande « consolidé » + correctifs ciblés identifiés dans le log).

**Recommandation** : relancer le massif complet après correction contrast / POS list / central-management pour un chiffre **76/76** :

```bash
E2E_BACKEND_AVAILABLE=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test --config=playwright.config.js 2>&1 | tee reports/e2e-massive/FINAL_2026-05-04/logs/playwright_full_rerun.log
```

---

## 5. Références croisées

- Rapport P0→P5 antérieur (PHPUnit + première vague Playwright) : `reports/e2e-massive/20260504_1956_E2E_MASSIVE/RAPPORT_CONSOLIDE_P0_P5.md`
- Gate / discipline cache permissions : `docs/gates/GATE_CV1-V1-PIVOT-PRODUCTION-CUTOVER_2026-05-04.md` (H3 `permission:cache-reset`)

---

*Fin du rapport consolidé unique — 2026-05-04.*
