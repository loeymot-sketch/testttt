# VERIFY-16 — Tests / Régressions (PHPUnit + Vitest + Playwright)

**Date :** 2026-04-20  **Origine :** `AUDIT_POS_110_TESTS_REGRESSIONS_2026-04-19.md`  **Priorité :** P0  **Mode :** AUDIT-ONLY (mais peut **lancer les suites** en lecture)

## 1. Contexte
Vérifier que les tests des cycles P1–P10 passent encore aujourd'hui, qu'aucun fail Playwright n'est silencieusement ignoré, que la couverture des points critiques (fiscal, paiement, KDS race, isolation branche) est suffisante.

## 2. Sources OBLIGATOIRES
- `phpunit.xml`
- `tests/**`
- `vitest.config.js` / `package.json` scripts
- `playwright.config.ts`
- Résultats récents : `test-results/**`, `reports/antigravity/playwright-latest.json`
- Audit : `AUDIT_POS_110_TESTS_REGRESSIONS_2026-04-19.md`

## 3. Hypothèses à challenger
- H1 : Un test marqué `skip` masque un bug réel.
- H2 : `phpunit.xml` exclut un dossier critique.
- H3 : Playwright KDS échoue (déjà observé) → pas de fix appliqué.
- H4 : Tests paiement ne couvrent pas double-submit.
- H5 : Vitest ne couvre pas les nouveaux composants kiosk cash.

## 4. Plan multi-agent
1. **Étape exécution** : `./vendor/bin/phpunit --testsuite=Feature` (uniquement lecture résultat) + `npx playwright test --reporter=list` si environnement permet.
2. **Explore A** : énumère tests skipped/incomplete.
3. **Explore B** : couverture matrice critère métier × test (P1→P10 + axes audit).
4. **GeneralPurpose** : produit rapport priorisé + liste tests à ajouter.

## 5. Vérifications obligatoires
- [ ] V1 : PHPUnit suite Feature passe (ou liste précise des fails avec cause).
- [ ] V2 : Aucun `markTestSkipped` non justifié dans le commentaire.
- [ ] V3 : Tests `KdsChangeStatusConcurrencyTest`, `OrderRejectsUnavailableBranchItemTest`, `Pos*` exécutés et OK.
- [ ] V4 : Playwright KDS test (failure récente) explicitement reproduit ou expliqué.
- [ ] V5 : Couverture matrice rendue (≥ 80 % des cycles P couverts par au moins 1 test).
- [ ] V6 : Tests fiscaux (`tests/Feature/Fiscal/`) tous verts.

## 6. Critères d'acceptation
- ALL_GREEN si V1–V6 OK et fail Playwright résolu (ou cause documentée + ticket).
- WARN si V5 partiel.
- FAIL si V1/V6 rouge.

## 7. Livrables
- `reports/review/VERIFY_16_TESTS_REGRESSIONS_2026-04-20.md`

## 8. Suite
- FAIL → cycles ciblés `P11_TEST_FISCAL_FIX`, `P11_TEST_KDS_PLAYWRIGHT_FIX`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY (peut LANCER les suites de test, sans modifier code).
Lis tasks/verify-2026-04-20/16_VERIFY_TESTS_REGRESSIONS.md, applique §4-§7.

OBLIGATIONS:
- Lance: ./vendor/bin/phpunit --testsuite=Feature (rapport synthétique).
- Si Playwright disponible: lance la suite KDS isolément (pas tout).
- 2 explore parallèles (A skipped/incomplete, B matrice couverture).
- 1 generalPurpose synthèse priorisée.
Livrable: reports/review/VERIFY_16_TESTS_REGRESSIONS_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```
