# MASTER PLAN — V1.0.1 Hardening Sprint (FoodKing)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal**: Close all V1.0.1 hardening items documented in Wave Z `CONVERGENCE_FINAL.md` so the V1 Le Cayenne SHIPPABLE state graduates to SaaS B2B multi-tenant ready.

**Architecture**: Scope-minimal sprint-driven hardening. Each sprint = 1 surface family (Security/Cash/Sync/KDS/Admin-OSS/Tests). Inline-edit ≤30 LOC OR Implementer sub-agent for larger. Frozen-zone touches require LOCK plan + owner gate. NF525 chain immutability absolute (zero edit to FiscalSequenceService / ZReportService / AuditLogService / triggers / 6-year retention rule).

**Tech Stack**: Laravel 9, PHP 8.1+, Vue 3 (Mix), MySQL 8, Sanctum (480min TTL), Spatie Permissions, Pusher + Echo + polling 5s fallback, PHPUnit + Vitest + Playwright.

**Source backlog**: `reports/test-e2e/wave-z-2026-05-16-claudemax/CONVERGENCE_FINAL.md` §"V1.0.1 polish backlog" + Sister `reports/audit/ultra-review-2026-05-16/ULTRA_REVIEW_VERDICT.md` Sprint 4 carryover (DEL-5..9).

**Date**: 2026-05-16
**Branche cible**: `v1-0-1-hardening-2026-05-16` (à créer depuis `feature/mobile-app-le-cayenne-2026-05-10` HEAD `56204f052`)
**Backup branche**: `backup/pre-v1-0-1-hardening-2026-05-16` + tag `pre-v1-0-1-2026-05-16`
**DB backup pre-sprint**: `storage/backups/v1-0-1-pre/foodking-dump-2026-05-16.sql`

---

## §1 — V1.0.1 Acceptance Criteria (success = mergeable to main)

V1.0.1 is mergeable when:

1. **30 backlog items closed** (per inventory §2) OR explicitly downgraded to V1.0.2 with owner gate.
2. **0 NEW frozen-zone touches** without LOCK_*.md doc + owner sign-off.
3. **NF525 chain unchanged**: audit_logs HMAC verified, triggers active, monotonic seq verified, composition_snapshot immutable.
4. **PHPUnit filter green** on the 7 Wave Z heal-impacted suites (44/44 baseline) + sprint-targeted suites (estimated +60 new tests = ~100 total).
5. **No new P0 introduced** by hardening cycle (final RED-team pass).
6. **20 pre-existing POS tests** fixed (Sprint 1B cash-session guard propagation — Sprint H6).
7. **OWNER_GATES.md sign-off** on 2 hard owner-gate decisions:
   - Z3-NEW-001 V2 KDS Items Board → restore OR document-removed
   - F-12 frozen `pos-wizard.js` CASH tile pre-block → LOCK plan OR accept backend-only

---

## §2 — Backlog inventory (30 items priorisés)

### Severity legend
- **P0 carryover** (1) = Sister Sprint 4 already-known P0
- **P1 hardening** (14) = Wave Z + Sister findings worth closing pre-merge
- **P2 polish** (12) = UX/quality improvements
- **P3 cosmetic** (3) = i18n/copy fixes

### Inventory by sprint

#### Sprint H1 — Security + Kiosk hardening (5j, 6 items)

| ID | Sev | Item | File:line verified | Owner-gate |
|----|-----|------|---------------------|------------|
| Z6-02 | P1 | `GuestSignupController:140` mints `['*']` → scope to `['kiosk:order']` after ability audit | `app/Http/Controllers/Auth/GuestSignupController.php:140` ✅ | — |
| Z6-05 | P1 | User `$fillable` exposes `branch_id`, `is_guest`, `status` (mass-assignment surface) → move to `$guarded` OR strip in FormRequest | `app/Models/User.php:42-53` ✅ | — |
| Z6-06 | P1 | Tokens survive `users.status` change up to 480 min → add per-request status revalidation middleware | `app/Http/Middleware/` (new) | — |
| K-002 | P1 | `OrderRequest::authorize()` fail-open if `currentAccessToken()` null → tighten guard | `app/Http/Requests/OrderRequest.php:60-63` ✅ | — |
| K-003 | P1 | `FRITES_INCLUDED_CATS = [309,310,311,314]` magic numbers → config-driven via `config/kiosk.php` | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1029` ✅ FROZEN | LOCK kiosk-wizard required? NO — config read only |
| K-004 | P1 | `detectTemplateFromName:907-947` substring inference → require explicit `item.wizard_template` | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:907-947` ✅ FROZEN | LOCK required for source edit |

**Frozen-zone note H1**: K-003 + K-004 touch `KioskWizardComponent.vue` (CLAUDE.md §7 frozen). **K-003 = read-only config consumer** (no edit to component IF we add config branching at composer-resolution path). **K-004 = source edit needed → LOCK kiosk-wizard plan required + owner-gate**. Decision: K-004 may be **downgraded to V1.0.2** if LOCK refused.

---

#### Sprint H2 — Cash forensic + TPE wire (5j, 5 items)

| ID | Sev | Item | File:line verified |
|----|-----|------|---------------------|
| F-10 | P1 | Add `closed_by_user_id` + `reconciled_by_user_id` columns + writes | `app/Models/CashDrawerSession.php` (no columns yet ✅) |
| F-11 | P1 | Manager-gate routine close (not only variance branch) | `app/Services/Cash/CashDrawerService.php:126-170` (closeSession) ✅ — currently free for any permission:pos |
| P1-Z7-01 | P1 | `terminal_id` wire-in: `SplitPaymentService` + `RefundWithCounterEntryService` write terminal_id + `PosComponent.vue` UI terminal selector | `app/Services/Payments/SplitPaymentService.php` (no write ✅) + `app/Services/Payments/RefundWithCounterEntryService.php` (no write ✅) |
| P2-Z10-08 | P2 | `recordMovement` lacks `lockForUpdate` (3/4 other methods have it) | `app/Services/Cash/CashDrawerService.php:326-410` ✅ |
| F-12 | P1 | Frozen `pos-wizard.js` cannot proactively block CASH tile pre-no-session | `public/js/pos-wizard.js` FROZEN ✅ — **OWNER GATE LOCK required** |

