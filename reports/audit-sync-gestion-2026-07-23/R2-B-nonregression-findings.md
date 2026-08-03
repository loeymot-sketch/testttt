# R2-B — Non-régression des dimensions VERTES après les 4 guérisons cycle-1

**Rôle** : CYCLE 2 — vérifier que les heals cycle-1 (B-1, B-2, D-1, D-2) n'ont PAS fait régresser
money-path / sync-cascade / NF525. **READ-ONLY, rien muté/commité.**
HEAD `552e85409` · range heals `b8084b310..HEAD` · DB-safe : PHPUnit sqlite `:memory:` (phpunit.xml,
env immutable → `.env.testing`=MySQL réel jamais touché) + `fiscal:verify-chain` read-only (0 write-op).

## VERDICT : ✅ 0 RÉGRESSION — dimensions vertes stables (P0=0, P1=0)

## Tableau chemins critiques

| Chemin critique | Verdict | Preuve |
|---|:-:|---|
| **Money-path** — PENDING→ACCEPT→…→encaissé (counter-collect) ; DELIVERED→RETURNED (refund gaté) | **VERT** | filtre `Refund\|Receipt\|Payment\|CashBack\|ChangeStatus\|CounterCollect` = **447 tests OK, 1552 assertions, 0 fail** (1 `Incomplete` pré-existant) |
| **D-1 garde** — refuse UNIQUEMENT terminal→actif, jamais un flux légitime | **VERT** | `TerminalOrderResurrectionGuardTest` **8/8, 13 assert**. Tests explicites : « legit PENDING→ACCEPT unaffected », « ACCEPT→PREPARING unaffected », « non-admin terminal→active stays blocked ». Garde bloque `from∈{CANCELED,REJECTED,RETURNED} ∧ to∉terminal` → DELIVERED→RETURNED (from=DELIVERED) passe. |
| **Cascade OrderCreated** (outbox/mails/conso matière) + **OrderCanceled double-listener** | **VERT** | filtre `Order\|Sync\|Availability\|RawMaterial` = **1184 tests OK, 3663 assert, 0 fail** (6 skip + 1 incomplete pré-existants). |
| **B-1 non-interférence** — `ReleaseStockOnOrderCanceled` (stock_levels) vs nouveau `ReverseRawMaterialsOnOrderCanceled` (matière) | **VERT** | 3 listeners OrderCanceled tjrs registrés, nouveau **APPENDÉ en dernier** (ne peut halt les 2 existants) ; `ShouldQueue`+try/catch isolé ; **ledgers disjoints** (stock_levels ≠ raw_material_movements) ; idempotent (`source_type='order_item_reversal'` dédié + `reversalExists`). `RawMaterialConsumptionTest` (rendu 5→5→0) vert. |
| **NF525 chaîne** | **VERT ×4** | `fiscal:verify-chain --all` → branches 1/7/8/9 `CHAIN OK` → « SWEEP COMPLETE — CHAIN OK on every active branch (4 total) ». |
| **Frozen zones = 0 ligne** | **VERT** | `git diff --stat b8084b310..HEAD` sur Fiscal/, PricingService, **OrderStateMachine** (intact), IdempotencyKeyMiddleware, BranchScope, kiosk*, PaymentComponent, PosV5TrancheRow, pos-wizard = **VIDE**. D-1 posé en `OrderService.php` (NON-frozen), defense-in-depth. |
| **vitest smoke** | **VERT** | `npx vitest run tests/js` = **353 files, 2525 passed \| 3 skipped, 0 fail**. |

## Comptes de tests
- PHPUnit money-path : **447/447** (1 incomplete pré-existant)
- PHPUnit cascade/BOM : **1184/1184** (6 skip + 1 incomplete pré-existants)
- PHPUnit D-1 garde : **8/8**
- vitest : **2525 passed / 3 skipped** (2528, 0 fail) — conforme attendu ~2525
- NF525 : **4/4** branches OK
- Frozen diff : **0 ligne**

Skips/incompletes = markers PRÉ-EXISTANTS dans des tests hors-heal ; les tests AJOUTÉS par les heals
(D-1 8/8, RawMaterial 41/41) sont 100% verts → non introduits par le cycle-1.

## Attestation
Les 4 guérisons cycle-1 (B-1, B-2, D-1, D-2) **ne régressent aucune dimension verte**. Money-path
au vert (447), cascade OrderCreated/OrderCanceled saine avec les 3 listeners coexistants sans se
marcher dessus (1184), NF525 OK ×4, frozen 0, vitest 2525. La garde D-1 refuse strictement
terminal→actif et préserve toutes les arêtes d'encaissement/remboursement légitimes.
**0 régression, dimensions vertes stables.**
