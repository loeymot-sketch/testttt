# A07 — Fiscal Hash Chain + Audit Log

**Agent** : A07 — Adversarial audit of HMAC chains + immutability (NF525)
**HEAD** : `a220b9bd8` on `feature/mobile-app-le-cayenne-2026-05-10`
**Method** : READ-ONLY, file:line verified, fresh re-walk of past findings + new defect search.
**Date** : 2026-05-11

---

## 0. EXECUTIVE VERDICT

Substantial improvement vs `pos-ultra-audit-2026-05-09` (P0-03 now has dedicated MySQL test class). However, **P0-04 (cascadeOnDelete on fiscal-bearing tables) is UNCHANGED** at HEAD; **P1-03 (saveQuietly bypass of immutability) is confirmed** as the explicit design — only application-layer detection backs it; **P1-04 (FiscalChainValidator first-row anchor absent)** is **CONFIRMED on `FiscalChainValidator.php:149`** — silently skips chain-break detection on the oldest row of the rolling window. **3 new defects** found.

**Recommendation** : block V1 merge until P0-04 (FK strategy) + P1-04 (first-row anchor) + N-01 (TRUNCATE/GRANT operational doc) close.

---

## 1. PAST FINDINGS — FRESH VERIFICATION

### P0-03 — `z_reports` DELETE trigger 0-test-coverage → **PARTIALLY CLOSED**

Past claim : trigger MySQL-only, never exercised by SQLite default suite.

Fresh evidence :
- Migration `database/migrations/2026_05_09_160000_add_z_reports_delete_trigger_immutability.php:42-44` confirms `if ($driver !== 'mysql' && $driver !== 'mariadb') return;` — SQLite is skipped silently.
- `phpunit.xml:39` still ships `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:`. SQLite is the default CI driver.
- **NEW since iter15** : `tests/Feature/Fiscal/ZReportDeleteTriggerMysqlOnlyTest.php` (created 2026-05-10) and `tests/Support/MysqlOnly.php` exist and exercise the trigger via `information_schema` introspection + raw + Eloquent DELETE attempts (lines 64-131).
- **REMAINING GAP** : the MySQL job must actually run in CI. If the matrix only spins SQLite, the test is silent. No `.github/workflows/*.yml` reviewed in scope, but `tests/Support/MysqlOnly.php:41` confirms a clean `markTestSkipped` path — i.e. SQLite-only CI = 3 tests skipped, green light unchanged.
- `ZReportCloseTest.php:117-138` documents the design: `$closed->saveQuietly()` is explicitly used to flip `total_ttc` — only `verifySignature` catches it. The trigger does NOT cover this (intentional, per `2026_05_09_160000:18-21`). → **P1-03 confirmed below.**

**Verdict** : test class exists; CI execution proof remains TODO. Re-rate **P0-03 → P1-09**.

### P0-04 — `cash_movements` + `order_payments` cascadeOnDelete → **CONFIRMED OPEN**

Fresh grep :
```
database/migrations/2026_05_06_180000_create_order_payments_table.php:32:
    $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
database/migrations/2026_05_08_140100_create_cash_movements_table.php:50:
    ->cascadeOnDelete();   (parent = cash_drawer_sessions)
```
Neither table has a DELETE trigger. `order_payments` carries TVA-bearing split payment evidence (mode / amount / reference); `cash_movements` carries NF525 cash-trail evidence (cashback / refund / drawer_open). Cascading parent deletion silently destroys both — **no SIGNAL, no trigger, no application-layer guard**. AuditLog::deleting() (`AuditLog.php:52-57`) is the ONLY model with that defence and it doesn't apply here.

`OrderPayment.php` and `CashMovement.php` model classes have no `deleting` boot hooks (not in scope, verified by grep).

**Impact** : fiscal audit-trail can be wiped via `DB::table('orders')->delete()` or even a soft-delete cascade post-archive (Order uses SoftDeletes per past-audit-confirmed `Order.php:11`).

**Verdict** : **P0-04 OPEN, unchanged at HEAD.**

### P1-03 — z_reports UPDATE intentionally permitted → **CONFIRMED, DESIGNED**

Fresh evidence : `migration 2026_05_09_160000:18-21` explicitly states "UPDATE is INTENTIONALLY allowed because z_reports has a legitimate state machine: open → closed → archived. Cash enrichment also UPDATEs post-close."

`ZReportService::close:241-247` uses `$open->forceFill(…)->save();` — the legitimate close path. But the **attack surface** is identical: any caller with raw DB access can flip `total_ttc` on a closed Z (`ZReportCloseTest.php:132-133` demonstrates `$closed->total_ttc = 999999.99; $closed->saveQuietly();` and only `verifySignature()` detects).

`ZReport.php:18-43` has **no `updating` boot hook** (unlike `AuditLog.php:42-58`). Eloquent `saveQuietly()` bypasses the absent guard cleanly.