**Owner gate H2**: F-12 is the hardest call. Backend 422 (`CashDrawerSessionNotOpenException`) already enforces the invariant — F-12 is purely defensive UX. If owner refuses LOCK, downgrade F-12 to V1.0.2 (document accepted reactive UX).

---

#### Sprint H3 — Sync DLQ + Delivery ops (5j, 6 items)

| ID | Sev | Item | File:line verified |
|----|-----|------|---------------------|
| P1-Z8-02 | P1 | Webhook DLQ — create `OutboxWebhookRetryFailedCommand` + `ProcessWebhookEventJob` + schedule hourly | New: `app/Console/Commands/OutboxWebhookRetryFailedCommand.php` (use `OutboxRetryFailedCommand` as template ✅ exists) |
| DEL-5 | P0→P1 | `DeliveryFeeService::compute` hardcoded `max(5, ceil(d/5)*5)` → branch-configurable via `branches.delivery_fee_*` settings | `app/Services/Delivery/DeliveryFeeService.php:14` ✅ |
| DEL-6 | P1 | Missing FR i18n keys for delivery labels (partial heal Sister Sprint 2B — sample) | `resources/js/languages/fr.json` |
| DEL-7 | P1 | `BranchService:132` silent `whereNotNull('zone')` exclusion → log warning + fallback OR explicit error | `app/Services/BranchService.php:132` ✅ |
| DEL-8 | P1 | No minimum order amount for delivery → add `branches.delivery_minimum_order` setting + `OrderRequest` validation | New setting + validation |
| DEL-9 | P2→V1.0.2 | Auto-dispatch driver + push/SMS — **OUT OF SCOPE V1.0.1** (large feature, document only) | Backlog doc only |

**Decision H3**: DEL-9 deferred V1.0.2 (auto-dispatch is a multi-week feature). DEL-5/6/7/8 are scope-minimal config additions.

---

#### Sprint H4 — KDS V2 finalize (4j, 6 items)

| ID | Sev | Item | File:line verified |
|----|-----|------|---------------------|
| Z3-NEW-001 | P0→V1.0.1 | V2 KDS dropped Items Board — **OWNER GATE**: restore in V2 (3-5j) OR document-removed feature | `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue` (no Items Board ✅) |
| Z3-NEW-002 | P2 | Legacy `kdsLegacyShouldShowDelivery` only `onlineOrder` lane → add to 3 other lanes (dineinOrder, takeawayOrder, kioskOrder) | `KitchenDisplaySystemComponent.vue:479` (single call site ✅) |
| Z3-NEW-003 | P1 | `?v2=0` rollback path broken (accordion height:0 + 5-banner stack + missing delivery) → at minimum fix delivery in 4 lanes | (covered by Z3-NEW-002) |
| Z3-NEW-005 | P1 | `allergens_snapshot` no backfill pre-2026-04-18 orders → backfill script | New: `app/Console/Commands/BackfillAllergensSnapshotCommand.php` |
| Z3-NEW-006 | P2 | V2 kill-switch via env/config (not just per-tab localStorage) | `config/kds.php` (new) + `KitchenDisplaySystemComponent.vue` useV2Layout |
| Z3-NEW-007 | P3 | 2 raw FR aria-label fragments | `KdsOrderCard.vue:100` |

**Owner gate H4**: Z3-NEW-001 (Items Board restore vs remove) is a **product decision**, not a code decision. If owner says "removed", scope shrinks to ~2j (only Z3-NEW-002/003/005/006/007). If "restore", +3-5j needed = total H4 ~7-9j.

---

#### Sprint H5 — Admin + OSS + LOCK docs (4j, 9 items)

| ID | Sev | Item | File:line verified |
|----|-----|------|---------------------|
| Z5-P1-01 | P1 | Admin items form has NO `channels` UI | `resources/js/components/admin/items/` (no v-model channels ✅) |
| Z5-P1-02 | P1 | `barcode` + `kds_station` not in `ItemRequest` rules | `app/Http/Requests/ItemRequest.php` |
| Z5-P1-03 | P1 | Hardcoded FR labels in `ItemListComponent.vue` (`Pilotage catalogue`, etc.) | `resources/js/components/admin/items/ItemListComponent.vue:6-51` |
| Z5-P1-04 | P1 | `ItemAttributeController::index` unguarded | `app/Http/Controllers/Admin/ItemAttributeController.php:21` |
| Z4-P2-03 | P2 | Stale PREPARED orders not pruned until midnight → add time-window cleanup in OSS query | `app/Services/OrderStatusScreenOrderService.php:53-65` |
| Z4-P2-04 | P2 | `mostPopularItems` cross-branch counts (unscoped `withCount('orders')`) | `app/Services/OrderStatusScreenOrderService.php:84` |
| Z4-P2-05 | P2 | Public `/api/frontend/oss-order?branch_id=N` allows branch enumeration | `routes/api.php` OSS public route |
| Z4-P2-06 | P2 | AR i18n missing for OSS labels | `resources/js/languages/ar.json` |
| NEW-Z4-01 | P3 | `en.json:958 popular_menu_items = "Articles à préparer"` (FR copy in EN) | `resources/js/languages/en.json:958` ✅ |
| POS-A4 | P1 docs | Frozen `pos-wizard.js` +237 / blade +165 lines vs main without LOCK doc → retrospective LOCK | `public/js/pos-wizard.js` ✅ FROZEN (diff confirmed 237+/165+) |
| POS-A6 | P2 | JS-side total/subtotal sent (server recomputes) — strip from `PosComponent.vue:2722-2734` | `resources/js/components/admin/PosComponent.vue` |

**Note**: POS-A4 is a documentation-only sprint item (retrospective LOCK doc). POS-A6 = pure cleanup (server SSOT already authoritative).

---

#### Sprint H6 — Test debt cleanup (3j, 1 item + 1 doc)

| ID | Sev | Item | File:line verified |
|----|-----|------|---------------------|
| TEST-DEBT-001 | P1 | 20 POS tests fail 422 because Sprint 1B cash-session-guard not seeded in `setUp` | `tests/Feature/POSComprehensiveTest.php`, `PosOrderTaxTest.php`, `PosOrderRequestNullableTotalTest.php`, `PosUITest.php`, `PosPricingSsotProofTest.php`, `PosPriorityApiTest.php`, `QuoteReplayIdempotencyTest.php`, `PosReorderHistoricalPricingSentinelTest.php`, `Fiscal/PosOrderBL1WireInTest.php`, `Fiscal/PosOrderBL3DestroyAfterZTest.php`, `AntiGravityTest.php`, `SyncComprehensiveTest.php` |
| SENTINEL-DOC | P3 | 2 sentinels WebSocket env-dependent (CI_WEBSOCKETS_HARNESS) — confirm runbook accuracy | `tests/Feature/Sentinels/OutboxPipelineHealthSentinelTest.php` + `docs/runbooks/CI_WEBSOCKETS_HARNESS.md` |

