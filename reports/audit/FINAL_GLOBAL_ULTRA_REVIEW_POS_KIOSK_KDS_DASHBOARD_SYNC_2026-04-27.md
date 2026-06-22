# Final Global Ultra Review — POS / Kiosk / KDS / Dashboard / Sync

Date: 2026-04-27  
Mode: audit final + validation globale, no product patch  
Scope: caisse POS, borne kiosk, KDS, queue number, paiement simule/TPE, dashboard/gestion catalogue, categories/produits, stock V1, sync/outbox, fiscal/recu  
Execution trace: `codex-extension`, Graphiti queried, browser-use attempted, Playwright local used  
Verdict court: `FINAL_GLOBAL_AUDIT_VERDICT: HOLD_REWORK_REQUIRED`

## 1. Verdict Executif

Le systeme a une base Caisse V1 solide, mais l'audit final ne peut pas etre signe en PASS aujourd'hui.

Les preuves positives sont fortes:

- Vitest complet: `126 files passed`, `867 tests passed`.
- PHP cible critique moderne: `73 passed` sur Kiosk, POS/KDS sync, paiement simule, queue number, catalogue/stock V1, pricing SSOT, menu projection.
- Playwright global: `34 passed` sur 35, couvrant POS cash de base, borne, KDS, POS card surface, staff-only routing, et contrats statiques kiosk/realtime.
- `npm run production`: PASS.
- Guards statiques: OrderStatus OK, branch isolation OK, client totals OK, legacy imports OK.

Mais les blocages sont reels:

1. Safety-check governance HALT: fichier frozen staged `app/Services/OrderService.php`.
2. PHPUnit complet FAIL: `15 failed`, `8 skipped`, `1081 passed`.
3. Playwright global FAIL: 1 test POS tacos cash/recu fiscal echoue apres retry.
4. `npm run pos:lint:pricing` FAIL sur calcul prix frontend dans `KioskWizardComponent.vue:1184`.
5. `npm run i18n:audit` FAIL pour locales non-FR; FR a `0 missing`, mais le job sort en erreur.
6. Gates commerciaux encore ouverts: D-M13 production rollout, hardware UAT, paiement live, i18n visible, legacy kiosk bundle.

Conclusion: `HOLD_REWORK_REQUIRED`, pas `READY_FOR_RELEASE`.

## 2. Evidence De Validation

| Commande | Resultat | Interpretation |
| --- | ---: | --- |
| `bash .cursor/hooks/safety-check.sh` | FAIL | Frozen zone staged: `app/Services/OrderService.php`. Gate requis. |
| `npm run verify:boucle` | PASS | Boucle gouvernee; `claude` sur PATH; API smoke non lance par defaut. |
| `php artisan test` | FAIL | `1081 passed`, `8 skipped`, `15 failed`. |
| `php artisan test --filter="...critical..."` | PASS | `73 passed`; flux modernes critiques verts. |
| `npx vitest run` | PASS | `126 files`, `867 tests`. |
| `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test` | FAIL | `34 passed`, `1 failed`. |
| `npm run production` | PASS | Mix compiled successfully. |
| `bash scripts/lint-fk-bundle-legacy.sh strict` | PASS_WITH_WARNING | Exit 0, warning legacy kiosk bundle reference. |
| `npm run pos:lint:pricing` | FAIL | Frontend price arithmetic detected. |
| `npm run pos:lint:status` | PASS | No hardcoded OrderStatus literals in scanned files. |
| `bash scripts/lint-fk-client-totals.sh` | PASS | No client-supplied final total writes. |
| `bash scripts/lint-fk-branch-isolation.sh` | PASS | No `branch_id LIKE` filters. |
| `bash scripts/lint-fk-legacy-imports.sh` | PASS | No legacy imports. |
| `npm run i18n:audit` | FAIL | FR complete, other locales missing keys/dead keys. |

Logs:

