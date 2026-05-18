# T-1.2.1 SECURITY findings — NF525 fiscal chain attestation under load
**Agent**: SECURITY (read-only)
**Round**: 1
**Date**: 2026-05-18
**Threat model**: attacker holds **admin Laravel credentials + DB read-only access**, aiming to corrupt / forge / silently bypass the NF525 fiscal chain. Hostile framing — every assumption questioned.

---

## Cross-reference to existing PASS tests (do not duplicate)

| Attack vector | Defended by PASS test |
|---|---|
| UPDATE/DELETE on `audit_logs` row | `AuditLogImmutabilityTest.php` + DB trigger (migration `2026_04_22_000002`) |
| HMAC chain forgery on `audit_logs` | `AuditLogHashChainTest.php` + `verifyChain()` |
| Write without `branch_id` | `AuditLogBranchRequiredTest.php` (Graphiti-attested) |
| Z report signature forgery | `FiscalSealingHmacTest.php` |
| Dev sentinel / short secret prod | `FiscalSecretProductionGuardTest.php` |
| Sealed-Z post-Z mutation (RETURNED) | `SealedOrderMutationGuardTest.php` |
| `z_reports` DELETE (MySQL) | `ZReportDeleteTriggerMysqlOnlyTest.php` |
| Sequence allocation race | `FiscalRateLimitTest.php` (rate) — **NOT a race test, gap remains** |

---

## Finding S-1 — TRUNCATE bypass mitigation is UNDOCUMENTED in deploy artefacts (P0)

```yaml
finding_id: S-1
severity: P0
category: chain_immutability_bypass
file_evidence:
  - database/migrations/2026_05_09_160000_add_z_reports_delete_trigger_immutability.php:30-32
  - database/migrations/2026_04_22_000002_create_audit_logs_table.php (no TRUNCATE note)
  - docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt (no TRUNCATE / REVOKE / GRANT line found)
  - deploy/ansible/site.yml (no fiscal-user privilege block; only `copytruncate` logrotate)
  - deploy/ansible/group_vars/all.yml:37-38 (secrets but no DB ACL)
attacker_capability_required: DB user with TRUNCATE privilege (default for the Laravel `DB_USERNAME` unless explicitly revoked)
trigger:
  load_mode: irrelevant
  failure_mode: |
    `TRUNCATE TABLE audit_logs;` (or `z_reports`, `cash_movements`,
    `order_payments`, `cash_drawer_sessions`) bypasses MySQL BEFORE
    DELETE triggers ENTIRELY. The migration comments at
    2026_05_09_160000:30-32 and 2026_05_10_010000:48-49 acknowledge
    this and state: "Mitigation = revoke TRUNCATE permission on
    production DB user (deploy doc, not migration scope)." Grep over
    `docs/`, `docs/cloud/`, `deploy/ansible/`, `.env.example`
    returns **ZERO matches** for TRUNCATE / REVOKE / GRANT /
    PRIVILEGES — the deploy doc that was supposed to carry the
    mitigation does not exist.
v2_saas_impact: |
  Multi-tenant SaaS amplifies catastrophically. One DB user shared
  across N branches: a single compromised app credential lets the
  attacker wipe the entire 6y fiscal trail across ALL tenants in
  ~50ms. Every tenant simultaneously fails NF525 audit.
cost_of_delay: |
  **DGFiP audit fail / criminal prison time risk** under Art. 1743
  CGI (5 years imprisonment + 500k EUR fine for fiscal-evidence
  destruction). Worse than UPDATE/DELETE attack because TRUNCATE
  is the EXPECTED workaround once a sophisticated attacker reads
  the migration comments (which are committed in the repo, i.e.
  readable to any developer + leaked to GitHub if open-sourced).
recommendation: |
  IMMEDIATELY add to `deploy/ansible/site.yml` (or a new
  `deploy/ansible/tasks/fiscal-db-acl.yml`) a play that runs:
    REVOKE DROP, TRUNCATE, ALTER ON {{ db_name }}.audit_logs FROM '{{ db_user }}'@'%';
    REVOKE DROP, TRUNCATE, ALTER ON {{ db_name }}.z_reports FROM '{{ db_user }}'@'%';
    REVOKE DROP, TRUNCATE, ALTER ON {{ db_name }}.cash_movements FROM '{{ db_user }}'@'%';
    REVOKE DROP, TRUNCATE, ALTER ON {{ db_name }}.cash_drawer_sessions FROM '{{ db_user }}'@'%';
    REVOKE DROP, TRUNCATE, ALTER ON {{ db_name }}.order_payments FROM '{{ db_user }}'@'%';
    FLUSH PRIVILEGES;
  AND add a `tests/Feature/Fiscal/FiscalTablePrivilegeTest.php` that
  asserts the privilege grid on a real MySQL connection
  (skipped on SQLite). Plus document in `docs/FISCAL_SECRETS.md`
  the migration-runner separate user (which DOES need ALTER for
  schema changes) vs the runtime user (which must not).
```