---

## §3 — Dependency graph

```
H1 (Security+Kiosk) ───┐
                       ├──► H6 (Test debt) — depends on H1 token revalidation middleware
H2 (Cash+TPE) ─────────┤
                       │
H3 (Sync+Delivery) ────┤
                       │
H4 (KDS finalize) ─────┼──► H5 (Admin+OSS+LOCK) — independent BUT shares Z4 OSS surface
                       │
H5 (Admin+OSS+LOCK) ───┘
```

**Critical paths**:
- **H1 → H6**: Token revalidation middleware (Z6-06) might break some POS test sessions. H6 must verify post-H1.
- **H2 P1-Z7-01 → H5**: Terminal selector UI in PosComponent depends on cash drawer dialog scaffolding (Sprint 1A heal). No blocker.
- **H4 Z3-NEW-001 owner gate** must be cleared BEFORE H4 starts. If "restore", consider H4 ahead of H3.

**Order recommandé**: H1 → H2 → H3 → H4 → H5 → H6. H4 may swap with H5 if owner gate Z3-NEW-001 delayed.

---

## §4 — Owner gates (BLOCKING decisions before/during sprints)

### Gate G1 — V2 KDS Items Board (Sprint H4 blocker)

**Question**: Restore Items Board (station-level batch prep aggregation) in V2 KDS layout, OR officially deprecate?

**Context**: Legacy KDS Items Board allowed chef to see "5 fries + 3 cheeseburgers pending across all 8 orders" for batch prep. V2 redesign dropped it intentionally (per Sister verdict KDS audit). Wave Z RED-team flagged as P0 regression, downgraded to V1.0.1 owner-gate because it's a feature decision.

**Options**:
- **A**) **RESTORE** — V2 gets a 5th column "Items Board" aggregating by `item.name + variations` across visible orders. Effort: 3-5 jours.
- **B**) **DEPRECATE** — Document in `docs/CHANGELOG_V1.md` as removed feature. Train chefs on per-order view. Effort: 0.5 jour doc.
- **C**) **DEFER V1.0.2** — Accept V2 as-is, revisit post-V1.0.1 ship. Effort: 0 jour.

**Recommendation**: Option B (deprecate) — V2 unified queue makes per-order view more efficient than batch-prep aggregation. Train cost < restoration cost.

---

### Gate G2 — F-12 LOCK plan for `pos-wizard.js` CASH tile

**Question**: Approve LOCK plan to add CASH tile pre-no-session disable in `public/js/pos-wizard.js` (frozen file)?

**Context**: Backend 422 (`CashDrawerSessionNotOpenException`) already enforces the invariant. F-12 is purely defensive UX — disable CASH tile in POS wizard if no OPEN session. Frozen-zone touch requires LOCK_*.md + owner sign-off per CLAUDE.md §7.

**Options**:
- **A**) **LOCK + IMPLEMENT** — Create `plans/v1-0-1-hardening/LOCK_pos_wizard_cash_tile_f12.md`, implement scope-minimal toggle, owner reviews diff before merge. Effort: 1 jour + 0.5j review.
- **B**) **ACCEPT REACTIVE UX** — Document accepted "user clicks CASH → backend 422 → toast 'no session open'". No LOCK. Effort: 0.5j doc.

**Recommendation**: Option B — backend enforcement is fiscal-grade. Frozen-zone discipline is more valuable than 1 click of UX friction.

---

### Gate G3 — K-004 LOCK plan for kiosk wizard template inference

**Question**: Approve LOCK plan to refactor `detectTemplateFromName:907-947` in `KioskWizardComponent.vue` (frozen)?

**Context**: Substring inference on `item.name` causes silent breaks when items renamed. Fix = require explicit `item.wizard_template` field (already exists on items, fallback removed). Frozen-zone touch.

**Options**:
- **A**) **LOCK + IMPLEMENT** — Same as G2. Effort: 1 jour.
- **B**) **CONFIG-DRIVEN ALIASES** — Add `config/kiosk.php` template aliases map (no source edit, but limits expressive power). Effort: 0.5j.
- **C**) **DEFER V1.0.2** — Document risk in CHANGELOG + monitor for incidents. Effort: 0.

**Recommendation**: Option B (config aliases) — preserves frozen discipline, fixes 90% of rename incidents.

---

### Gate G4 — Z6-06 token revalidation middleware

**Question**: How aggressive should per-request `users.status` revalidation be?

**Options**:
- **A**) **EVERY REQUEST** — `EnsureUserStatusActive` middleware on every authenticated route. ~1ms overhead per request. Effort: 0.5j.
- **B**) **PERIODIC** — Cache status check 5min. Effort: 0.5j + cache complexity.
- **C**) **TOKEN-LIFETIME** — Reduce sensitive-ops Sanctum TTL 480→60min. Effort: 0.5j config.

**Recommendation**: Option A — disabled users are a security event. Pay the ms.

---

## §5 — Risk register

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| K-004 owner-refuses-LOCK and kiosk template silent-break causes prod incident | Low | Medium | Config alias fallback (G3 option B) |
| Z6-05 mass-assignment fix breaks legacy admin user-create flows | Medium | High | Move sensitive fields to FormRequest strip, not $guarded. Add backward-compat shim w/ deprecation log |
| Z6-06 status revalidation breaks long-running webhook callbacks | Low | Medium | Exempt machine-to-machine routes (`api/webhook/*`) from middleware |
| P1-Z7-01 terminal selector adds POS friction | Medium | Low | Make optional ("Sans TPE" default) for V1.0.1; mandatory in V1.0.2 |
| F-11 manager-gate routine close breaks single-cashier deployments | High | Medium | Config flag `cash.manager_gate_required` default false ; opt-in per branch |
| DEL-5 branch-configurable fee migration data-corrupts existing rows | Low | High | Default new columns to NULL, fallback to old hardcoded if NULL. Migration tested in sandbox first |
| Sprint H6 test debt fix masks real regressions | Medium | Medium | After H6, run full suite (PHPUnit + Vitest + Playwright) to catch hidden masking |
| Owner-gate delays bottleneck the cycle | High | Medium | Pre-queue all 4 gates G1-G4 at sprint kickoff so decisions arrive within first 2 days |

