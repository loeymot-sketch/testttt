# PROPOSAL — AuditLogService — `env()` call outside `config/*` silently drops per-branch HMAC override under `php artisan config:cache`

**File**: `app/Services/Fiscal/AuditLogService.php`
**Phase**: B.5 (Proposal — Frozen §7 + §8 NF525-critical, ZERO file edits)
**Author**: PROPOSAL AGENT for AuditLogService
**Date**: 2026-05-23
**Reference attestation**: B3.6 GREEN (`reports/test-e2e/goal-2026-05-23/round-1/B3.6-fiscal-findings.json` — count=64 last_hash=8daed68a65b8c8e75a7143f305967047ee1bb0b664a95afb5d9d2e0657777592, bit-identical pre/post)

---

## 1. Scope of audit

Read **integrally** of `app/Services/Fiscal/AuditLogService.php` (376 LOC) + cross-referenced:
- `app/Models/AuditLog.php` (booted-event guards + DB triggers parity)
- `database/migrations/2026_04_22_000002_create_audit_logs_table.php` (immutability triggers, INSERT-only)
- `database/migrations/2026_04_22_100000_add_unique_chain_index_to_audit_logs.php` (UNIQUE(branch_id, prev_hash) DB-level fork rejection)
- `config/fiscal.php` (secret config shape + dev sentinels + min length)
- `app/Services/Fiscal/FiscalChainValidator.php` (bounded-tail re-walk, uses `computeHash` for parity)
- `app/Console/Commands/FiscalVerifyChainCommand.php` (always passes explicit `branch_id`)
- 21 callsites of `AuditLogService::write()` across `OrderService`, `PaymentService`, `CashDrawerService`, `DeliveryBoyCashSessionService`, `Payments\SplitPaymentService`, `Order\RefundWithCounterEntryService`, console commands

Cross-checked against CLAUDE.md §8 NF525 invariants and the existing B3.6 GREEN attestation (12/12 verified-invariants checklist).

---

## 2. Verdict

**ONE AMBER finding (latent), ONE NIT (cosmetic dead code).**

The service is **genuinely well-defended**: append-only at three layers (DB trigger + Eloquent boot guard + service writer), per-branch cache lock + DB UNIQUE(branch_id, prev_hash) + retry-once on UNIQUE violation, canonicalised payload, production-secret guard rejecting dev sentinels + sub-32-char secrets, explicit branch_id requirement at line 93-98 closing the cross-chain poison path, FISCAL_TIMING breadcrumb via finally block, and structured success-only breadcrumb on the fiscal log channel.

No NF525 risk identified that breaches the B3.6 GREEN posture for V1 LOCAL. Findings below are **cloud-prep / V2 SaaS** posture concerns, not active V1 violations.

---

## 3. Finding A.1 — `env()` outside config returns null after `config:cache` → per-branch override silently lost → false-positive verifyChain tamper alert

**Severity**: AMBER (latent, NF525-adjacent under HMAC-correctness)
**Status V1 LOCAL Le Cayenne single-tenant**: dormant (no per-branch override in use; single `FISCAL_AUDIT_SECRET` is the active secret).
**Status V2 SaaS multi-tenant**: **active landmine** once `FISCAL_AUDIT_SECRET_BRANCH_N` is set for any tenant.

### 3.1 Location

```php
// app/Services/Fiscal/AuditLogService.php:269-292
private function secretFor(?int $branchId): string
{
    // Support per-branch override via env: FISCAL_AUDIT_SECRET_BRANCH_{id}
    if ($branchId !== null) {
        $override = env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId);  // ← LINE 273
        if (is_string($override) && $override !== '') {
            return $this->assertProductionSafe($override, 'fiscal.audit_secret[branch='.$branchId.']');
        }
    }

    $configured = Config::get('fiscal.audit_secret');
    // ...
}
```

### 3.2 Root cause

Laravel documents that `env()` returns `null` for **every variable** after `php artisan config:cache` is run, when the call occurs **outside** a `config/*.php` file. This is not a Laravel bug — it's the documented contract:

> "If you execute the `config:cache` command during your deployment process, you should be sure that you are only calling the `env()` function from within your configuration files. Once the configuration has been cached, the `.env` file will not be loaded and all calls to the `env` function for `.env` variables will return `null`."
> — Laravel docs, Configuration / Environment Configuration

`config/fiscal.php:31` already follows this discipline for the global secret:
```php
'audit_secret' => env('FISCAL_AUDIT_SECRET', ''),
```