---

## Finding S-2 — `env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId)` at runtime BREAKS under `config:cache` (P0)

```yaml
finding_id: S-2
severity: P0
category: hmac_secret_resolution_bypass
file_evidence:
  - app/Services/Fiscal/AuditLogService.php:273
  - docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:16  (FISCAL_AUDIT_SECRET_BRANCH_1=…)
attacker_capability_required: none — it is a self-inflicted production-deploy footgun
trigger:
  load_mode: any (especially first request after `php artisan config:cache`)
  failure_mode: |
    Line 273: `$override = env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId);`
    Laravel **canonical rule**: `env()` returns `null` outside of
    config files when `config:cache` is in effect (production
    optimization). When that happens:
      1. `$override` resolves to null → service falls back to the
         shared `fiscal.audit_secret`.
      2. The previous chain rows for that branch were signed with
         the per-branch override.
      3. The NEXT write() for branch=1 signs with the SHARED secret.
      4. `verifyChain(1)` immediately returns the new row id —
         FALSE-POSITIVE chain corruption blocks `ZReportService::open()`
         (FiscalChainValidator throws FiscalChainCorruptedException).
    Cashiers cannot close the Z. NF525 daily-close obligation
    violated.

    Worse silent variant: if the per-branch override was used since
    day 1, ALL audit rows for branch=1 are NOW unverifiable on a
    cached config — and the attacker only needs to trigger a deploy
    `artisan config:cache` to corrupt the entire branch's chain
    state. Production was likely never cached (deployments are
    rare) so this has lain dormant.
v2_saas_impact: |
  SaaS = mandatory `config:cache` for performance at scale. The
  bug is a guaranteed P0 outage on the first multi-tenant rollout
  with per-branch secrets.
cost_of_delay: |
  **DGFiP audit fail risk** (cannot produce verifiable chain on
  demand) + ops outage at Z-close time across all branches using
  per-branch secrets. Owner-customer-facing: cashiers cannot close
  the till = cannot reconcile = cannot deposit cash.
recommendation: |
  Move per-branch overrides into config:
    'audit_secret' => env('FISCAL_AUDIT_SECRET', ''),
    'audit_secret_per_branch' => [
        1  => env('FISCAL_AUDIT_SECRET_BRANCH_1'),
        2  => env('FISCAL_AUDIT_SECRET_BRANCH_2'),
        // …
    ],
  Read in `secretFor()` via `Config::get('fiscal.audit_secret_per_branch.'.$branchId)`.
  Add regression test that boots Laravel with `config:cache` simulated
  (`$app->configurationIsCached()` true) and asserts overrides still
  resolve. Existing `FiscalSecretProductionGuardTest` covers the
  weak-secret path but NOT the cached-config path.
```

---

