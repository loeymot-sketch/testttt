# LOCK plan — Fiscal verify-chain test anon class fragility

**ID** : LOCK_FISCAL_TEST_ANON_CLASS_2026-05-18
**Date** : 2026-05-18
**Status** : OWNER GATE PENDING (G6 per `plans/GOAL_V1_PRODUCTION_PERFECT_PHASE2_2026-05-18.md` §G)
**Author** : Claude orchestrator (T-1.3.1)
**Branche** : `heal/cms-pr1-quickwins-2026-05-18`

---

## §1 — Why this LOCK exists

`tests/Feature/Fiscal/FiscalVerifyChainCommandTest.php:255-283` uses an inline anonymous class to override `App\Services\Fiscal\AuditLogService::verifyChain()` and force a `RuntimeException` (to assert the command returns exit code 3 = execution error).

```php
$this->app->bind(AuditLogService::class, function () {
    return new class extends AuditLogService
    {
        public function __construct() {}   // <-- the fragility

        public function verifyChain(?int $branchId = null): ?int
        {
            throw new \RuntimeException('Simulated DB outage / missing secret');
        }
    };
});
```

The anon class explicitly declares an empty constructor — necessary today because `AuditLogService` has no explicit constructor (PHP uses the default). If a future hardening adds constructor injection :

```php
// HYPOTHETICAL future change
class AuditLogService
{
    public function __construct(
        private readonly FiscalSequenceService $sequence,
        private readonly Encrypter $encrypter,
    ) {}
    ...
}
```

...the anon class `public function __construct() {}` would still be valid PHP but would create an instance with **null** dependencies. Any subsequent call to a method that touches `$this->sequence` or `$this->encrypter` would throw a `TypeError` BEFORE our test's `RuntimeException` line was reached, breaking the test in a confusing way.

## §2 — Frozen-zone touch (NF525)

`AuditLogService` IS a frozen-zone file (cf. `memory/reference_frozen_zones.md` + `CLAUDE.md §7`). Any constructor signature change requires owner gate.

This LOCK is meta : we are NOT proposing to change `AuditLogService` itself. We are proposing to harden the TEST so that a future legitimate frozen-zone-gated change doesn't silently break it.

## §3 — Scope of proposed test heal (owner-gated)

### Option A — DI-aware anon class
Rewrite the anon class to accept whatever dependencies the parent declares via spread-and-forward :

```php
$this->app->bind(AuditLogService::class, function () {
    return new class(/* will be injected by container */) extends AuditLogService
    {
        public function verifyChain(?int $branchId = null): ?int
        {
            throw new \RuntimeException('Simulated DB outage / missing secret');
        }
    };
});
```

The container resolves the parent's ctor args automatically. **Risk** : if parent ctor uses readonly or private promotion, anon class cannot trivially override behavior — but we don't OVERRIDE the ctor, we let the parent's ctor run.

### Option B — Mockery substitute
Replace the anon class with a Mockery partial mock :

```php
$mock = Mockery::mock(AuditLogService::class)->makePartial();
$mock->shouldReceive('verifyChain')->andThrow(new RuntimeException('Simulated'));
$this->app->instance(AuditLogService::class, $mock);
```

**Risk** : Mockery vs Container DI can get cute with ordering. Pre-existing tests use anon classes for a reason (no Mockery deps).

### Option C — Defer to V1.0.X with rationale (RECOMMENDED for V1)
Document the fragility, do not touch test today. The test PASSES today (verified Wave 1 PHPUnit smoke). Heal only when an actual frozen-zone change forces the issue.

## §4 — Files to touch (Option A or B)

- `tests/Feature/Fiscal/FiscalVerifyChainCommandTest.php` lines 263-273 only.
- NO production code change.
- NO LOC change to AuditLogService.php.

## §5 — Justification

Option A preserves the anon class pattern used elsewhere in the suite, requires zero new deps. Option B introduces Mockery dependency-style mocks that diverge from the existing test idiom. Option C accepts the fragility as-is until forced.

## §6 — Rollback

Revert the single git commit. Test reverts to pre-heal anon class. No state migration required.

## §7 — Safety-check.sh override config

```yaml
LOCK_FILE: tests/Feature/Fiscal/FiscalVerifyChainCommandTest.php
LOCK_LINES: 263-273
LOCK_RATIONALE: anon-class-DI-robustness
OWNER_GATE: REQUIRED
NF525_IMPACT: zero (test-only, no chain touch)
ROLLBACK_COMPLEXITY: trivial (single commit revert)
```

## §8 — Sub-agent instructions (if Option A chosen post-owner gate)

```
Goal: make the anon class robust to future AuditLogService ctor changes.
Step 1: Run baseline test (must be GREEN).
Step 2: Edit lines 263-273 to remove `public function __construct() {}` declaration.
Step 3: Validate test still GREEN.
Step 4: Stage + commit with reference to this LOCK.
Step 5: Do NOT touch any production code under app/Services/Fiscal/.
```

## §9 — Decision matrix

| Option | LOC | Test cost | Risk | Owner gate | Verdict |
|---|---|---|---|---|---|
| A — DI-aware | 1 (delete `__construct() {}`) | run 1 PHPUnit test | low | owner sign §10 | **PROPOSED** |
| B — Mockery | ~5 | run 1 PHPUnit test | medium (deps) | owner sign §10 | alt |
| C — defer V1.0.X | 0 | none | as-is | none | **OK for V1** |

## §10 — Owner sign-off

```
[ ] Option A — anon class DI-aware (1 LOC delete)
[ ] Option B — Mockery substitute
[X] Option C — Defer V1.0.X — accept fragility as-is (RECOMMENDED V1)

Owner signature : __________________________________
Date : 2026-05-_____
Commit ref : __________________________________
```

---

**Pending owner countersign** before any test code change. Until signed, Option C (defer) is the operational state.