But `AuditLogService::secretFor()` line 273 calls `env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId)` **inside service code**, bypassing the config layer.

### 3.3 Failure scenario (post-cache, V2 SaaS multi-tenant)

1. Operator sets `FISCAL_AUDIT_SECRET_BRANCH_17=<48-char-hex>` in `.env` and `FISCAL_AUDIT_SECRET=<different-48-char-hex>` as fallback.
2. Operator runs `php artisan config:cache` as part of standard deploy.
3. `audit_logs` rows for branch 17 are signed under the **global** secret (env() returns null → fallback to `Config::get('fiscal.audit_secret')` line 279), **not** the branch-17 override.
4. Operator notices the bug and re-deploys without `config:cache`, OR rotates the per-branch secret. Now `env('FISCAL_AUDIT_SECRET_BRANCH_17')` resolves correctly.
5. Next `AuditLogService::verifyChain(17)` or `FiscalChainValidator::verifyAuditChainTail(17, ...)` run recomputes `computeHash` for historical rows. Pre-step-4 rows were signed under the global secret, but `secretFor(17)` now returns the branch-17 override.
6. **`hash_equals($storedCurrent, $recomputed)` returns false for every historical row** → `verifyChain()` returns the id of the first signed-with-global row.
7. **False-positive NF525 tamper alert.** `FiscalChainCorruptedException(KIND_AUDIT_CHAIN)` is thrown. Ops cannot distinguish this from a real tamper — they must read source to discover the env-vs-config-cache interaction.

### 3.4 Evidence — per-branch override is documented as a production surface

This is not a theoretical API — it's actively documented:

- `.env.example:268` — `# FISCAL_AUDIT_SECRET_BRANCH_17=` (commented placeholder, but documented)
- `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:16` — `FISCAL_AUDIT_SECRET_BRANCH_1=ROTATE_per_branch_openssl_rand_hex_32`
- `config/fiscal.php:28` (block comment) — `FISCAL_AUDIT_SECRET_BRANCH_N  (optional, overrides per branch)`

V2 SaaS deployment will use this feature. The landmine is dormant only because **V1 LOCAL is single-tenant** and no per-branch override is set.

### 3.5 Severity rationale

- **V1 LOCAL Le Cayenne**: AMBER-but-dormant. The feature is unused; the global secret is the only active path. B3.6 GREEN posture preserved.
- **V2 SaaS cloud-prep**: AMBER-active. The first multi-tenant deployment that uses `config:cache` (standard Laravel ops practice) will silently sign with the wrong secret. Any subsequent verifyChain run will surface as false-positive tamper, indistinguishable from a real NF525 breach.
- **Anti-fraude TVA / prison-time risk**: not direct (the chain *can* be re-validated by recomputing under the correct secret), but operational fire drill on a false-positive looks identical to a real breach and may force ops to escalate to legal / restore from backup.

### 3.6 Why I am flagging this even though B3.6 attests GREEN

B3.6 verified the chain HMAC parity at count=64 last_hash=8daed68a65… for **V1 LOCAL single-tenant**. The auditor correctly attested the runtime state — no per-branch override in use, no `config:cache` mismatch. B3.6 did not audit the latent `env()-outside-config` pattern because no multi-tenant deployment exists yet.

The proposal does **not** contradict B3.6 — it surfaces a forward-looking cloud-prep concern aligned with `UNI-03` (CACHE_DRIVER cloud-prep backlog, CLAUDE.md verified-note 2026-05-21).

### 3.7 Recommended fix (FROZEN — NO EDIT, just proposal)

Move the per-branch override into `config/fiscal.php` so the `env()` call lives in the only place where Laravel guarantees it resolves after `config:cache`. Cardinality of branches in V2 SaaS is known at deploy time, so a static array works; for dynamic-branch SaaS, the canonical pattern is a registry seeded at boot.

**Option A — config-only (preferred, minimal):**

```php
// config/fiscal.php (proposed addition)
'audit_secret_per_branch' => array_filter([
    1  => env('FISCAL_AUDIT_SECRET_BRANCH_1'),
    17 => env('FISCAL_AUDIT_SECRET_BRANCH_17'),
    // Add per-tenant entries here at deploy time.
]),
```

