# Résultat validation — tranche 2 (plan E central-management + correctifs wizard E2E)

**Date** : 2026-05-05  
**TASK_ID** : `CV1-V1.5D-E2E-HEAL-MASTER` (suite plan maître `plans/PLAN_CV1-V1.5D-E2E-HEAL-MASTER_2026-05-04.md` § sous-plan **E**)

---

## 1. Objectif tranche 2

- Exécuter les specs **central-management** (sync admin → POS/Kiosk/KDS/stock).
- Enchaîner sur la **preuve de non-régression** de la tranche 1 (10 tests studio + POS + ingrédients).

---

## 2. Livrables code (session)

| Fichier | Rôle |
| --- | --- |
| `app/Support/WizardPerItemDemo.php` | SSOT : `enabled(request())` = flag `.env` **ou** bypass Playwright **local** + header `X-Foodking-Playwright-E2e: 1`. |
| `resources/views/master.blade.php` | `wizard_per_item_demo` injecté côté Blade via `WizardPerItemDemo::enabled(request())` (aligné SPA + API). |
| `app/Http/Middleware/EnsureWizardPerItemDemoEnabled.php` | Idem `WizardPerItemDemo::enabled`. |
| `app/Http/Middleware/EnsureProfileNotItemOwnedUnlessDemoEnabled.php` | Idem. |
| `tests/e2e/helpers/ensure-wizard-demo.js` | Dépose le header sur le `BrowserContext` (plus de `page.evaluate` fragile). |
| `tests/e2e/central-management-va-sys05.spec.js` | `ensureWizardPerItemDemoForComposer(adminContext)` **avant** `newPage()`. |
| `tests/e2e/central-management-dashboard-crud.spec.js` | Idem sur `adminContext`. |
| `tests/Feature/WizardPerItemDemoMiddlewareTest.php` | +1 test : header Playwright **ne** bypass **pas** en `testing` (régression). |

**Important** : redémarrer `php artisan serve` après `git pull` sur cette tranche pour charger le PHP/Blade mis à jour.

---

## 3. Résultats Playwright

### 3.1 Tranche 1 (non-régression) — **PASS**

`E2E_BACKEND_AVAILABLE=1` `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000` :

- `tests/e2e/catalog-studio-a11y-axe.spec.js` (3)
- `tests/e2e/catalog-studio-create-product-flow.spec.js` (1)
- `tests/e2e/design/pos/d2-pos-design-audit.spec.js` (1)
- `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts` (1)
- `tests/Playwright/critical-flow/v1-ingredients-a11y.spec.js` (4)

**Verdict** : **10 / 10 PASS** (~2,3 min).

### 3.2 Plan E — central-management — **REWORK** (cette machine)

- `tests/e2e/central-management-dashboard-crud.spec.js` — échec : `admin-composer-template` — `selectOption` (« Element is not a \<select\> ») : dérive DOM / composant (non régressif directement au header wizard ; à traiter dans un cycle UI/test dédié).
- `tests/e2e/central-management-va-sys05.spec.js` — après ouverture de `admin-composer-root`, échec sur `admin-composer-step-0-key` (état composer / données fixture).

**Verdict tranche E** : **0 / 2 PASS** sur l’environnement d’exécution CI locale du run ; la **déblocage wizard** (root composer visible avec header + `APP_ENV=local`) est validé en tinker (`WizardPerItemDemo::enabled` = Y avec header).

---

## 4. PHPUnit

`tests/Feature/WizardPerItemDemoMiddlewareTest.php` : **4 / 4 PASS**.

---

## 5. Massif 76 tests (plan maître §6)

**Non exécuté** dans cette session (durée ~18 min + file d’E2E complète). Commande inchangée :

```bash
E2E_BACKEND_AVAILABLE=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test --config=playwright.config.js
```

---

## 6. Prérequis E2E composer (local)

1. `APP_ENV=local` (le bypass Playwright **ne** s’applique pas en `testing` / production).
2. Contexte Playwright : `ensureWizardPerItemDemoForComposer(adminContext)` avant `newPage()` **ou** équivalent `setExtraHTTPHeaders({ 'X-Foodking-Playwright-E2e': '1' })`.
3. Alternative : `FEATURE_WIZARD_PER_ITEM_DEMO=true` dans `.env` (sans header).

---

**FIN — tranche 2 : code livré + tranche 1 verte ; plan E encore REWORK sur specs centrales (UI/locators).**