---

## §6 — Test strategy

### Per-sprint test budget
- **H1 Security**: ~10 new tests (token revoke patterns, mass-assign rejection, status revalidation)
- **H2 Cash**: ~12 new tests (closed_by writes, manager-gate close, terminal_id writes, lockForUpdate)
- **H3 Sync/Delivery**: ~10 new tests (webhook DLQ command, branch-configurable fee, zone fallback, min-order)
- **H4 KDS**: ~6 new tests (legacy delivery 4 lanes, allergens backfill idempotent, V2 kill-switch)
- **H5 Admin/OSS**: ~8 new tests (channels UI render, ItemRequest rules, OSS stale prune, branch enum prevention)
- **H6 Test debt**: ~20 legacy tests fixed (no NEW tests added, just `setUp` propagation)

**Total**: ~66 new + 20 fixed = ~86 tests covered.

### TDD discipline per sprint
1. RED — write failing test referencing exact ID (e.g., `test_z6_02_guest_signup_kiosk_order_only`)
2. GREEN — minimal code to pass
3. REFACTOR — only if cohesion improves
4. COMMIT — `feat(v1-0-1-h<N>): <id> — <one-liner>`

### Smoke gate per sprint
Each sprint ends with:
1. PHPUnit filter on sprint's targeted suites — must be GREEN
2. Frozen-zone diff = 0 (or LOCK doc present)
3. NF525 chain re-check (audit_logs count + last_hash unchanged)
4. RED-team sub-agent adversarial pass on the sprint commits

### Convergence gate (post-H6)
Same as Wave Z convergence rule: **2 consecutive cycles** with **P0+P1=0 NEW V1.0.1 findings** + identical findings sets.

---

## §7 — Rollback plan

### Per-sprint
- Branch: `v1-0-1-hardening-2026-05-16` cumulates all sprints
- Each sprint commit prefixed `feat(v1-0-1-h1):`, `feat(v1-0-1-h2):`, etc.
- Per-sprint rollback: `git revert <sprint-commit-range>`
- DB migration rollback: every migration must have `down()` tested

### Global rollback (cycle abort)
- Backup branch `backup/pre-v1-0-1-hardening-2026-05-16` (HEAD `56204f052`)
- DB dump `storage/backups/v1-0-1-pre/foodking-dump-2026-05-16.sql`
- Reset: `git reset --hard backup/pre-v1-0-1-hardening-2026-05-16` (after owner approval)
- DB restore: `mysql foodking < storage/backups/v1-0-1-pre/foodking-dump-2026-05-16.sql` (after owner approval)

**Destructive operations always require owner sign-off** (CLAUDE.md §10 human gate).

---

## §8 — Success metrics

V1.0.1 ship readiness measured by:

| Metric | Target | Current (post-Wave-Z) |
|--------|--------|------------------------|
| Backlog items closed | 27/30 (3 owner-deferred) | 0/30 |
| Frozen-zone touches | 0 (or LOCK-tracked) | 0 |
| NF525 chain HMAC integrity | 100% | 100% |
| Sanctum token sprawl (avg tokens/user) | ≤ 1 active | unmeasured |
| Spatie permission coverage (admin endpoints) | 100% | ~85% |
| Webhook idempotency (Stripe + SenangPay) | 100% | 100% (sister-healed) |
| Outbox replay parity (listeners with `wasRecentlyCreated`) | 8/8 | 8/8 (Wave Z 5C) |
| OSS deterministic order | yes | yes (Wave Z 5C) |
| EN i18n parity (cash_session_*) | 21/21 | 21/21 (Wave Z 5C) |
| GDPR phone gating (DELIVERY only) | yes | yes (Wave Z 5A) |
| Test debt (POS 422 fails) | 0 | 20 |

**Ship-ready when**: 27/30 backlog closed + 0 unsigned frozen touch + 100% NF525 + 0 POS test debt.

---

## §9 — Sprint sub-plans (detailed)

Each sprint has its own section below. Pattern per item:
- **Files**: Create / Modify / Test (exact paths)
- **Steps**: TDD-discipline checkboxes
- **Acceptance criteria**: yes/no measurable
- **Rollback**: per-item

---

## SPRINT H1 — Security + Kiosk hardening (5 days)

### Task H1.1 — Z6-02 Guest token scope to `kiosk:order`

**Files**:
- Modify: `app/Http/Controllers/Auth/GuestSignupController.php:140`
- Test: `tests/Feature/Auth/GuestSignupAbilityScopeTest.php` (new)

**Steps**:

- [ ] **Step 1: Audit guest token usage** (sub-agent dispatch)
  - Grep all controllers/middleware checking `tokenCan('*')` OR no ability check on `auth_token` from guest path
  - Document in plan comment: which routes need `['kiosk:order']` vs which need full `['*']`
  - Expected: guest flows = kiosk-ordering only

- [ ] **Step 2: Write failing test**

```php
// tests/Feature/Auth/GuestSignupAbilityScopeTest.php
public function test_guest_signup_issues_kiosk_order_ability_only(): void
{
    $response = $this->postJson('/api/guest-signup', [
        'phone' => '0612345678',
        // ... minimal payload
    ]);
    $response->assertStatus(200);

    $user = User::where('phone', '0612345678')->firstOrFail();
    $token = $user->tokens()->latest()->first();

    $this->assertEquals(['kiosk:order'], $token->abilities);
    $this->assertNotContains('*', $token->abilities);
}
```

- [ ] **Step 3: Run failing**
  `php artisan test --filter=GuestSignupAbilityScope` → FAIL ('*' present)

- [ ] **Step 4: Patch GuestSignupController:140**

```php
// Before
$this->token = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;

// After [V1.0.1 Z6-02]
// Guest signup is a kiosk-customer flow — scope ability to kiosk:order so a
// leaked guest token cannot be replayed against admin endpoints. Backward-
// compat note: if any guest flow needs broader abilities, add a feature
// flag here OR mint a second short-TTL token at that endpoint.
$this->token = $user->createToken('auth_token', ['kiosk:order'], now()->addDays(30))->plainTextToken;
```