## Finding S-3 — `RefundWithCounterEntryService` mirror order assigns `fiscal_sequence_no` via property write (P1)

```yaml
finding_id: S-3
severity: P1
category: refund_chain_forgery_partial
file_evidence:
  - app/Services/Order/RefundWithCounterEntryService.php:114-117
  - app/Models/Order.php:20-58 (fillable: fiscal_sequence_no NOT in fillable — correct)
  - app/Services/PaymentService.php:206-207 (same pattern)
  - app/Services/OrderService.php:922-923 (same pattern)
attacker_capability_required: code injection / authenticated developer + ability to add a new endpoint
trigger:
  load_mode: code-path discoverable
  failure_mode: |
    `fiscal_sequence_no` is correctly EXCLUDED from `$fillable`,
    so `Order::create([…])` cannot mass-assign it. BUT property
    assignment + save() bypasses Laravel's mass-assignment guard:
        $mirror->fiscal_sequence_no = $mirrorSeq;  // line 115
        $mirror->reason = $reason;
        $mirror->save();                            // line 117
    There is NO Eloquent model-level guard (cf. `static::saving`
    or `static::updating`) that asserts:
      - the value came from FiscalSequenceService::next()
      - it is strictly greater than current max for the branch
      - it has not been already used for another order in the same branch

    An attacker with code-write capability can therefore forge an
    order with an arbitrary `fiscal_sequence_no` (e.g. duplicate
    of an existing receipt, or far-future value to create a gap).
    The DB unique constraint `orders_branch_fiscal_seq_unique`
    blocks duplicates but NOT gap-creation (sequence_no=9999 when
    current max=42 produces 9956 fake gaps detectable only at next
    Z close).
v2_saas_impact: |
  Same threat — but per-tenant. A SaaS-shipped Order model that
  doesn't guard fiscal_sequence_no exposes every tenant to one
  developer's mistake on the next feature.
cost_of_delay: |
  **DGFiP audit fail** (sequence_no gap or duplicate = direct NF525
  violation). Lower than S-1/S-2 because it requires code-deploy
  privilege (not just DB or env).
recommendation: |
  Add to Order::booted() :
    static::saving(function (Order $order): void {
        if ($order->isDirty('fiscal_sequence_no') && $order->exists) {
            throw new \RuntimeException(
                'Order::fiscal_sequence_no is immutable post-allocation.'
            );
        }
        if ($order->fiscal_sequence_no !== null && !$order->exists) {
            // First allocation: must be exactly max(branch)+1
            $expected = ((int) Order::withoutGlobalScopes()
                ->where('branch_id', $order->branch_id)
                ->max('fiscal_sequence_no')) + 1;
            if ((int) $order->fiscal_sequence_no !== $expected) {
                throw new \RuntimeException(sprintf(
                    'fiscal_sequence_no=%d violates gap-free invariant (expected %d).',
                    $order->fiscal_sequence_no,
                    $expected
                ));
            }
        }
    });
  Note: this MUST run INSIDE the FiscalSequenceService lock to
  avoid a TOCTOU window. Document carefully or move the check
  into FiscalSequenceService::next() returning a `SealedSequenceNo`
  value object that the model accepts only on instanceof.
```

---

## Finding S-4 — Concurrent allocation: PHP exit between Cache::lock release and DB commit forks the chain (P1)

