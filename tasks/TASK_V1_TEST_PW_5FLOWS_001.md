# TASK_V1_TEST_PW_5FLOWS_001 — Stabilisation 5 flows Playwright critiques

## Meta
- **Priority** : P0 (nécessaire pour livrer V1 avec confiance)
- **Vague** : 4 — Data, observabilité, tests
- **PRIMARY_MODEL** : Claude (strategy) + Composer (implémentation)
- **TEST_STRATEGY** : `playwright-full-e2e`
- **DEPENDS_ON** : TASK_V1_EVENT_CONTRACT_001, TASK_V1_PRICING_SSOT_001, TASK_V1_STATUS_MACHINE_001, TASK_V1_MENU_86_001
- **BLOCKS** : —
- **Estimation** : 2 j-h

## Contexte

Playwright MCP est configuré dans le projet mais aucune suite stable n'est invocable en CI. Pour livrer V1 avec confiance, il faut que **les 5 flows cœur** soient testés automatiquement, verdissent en CI et ne soient pas flaky.

Les 5 flows cœur :
1. **POS Cash** — caissier prend une commande, paie cash, ticket imprimé, statut passe à completed.
2. **POS Card** — idem paiement carte (mocké).
3. **Kiosk** — client borne sélectionne articles, paie, commande reçue au KDS.
4. **KDS** — commande reçue, transitions preparing → ready → served fluides.
5. **Auth F5** — login, navigation, F5 → session conservée, authentifié.

## Acceptance Criteria
- [ ] 5 specs Playwright stables, nommées selon la convention `tests/playwright/<flow>.spec.ts`.
- [ ] Suite complète en < **3 minutes** (temps total wall-clock).
- [ ] **10 runs consécutifs verts** — aucun flaky.
- [ ] Configuration CI : GitHub Actions (ou équivalent) exécute la suite sur chaque PR → bloque merge si rouge.
- [ ] `reports/antigravity/v1-baseline.md` : rapport initial avec capture écran par step, latence par action, durée totale.
- [ ] Playwright tourne contre seed reproductible : `php artisan migrate:fresh --seed` en setup CI.
- [ ] Tests documentent explicitement les assertions (pas juste clic + sleep).
- [ ] `docs/PLAYWRIGHT_SUITE.md` — comment lancer en local, comment debugger un test qui foire.

## Scope

### SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `tests/playwright/pos-cash.spec.ts` | nouveau | Write | No | No |
| `tests/playwright/pos-card.spec.ts` | nouveau | Write | No | No |
| `tests/playwright/kiosk-order.spec.ts` | nouveau | Write | No | No |
| `tests/playwright/kds-transitions.spec.ts` | nouveau | Write | No | No |
| `tests/playwright/auth-f5.spec.ts` | nouveau | Write | No | No |
| `tests/playwright/fixtures/*` | helpers login, seed, stubs | Write | No | No |
| `playwright.config.ts` | configuration | Write | No | No |
| `.github/workflows/playwright.yml` (ou équivalent CI) | nouveau | Write | No | No |
| `docs/PLAYWRIGHT_SUITE.md` | doc | Write | No | No |

### SUBSYSTEMS_OFF_LIMITS
- Code backend / frontend applicatif — aucune modification. Si un test nécessite un hook/fixture côté app, faire l'ajout **dans le scope de la task concernée**, pas ici.
- Pas de refactoring de business logic pour rendre les tests plus simples.

## Invariants at Risk
- [x] None
- [ ] Backend pricing SSOT
- [ ] OrderStatus enum
- [ ] branch_id data isolation
- [ ] Dispatch after DB commit
- [ ] OrderService / FrontendOrderService symmetry
- [ ] Frozen zone

## Execution Steps

### E1 — Fixtures communes
1. `fixtures/login.ts` : helper `login(page, email, password)`.
2. `fixtures/seed.ts` : `beforeAll` déclenche `php artisan migrate:fresh --seed`.
3. `fixtures/stubs/card-gateway.ts` : stub carte succès/échec pour test pos-card.

### E2 — pos-cash.spec.ts
```ts
test('POS cash cycle complet', async ({ page }) => {
    await login(page, 'pos@lecayenne.fr', '123456');
    await expect(page).toHaveURL(/\/pos/);
    await page.click('[data-test=category-burgers]');
    await page.click('[data-test=product-tacos-xl]');
    await page.click('[data-test=wizard-next]'); // no options
    await expect(page.locator('[data-test=cart-total]')).toContainText('€');
    await page.click('[data-test=payment-cash]');
    await page.click('[data-test=confirm-payment]');
    await expect(page.locator('[data-test=order-status]')).toContainText('pending');
    // attente KDS pickup simulée : skip ou mock
});
```

### E3 — pos-card.spec.ts
Idem E2, stub gateway carte retourne succès immédiat.

### E4 — kiosk-order.spec.ts
Flow kiosk visiteur (pas de login client) :
1. Page accueil kiosk.
2. Choix catégorie → article → options.
3. Panier → paiement kiosk (cash ou carte).
4. Confirmation visuelle commande reçue.
5. **Vérifier** (autre page) KDS reçoit la commande via WebSocket en < 3s.

### E5 — kds-transitions.spec.ts
1. Login `chef@lecayenne.fr`.
2. Seeder crée 1 commande pending.
3. Chef clique "Démarrer préparation" → status `preparing`.
4. Chef clique "Prêt" → status `ready`.
5. POS (autre onglet) marque "Servi" → `served`.
6. Assertions : chaque transition visible sur les 2 écrans en < 2s.

### E6 — auth-f5.spec.ts
1. Login.
2. Navigation vers `/admin/orders`.
3. `page.reload()`.
4. Attendu : toujours authentifié, URL préservée, pas redirigé vers login.

### E7 — CI workflow
```yaml
name: playwright
on: [pull_request]
jobs:
  e2e:
    runs-on: ubuntu-latest
    services:
      mysql: { image: mysql:8, env: {...} }
      redis: { image: redis:7 }
    steps:
      - uses: actions/checkout@v4
      - run: composer install --no-interaction --no-progress
      - run: php artisan migrate:fresh --seed
      - run: npm ci
      - run: npm run build
      - run: npx playwright install --with-deps chromium
      - run: npx playwright test
      - uses: actions/upload-artifact@v4
        if: failure()
        with: { name: playwright-report, path: playwright-report/ }
```

### E8 — Flakiness budget
Exécuter `npm run test:e2e` 10 fois consécutives en local. **Tout** vert requis.

Si un test est flaky : traiter le root cause (race condition WebSocket, animation, timing) — ne jamais mettre `await page.waitForTimeout(5000)` sauvage.

### E9 — Rapport baseline
`reports/antigravity/v1-baseline.md` :
- Durée totale suite.
- Durée moyenne par spec.
- Screenshots d'étape.
- Traces Playwright zippées.

### E10 — Documentation
`docs/PLAYWRIGHT_SUITE.md` : comment setup, lancer, debugger.

## SYMMETRY_NOTE
N/A.

## GATE_CONDITIONS
- **Gate requise** : NON.
- Stop-gate si : propose de skip un test flaky avec `.skip` — non, fixer le root cause.
- Stop-gate si : propose `waitForTimeout` sans raison forte — non, `expect().toBeVisible()` ou `toHaveText()` avec timeout explicite.

## Status
- [ ] Pending plan
- [ ] Plan approved
- [ ] In execution
- [ ] Validation
- [ ] Audit
- [ ] Gate open
- [ ] Closed