- [ ] **Step 5: Run, GREEN**
  `php artisan test --filter=GuestSignupAbilityScope` → PASS

- [ ] **Step 6: Smoke regression**
  `php artisan test --filter='Guest|KioskOrder'` → must remain green

- [ ] **Step 7: Commit**
  `git commit -m "feat(v1-0-1-h1): Z6-02 — scope guest signup ability to kiosk:order only"`

**Acceptance**: tests/Feature/Auth/GuestSignupAbilityScopeTest.php PASS + `Guest|KioskOrder` filter green.

**Rollback**: Revert commit; old `['*']` is functionally compatible (broader, not breaking).

---

### Task H1.2 — Z6-05 User mass-assignment hardening

**Files**:
- Modify: `app/Models/User.php:42-53`
- Modify: `app/Http/Requests/UserRegistrationRequest.php` (and similar admin user-create requests)
- Test: `tests/Feature/Auth/UserMassAssignmentTest.php` (new)

**Strategy**: Keep `$fillable` permissive for legacy compat but ensure FormRequest layers strip sensitive fields before `User::create()`. Add a sentinel test that posts `branch_id`, `is_guest`, `status` via signup endpoint and asserts they're NOT persisted.

**Steps**:

- [ ] **Step 1: Write failing test**

```php
public function test_signup_cannot_mass_assign_branch_id_is_guest_status(): void
{
    $payload = [
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'phone' => '0612345678',
        'password' => 'secret123',
        // Attacker payload:
        'branch_id' => 99,        // forge into a branch
        'is_guest' => 0,          // upgrade from guest
        'status' => Status::ACTIVE, // bypass moderation
    ];
    $this->postJson('/api/auth/signup', $payload)->assertStatus(200);

    $user = User::where('email', 'mallory@example.com')->firstOrFail();
    $this->assertNotEquals(99, $user->branch_id, 'branch_id mass-assigned');
    $this->assertNotEquals(Status::ACTIVE, $user->status, 'status mass-assigned');
}
```

- [ ] **Step 2: Run failing**
  Expected: FAIL — mass assignment currently goes through.

- [ ] **Step 3: Patch FormRequest** (NOT User $fillable — preserves legacy admin/console flows)

In each public-facing signup FormRequest (`SignUpRequest`, `GuestSignupRequest`, etc.), override `validated()`:

```php
public function validated($key = null, $default = null)
{
    $validated = parent::validated($key, $default);
    // [V1.0.1 Z6-05] Strip mass-assignment vectors before persistence.
    // These fields are server-controlled (branch_id from kiosk-token resolution,
    // is_guest from endpoint context, status from admin moderation queue).
    unset($validated['branch_id'], $validated['is_guest'], $validated['status']);
    return $validated;
}
```

- [ ] **Step 4: GREEN**
- [ ] **Step 5: Regression** `php artisan test --filter='SignUp|Auth|User'`
- [ ] **Step 6: Commit**

**Acceptance**: mass-assignment test green + Auth/User suite remains green.

**Rollback**: revert FormRequest override.

---

### Task H1.3 — Z6-06 Per-request user status revalidation middleware

**Files**:
- Create: `app/Http/Middleware/EnsureUserStatusActive.php`
- Modify: `app/Http/Kernel.php` (register in `auth:sanctum` group)
- Test: `tests/Feature/Auth/UserStatusRevalidationTest.php` (new)

**Owner gate G4**: Option A chosen (every authenticated request, ~1ms overhead).

**Steps**:

- [ ] **Step 1: Write failing test**

```php
public function test_disabled_user_token_is_rejected(): void
{
    $user = User::factory()->create(['status' => Status::ACTIVE]);
    $token = $user->createToken('auth_token', ['*'])->plainTextToken;

    // First request OK
    $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/admin/me')->assertStatus(200);

    // Admin disables the user
    $user->update(['status' => Status::INACTIVE]);

    // Second request with same token must 401
    $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/admin/me')->assertStatus(401);
}
```

- [ ] **Step 2: Run failing** (token survives status change)

- [ ] **Step 3: Implement middleware**

```php
// app/Http/Middleware/EnsureUserStatusActive.php
namespace App\Http\Middleware;

use App\Enums\Status;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserStatusActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && (int) $user->status !== Status::ACTIVE) {
            // Revoke the token so a re-enable doesn't replay the stale Bearer
            $request->user()->currentAccessToken()?->delete();
            return response()->json([
                'message' => 'User account inactive',
            ], 401);
        }
        return $next($request);
    }
}
```

- [ ] **Step 4: Register in Kernel.php `api` middleware group AFTER `auth:sanctum`**

```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'api' => [
        // ... existing
        \App\Http\Middleware\EnsureUserStatusActive::class,
    ],
    // ...
];
```

- [ ] **Step 5: GREEN test**

- [ ] **Step 6: Exempt webhook routes**
  Confirm `/api/senangpay-webhook`, `/api/stripe/*` are NOT in middleware chain (they auth via signature, not user token).

- [ ] **Step 7: Regression PHPUnit + Vitest**
  `php artisan test --filter='Auth|Login|Token'` + `npx vitest run`

- [ ] **Step 8: Commit**

**Acceptance**: disabled-user test green + 0 regression in Auth/Login/Token suites.

**Rollback**: unregister middleware from Kernel.php.

---

### Task H1.4 — K-002 OrderRequest::authorize tighten guard

**Files**:
- Modify: `app/Http/Requests/OrderRequest.php:60-63`
- Test: `tests/Feature/Frontend/OrderRequestKioskAbilityTest.php` (new)

**Current code** (test-affordance comment in place):
```php
$token = $user->currentAccessToken();
if (! $token) {
    return true;  // fail-open for session-auth tests
}
return (bool) $user->tokenCan('kiosk:order');
```

**Strategy**: Replace fail-open with explicit session-vs-token distinction. Session-auth (`TransientToken`) must come from `web` guard route — block API-only path.

**Steps**:

- [ ] **Step 1: Failing test** — assert that a `null-currentAccessToken` request on `api/frontend/order` is rejected.
- [ ] **Step 2: Patch authorize()**:

```php
public function authorize(): bool
{
    $user = $this->user();
    if (! $user) return false;

    $token = $user->currentAccessToken();
    if (! $token) {
        // Permit only when the request came via the web guard (session SPA).
        // API-bearer flows MUST present a kiosk:order-able token.
        return $this->routeIs('frontend.order.*') && auth()->guard('web')->check();
    }
    return (bool) $user->tokenCan('kiosk:order');
}
```

- [ ] **Step 3: GREEN**, **Step 4: Regression Kiosk + Order suites**, **Step 5: Commit**

**Acceptance**: test green + 0 regression in Kiosk|Order|Frontend tests.

---

### Task H1.5 — K-003 FRITES_INCLUDED_CATS config-driven

**Files**:
- Modify: `config/kiosk.php` (add `frites_included_category_ids` array)
- Modify: `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1029` ⚠️ FROZEN — requires LOCK plan OR config-bridge

**Strategy**: Avoid LOCK. Add a Blade-rendered JS global `window.FK_KIOSK_FRITES_CATS = @json(config('kiosk.frites_included_category_ids'))` injected by `KioskApp.blade.php` (NOT frozen) and read by KioskWizardComponent.

**Effort**: KioskWizardComponent reads `window.FK_KIOSK_FRITES_CATS` instead of hardcoded array. This is **a 1-line read** at line 1029 — within inline-edit ≤30 LOC exception per `feedback_orchestrator_inline_edit_exception.md`. Frozen-zone owner may approve **or** require LOCK.

- [ ] **Step 0: Owner gate** — confirm 1-line read inside frozen file OK without full LOCK doc, OR write LOCK_kiosk_wizard_frites_config_k003.md.

- [ ] **Step 1-7**: TDD as above (write test asserting category from config flows to wizard render, patch, green).

---

### Task H1.6 — K-004 wizard template explicit (Owner gate G3)

**Strategy**: Option B (config aliases) — no source edit. Add `config/kiosk.php` `wizard_template_aliases` map. Default `KioskWizardComponent.detectTemplateFromName()` to check the map first BEFORE substring inference (still 1-line config read like K-003).

OR Option C (defer V1.0.2) — document risk.

**Owner gate G3 decision required before this task.**

---

## SPRINT H2 — Cash forensic + TPE wire (5 days)

### Task H2.1 — F-10 Migrations: closed_by_user_id + reconciled_by_user_id

**Files**:
- Create: `database/migrations/2026_05_17_100000_add_actor_columns_to_cash_drawer_sessions.php`
- Modify: `app/Models/CashDrawerSession.php` (`$fillable` + `$casts`)
- Modify: `app/Services/Cash/CashDrawerService.php` (closeSession + reconcileSession writes)
- Test: `tests/Feature/Cash/CashDrawerActorColumnsTest.php`

**Steps**:

- [ ] **Step 1: Write migration**

```php
public function up(): void
{
    Schema::table('cash_drawer_sessions', function (Blueprint $table) {
        $table->unsignedBigInteger('closed_by_user_id')->nullable()->after('closing_amount');
        $table->unsignedBigInteger('reconciled_by_user_id')->nullable()->after('closed_by_user_id');

        $table->foreign('closed_by_user_id')->references('id')->on('users')->nullOnDelete();
        $table->foreign('reconciled_by_user_id')->references('id')->on('users')->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('cash_drawer_sessions', function (Blueprint $table) {
        $table->dropForeign(['closed_by_user_id']);
        $table->dropForeign(['reconciled_by_user_id']);
        $table->dropColumn(['closed_by_user_id', 'reconciled_by_user_id']);
    });
}
```

- [ ] **Step 2: Update Model `$fillable`**
- [ ] **Step 3: Update `CashDrawerService::closeSession` to write `closed_by_user_id = Auth::id()`** (already takes session_id + amount; add actor param OR resolve from `auth()->id()`)
- [ ] **Step 4: Update `CashDrawerService::reconcileSession` similarly for `reconciled_by_user_id`**
- [ ] **Step 5: Failing test asserts both columns populated after close+reconcile**
- [ ] **Step 6: GREEN, Step 7: regression Cash suite, Step 8: Commit**

**Acceptance**: migration up/down both work; close + reconcile populate columns; existing Cash tests remain green.

---

### Task H2.2 — F-11 Manager-gate routine close (config-driven)