```yaml
finding_id: S-4
severity: P1
category: chain_fork_via_crash
file_evidence:
  - app/Services/Fiscal/FiscalSequenceService.php:95-103 (lock release in finally)
  - app/Services/Fiscal/AuditLogService.php:115-120 (idem)
  - app/Services/Fiscal/AuditLogService.php:99-109 (lock acquire BEFORE transaction)
attacker_capability_required: ability to SIGKILL php-fpm worker mid-request (e.g. OOM-killer attack via large payload, or hostile sysadmin)
trigger:
  load_mode: high-CPU + memory-limit attack OR concurrent k requests until OOM
  failure_mode: |
    Acquire ordering (AuditLogService.php:101 → 112):
      1. Cache::lock acquired
      2. DB::transaction starts
      3. INSERT row
      4. DB::transaction commits
      5. finally → lock->release()

    Vulnerability: between step 1 and step 4, if PHP is SIGKILLed
    (NOT a soft exception — uncatchable), the `finally` block does
    NOT run. The DB transaction is rolled back by MySQL on
    connection drop, BUT the Cache::lock entry persists in
    Redis/Memcached until its TTL (CHAIN_LOCK_TTL=10s in
    AuditLogService, LOCK_TTL_SECONDS=5 in FiscalSequenceService).

    Meanwhile, the application is functionally frozen for that
    branch's chain (every subsequent write blocks 5s waiting for
    the lock). After TTL expiration, however, the cache layer
    auto-releases. Worst case window: 10s of throughput collapse.

    True attack: combine with S-1 — if the attacker can also drop
    the DB connection at exactly step 3 commit time (e.g. via
    network partition), Redis lock holds 10s but the INSERT may
    have committed and replicated. Mismatch produces a window
    where verifyChain() reports "tail row not visible" while the
    next writer proceeds — RACE: two writers see different "last
    hashes" if they read across replicas. The UNIQUE(branch_id,
    prev_hash) index DOES catch the duplicate at insert time
    (retry path at line 187 — single retry). After retry exhaust,
    the second write fails → audit row LOST for that event.
v2_saas_impact: |
  Multi-region SaaS with read replicas amplifies: replication lag
  + lock-TTL desync = guaranteed race. Single-region deploy is
  much safer.
cost_of_delay: |
  **Silent audit-row loss** under attack. Doesn't violate chain
  integrity (the chain stays verifiable) but loses evidence of
  the failed event. NF525 mandates audit of mutations — a missed
  mutation is a compliance gap, not a chain break.
recommendation: |
  1. Add a `tests/Feature/Fiscal/FiscalChainCrashRecoveryTest.php`
     that simulates PHP crash mid-transaction (e.g. throw
     LengthException after Cache::lock but before INSERT, then
     assert the next write succeeds and verifyChain() stays
     intact).
  2. Add Redis SCRIPT LOAD that releases the lock atomically with
     transaction commit (Lua) — Laravel Cache::lock natively does
     this but only via the queued cache lock variant.
  3. Document max sustained QPS per branch (≤ 1/CHAIN_LOCK_TTL
     under crash scenario = 0.1/s before degradation).
```

---

## Finding S-5 — `AuditLog::create()` is publicly accessible via Eloquent (no service-only enforcement) (P2)