- `reports/validation/final-global-2026-04-27/phpunit-full.log`
- `reports/validation/final-global-2026-04-27/phpunit-failing-rerun.log`
- `reports/validation/final-global-2026-04-27/phpunit-critical-targeted.log`
- `reports/validation/final-global-2026-04-27/vitest-full.log`
- `reports/validation/final-global-2026-04-27/playwright-full.log`
- `reports/validation/final-global-2026-04-27/npm-production.log`
- `reports/validation/final-global-2026-04-27/lint-pos-pricing.log`
- `reports/validation/final-global-2026-04-27/i18n-audit.log`

## 3. Blocage 1 — Safety / Frozen Zone

`bash .cursor/hooks/safety-check.sh`:

```text
[HALT] Frozen zone staged: app/Services/OrderService.php — gate clearance required. See docs/gates/
```

Impact:

- Tant que ce fichier reste staged sans gate clair, on ne peut pas produire un close final conforme FoodKing.
- Ce blocage est de gouvernance, pas une preuve runtime que le POS ne marche pas.
- Il empeche quand meme un audit final PASS.

Action requise:

- Soit produire/joindre le gate qui autorise explicitement la zone frozen staged.
- Soit faire le cleanup de staging dans un cycle humain/gouverne, sans revert de changements utiles.

## 4. Blocage 2 — PHPUnit Complet

Resultat:

```text
Tests: 15 failed, 8 skipped, 1081 passed
```

Fichiers en echec:

- `tests/Feature/AntiGravityFinalTest.php`
- `tests/Feature/AntiGravityTest.php`
- `tests/Feature/ConcurrentOrderTest.php`
- `tests/Feature/POSComprehensiveTest.php`
- `tests/Feature/PosPriorityApiTest.php`
- `tests/Feature/Sentinels/PosSubtotalForgerySentinelTest.php`
- `tests/Feature/SyncComprehensiveTest.php`

Motif dominant:

```text
Expected response status code [200] but received 422.
Article 1 inactif dans le catalogue. Commande rejetee.
```

Analyse:

- Les chemins backend modernes passent quand ils creent ou selectionnent des items actifs.
- Les tests legacy/full-suite semblent encore utiliser `item_id=1` comme fixture implicite.
- Depuis le durcissement `AvailabilityService`, un item inactif est correctement rejete.
- Ce n'est pas automatiquement une regression business; c'est un conflit entre anciennes fixtures et nouvelle regle d'autorite catalogue/stock.
- Mais tant que la suite complete echoue, la release ne peut pas etre declaree verte.

Preuve complementaire:

- Le paquet moderne cible `phpunit-critical-targeted.log` passe `73 tests`, dont:
  - `KioskFullFlowE2ETest`
  - `KioskPaymentStateMachineTest`
  - `PaymentConfirmCrossBranchTest`
  - `PaymentConfirmConcurrencySentinelTest`
  - `QueueNumberConcurrencyTest`
  - `QueueNumberUniquenessSentinelTest`
  - `CatalogStockCentralSyncEndToEndTest`
  - `AvailabilityServiceTest`
  - `KDSFlowTest`
  - `KdsExpectedStatusConflictTest`
  - `KdsTransitionWhitelistTest`
  - `KioskQuoteIntegrityTest`
  - `PosKioskPricingParityTest`
  - `PosPricingSsotProofTest`
  - `PaymentMethodRestrictedTest`
  - `MenuProjectionServiceTest`

Action requise:

- `REWORK-FINAL-01-PHPUNIT-LEGACY-FIXTURES`
- Mettre a jour les fixtures des tests legacy pour creer explicitement un item actif/orderable.
- Ne pas affaiblir `AvailabilityService`.
- Si un test revele un vrai flux cassant, corriger le flux, pas contourner le guard.

## 5. Blocage 3 — Playwright POS Tacos / Recu

Resultat:

```text
1 failed, 34 passed
```

Test en echec:

```text
tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts
POS — Tacos seed-adapted mix -> especes -> recu
```

