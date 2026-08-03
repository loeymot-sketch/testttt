# WJ-7 / WI-7 STATUS — CLAUDE.md §9 contradiction + 8 drifts healed

**Date** : 2026-05-19
**Wave** : J (Heal Wave I)
**Task** : WJ-7 — fix CLAUDE.md §9 hard contradiction + D1–D8 drifts
**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**Discipline** : doc-only fix, source-of-truth alignment
**Heal pattern** : edit CLAUDE.md + new sentinel for ongoing drift protection
**Frozen-zone touch** : 0
**Code touch** : 0 (docs + 1 new sentinel test only)

---

## §0 Executive verdict

**Status** : GREEN — all 8 D1–D8 drifts healed in a single commit.

CLAUDE.md is now consistent with the canonical source-of-truth files :
- `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php` (BranchScope baseline)
- `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php` (FormRequest baseline)
- `app/Providers/AppServiceProvider.php` (production boot guards)
- `app/Models/User.php:90` (User IS BranchScope-scoped)

A NEW sentinel `tests/Feature/Sentinels/ClaudeMdBranchScopeCountSentinelTest.php`
locks the §9 model count + Customer-vs-User exemption at PR time — drift
recurrence prevented at CI level.

---

## §1 Drifts healed (D1–D8)

### D1 — §7 Frozen list incomplete (P1) — HEALED
Added 2 paths to §7 "Backend (multi-tenant + payment critical)" section :
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`

Both verified to exist on disk + BRAIN §2 attests them untouched in
6+ session entries (Ultra-goal, V1.0.1, Wave Z). Operationally frozen
since 2026-05-09 — now canon.

### D2 — §8 NF525 production boot guards (P2) — HEALED
Added new subsection "Production boot guards (concrete enforcement)"
after `### Pricing SSOT`. Documents the 4 RuntimeExceptions raised at
boot in `app/Providers/AppServiceProvider.php:78-145` :
- `POS_SIMULATION_HARDWARE != false` → refuse boot
- `IDEMPOTENCY_MIDDLEWARE_ENABLED != true` → refuse boot
- `APP_DEBUG = true` → refuse boot
- `APP_URL` empty → refuse boot

Anchors : commits `2477a2d05`, `dafb6b3c4`, `1e7c65ecc`, `2949e92ed`
(all verified to exist via `git show`).

### D3 — §8 TRUNCATE bypass concrete reference (P2) — HEALED
Replaced :
> "TRUNCATE bypass mitigé via GRANT level (deploy doc)"

With :
> "TRUNCATE bypass mitigé via GRANT-level REVOKE on `audit_logs` +
> `z_reports` (Ansible task CVP0-1, commit `f840c3ef5`)"

Commit `f840c3ef5` verified (Round 1 cross-validated P0 Sec S-1 +
Fiscal F-FISC-002).

### D4 — §9 BranchScope paragraph (P0 hard contradiction) — HEALED — STRONGEST
**Before (CLAUDE.md:274-279, stale since 2026-05-09)** :
> "`BranchScope` global appliqué sur 11 models post iter11+12 :
>  Order, FrontendOrder, OrderItem, OrderPayment, KioskMachine,
>  StockLevel, StockMovement, CashDrawerSession, CashMovement,
>  PendingPaymentConfirmation, PushNotification, DiningTable, Printer
>  ...
>  User model exempté pour éviter Sanctum recursion"

**Issues** :
- Count "11" was wrong — actual is **20** declarations
- List enumerated **13** names, not 11 (internal inconsistency)
- "User model exempté" was the inverse of reality — User IS scoped
  (User.php:90), Customer is the exempt one (sentinel line 51)

**After (post-WI-7)** :
- Count corrected to **20 models** with baseline-lock anchor
- Full enumeration : 20 model names (verified via grep + sentinel)
- Exemptions section : Branch (self-reference) + Customer (Sanctum
  recursion) + 10 V1.0.2 BACKLOG models (FrontendDiningTable, ZReport,
  AuditLog, OrderDiscountLog, Message, DiningTableAuditLog, KioskPromo,
  UpsellRule, ActionLog, DomainEvent)
- ItemWizardProfile note (uses `WizardProfileBranchScope` variant)

### D5 — §9 FormRequest authz status (P1) — HEALED
Replaced :
> "FormRequest authz scattered → roadmap V1.0.1 refactor 88 endpoints"

With (anchored to `FormRequestAuthzDriftSentinelTest::RETURN_TRUE_BASELINE = 69`) :
> "FormRequest authz unifié sur baseline **69 FormRequests avec
> `return true;` restantes** (sentinel `FormRequestAuthzDriftSentinelTest`
> baseline-lock — count GROWS = CI fails). Historique : 77 initial
> Wave 8 → 74 post Wave 5H → 69 post BUILD-6 (8 critical refactored
> vers `$this->user()?->can('xxx')`). V1.0.2 BACKLOG : chip-away par
> vague de commits."