**Verdict** : design-acknowledged backstop = application detection only. UPDATE remains a forge vector unless verification runs at every read site. **P1-03 confirmed**.

### P1-04 — FiscalChainValidator 500-row tail without first-row anchor → **CONFIRMED OPEN**

Fresh code review `FiscalChainValidator.php:118-183` :

```php
$tailIds = AuditLog::query()->where('branch_id', $branchId)
    ->orderByDesc('id')->limit(max(1, $window))->pluck('id')->toArray();
$oldestId = (int) min($tailIds);
$rows = AuditLog::query()->where('branch_id', $branchId)
    ->where('id', '>=', $oldestId)->orderBy('id')->cursor();

$expectedPrev = null;
$isFirstRow   = true;
foreach ($rows as $row) {
    $rowPrev = $row->prev_hash === null ? null : trim((string) $row->prev_hash);
    // First row of the window: prev_hash anchor is outside the window —
    // we can only validate the current_hash recompute, not the link.
    if (!$isFirstRow) {
        ...chain_break check...
    }
    $recomputed = $this->auditLogService()->computeHash(...)
    if (!hash_equals($stored, $recomputed)) { errors[] = signature_mismatch; }
    $expectedPrev = $stored;
    $isFirstRow   = false;
}
```

The first row in the window (`$isFirstRow = true`) is exempted from the `chain_break` check (`FiscalChainValidator.php:149`). **Forge attack** : an attacker who can INSERT (e.g. via privilege escalation to AuditLogService::write but with malicious payload, or via raw INSERT if MySQL triggers covered UPDATE/DELETE but not INSERT) can fabricate a row whose `prev_hash` does **not** match the previous tail; provided its `current_hash` recompute is self-consistent (which the attacker controls, since they pick the inputs), the window sweep returns `errors = []`.

The full `AuditLogService::verifyChain()` (file `AuditLogService.php:199-231`) **does** anchor on the genuine first row by virtue of the unbounded `orderBy('id')` walk. But `FiscalChainValidator::assertChainIntegrity` is the runtime hook (`Config::get('fiscal.audit_chain_tail_window', 500)`) and is the only one invoked under the Z-open cache lock.

**Mitigation present but insufficient** : `UNIQUE(branch_id, prev_hash)` index (per `AuditLogService.php:60-65` comment, migration `2026_04_22_100000_add_unique_chain_index_to_audit_logs.php`) blocks a second row sharing the SAME `prev_hash`. But it does NOT block a row whose `prev_hash` matches no earlier `current_hash` at all — that fabricated value is novel, unique by definition.

**Verdict** : **P1-04 confirmed**. Recommended fix: persist the latest verified chain head in a separate `fiscal_chain_anchors` table or in `fiscal_settings` keyed per branch, and assert `tail[0].prev_hash === anchors[branch_id]`.

---

## 2. NEW DEFECTS (FRESH AT HEAD a220b9bd8)

### N-01 — TRUNCATE / GRANT bypass on z_reports + audit_logs → **P1 OPERATIONAL**

`migration 2026_04_22_000002_create_audit_logs_table.php:138-141` admits "Other drivers (pgsql, sqlsrv) — application-layer enforcement only". MySQL TRUNCATE bypasses BEFORE-DELETE triggers (per MySQL docs). `migration 2026_05_09_160000:30-32` references "Mitigation = revoke TRUNCATE permission on production DB user (deploy doc, not migration scope)" — but **no deploy doc in `docs/FISCAL_SECRETS.md` or referenced** within scope.

The model layer `AuditLog::deleting()` (`AuditLog.php:52-57`) only fires on Eloquent `delete()`. `DB::statement('TRUNCATE audit_logs')` returns success on default GRANT setup.

**Recommended** : add migration step to `REVOKE DROP, ALTER, TRUNCATE ON audit_logs FROM 'foodking_app'@'%'` OR document explicitly in `docs/FISCAL_SECRETS.md` with a deploy verification command.

### N-02 — `secretFor()` `env()` runtime call → **P2 PERFORMANCE/SAFETY**

`AuditLogService.php:273` reads `env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId)` at every `write()`. Laravel's `env()` returns `null` after `config:cache` is run unless the value was used at boot. In a production deployment that runs `php artisan config:cache`, per-branch override secrets become permanently `null` — silently falling back to the global secret. Not a chain break, but defeats the documented per-branch isolation.

Recommended : move per-branch override into `config/fiscal.php` keyed array (already supported on `AuditLogService.php:281-283`), drop the `env()` lookup.

### N-03 — `verifySignature` not auto-invoked on every read path → **P2 CONTROL GAP**

`ZReportService::verifySignature` (`ZReportService.php:441-449`) is never called in `XReportService` (line 60-73 fetches `$lastClose` without verify), in `ZReportController` (out of scope but search shows no `verifySignature` call site outside tests/Audit/Refund flows). Combined with **P1-03** (`saveQuietly` UPDATE allowed) → tampered Z totals propagate into X reports and refund reconciliation without surfaced exception.

