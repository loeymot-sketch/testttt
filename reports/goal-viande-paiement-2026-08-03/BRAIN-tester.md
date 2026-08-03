# BRAIN-tester — Audit couverture READ-ONLY du range `7ba7bd620..HEAD` (14 commits pré-VPS)

Date : 2026-08-03 · Branche : `pos/category-first-caisse-2026-06-23`
Méthode : diff complet lu fichier par fichier, tests du range lus intégralement, vitest exécuté (safe), AUCUN phpunit lancé (DB).

## VERDICT : GAPS P1 — 1 BLOCKER de gate + 3 trous de couverture

### 🔴 BLOCKER P1 — `FrozenZoneSha256BaselineSentinelTest` est ROUGE à HEAD
- `public/js/pos-wizard.js` a été modifié sous LOCK (`plans/LOCK_POS_WIZARD_TICKET_VIANDE_EN_PLUS_2026-08-03.md`, commits `34969acaf`/`f4c0538db`/`c125cf3ff`) mais
  `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` n'a PAS été régénéré.
- Preuve : baseline `7ec8f342f47a…` == sha256 de `git show 7ba7bd620:public/js/pos-wizard.js` (fichier AVANT le range) ; sha256 actuel = `0f62acea0c19…` → MISMATCH.
- La procédure du sentinel exige : baseline mis à jour AVEC citation du LOCK. Le LOCK existe, le baseline non. Toute exécution de la suite (CI/pré-deploy) échoue ici.
- Action avant deploy : régénérer l'entrée `public/js/pos-wizard.js` du baseline en citant le LOCK dans le commit.

## 1. Table fichier source → tests → verdict

| Fichier modifié | Comportement modifié | Tests couvrant | Verdict |
|---|---|---|---|
| `app/Http/Controllers/Auth/GuestSignupController.php` | nom complet mémorisé au canal + scellé au verify | `tests/Feature/Auth/EmailOtpSignupTest.php` (2c, 2d, channel-confusion mis à jour) | OK |
| `app/Http/Requests/GuestSignupEmailOtpRequest.php` | first/last_name requis | idem (422 + Mail::assertNothingSent) | OK |
| `app/Http/Requests/VerifyPhoneRequest.php` | last_name nullable | idem (verify sans nom / avec nom) | OK |
| `app/Services/LoyaltyService.php` | refund par PORTEUR du grand-livre + symétrie statut | `tests/Feature/Loyalty/LoyaltyRefundOwnerAndStatusSentinelTest.php` (3 tests) | OK |
| `app/Services/FrontendOrderService.php` | `whereIn status [1, ACTIVE]` | `tests/Feature/ConcurrentOrderTest.php` (renforcé : 1 seul redeem, 2ᵉ refusée <500) | OK |
| `app/Services/Order/OrderQuoteService.php` | idem côté devis | `ConcurrentOrderTest` (indirect via withQuote) | OK (indirect) |
| `app/Http/Controllers/Frontend/LoyaltyController.php` | création comptoir `status=ACTIVE` (fin boucle 401) | **aucun test trouvé** (grep status/ACTIVE dans LoyaltyApiTest, LoyaltyRegisterAllowsWebLoginTest : rien sur la valeur du status créé) | **GAP** |
| `database/migrations/2026_08_01_190000_activate_legacy_loyalty_customers.php` | activation legacy CLIENTS only (exclut staff avec rôle) | **aucun test** de la logique de périmètre (exécutée mécaniquement par RefreshDatabase mais sélection jamais assertée) | **GAP (P2)** |
| `app/Http/Controllers/Frontend/MolliePaymentController.php` | inline/3ds/reason + regex card_token | `tests/Feature/Payment/MollieStructureTest.php` (inline + 3ds) ; **regex token invalide → 422 non testée** | OK / gap P2 |
| `app/Http/PaymentGateways/Gateways/Mollie.php` | cardToken → method=creditcard, checkout_url optionnelle | `MollieStructureTest` (Http::assertSent : cardToken + montant scellé backend) | OK |
| `app/Services/KitchenDisplaySystemOrderService.php` | plancher 48 h branche advance | `tests/Feature/KDS/KdsAdvanceZombieFloorTest.php` (visible récent / zombie exclu / rien supprimé) | OK |
| `config/oss.php` | `advance_stale_window_hours` | idem (config() forcée dans le test) | OK |
| `app/Services/Hardware/KitchenTicketSymbolicFormatter.php` | strip SEGMENT « en plus » (note ALLERGIE survit) + marqueur ENF | `tests/Unit/Hardware/KitchenTicketSymbolicFormatterTest.php` (+2) · `tests/Feature/Hardware/KitchenTicketBolBaseTest.php` (`ENF BUR` ≠ `BUR`) | OK |
| `public/js/pos-wizard.js` (FROZEN, sous LOCK) | ligne « Viandes en plus : X » émise vers ticket | `tests/js/posWizardViandeSupplementUnified.spec.js` (assert déplacé sur `.ticket-content`) + `tests/Playwright/goal-viande-nommee-2026-08-03.spec.js` | OK (mais baseline sentinel non régénéré → blocker ci-dessus) |
| `resources/js/helpers/kdsSymbolic.js` | marqueur `ENF` (parité PHP) | `tests/js/kdsSymbolicKidsMenu.spec.js` (4 tests, adultes non marqués) | OK |
| `resources/js/helpers/kdsCustomization.js` | strip segment « en plus » (parité PHP) | `tests/js/kdsCustomization.spec.js` (+2, dont ALLERGIE co-localisée) | OK |
| `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue` | badge 86 sur board V2 | `tests/js/kdsV2OosBadge.spec.js` (teste le RENDU, pas le store ; + absence module) | OK |
| `resources/js/components/admin/pos/PosComponent.vue` — `composedVariations/Extras` | fin des « undefined » / « , , » | `tests/js/posCounterCompositionLabels.spec.js` (2 formes réelles + dégradés) | OK |
| `resources/js/components/admin/pos/PosComponent.vue` — `noSale` appelle `POST admin/pos/cash-drawer/open` + message untraced | **traçage réel de l'ouverture tiroir** (titre du commit `d945570b0`) | **aucun test JS** n'asserte l'appel axios ni la bascule `no_sale_untraced`. Backend endpoint couvert par `tests/Feature/Cash/CashDrawerEndpointsTest.php` (pré-existant, inchangé) | **GAP** |
| `resources/js/languages/{fr,en}.json` | clé `no_sale_untraced` | `tests/Feature/Sentinels/I18nNoEmptyKeySentinelTest.php` (structure) | OK |
| `tests/fixtures/parity_php.json` | régénéré (marqueur ENF) | consommé par `tests/js/kitchenParityRealData.spec.js` — **exécuté : 7/7 verts** | OK |

