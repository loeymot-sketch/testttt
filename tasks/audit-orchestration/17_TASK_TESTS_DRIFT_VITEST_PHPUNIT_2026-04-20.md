# T17 — Tests drift Vitest + PHPUnit (re-run réel + comparaison)

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `shell`

## Objectif unique

K-10 a déclaré **PHPUnit Feature 510 / 510 (8 skipped)** et **Vitest 718 / 1 skipped**.
Les modifications post-K-10 (allergens FR, kioskPerf supprimé, sentry.js supprimé,
postEvent helper, button type) **changent forcément la baseline**. Il faut **rejouer** les
suites et comparer.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `shell`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93.

Étapes :
1) Vérifier .env.testing présent (sinon copier .env.example → .env.testing).
2) Vérifier composer/vendor + node_modules. Si absent : `composer install --no-interaction`
   puis `npm ci`.
3) Lancer PHPUnit Feature suite :
   ./vendor/bin/phpunit tests/Feature --no-coverage --testdox > /tmp/phpunit_out.log 2>&1
   Capturer : passed / failed / skipped / risky / warning / time.
4) Lancer Vitest :
   npx vitest run --reporter=verbose > /tmp/vitest_out.log 2>&1
   Capturer : passed / failed / skipped / time.
5) Comparer aux baselines K-10 :
   - PHPUnit Feature : 510 passed / 8 skipped → écart ?
   - Vitest : 718 passed / 1 skipped → écart ?
6) Pour chaque test failed/skipped NOUVEAU (vs baseline) : nommer + extrait erreur.
7) Identifier les tests cassés par les changements récents (allergens, button type, etc.).
8) Rejouer mêmes suites sur testttt (clone principal) si pertinent.

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK17_TESTS_DRIFT_2026-04-20.md

Format :
- Section A : résultat brut PHPUnit + Vitest
- Section B : delta vs K-10 baseline
- Section C : tests cassés à analyser
- Section D : verdict PASS/FAIL
```

## Lecture obligatoire

- `phpunit.xml`
- `package.json`, `vitest.config.js`
- `reports/execution/RUN_K10_ACCEPTANCE_2026-04-19.md` (baseline 510/718)

## Checklist multi-points

- [ ] V1. Suite PHPUnit Feature exécutée bout en bout
- [ ] V2. Suite Vitest exécutée bout en bout
- [ ] V3. Counts passed/failed/skipped reportés
- [ ] V4. Delta vs baseline K-10 calculé
- [ ] V5. Liste des tests nouvellement cassés
- [ ] V6. Hypothèse cause racine pour chaque (allergens, sentry, kioskPerf, button, postEvent)
- [ ] V7. Recommandation : fixer test ou fixer code

## Critères PASS / FAIL

- **PASS** : 0 régression OU régressions toutes expliquées + plan.
- **FAIL** : ≥ 1 test rouge sans plan.

## Output

`reports/audit-orchestration/REPORT_TASK17_TESTS_DRIFT_2026-04-20.md`

## Si FAIL → action

→ T17b `generalPurpose` : fixer tests rouges (test-first si symptôme code).