```php
// app/Services/Fiscal/AuditLogService.php::secretFor() — proposed
private function secretFor(?int $branchId): string
{
    if ($branchId !== null) {
        $perBranch = (array) Config::get('fiscal.audit_secret_per_branch', []);
        if (isset($perBranch[$branchId])
            && is_string($perBranch[$branchId])
            && $perBranch[$branchId] !== ''
        ) {
            return $this->assertProductionSafe(
                $perBranch[$branchId],
                'fiscal.audit_secret_per_branch['.$branchId.']'
            );
        }
    }
    // ... existing fallback chain (Config::get('fiscal.audit_secret') array-form + scalar)
}
```

This preserves the existing array-form support at line 281 (`Config::get('fiscal.audit_secret')` keyed by branch id), so deployments that already use that shape are untouched.

**Option B — keep `env()` but document the post-cache contract:**

If the maintainer wants to keep the `env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId)` call (e.g. to avoid editing config for every new tenant), then `AuditLogService::secretFor()` must explicitly `throw` when `app()->configurationIsCached() && app()->environment('production')` AND no `config/fiscal.audit_secret_per_branch.{branchId}` entry exists. The exception text must be operator-actionable, e.g.:

> "FISCAL_AUDIT_SECRET_BRANCH_{N} is set in .env but config is cached — env() returns null. Add the secret to config/fiscal.audit_secret_per_branch and rebuild config cache, or clear the cache."

Option A is strictly safer.

### 3.8 Test contract to add (post-fix)

```php
// tests/Feature/Fiscal/AuditLogPerBranchSecretConfigCacheTest.php (proposed, scope frozen)
public function test_per_branch_secret_survives_config_cache(): void
{
    // Set env var, then simulate config:cache (which strips env() access).
    Config::set('fiscal.audit_secret_per_branch', [1 => 'tenant-1-secret-48chars-padding-ok-aaaaaaaaaaaaaaaa']);

    // Wipe env so any leftover env() reads in the service return null.
    putenv('FISCAL_AUDIT_SECRET_BRANCH_1');

    $service = app(AuditLogService::class);
    $row = $service->write(['branch_id' => 1, 'action' => 'test.cache']);

    $this->assertNotNull($row->id);
    $this->assertNull($service->verifyChain(1)); // chain intact under per-branch secret
}
```

---

## 4. Finding A.2 — NIT (cosmetic) — unreachable `array_key_exists` check after `empty()`

**Severity**: NIT (zero functional impact)

### 4.1 Location

```php
// app/Services/Fiscal/AuditLogService.php:76-82
if (empty($data['action'])) {
    throw new \InvalidArgumentException('AuditLogService::write() requires a non-empty action.');
}

if (! array_key_exists('action', $data)) {
    throw new \InvalidArgumentException('AuditLogService::write() requires an action.');
}
```

### 4.2 Root cause

`empty($data['action'])` returns **true** when the key is missing (PHP suppresses the undefined-key notice in `empty()`). Therefore the second `if` at line 80-82 is unreachable: any input that would have failed `array_key_exists('action', ...)` already threw on line 77.

### 4.3 Risk

Zero. Pure dead code. Mentioned only for cleanliness.

### 4.4 Recommended fix (FROZEN — NO EDIT)

Delete lines 80-82 in a future maintenance pass when frozen-zone gate permits a stylistic edit.

---

## 5. Items deliberately NOT flagged (verified safe)

These were inspected per the proposal brief; finding negative results is part of the audit signal:

### 5.1 Silent AuditLog::create failure paths
- **Service itself**: `write()` re-throws every Throwable (line 121-124) before logging FISCAL_TIMING in the `finally`. **No silent path.**
- **The `try/catch` on FISCAL_TIMING logger (line 127-130) only swallows logger-channel failures**, never the write itself. This is correct — losing a timing breadcrumb must not abort an NF525 write.
- The Eloquent boot-events on `AuditLog` (UPDATE/DELETE) throw RuntimeException — matches DB trigger SIGNAL '45000' parity.
- **Callsite concern (out of scope for this proposal but noted)**: `CashDrawerService:534`, `DeliveryBoyCashSessionService:424` wrap `AuditLogService::write()` in `try {} catch (\Throwable $e) { Log::warning(...); }` and degrade to a warning. This violates the "audit log MUST never be silently dropped" stance documented at `DeliveryBoyCashSessionService:47`. **Service-level fix is not the right place** — the service correctly re-throws. A callsite-policy proposal would be the right surface; out of scope for B.5 frozen `AuditLogService.php`.