Premier run:

```text
Locator: #receiptModal #print text /NF525 receipt|SIRET|TVA/i
Expected: visible
Error: element(s) not found
```

Retry:

```text
Locator: #item-variation-modal
Expected: visible
Received: hidden
```

Analyse:

- Le parcours POS cash de base passe (`tests/e2e/02-pos-cash.spec.js`).
- La surface POS charge, le panier demarre vide, aucun crash JS fatal n'est detecte.
- Le test profond tacos/recu echoue sur deux points:
  1. marqueur fiscal visible du recu non trouve (`NF525 receipt`, `SIRET` ou `TVA`);
  2. instabilite du modal variation POS au retry.
- L'absence du marqueur fiscal visible dans le DOM du recu est un vrai risque d'UAT caisse, meme si les tests backend fiscal passent.

Action requise:

- `REWORK-FINAL-02-POS-TACOS-RECEIPT-E2E`
- Verifier le screenshot et trace Playwright:
  - `test-results/e2e-pos-tacos-4-viandes-ca-2fa22-nt-espèces-composition-reçu-chromium/test-failed-1.png`
  - `test-results/e2e-pos-tacos-4-viandes-ca-2fa22-nt-espèces-composition-reçu-chromium/error-context.md`
  - retry trace zip sous le dossier `...-retry1/trace.zip`
- Corriger soit le recu, soit le test si le label fiscal a ete volontairement renomme.
- Stabiliser l'ouverture du modal variation POS pour les items configurables.

## 6. Blocage 4 — Pricing Frontend Guard

Commande:

```text
npm run pos:lint:pricing
```

Resultat:

```text
FAIL — 1 pricing arithmetic violation(s):
resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1184
itemExtraTotal += price * count;
```

Contexte source:

```text
1180 const price = parseFloat(extra.convert_price || extra.price || 0);
1184 itemExtraTotal += price * count;
1198 itemExtraTotal += price;
1205 const sauceVariationSurcharge = extraSauceCount * sauceExtraPrice;
```

Analyse:

- Le backend reste l'autorite du prix via quote/commit; les tests SSOT passent.
- Mais le guard statique FoodKing interdit que du calcul prix frontend soit invisible a l'audit.
- Le calcul peut etre acceptable comme preview UX uniquement, mais il doit etre explicitement encadre par la politique du repo ou remplace par quote preview backend.
- En audit final, un guard qui sort code 1 est bloquant.

Action requise:

- `REWORK-FINAL-03-KIOSK-PRICING-GUARD`
- Option preferee: convertir l'affichage dynamique en lecture du quote preview backend quand disponible, et limiter le frontend a l'assemblage de payload.
- Option temporaire: ajouter un bloc allowlist commente seulement avec signoff Tech Lead + Backend, si le repo accepte ce mode jusqu'au `2026-05-10`.
- Ne jamais faire du total frontend une autorite d'encaissement.

## 7. Blocage / Warning i18n

Commande:

```text
npm run i18n:audit
```

Resultat:

```text
VUE:
  fr: 0 missing
  en: 26 missing
  ar: 69 missing
  de: 138 missing
  bn: 139 missing

LARAVEL:
  fr: 0 missing
  en: 19 missing
  ar: 24 missing
  de: 35 missing
  bn: 32 missing
```

Interpretation:

- Pour la decision humaine actuelle, FR est la langue V1 primaire et elle est complete en missing keys.
- Le script sort en erreur a cause des autres locales et des dead/identical keys.
- Si la CI de release lance ce job strictement, c'est bloquant.
- Si la release V1 est FR-only, c'est un warning de release a documenter et a neutraliser proprement dans le pipeline.

Action requise:

- `REWORK-FINAL-04-I18N-RELEASE-POLICY`
- Decider si V1 bloque sur toutes les locales ou seulement FR.
- Si FR-only: ajuster le job release ou documenter le gate.
- Si multi-locale: remplir les keys manquantes avant release.