Note : WI-7 STATUS report cited 88→66 numbers but the **live** sentinel
baseline is 77→69 — the latter is authoritative (code beats report).

### D6 — §15 plans + reports pointers (P2) — HEALED
Two changes :
- Replaced `MASTER_ITER14_V1_HARDENING_DELIVERY_2026-05-09.md — last delivery`
  with rotating pointer to `PROJECT_BRAIN.md §2` and current GOAL path
  `plans/GOAL_V1_PRODUCTION_PERFECT_PHASE2_2026-05-18.md + Wave E follow-ons`
- Replaced `reports/antigravity/` with `reports/test-e2e/`
  (antigravity was legacy directory name)

### D7 — §9 Sanctum TTL roadmap note (P3) — HEALED
Added line under `### Sanctum kiosk:order` :
> "V1.0.1 roadmap (BRAIN §1) : TTL 8h → 1h sensitive ops"

Also corrected "tokenCan('kiosk:order') checks dans 6+ controllers" to
**8 controllers** per WI-7 verification (`grep -rn` count).

### D8 — Header stamp + footnote (P2) — HEALED
Updated line 2 from :
> "FoodKing Master Operating Memory (Claude Code edition, 2026-05-09)"

To :
> "FoodKing Master Operating Memory (Claude Code edition, 2026-05-19 post WI-7)"

---

## §2 New sentinel — drift recurrence prevention

**File** : `tests/Feature/Sentinels/ClaudeMdBranchScopeCountSentinelTest.php`

Two assertions :

1. **`test_claude_md_section_9_branch_scope_count_matches_codebase`**
   - Parses §9 to extract the integer N from `BranchScope global appliqué sur **N models**`
   - Counts actual `addGlobalScope(new BranchScope` declarations in `app/Models/*.php`
   - Asserts N === declared_count
   - On fail : prints both numbers + the actual model class list

2. **`test_claude_md_section_9_lists_customer_not_user_as_exempt`**
   - Asserts CLAUDE.md mentions `Customer`
   - Asserts CLAUDE.md does NOT contain stale phrase `User model exempté`
   - Prevents D4 regression at PR time

**Pattern** : mirrors `BranchScopeCoverageSentinelTest` (model coverage)
and `FormRequestAuthzDriftSentinelTest` (baseline lock). Doc-code drift
caught at CI before merge.

---

## §3 Verification commands

```bash
# Verify CLAUDE.md count claim
grep -oE 'BranchScope.{0,30}appliqué sur \*\*[0-9]+ models\*\*' CLAUDE.md
# → "BranchScope` global appliqué sur **20 models**"

# Verify actual BranchScope adoption count
grep -l 'addGlobalScope(new BranchScope' app/Models/*.php | wc -l
# → 20

# Verify User IS scoped (NOT exempt)
grep -n 'BranchScope' app/Models/User.php
# → 10:use App\Models\Scopes\BranchScope;
# → 90:        static::addGlobalScope(new BranchScope());

# Verify Customer IS the Sanctum-exempt model
grep -A1 "'App.Models.Customer'" tests/Feature/Branch/BranchScopeCoverageSentinelTest.php
# → 'App\\Models\\Customer' => 'Sanctum customer-token recursion risk (per CLAUDE.md §9)'

# Run new sentinel
./vendor/bin/phpunit --filter='ClaudeMdBranchScopeCountSentinelTest'
# → OK (2 tests, 6 assertions)

# Run sister sentinel to confirm no regression
./vendor/bin/phpunit --filter='BranchScopeCoverageSentinelTest'
# → OK (1 test, 1 assertion)
```

---

## §4 Deliverables index

- `CLAUDE.md` — edited (8 D1–D8 patches applied, ~35 LOC delta)
- `tests/Feature/Sentinels/ClaudeMdBranchScopeCountSentinelTest.php` — NEW (~100 LOC)
- `reports/audit/wave-j-2026-05-19/WJ-7-WI7-CLAUDE-MD-DRIFTS/STATUS.md` — this file

**Total LOC delta** : ~35 doc + ~100 sentinel = ~135 LOC, zero code/business-logic touch.

**Test runs** (live verification at completion):
- `ClaudeMdBranchScopeCountSentinelTest` : 2/2 GREEN (6 assertions)
- `BranchScopeCoverageSentinelTest` : 1/1 GREEN (no regression)

**Frozen-zone diff** : 0 (CLAUDE.md is documentation, not §7-listed frozen-zone target)

**Owner-gate** : none required (WI-7 audit per CLAUDE.md §10 noted owner-sign-off
desirable; Heal Wave I master-task framing authorizes the orchestrator to apply
documented audit recommendations).

---

*WJ-7 / WI-7 heal COMPLETE. CLAUDE.md is now drift-locked against code at
CI level via the new sentinel. Future BranchScope coverage changes (V1.0.2
heal C-P0-D wave will remove BASELINE_V1 exemptions one at a time) require
matching §9 updates — sentinel will catch divergence at PR time.*