## 2. Qualité des tests ajoutés — comportement vs implémentation

Globalement EXEMPLAIRE sur le piège « test vert qui encode un bug » :
- `posWizardViandeSupplementUnified.spec.js` : le range CORRIGE exactement ce piège — l'ancien assert portait sur le RÉCAP (`buildWizardInstruction`) et restait vert alors que la cuisine recevait l'extra générique ; le nouvel assert porte sur `.ticket-content` (l'artefact SOUMIS, parsé par `extraViandeNames`).
- `ConcurrentOrderTest` : documente l'ancien « vert pour une mauvaise raison » et asserte désormais le grand-livre (`redeem count == 1`) + refus métier `<500`.
- `KdsV2OosBadge` : teste le rendu DOM, pas le getter du store (la régression d'origine était précisément « store OK, rendu absent »).
- `MollieStructureTest` : asserte le payload sortant réel (`Http::assertSent` : cardToken, `amount == total scellé backend`) et non la seule réponse JSON.
- `KitchenTicketSymbolicFormatterTest` / `kdsCustomization.spec.js` : cas adversarial ALLERGIE co-localisée mono-ligne — le vrai risque food-safety du strip.
- ⚠️ Seule faiblesse : dans `goal-viande-nommee-2026-08-03.spec.js`, le test 2 (borne, étape viande) n'a AUCUN `expect()` — il capture screenshot + report.json et loggue. C'est une preuve visuelle, pas une gate : ne pas le compter comme assertion de non-régression.

## 3. Gate pré-deploy minimale (exacte)

Config vérifiée : `phpunit.xml` force `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` → `vendor/bin/phpunit` est DB-safe. (`.env.testing` pointe mysql `foodking_test` — ne PAS passer par `php artisan test --env=testing` pour la gate.)

PHPUnit 9 (1 path par run) :
```
vendor/bin/phpunit tests/Feature/Sentinels/FrozenZoneSha256BaselineSentinelTest.php   # ROUGE aujourd'hui → à réparer d'abord
vendor/bin/phpunit tests/Feature/Auth/EmailOtpSignupTest.php
vendor/bin/phpunit tests/Feature/Loyalty/EmailSignupLoyaltyLinkTest.php
vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyRefundOwnerAndStatusSentinelTest.php
vendor/bin/phpunit tests/Feature/ConcurrentOrderTest.php
vendor/bin/phpunit tests/Feature/Payment/MollieStructureTest.php
vendor/bin/phpunit tests/Feature/KDS/KdsAdvanceZombieFloorTest.php
vendor/bin/phpunit tests/Unit/Hardware/KitchenTicketSymbolicFormatterTest.php
vendor/bin/phpunit tests/Feature/Hardware/KitchenTicketBolBaseTest.php
vendor/bin/phpunit tests/Feature/Sentinels/OssKdsMidnightStraddleTest.php             # fenêtre KDS touchée
vendor/bin/phpunit tests/Feature/Sentinels/KdsTodayWindowTzSentinelTest.php           # idem
vendor/bin/phpunit tests/Feature/Cash/CashDrawerEndpointsTest.php                     # endpoint tiroir appelé par le nouveau noSale
vendor/bin/phpunit tests/Feature/Sentinels/I18nNoEmptyKeySentinelTest.php
```

Vitest (exécuté 2026-08-03 : 62/62 + 19/19 sentinels = VERTS) :
```
npx vitest run tests/js/kdsCustomization.spec.js tests/js/kdsSymbolicKidsMenu.spec.js tests/js/kdsV2OosBadge.spec.js tests/js/posCounterCompositionLabels.spec.js tests/js/posWizardViandeSupplementUnified.spec.js tests/js/kitchenParityRealData.spec.js tests/js/sentinels/posComponentCleanupSentinel.spec.js tests/js/sentinels/kdsInflightOosMarkerStructure.spec.js tests/js/sentinels/i18nForceFRForAdminSurfaces.spec.js
```

## 4. Sentinels pertinents pour le range

| Sentinel | Pourquoi | État |
|---|---|---|
| `tests/Feature/Sentinels/FrozenZoneSha256BaselineSentinelTest.php` | pos-wizard.js FROZEN modifié sous LOCK | **ROUGE (baseline stale)** |
| `tests/Feature/Sentinels/OssKdsMidnightStraddleTest.php` + `KdsTodayWindowTzSentinelTest.php` | fenêtre board KDS modifiée (plancher advance) | à runner |
| `tests/Feature/Sentinels/I18nNoEmptyKeySentinelTest.php` | fr/en.json touchés | à runner |
| `tests/js/sentinels/posComponentCleanupSentinel.spec.js` | PosComponent modifié | VERT (exécuté) |
| `tests/js/sentinels/kdsInflightOosMarkerStructure.spec.js` | store kdsInflight consommé par le nouveau badge | VERT (exécuté) |
| `tests/js/sentinels/i18nForceFRForAdminSurfaces.spec.js` | surfaces admin FR | VERT (exécuté) |
| `tests/Feature/Loyalty/LoyaltyRefundOwnerAndStatusSentinelTest.php` | nouveau sentinel du range lui-même | à runner |

## 5. Trous restants (par priorité)

1. **P1 gate** — baseline frozen-zone à régénérer (blocker mécanique, 2 min, citer le LOCK).
2. **P1 couverture** — traçage `noSale` frontend : aucun test n'asserte que le clic « Ouvrir tiroir » POST `admin/pos/cash-drawer/open` ni la bascule `no_sale_done`/`no_sale_untraced`. La promesse du commit (« VRAIMENT tracée ») peut re-régresser en silence. Proposition : sentinel JS structurel (grep `cash-drawer/open` + `no_sale_untraced` dans PosComponent.vue) ou test @vue/test-utils avec axios mocké.
3. **P2** — `LoyaltyController::check` création comptoir : rien n'asserte `status == Status::ACTIVE` sur le user créé (le bug 401-boucle peut revenir).
4. **P2** — migration `activate_legacy_loyalty_customers` : périmètre (exclusion staff avec rôle / branch_id≠0) jamais asserté.
5. **P2** — `MolliePaymentController` : regex `card_token` invalide → 422 non testée.