## 8. POS / Caisse

Etat valide:

- POS cash de base passe en Playwright.
- POS card surface charge sans crash.
- Backend POS quote binding passe dans les tests modernes.
- `PosPricingSsotProofTest` passe: le serveur ecrase les prix forges.
- `PosKioskPricingParityTest` passe: parite POS/Kiosk sur configurations.
- Remises POS: tests permission/motif/audit passent.
- Paiement ticket restaurant et cash collection kiosk passent dans les tests cibles.
- Branch isolation POS passe dans plusieurs sentinels.

Risques restants:

- Suite legacy POS encore rouge sur fixtures item inactif.
- E2E profond tacos cash/recu fiscal rouge.
- Le modal variation POS n'est pas deterministe dans le retry Playwright.
- Le recu visible doit prouver clairement SIRET/TVA/NF525 ou equivalent FR.

Verdict POS:

`POS_CORE_PASS__POS_FINAL_E2E_RECEIPT_HOLD`

## 9. Borne / Kiosk

Etat valide:

- Kiosk login/navigation Playwright passe.
- Kiosk full flow backend vers KDS passe.
- Quote kiosk obligatoire et integre:
  - commit sans quote token rejete;
  - signature obligatoire;
  - quote expiree -> 410;
  - quote valide -> commande creee.
- Paiement kiosk:
  - card order reste pending jusqu'a confirmation;
  - cash order accepte/paye directement;
  - confirm cross-branch rejete;
  - duplicate TPE reference rejete.
- Offline payment contracts et cart/offline queue Vitest passent.
- Nouveau design wizard compile et ses tests JS passent.

Risques restants:

- Static pricing guard detecte du calcul local dans le wizard.
- Hardware UAT borne physique pas execute.
- WebSocket local non lance pendant browser inspection; warnings de reconnexion attendus.
- In-app browser snapshot sur `/kiosk/idle` a remonte des elements de shell admin dans le DOM; Playwright kiosk reste vert, mais une inspection visuelle finale de l'onglet courant est conseillee.

Verdict Kiosk:

`KIOSK_FUNCTIONAL_PASS__PRICING_GUARD_AND_HARDWARE_UAT_HOLD`

## 10. KDS / OSS / Cuisine

Etat valide:

- KDS route/auth/list Playwright passe.
- `KDSFlowTest`, `KdsExpectedStatusConflictTest`, `KdsTransitionWhitelistTest`, `KitchenReleaseRuleTest` passent.
- KDS allergen snapshot et aggregation passent.
- KDS branch filtering exact passe.
- Outbox KDS/realtime contracts passent dans les tests cibles et sentinels.
- OSS read-only et status screen backend passent dans la suite.

Risques restants:

- Les tests legacy `SyncComprehensiveTest` echouent quand ils creent des commandes avec fixture item inactive.
- Pas encore un vrai live-board POS/KDS/Dashboard unifie comme cible V2.
- Pas de hardware UAT sur ecran cuisine reel.

Verdict KDS:

`KDS_CORE_PASS__LEGACY_SYNC_FIXTURES_AND_HARDWARE_UAT_PENDING`

## 11. Queue Number / File D'Attente

Etat valide:

- `QueueNumberConcurrencyTest`: PASS.
- `QueueNumberUniquenessSentinelTest`: PASS.
- DB unique `(branch_id, queue_number)` local/test existe et est teste.
- `null` queue numbers restent autorises pour legacy rows.
- Meme numero permis entre branches differentes.

Risques restants:

- D-M13 production migration n'est pas executee.
- Le rollout prod requiert preflight doublons, backup, cutover window, rollback.
- Allocateur central `QueueNumberAllocator` reste une dette V2; aujourd'hui les tests couvrent l'unicite DB, pas encore l'abstraction unique.

Verdict Queue:

`QUEUE_LOCAL_TEST_PASS__PROD_ROLLOUT_GATE_PENDING`