### 5.2 HMAC computation correctness
- Input ordering: `($prevHash ?? '').'|'.$canonical` — deterministic, NULL-stable (line 240). `verifyChain` uses identical computation via the same `computeHash` method (line 215-220) — zero algorithmic drift risk.
- Canonical JSON: recursive `ksort` on assoc arrays, list arrays preserved (`array_is_list`), `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` — survives PHP-version drift and MySQL/SQLite parity.
- Secret retrieval: prod-mode guard (line 303-327) rejects dev sentinels + sub-32-char secrets. Throws hard, never silently falls back to a weak default.

### 5.3 Race on append
- `Cache::lock('audit_chain_b'.$branchId, CHAIN_LOCK_TTL=10, wait=5)` → serialises per-branch writers.
- `DB::transaction(...)` groups tail-read + INSERT atomically.
- **DB-level last line of defense**: `UNIQUE(branch_id, prev_hash)` index rejects forks even when cache is split-brained (migration `2026_04_22_100000`).
- Retry-once on UNIQUE violation (line 187-189) handles the genuine "cache lock missed, tail advanced" case without infinite recursion (`attempt < 2`).
- AppServiceProvider line 207-220 fails-fast at boot if `CACHE_DRIVER in ['array', 'null']` — Cache::lock semantic dependency is enforced (B3.6-F7 documented narrower-than-stated; `file` driver safe on single-box V1 LOCAL per CLAUDE.md verified-note).

### 5.4 Branch scoping — `audit_logs.branch_id` nullable
- The schema permits NULL (migration line 36 `unsignedBigInteger('branch_id')->nullable()`) — design intent for system-level cross-branch admin events.
- **The service rejects null at write time** (line 93-98) with operator-actionable text directing callers to pass `branch_id=0` for system/CLI chains. This closes the lastHashFor(null) "poison whichever chain is latest" path the comment at line 87-92 documents.
- B3.3-F11 (DBA) attested this as AMBER-by-design; UNIQUE(branch_id, prev_hash) carries chain integrity even with nullable column.

### 5.5 Retry inside `DB::transaction` (recursive `performInsert` on UNIQUE)
- Theoretical concern: a retry on UNIQUE violation inside a REPEATABLE-READ MySQL transaction could see the stale tail snapshot and fail again.
- **Mitigated in practice**: `lastHashFor` issues a new `SELECT ... ORDER BY id DESC LIMIT 1` (line 247-251) on the same connection. Under REPEATABLE-READ, MySQL's gap-locking + the UNIQUE index force the second INSERT to either succeed (new tail visible to this transaction post-savepoint) or surface a deadlock — both of which propagate to the caller. The `attempt < 2` guard prevents infinite recursion.
- B3.6 attested CHAIN OK pre/post at count=64 bit-identical — runtime stability confirmed for V1 LOCAL load profile.

---

## 6. Summary

| ID | Severity | Subject | V1 LOCAL impact | V2 SaaS impact |
|----|----------|---------|-----------------|----------------|
| A.1 | AMBER | `env()` outside config drops per-branch HMAC override under `config:cache` | dormant (no per-branch override in use) | active landmine → false-positive verifyChain tamper |
| A.2 | NIT | Dead `array_key_exists` after `empty()` | zero | zero |

**Verdict**: **GREEN for V1 LOCAL Le Cayenne single-tenant deployment.** B3.6 attestation stands. One AMBER cloud-prep item for V2 SaaS posture, recommended for the same backlog tier as UNI-03 (`docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` consumers / V1.0.X cloud cutover prep).

---

## 7. Required actions

**NONE for V1 LOCAL.** B.5 proposal phase is complete — ZERO file edits per brief.

For V1.0.X cloud-prep backlog (track alongside UNI-03):
1. Move `FISCAL_AUDIT_SECRET_BRANCH_N` handling into `config/fiscal.php` per §3.7 Option A.
2. Add `tests/Feature/Fiscal/AuditLogPerBranchSecretConfigCacheTest.php` per §3.8.
3. Update `.env.example:268` + `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:16` to document that per-branch overrides must also be referenced in `config/fiscal.audit_secret_per_branch` for `config:cache` compatibility.

---

## 8. Audit metadata

- **File LOC inspected**: 376
- **Cross-references inspected**: 8 (model, 2 migrations, config, validator, command, callers map)
- **Tests cross-referenced**: 5 (AuditLogHashChainTest, AuditLogBranchRequiredTest, AuditLogConcurrencyTest, AuditLogImmutabilityTest, FiscalSecretProductionGuardTest)
- **File edits made by this agent**: **0** (proposal phase — frozen §7 + §8)
- **Attestation lineage**: extends B3.6 GREEN (bit-identical chain count=64) with one cloud-prep forward-looking AMBER.