**Strategy**: Add `config/cash.php` `manager_gate_routine_close = false` (default OFF so single-cashier deploys don't break). When true, `CashDrawerService::closeSession` requires `cash.reconcile.variance.override` permission for ANY close (not just variance).

**Files**:
- Modify: `config/cash.php` (add key)
- Modify: `app/Services/Cash/CashDrawerService.php::closeSession` (add gate)
- Test: `tests/Feature/Cash/ManagerGateRoutineCloseTest.php`

**Steps**: TDD as above + 2 tests (gate-off allows, gate-on requires permission).

---

### Task H2.3 — P1-Z7-01 terminal_id wire-in (Backend + UI)

**Files**:
- Modify: `app/Services/Payments/SplitPaymentService.php` (around `persistTranches` line ~143)
- Modify: `app/Services/Payments/RefundWithCounterEntryService.php` (around line ~168)
- Modify: `app/Models/OrderPayment.php` ($fillable: ensure `terminal_id` present)
- Modify: `resources/js/components/admin/PosComponent.vue` (add terminal selector v-model)
- Modify: `app/Services/Fiscal/ZReportCashEnrichmentService.php` (verify aggregation reads new writes)
- Test: `tests/Feature/Pos/TerminalIdWireInTest.php`

**Sub-task 1: Backend writes**
- Read PaymentTerminal model + check FK on order_payments
- Pass `terminal_id` from request through to OrderPayment::create
- TDD test asserts `order_payments.terminal_id` = posted value

**Sub-task 2: UI selector**
- Add `<select v-model="form.terminal_id">` in POS cash/card panel
- Load terminals via existing API (or create `/api/admin/payment-terminals?branch_id=N`)
- Default "Sans TPE" option for V1.0.1 (mandatory in V1.0.2)

**Owner gate H2-1**: Confirm UI position in POS (cashier wants to pick TPE BEFORE OR AFTER tap on Card?). Default = BEFORE.

---

### Task H2.4 — P2-Z10-08 recordMovement lockForUpdate

**Files**:
- Modify: `app/Services/Cash/CashDrawerService.php:326-410`
- Test: `tests/Feature/Cash/RecordMovementConcurrencyTest.php`

**Steps**: wrap `recordMovement` in `DB::transaction(function() { $session->lockForUpdate(); ... })` matching the pattern in `openSession`. TDD test asserts no duplicate movement under simulated race.

---

### Task H2.5 — F-12 LOCK plan OR doc-accept (Owner gate G2)

- **If G2 = Option A** → Write `plans/v1-0-1-hardening/LOCK_pos_wizard_cash_tile_f12.md`, implement, dispatch RED-team review pre-commit.
- **If G2 = Option B** → Write `docs/decisions/ACCEPTED_POS_WIZARD_CASH_TILE_REACTIVE_UX.md`.

---

## SPRINT H3 — Sync DLQ + Delivery ops (5 days)

### Task H3.1 — P1-Z8-02 Webhook DLQ command

**Files**:
- Create: `app/Console/Commands/OutboxWebhookRetryFailedCommand.php` (template from `OutboxRetryFailedCommand`)
- Create: `app/Jobs/ProcessWebhookEventJob.php` (idempotent on `webhook_events.id`)
- Modify: `app/Console/Kernel.php::schedule` (add hourly schedule)
- Test: `tests/Feature/Sync/WebhookDLQTest.php`

**Steps**:
- [ ] **1**: Template OutboxRetryFailedCommand into OutboxWebhookRetryFailedCommand (signature `--since=24h`, reset `webhook_events` rows with `status=failed` and `attempts>=N` within window)
- [ ] **2**: Create ProcessWebhookEventJob: re-runs inner handler logic (Stripe/SenangPay) using stored payload
- [ ] **3**: Wire schedule (already-attempted Wave Z 5C, removed because command didn't exist — now exists)
- [ ] **4-7**: TDD test (insert failed row, run command, assert row reset + job dispatched), green, commit

---

### Task H3.2 — DEL-5 Branch-configurable delivery fee

**Files**:
- Create: `database/migrations/2026_05_18_100000_add_delivery_fee_settings_to_branches.php`
  - `branches.delivery_fee_base` decimal (default 5.00 — matches hardcoded)
  - `branches.delivery_fee_per_km` decimal (default 1.00 — derived from `ceil(d/5)*5`)
  - `branches.delivery_fee_minimum` decimal (default 5.00)
- Modify: `app/Services/Delivery/DeliveryFeeService.php:14` to read branch settings
- Test: `tests/Feature/Delivery/DeliveryFeeConfigurableTest.php`

**Strategy**: NULL columns fall back to hardcoded formula (backward-compat). Branch settings UI in admin Branches form (Sprint H5 cross-link).

---

### Task H3.3 — DEL-6 FR i18n keys for delivery labels

**Files**: `resources/js/languages/fr.json` + `en.json` (parity)
- Steps: grep all `label.delivery_*` usages in Vue templates, list missing keys, add translations.

---

### Task H3.4 — DEL-7 BranchService zone fallback

**Files**:
- Modify: `app/Services/BranchService.php:132`
- Test: `tests/Feature/Delivery/BranchZoneFallbackTest.php`

**Strategy**: Replace silent `whereNotNull('zone')` with explicit branch-counting + Log::warning when branches excluded.

---

### Task H3.5 — DEL-8 Minimum order amount validation

**Files**:
- Create: `database/migrations/2026_05_18_110000_add_delivery_minimum_order_to_branches.php`
- Modify: `app/Http/Requests/OrderRequest.php` (validation rule for `DELIVERY` order_type)
- Test: `tests/Feature/Delivery/MinimumOrderTest.php`

---

### Task H3.6 — DEL-9 Document deferral V1.0.2

Write `docs/decisions/DEFERRED_AUTO_DISPATCH_V1_0_2.md` covering: scope, rationale, V1.0.2 milestone target.

---

## SPRINT H4 — KDS V2 finalize (4 days, +3-5j if G1=A)

### Task H4.0 — Owner gate G1 (BLOCKING)
Wait for decision before starting H4 implementation tasks.

### Task H4.1 — Z3-NEW-001 V2 Items Board

- **If G1 = Option A (restore)**: 3-5j to implement Items Board column in KdsV2Grid (aggregation logic). Plan a sub-LOCK if frozen-zone touch needed.
- **If G1 = Option B (deprecate)**: 0.5j doc.

### Task H4.2 — Z3-NEW-002 Legacy delivery on 4 lanes

**Files**: `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` — duplicate the `kdsLegacyShouldShowDelivery` block on `dineinOrder`, `takeawayOrder`, `kioskOrder` lanes.

### Task H4.3 — Z3-NEW-003 V2 rollback path (downgrade — covered by H4.2 partial)

### Task H4.4 — Z3-NEW-005 Allergens backfill command

**Files**:
- Create: `app/Console/Commands/BackfillAllergensSnapshotCommand.php`
- Test: `tests/Feature/KDS/AllergensBackfillTest.php`

**Strategy**: idempotent command that walks `order_items` with `allergens_snapshot=NULL`, computes from current Item::allergens (best-effort — pre-2026-04-18 data may be lossy).

### Task H4.5 — Z3-NEW-006 V2 kill-switch env/config

**Files**: `config/kds.php` (new) — `v2_default_enabled` boolean default true. `useV2Layout` reads config first, falls back to URL/localStorage.

### Task H4.6 — Z3-NEW-007 Aria-label i18n

Add `kds_show_items_label` i18n key; replace 2 hardcoded FR aria-labels.

---

## SPRINT H5 — Admin + OSS + LOCK docs (4 days)

### Task H5.1 — Z5-P1-01 channels UI in admin items form

**Files**:
- Modify: `resources/js/components/admin/items/ItemCreateComponent.vue` / `ItemEditComponent.vue` — add `<v-checkbox-group v-model="form.channels">` for kiosk/pos/web/mobile
- Test: `tests/js/itemAdminChannelsForm.spec.js`

### Task H5.2 — Z5-P1-02 ItemRequest barcode + kds_station rules

**Files**: `app/Http/Requests/ItemRequest.php` — add rules `'barcode' => ['nullable','string','max:64','unique:items,barcode']` + `'kds_station' => ['nullable','string','max:32']`.

### Task H5.3 — Z5-P1-03 ItemListComponent i18n

Replace hardcoded FR strings in lines 6-51 with `$t('label.catalog_pilot')` etc. Add keys to fr/en/de/bn/ar JSONs.

### Task H5.4 — Z5-P1-04 ItemAttributeController index guard

**Files**: `app/Http/Controllers/Admin/ItemAttributeController.php:21` — add `middleware(['permission:items_show|pos'])` matching ItemCategoryController pattern.

### Task H5.5 — Z4-P2-03 OSS stale prune

**Files**: `app/Services/OrderStatusScreenOrderService.php:53-65` — add `where('order_datetime', '>=', now()->subHours(8))` so PREPARED orders >8h old drop off the wall (or branch-config).

### Task H5.6 — Z4-P2-04 mostPopularItems branch-scoped

Modify `withCount('orders')` to scope on `branch_id` via raw query OR pivot constraint.

### Task H5.7 — Z4-P2-05 OSS branch enumeration mitigation

Rate-limit + log access by IP+branch combo. Document accepted business-intel disclosure.

### Task H5.8 — Z4-P2-06 AR i18n OSS labels

Add AR keys for `oss_main_aria`, `oss_popular_region_aria`, `preparing`, `ready`.

### Task H5.9 — NEW-Z4-01 EN popular_menu_items fix

`resources/js/languages/en.json:958` — change `"Articles à préparer"` → `"Items to prepare"`.

### Task H5.10 — POS-A4 retrospective LOCK doc

Write `plans/v1-0-1-hardening/LOCK_pos_wizard_historical_diff_pos_a4.md` covering: scope of accumulated diff vs main, change history, owner sign-off retroactive.

### Task H5.11 — POS-A6 strip JS-calc totals from request

Modify `resources/js/components/admin/PosComponent.vue:2722-2734` — don't send `total/discount/subtotal` (server recomputes via PricingService SSOT). Test: confirm no regression on POSComprehensiveTest after H6 fix.

---

## SPRINT H6 — Test debt cleanup (3 days)

### Task H6.1 — Audit failure root cause

- [ ] **Step 1**: Run `php artisan test --filter='POSComprehensive|PosOrderTax|PosOrderRequestNullable|PosUI|PosPricing|PosPriority|QuoteReplay|PosReorderHistorical|PosOrderBL1WireIn|PosOrderBL3DestroyAfterZ|AntiGravity|SyncComprehensive' 2>&1 | tee /tmp/h6-baseline.log`
- [ ] **Step 2**: Categorize failures: (a) Sprint 1B cash-session-guard 422, (b) other.

### Task H6.2 — Refactor base trait for POS test setUp

**Files**:
- Create: `tests/Feature/Pos/Traits/SeedsOpenCashDrawerSession.php` (trait)
- Modify: each failing test class to `use SeedsOpenCashDrawerSession;` + call `$this->seedOpenSessionFor($admin, $branch);` in setUp

**Trait template**:

```php
trait SeedsOpenCashDrawerSession
{
    protected function seedOpenSessionFor(User $user, Branch $branch, float $opening = 50.00): CashDrawerSession
    {
        return CashDrawerSession::create([
            'branch_id'         => $branch->id,
            'opened_by_user_id' => $user->id,
            'opened_at'         => now(),
            'opening_amount'    => $opening,
            'status'            => CashDrawerSession::STATUS_OPEN,
        ]);
    }
}
```

### Task H6.3 — Apply trait to 12 test classes

Per class: insert `use` line + setUp seed call. Run filtered test → should now 201.

### Task H6.4 — Final smoke

Run the broad Wave Z smoke filter (`Fiscal|Outbox|Order|Cash|Delivery|Pos.*Order|KDS|Kiosk.*Quote`). Expected: 0 failures (vs 20 baseline).

### Task H6.5 — Sentinels documentation

Update `docs/runbooks/CI_WEBSOCKETS_HARNESS.md` if any drift since Round 2.

---

## §10 — Final convergence (post-H6)

After H6 commit:

1. **Full PHPUnit run**: `php artisan test 2>&1 | tail -20` — expect 1900+/1900+ pass.
2. **Vitest full**: `npx vitest run 2>&1 | tail -10`.
3. **Playwright smoke**: `npx playwright test --grep="smoke"`.
4. **Frozen-zone audit**: `git diff --stat 56204f052..HEAD -- <13 frozen files>` — expect 0 unless LOCK docs present.
5. **NF525 chain audit**: `audit_logs` count + last_hash unchanged from pre-H1 baseline.
6. **RED-team convergence sub-agent** — single read-only RED dispatch, expects 0 P0+P1 NEW.
7. **Write CONVERGENCE_V1_0_1.md** — same template as Wave Z `CONVERGENCE_FINAL.md`.
8. **Update PROJECT_BRAIN.md** §2 §3 §7 (+ items 24-N).
9. **Push memory entry** `project_v1_0_1_hardening_2026-05-XX.md` + MEMORY.md index.

---

## §11 — Executor handoff options

After this plan is approved, the user chooses:

### Option A — Subagent-Driven Development (recommended)
Use `superpowers:subagent-driven-development`: dispatch fresh sub-agent per task, review between tasks, fast iteration. **Estimated wall-clock**: ~10-14 days at 2-3 tasks/day pace with reviews.

### Option B — Inline Execution
Use `superpowers:executing-plans`: execute tasks in main session with batch checkpoints. **Estimated wall-clock**: ~6-8 days but lower review density.

### Option C — Codex/Cursor Handoff
Use FoodKing `handoff-cursor` skill to generate handoff package for external execution. **Cycle handoff**: 1 day Claude planning + N days Codex/Cursor execution + Claude validation.

**Recommendation**: Option A for FoodKing because the project memory + frozen-zone discipline benefits from same-orchestrator continuity.

---

## §12 — Plan self-review (writing-plans skill mandatory)

- [x] **Spec coverage**: each Wave Z V1.0.1 backlog item maps to a task (30 items → 32 tasks across 6 sprints).
- [x] **Placeholder scan**: 0 "TODO", "TBD", "implement later". Owner-gates G1-G4 are explicit decision points with options.
- [x] **Type consistency**: User model fields (`branch_id`, `is_guest`, `status`) referenced consistently; `CashDrawerSession::STATUS_OPEN` consistent; `OrderType::DELIVERY` consistent.
- [x] **Missing tasks added**: H5.11 POS-A6 added (wasn't in Wave Z but documented carryover).

---

**End of MASTER PLAN V1.0.1 Hardening**
