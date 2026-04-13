# Execution reports (merged)

## Post-Stabilisation Robustness Cycle

**Date:** 2026-03-31  
**Type:** Claude deep hardening + QA stabilization  
**Status:** READY_FOR_HEADLESS_VALIDATION / NEEDS_DEVICE_VALIDATION

### Source of truth (read-only)

- Le socle kiosk/POS/KDS/OSS a été stabilisé après audit :
  - permissions/roles legacy réalignés sur les contrôleurs réels
  - fixtures/tests incomplets réparés
  - routes, statuts HTTP et payloads de test réalignés sur les contrats actuels
  - `OrderStatusChanged` ajouté sur le path client self-cancel pour éviter un délai de 30s côté KDS/OSS
  - `BranchScope` et `DefaultAccess` remis en cohérence en environnement de test
  - `CompanyResource` et `DiningTableService` durcis pour mieux tolérer les données/config partielles

- La validation PHP monolithique n’est plus bloquée par une vague d’échecs métier, mais par une limite mémoire du runner.
- En compensation, une stratégie de validation PHP **par lots** a été mise en place via scripts dédiés.

### Validation exécutée

#### JS

```bash
npm test
npm test -- --run tests/js/KioskWizard.spec.js
npm run production
```

**Résultats :**
- Vitest complet : **108 passed**
- `KioskWizard.spec.js` isolé : **66 passed**
- Build production : **OK**

#### PHP ciblé critique

Suites repassées en vert individuellement :
- `tests/Feature/AddressSecurityTest.php`
- `tests/Feature/AdminCrudComprehensiveTest.php`
- `tests/Feature/AntiGravityFinalTest.php`
- `tests/Feature/AntiGravityLoginRedirectionTest.php`
- `tests/Feature/AntiGravityManualTest.php`
- `tests/Feature/AntiGravityTest.php`
- `tests/Feature/BranchScopeTest.php`
- `tests/Feature/KDSFlowTest.php`
- `tests/Feature/KDSScopeRestrictionTest.php`
- `tests/Feature/KioskScopeIsolationTest.php`
- `tests/Feature/KioskSecurityTest.php`
- `tests/Feature/LoyaltyApiTest.php`
- `tests/Feature/OrderFlowTest.php`
- `tests/Feature/PosDiscountTest.php`
- `tests/Feature/PosUITest.php`
- `tests/Feature/SecurityComprehensiveTest.php`
- `tests/Feature/SyncComprehensiveTest.php`

#### Validation par lots

```bash
bash scripts/run_php_feature_batches.sh all
bash scripts/profile_php_memory.sh
```

**Résultats :**
- `auth-security` : OK
- `kiosk-pos-sync` : OK
- `admin-seeders-reports` : OK
- Profil mémoire écrit dans `reports/execution/php_memory_profile_latest.md`

### Outils ajoutés

- `scripts/run_php_feature_batches.sh`
- `scripts/profile_php_memory.sh`

### Documentation mise à jour

- `docs/TEST_PLAN.md`
- `docs/API_MAP.md`
- `scripts/README.md`

### Residual Risks

- `php artisan test` complet reste sensible à la mémoire du runner malgré les suites vertes isolées et par lots.
- Le flux réel borne/TPE/device n’a pas encore été validé sur un environnement browser/device configuré.
- Le runtime local actuel indique :
  - `broadcast=pusher`
  - `queue=database`
  - `kiosk_auto=no`
  Ce dernier point bloque un tunnel borne browser réellement autonome sans préparation runtime supplémentaire.

### Next Step

- Lire `reports/antigravity/latest.md` pour la synthèse de validation headless sync
- Lire `reports/review/latest.md` pour le verdict readiness actualisé
- Utiliser `scripts/run_php_feature_batches.sh` comme pipeline PHP de référence tant que le run monolithique reste limité par la mémoire

---

## REAL-CYCLE-001 (documentation alignment)

**cycle_id:** `bfebb694-c71d-4310-9731-4a9e6f7053fd`  
**task_id:** `REAL-CYCLE-001`  
**Date:** 2026-04-12  
**Scope:** Documentation-only alignment of `OrderStatus` integers with `app/Enums/OrderStatus.php` (P1-01).

Enum `App\Enums\OrderStatus` (interface `app/Enums/OrderStatus.php`):

| Constant | Integer |
|----------|---------|
| PENDING | 1 |
| ACCEPT | 4 |
| PREPARING | 7 |
| PREPARED | 8 |
| OUT_FOR_DELIVERY | 10 |
| DELIVERED | 13 |
| CANCELED | 16 |
| REJECTED | 19 |
| RETURNED | 22 |

**No PHP, test, migration, or route files were modified.**

### Per-file verification

#### `docs/BUSINESS_RULES.md`

- **Checked:** §4 pipeline and terminal states already match the enum (PENDING(1) through DELIVERED(13), plus CANCELED/REJECTED/RETURNED).
- **Changed:** no (already correct).

#### `docs/DATABASE_SCHEMA_CORE.md`

- **Checked:** Mermaid `ORDER.status` annotation lists all nine statuses with correct integers.
- **Changed:** no (already correct).

#### `.cursor/rules/safety.mdc`

- **Before:** Pipeline listed main flow; terminal states referred to as “(+ états terminaux enum)” without explicit integers.
- **After:** Same pipeline plus explicit `CANCELED (16)`, `REJECTED (19)`, `RETURNED (22)` and pointer to `app/Enums/OrderStatus.php`.

#### Other docs (out of write scope)

- Searched `docs/` for legacy wrong order-status patterns (e.g. PENDING(5), DELIVERED(17), PREPARED(14) as **order** status). `docs/CONTRIBUTING_QA_BOTS.md` mentions “14 pour PREPARED” only as a **warning against** wrong docs — no change required in allowed files.
- `docs/ARCHITECTURE_TECHNIQUE.md`, `docs/roles/*`, etc. still contain simplified flow text without `OUT_FOR_DELIVERY`; **not edited** (outside `files_allowed` for this cycle).

### Validation

- Command: `php artisan test --filter=Order`
- **Result:** 61 passed (exit 0).

### Files changed (this execution)

1. `.cursor/rules/safety.mdc` — explicit terminal `OrderStatus` integers + file reference.
2. `reports/execution/latest.md` — this report.
3. `bot/inbox/cursor_result/cursor_done.json` — cycle completion signal.