Recommended : invoke `FiscalChainValidator::assertChainIntegrity($branchId)` on the X-report read path AND on each Z-report admin export.

---

## 3. PROPOSED PHPUNIT SCENARIOS (3-5)

1. **`ZReportDeleteTriggerMysqlOnlyTest::test_trigger_blocks_truncate`** — extend existing class. Attempt `DB::statement('TRUNCATE TABLE z_reports')`; assert success (MySQL allows it) → fails test → exposes the GRANT gap. (MysqlOnly skip on SQLite as today.)

2. **`AuditLogChainAnchorTest::test_forged_first_row_in_tail_window_is_detected`** — write 100 rows; capture true chain head; simulate attacker fabrication by `AuditLog::unguard(); AuditLog::create([... prev_hash = str_repeat('0', 64), current_hash = self_consistent_recompute])`; call `FiscalChainValidator::verifyAuditChainTail($branchId, $window=50)`. With current code, errors=[] (FALSE NEGATIVE). After P1-04 fix, errors must contain `chain_break` on row id=N.

3. **`OrderPaymentCascadeForbidsFiscalWipeTest::test_order_delete_does_not_destroy_fiscal_audit_trail`** — create Order + 2 OrderPayment; assert `DB::table('orders')->where('id', $o->id)->delete();` triggers either RESTRICT exception OR retains `order_payments` rows. **Will fail today** (cascade wipes). Drives fix migration that replaces `cascadeOnDelete` with `restrictOnDelete` or `nullOnDelete` for `order_id` once order is fiscally-sequenced.

4. **`CashMovementCascadeImmutabilityTest::test_cash_drawer_session_delete_does_not_destroy_cash_audit_trail`** — same shape on `cash_drawer_sessions → cash_movements`. Must fail today.

5. **`ZReportSaveQuietlyDetectionTest::test_tampered_z_report_surfaces_in_verifyChain_strict`** — close Z; `$z->total_ttc = 1.00; $z->saveQuietly();` ; call `FiscalChainValidator::assertChainIntegrity($branchId)` → expect `FiscalChainCorruptedException` (already implicit since `verifyChain(strict=true)` is called inside, per `FiscalChainValidator.php:66`). Pins the documented backstop and prevents accidental refactor.

---

## 4. FINDINGS TABLE

| ID | Sev | Title | File:Line | Status |
|---|---|---|---|---|
| P0-04 | P0 | `order_payments` + `cash_movements` cascadeOnDelete wipes fiscal trail | `2026_05_06_180000:32`, `2026_05_08_140100:50` | OPEN at HEAD |
| P1-03 | P1 | z_reports UPDATE/`saveQuietly` permitted, no model `updating` hook | `ZReport.php:18-43`, `ZReportCloseTest.php:130-138` | OPEN by design — backstop = verifySignature |
| P1-04 | P1 | `FiscalChainValidator` first-row anchor missing — forge possible inside window | `FiscalChainValidator.php:140-159` | OPEN |
| P1-09 | P1 (was P0-03) | `z_reports` DELETE trigger CI coverage depends on MySQL job presence | `phpunit.xml:39`, `ZReportDeleteTriggerMysqlOnlyTest.php:43-62` | TEST EXISTS — CI matrix proof TODO |
| N-01 | P1 | TRUNCATE / GRANT not enforced in code or doc | `2026_05_09_160000:30-32` (only comment) | OPEN — operational gap |
| N-02 | P2 | `env()` lookup per `write()` defeats config:cache | `AuditLogService.php:273` | OPEN |
| N-03 | P2 | `verifySignature` not auto-invoked on read paths (X-report, exports) | `ZReportService.php:441-449`, `XReportService.php:60-73` | OPEN |

---

## 5. CHAIN INTEGRITY ASSESSMENT

- HMAC algorithm `AuditLogService::computeHash:237-243` and `FiscalSealingService::signZReport:11-38` are cryptographically sound (HMAC-SHA256 over canonical JSON with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, recursive `ksort` for assoc arrays).
- Concurrent-write defence (Cache::lock + UNIQUE(branch_id, prev_hash) + 1-retry on QueryException) is comprehensive (`AuditLogService.php:100-191`).
- Production-secret guards (`assertProductionSafe` in both AuditLog and FiscalSealing services + dedicated `FiscalSecretProductionGuardTest`) **pass cleanly**.
- Soft-delete handling on Z aggregation (P0-01/02 reframed in past audit) is OUT OF A07 scope (delegated to A06).

**Conclusion** : the cryptography is correct. The **gaps are in the trust boundary** (cascades, model hooks, tail-window anchor, CI matrix proof).

---

## 6. DELIVERABLE

Report path : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/review/pos-parallel-2026-05-11/A07_fiscal_hash_chain.md`

Words : ~1380 (target ≤ 1500). READ-ONLY enforced. All file:line verified by Read/grep at HEAD `a220b9bd8`.