## 12. Paiement Simulation / TPE / Live Gateway

Etat valide:

- Decision humaine V1: paiement carte simule/manual external terminal accepte.
- `PaymentMethodRestrictedTest`: PASS.
- `PaymentConfirmCrossBranchTest`: PASS.
- `PaymentConfirmConcurrencySentinelTest`: PASS.
- `PaymentNoopIdempotencyTest`: PASS.
- `StripeActivationGuardTest`: PASS dans la suite complete.
- `WebPaymentDisabledTest`: PASS dans la suite complete.
- POS card surface Playwright sans crash.

Risques restants:

- Aucun gateway live n'est valide comme production payment processor.
- Le systeme ne doit pas pretendre avoir encaisse en live si seul le TPE manuel/simule est actif.
- Hardware TPE/recu physique non UAT.

Verdict Paiement:

`PAYMENT_V1_SIMULATION_PASS__LIVE_GATEWAY_AND_HARDWARE_HOLD`

## 13. Dashboard / Gestion Catalogue / Produit / Categorie / Stock

Etat valide:

- Catalogue central actuel:
  - `items`
  - `item_categories`
  - `item_variations`
  - `item_extras`
  - `item_addons`
  - `item_branch_availability`
- `MenuProjectionServiceTest`: PASS.
- `MenuProjectionControllerTest`: PASS.
- `ItemImageCatalogRefreshTest`: PASS.
- `AdminItemBranchAvailabilityProjectionTest`: PASS.
- `CatalogStockCentralSyncEndToEndTest`: PASS.
- `AvailabilityServiceTest`: PASS.
- `ItemRequestTest`, `ItemCategoryRequestTest`, `ItemAttributeRequestTest`: PASS dans la suite complete.
- `ItemExtraManagementTest`: PASS.
- `ItemAttributeComposerResourceTest`: PASS.

Couverture reelle:

- Backend/API et projections sont bien couverts.
- Les toggles disponibilite/stock V1 sont couverts.
- Le dashboard UI humain complet de CRUD produit/categorie/stock n'est pas encore couvert en E2E Playwright final.
- Stock V1 est disponibilite/cap simple; stock V2 quantitatif append-only n'est pas implemente et reste gate schema.

Verdict Dashboard:

`DASHBOARD_BACKEND_CATALOG_PASS__DASHBOARD_UI_CRUD_E2E_AND_STOCK_V2_PENDING`

## 14. Sync / Realtime / Outbox

Etat valide:

- Events apres commit valides par tests.
- Outbox persist/dispatch/dedupe/rescue passent.
- `CatalogStockCentralSyncEndToEndTest` prouve toggle central -> kiosk/POS/order guard.
- `KioskRealtimeBroadcastTest` prouve payload realtime identity.
- `pos-receives-kiosk-realtime.spec.js` passe.
- Observability sync overview et metrics passent dans la suite.

Risques restants:

- `SyncComprehensiveTest` legacy rouge a cause de fixtures item inactive.
- Realtime WS local non lance pendant certains tests/inspection; les tests contractuels passent, pas le fanout live Pusher reel.
- Les canaux separes `.stock`, `.catalog`, `.orders` restent cible V2.

Verdict Sync:

`SYNC_V1_CORE_PASS__LEGACY_FIXTURES_AND_REAL_WS_UAT_PENDING`

## 15. Fiscal / NF525 / Recu

Etat valide backend:

- Audit hash chain, Z report, fiscal archive, fiscal sequence, branch exactness, refund/void pre/post Z passent dans la suite.
- `FiscalSecretProductionGuardTest` passe.
- `PosReceiptFiscalExposureTest` passe.
- `PosReceiptTaxLinesTest` passe.

Blocage UI/E2E:

- Playwright POS tacos cash/recu ne trouve pas le marqueur visible `NF525 receipt|SIRET|TVA`.
- Pour une caisse reelle, le recu operateur/client doit montrer clairement les mentions fiscales attendues.