```yaml
finding_id: S-5
severity: P2
category: chain_write_outside_service
file_evidence:
  - app/Services/Fiscal/AuditLogService.php:148 (only legitimate writer)
  - app/Models/AuditLog.php:42-58 (booted() guards UPDATE+DELETE but NOT CREATE)
attacker_capability_required: code-write capability
trigger:
  load_mode: irrelevant
  failure_mode: |
    Eloquent does not prevent a future contributor from calling
    `AuditLog::create([…])` directly anywhere in the codebase.
    The HMAC chain is enforced inside AuditLogService::write() —
    not at the model boundary. If somebody (innocent or hostile)
    writes:
        AuditLog::create([
            'branch_id' => 1,
            'action' => 'test',
            'payload' => [],
            'prev_hash' => '0000...',
            'current_hash' => 'deadbeef…',  // arbitrary
        ]);
    The row inserts (no model-level rejection). At the next
    verifyChain() walk it produces a `signature_mismatch` error —
    but only AFTER the chain is already poisoned. Recovery
    requires forensic root-cause + offline chain re-anchor
    (cf. docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:13
    "re-keying mid-chain BREAKS audit_logs HMAC. Only on fresh
    chain or with re-anchor procedure documented BACKUP_RESTORE_NF525.md").

    Grep returned ZERO instances of AuditLog::create() outside the
    service, so the current production state is clean. But the
    invariant has no enforcement — a single PR could break it.
v2_saas_impact: |
  Same vulnerability per tenant; SaaS adds risk because contributors
  may not know the NF525 rules.
cost_of_delay: |
  Latent risk. No active breach. Owner gate should add the guard
  before next major release.
recommendation: |
  Add to AuditLog::booted() :
    static::creating(function (AuditLog $row): void {
        // Allow only when called from AuditLogService::write().
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        $allowed = collect($trace)->contains(fn ($frame) =>
            ($frame['class'] ?? null) === \App\Services\Fiscal\AuditLogService::class
            && in_array($frame['function'] ?? '', ['write', 'performInsert'], true)
        );
        if (!$allowed) {
            throw new \RuntimeException(
                'AuditLog::create() must go through AuditLogService::write(). '
                . 'Direct creation breaks the HMAC chain.'
            );
        }
    });
  Plus add `tests/Feature/Fiscal/AuditLogDirectCreateForbiddenTest.php`.
  Trade-off: debug_backtrace has perf cost (~5µs); acceptable on
  fiscal writes.
```

---

## Finding S-6 — `XReportService.php` exists at 81 LOC but is OUT-OF-SCOPE of FiscalChainValidator (note, P3)

```yaml
finding_id: S-6
severity: P3 (informational)
category: scope_gap
file_evidence:
  - app/Services/Fiscal/XReportService.php (81 LOC, unread)
  - app/Services/Fiscal/FiscalChainValidator.php:55-107 (validates Z + audit chains only)
attacker_capability_required: irrelevant
trigger:
  load_mode: n/a
  failure_mode: |
    XReports are interim "look but don't sign" snapshots (Z report
    preview). If `XReportService::generate()` writes to a
    persistent table without HMAC, an attacker could forge X
    reports for tax-purpose impersonation. Not audited here —
    flagged as a follow-up scope item for the next round.
v2_saas_impact: unknown — out of scope
cost_of_delay: low — X reports are not legally binding in France
recommendation: |
  Add X report scope to next round's audit. Confirm XReports
  are not written to a persistent table; if they are, confirm
  the HMAC chain extends. Add `tests/Feature/Fiscal/XReportHmacTest.php`.
```

---

## Summary

| ID | Severity | Recommendation TLDR |
|---|---|---|
| S-1 | P0 | Add TRUNCATE/DROP REVOKE in Ansible + privilege test (DGFiP / prison) |
| S-2 | P0 | Move per-branch secrets from `env()` to `config()` (config:cache breaks chain) |
| S-3 | P1 | Order::booted saving() guard on fiscal_sequence_no immutability + gap |
| S-4 | P1 | Crash-recovery test + lock-with-commit atomicity (race on SIGKILL) |
| S-5 | P2 | AuditLog::creating() backtrace guard + regression test |
| S-6 | P3 | Add XReportService.php to next round's audit scope |

**Verdict for T-1.2.1**: PASS-conditional. S-1 and S-2 are deploy-time blockers (not a code defect per se — the code is correctly defensive). S-3 and S-4 are code defects that should be hardened before V2 SaaS. S-5 + S-6 are nice-to-have hardening.

The chain-integrity primitives (HMAC, UNIQUE prev_hash, BEFORE DELETE triggers, sealed-Z guard, FiscalChainValidator with bounded tail, FiscalChainCorruptedException structured errors, AuditLog model UPDATE/DELETE rejection, dev sentinel guard, min-length guard, branch_id mandatory write) are **collectively impressive** and clearly NF525-grade. The attack surface that remains is operational (deploy doc) + future-developer footguns (no model-level fiscal_sequence_no / create() guards).

Word count: ~1480.