Verdict Fiscal:

`FISCAL_BACKEND_PASS__RECEIPT_E2E_HOLD`

## 16. Gouvernance / Release Gates

Open release blockers repris et confirmes:

- D-M13 production rollout: pending.
- Hardware lab UAT: pending.
- Live payment gateway activation: pending; V1 seulement manuel/simule.
- i18n cleanup: pending, meme si FR missing keys = 0.
- Legacy kiosk bundle warning: pending cleanup/shim decision.
- Safety-check frozen staged file: pending gate/cleanup.
- Playwright no `webServer` strategy: E2E depend d'un serveur Laravel deja lance.

Verdict Release:

`COMMERCIAL_RELEASE_NOT_SIGNED`

## 17. Rework Minimal Pour Obtenir PASS Final

Ordre recommande:

1. `REWORK-FINAL-00-GOV-FROZEN-STAGING`
   - regler le fichier frozen staged `app/Services/OrderService.php`;
   - ne pas revert aveuglement.

2. `REWORK-FINAL-01-PHPUNIT-LEGACY-FIXTURES`
   - aligner `AntiGravity*`, `ConcurrentOrder`, `POSComprehensive`, `PosPriority`, `PosSubtotalForgerySentinel`, `SyncComprehensive` sur fixtures actives/orderable;
   - conserver le rejet des items inactifs.

3. `REWORK-FINAL-02-POS-TACOS-RECEIPT-E2E`
   - stabiliser l'ouverture du modal variation POS;
   - restaurer/verifier les mentions recu visibles SIRET/TVA/NF525;
   - relancer Playwright global.

4. `REWORK-FINAL-03-KIOSK-PRICING-GUARD`
   - supprimer ou encadrer le calcul frontend preview dans `KioskWizardComponent.vue`;
   - prouver que le backend reste SSOT.

5. `REWORK-FINAL-04-I18N-RELEASE-POLICY`
   - decider FR-only strict ou multi-locale strict;
   - rendre le job coherent avec la decision V1.

6. `REWORK-FINAL-05-HARDWARE-UAT-PACK`
   - caisse physique, imprimante, tiroir-caisse, borne, ecran KDS, TPE manuel/simule.

7. `REWORK-FINAL-06-DM13-PROD-ROLLOUT`
   - duplicate preflight prod;
   - backup;
   - cutover;
   - rollback rehearsal.

## 18. Commande Finale De Revalidation Apres Rework

```bash
bash .cursor/hooks/safety-check.sh
npm run verify:boucle
php artisan test
npx vitest run
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test
npm run production
bash scripts/lint-fk-bundle-legacy.sh strict
npm run pos:lint:pricing
npm run pos:lint:status
bash scripts/lint-fk-client-totals.sh
bash scripts/lint-fk-branch-isolation.sh
bash scripts/lint-fk-legacy-imports.sh
npm run i18n:audit
```

Critere PASS:

- safety-check exit 0;
- PHPUnit complet 0 failed;
- Vitest complet 0 failed;
- Playwright complet 0 failed;
- guards statiques exit 0 ou gate explicite documente;
- hardware UAT humain signe;
- D-M13 prod rollout signe si vise release commerciale.

## 19. Verdict Final

FoodKing Caisse V1 n'est pas loin: les noyaux modernes POS/Kiosk/KDS/Queue/Paiement/Catalogue passent, et les tests cibles prouvent les invariants principaux.

Mais un audit final doit etre strict:

- suite complete backend rouge;
- E2E POS recu rouge;
- guard pricing rouge;
- i18n audit rouge;
- safety-check frozen rouge;
- gates production/hardware encore ouvertes.

`FINAL_GLOBAL_AUDIT_VERDICT: HOLD_REWORK_REQUIRED`

`NEXT_STATE: REWORK_FINAL_BLOCKERS_THEN_RERUN_GLOBAL_VALIDATION`
