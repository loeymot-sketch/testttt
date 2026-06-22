# Z3 — Round 2 verification (Wave Z)

**HEAD**: 56204f052
**Verdict**: GO

Auditor: Round 2 read-only convergence pass on Wave Z system Z3 (KDS V2 + Delivery
enrichment). Evidence captured by file:line from working tree at HEAD
`56204f052` on `feature/mobile-app-le-cayenne-2026-05-10`.

## Round 1 findings status

### Z3-NEW-001 (P0) — V2 KDS dropped Items Board → UNCHANGED (V1.0.1)

Evidence:
- `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue` — 297 lines
  total; `grep -nE "item-order|items_board|mergedItems|kds_items_board|orderItems"`
  returns **zero matches**. V2 grid is still a flat FIFO of `<KdsOrderCard>`.
- Legacy Items Board pane (`KitchenDisplaySystemComponent.vue:115` `<div id="item-order">`)
  still reachable only in `?v2=0` rollback (template `<v-else>` at line 22).
- Owner-gate verdict from Round 1: this is a **feature decision** (whether
  chefs need the station-level aggregation view), not a data-correctness
  defect. The V2 unified-queue per-card workflow is the new doctrine.

Status: **UNCHANGED / DEFERRED V1.0.1** per kickoff (owner-gate feature decision).

### Z3-NEW-002 (P0 → downgraded P2) — Legacy delivery block only in onlineOrder lane → UNCHANGED (V1.0.1)

Evidence:
- `KitchenDisplaySystemComponent.vue:479` — `v-if="kdsLegacyShouldShowDelivery(onlineOrder)"`
  remains the only template call site of the helper.
- `grep -n "kdsLegacyShouldShowDelivery"` returns exactly 2 hits:
  L479 (template) + L1348 (helper definition). takeawayOrder / kioskOrder /
  dineinOrder lanes still have no delivery block.
- Risk surface is strict-rollback-only — V2 default (`KdsOrderCard.vue:87`
  with `data-testid="kds-card-delivery"`) ships the canonical delivery block
  for all lanes via `isDeliveryOrder` regardless of source bucket.
- Round 1 downgrade rationale: V2 is now default. Legacy is rollback-only
  emergency path. The DEL-3 spec is healed in the path users actually run.

Status: **UNCHANGED / DEFERRED V1.0.1** (downgraded to P2 per kickoff; V2 is default).

### Z3-NEW-003 (P1) — Rollback path footgun → UNCHANGED (V1.0.1)

Evidence:
- `KitchenDisplaySystemComponent.vue:328/512/657/799` accordion `style="height: 0px"`
  blocks unchanged. Banner stack at L44/55/70/77/84/92 unchanged. All inside
  the `<v-else>` (line 22) — unreachable in V2 default.
- `useV2Layout()` precedence unchanged (`KitchenDisplaySystemComponent.vue:1105-1128`):
  default true; `?v2=0` or `localStorage 'kds.v2_enabled' === '0'` opts out.
- No owner-visible warning when `?v2=0` is applied — silent footgun.

Status: **UNCHANGED / DEFERRED V1.0.1** per kickoff.

### Z3-NEW-004 (P1) — `customer_phone` exposed unconditionally → HEALED

Evidence:
- `app/Http/Resources/SimpleOrderResource.php:58` —
  `'customer_phone' => ((int) $this->order_type === OrderType::DELIVERY)
  ? $this->user?->phone : null,`
  Gated. Comment block L53-57 documents Sprint 5A Z9-P0-03 GDPR rationale.
  Import of `OrderType` added at L6.
- `app/Http/Resources/KDSOrderDetailsResource.php:62-71` — `customer.phone`
  gated identically:
  `'phone' => ((int) $this->order_type === OrderType::DELIVERY) ? $this->user->phone : null,`
  Comment block L63-67 cites Sprint 5A Z9-P0-03. Import of `OrderType` added at L6.
- `git diff c3ba89863..56204f052 -- app/Http/Resources/SimpleOrderResource.php
  app/Http/Resources/KDSOrderDetailsResource.php` shows scope-minimal
  3-line gate change + 5-line comment per file. No collateral.
- `php artisan test tests/Feature/KDS/KDSDeliveryEnrichmentTest.php`:
  - `delivery_order_payload_includes_address_and_customer_contact` — PASS
    (asserts `$row['customer']['phone'] === '+33612345678'` at L164).
  - `dine_in_order_payload_omits_address_block` — PASS (asserts
    `$this->assertNull($row['customer']['phone'] ?? null, ...Z9-P0-03)` at L220).
  - `eager_loaded_relations_are_present_on_the_underlying_query` — PASS.
  - 3/3 passed in 0.69s.
- Test contract was correctly updated: pre-heal asserted `assertSame
  ('+33677889900', $row['customer']['phone'])` (DINE_IN expects phone);
  post-heal asserts `assertNull(...)`. Diff visible at
  `tests/Feature/KDS/KDSDeliveryEnrichmentTest.php:213-220`.
- Wider scan: `grep -rn "user->phone\|user?->phone" app/Http/Resources/`
  returns SimpleOrderResource + KDSOrderDetailsResource (both healed) plus
  MessageResource (staff-to-staff context, not GDPR-relevant). No other
  customer-phone exposure surfaces.
- Legacy `KitchenDisplaySystemComponent.vue:492` `v-if="onlineOrder.customer
  && onlineOrder.customer.phone"` is doubly safe — server now returns `null`
  for non-DELIVERY, so the entire `<a tel:>` block never renders.

Status: **HEALED**.

### Z3-NEW-005 (P1) — `allergens_snapshot` null for legacy orders → UNCHANGED (V1.0.1)

Evidence:
- `database/migrations/2026_04_18_140004_add_allergens_snapshot_to_order_items.php`
  unchanged. Column still nullable, no backfill migration. Pre-cutover orders
  still display empty allergen array as "no allergens declared".

Status: **UNCHANGED / DEFERRED V1.0.1** per kickoff.

### Z3-NEW-006 (P2) — No org-wide V2 kill switch → UNCHANGED (V1.0.1)

Evidence:
- `KitchenDisplaySystemComponent.vue:1105-1128` `useV2Layout()` still reads
  only URL param + localStorage. No env flag, no `Setting` model row, no
  admin UI toggle.

Status: **UNCHANGED / DEFERRED V1.0.1** per kickoff.

### Z3-NEW-007 (P3) — Raw FR aria-label fragments → UNCHANGED (V1.0.1)

Evidence:
- `KdsOrderCard.vue:100` `:aria-label="`Appeler ${customerName || ''}
  ${customerPhone}`.trim()"` unchanged.
- `KitchenDisplaySystemComponent.vue:321/505/650/792` aria-label fallback
  `|| 'Afficher les articles'` unchanged (legacy v-else only).

Status: **UNCHANGED / DEFERRED V1.0.1** per kickoff.

### Z9-P1-03 carryover — `PENDING_*` sentinel raw in tel: href → HEALED

Evidence:
- `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue:315-325`
  `customerPhone()` computed:
  ```
  const phone = this.order?.customer?.phone || '';
  if (typeof phone === 'string' && phone.startsWith('PENDING_')) {
      return '';
  }
  return phone;
  ```
  Comment block L316-319 cites Sprint 5A Z9-P1-03. Returns empty for any
  value starting with `PENDING_`.
- Template guard at L97 `v-if="customerPhone"` now collapses both `null`
  (non-DELIVERY) and `PENDING_*` (legacy users created without phone) to a
  no-render — clean card.
- Sentinel format alignment: `app/Models/User.php:109` generates
  `'PENDING_CREATE_' . bin2hex(random_bytes(6))`; backfill migration uses
  `PENDING_<id>` (per User.php:103-106 comments). Both forms have the
  `PENDING_` prefix and are correctly stripped by `startsWith('PENDING_')`.
- `git diff c3ba89863..56204f052 -- KdsOrderCard.vue` shows scope-minimal
  8-line guard + 7-line comment.

Status: **HEALED**.

## NEW issues introduced by Wave Z heals

**None.**

Audit performed:
- `git diff c3ba89863..HEAD -- public/js/pos-wizard.js public/css/pos-wizard.css
  resources/views/admin-pos-v4.blade.php` → **0 lines** (frozen POS wizard
  untouched).
- `git diff c3ba89863..HEAD -- app/Services/Fiscal/FiscalSequenceService.php
  app/Services/Fiscal/ZReportService.php app/Services/Fiscal/AuditLogService.php
  app/Services/Pricing/PricingService.php app/Models/Scopes/BranchScope.php
  app/Domain/Order/OrderStateMachine.php
  app/Http/Middleware/IdempotencyKeyMiddleware.php
  resources/js/components/frontend/kiosk/` → **0 lines** (all frozen zones
  untouched).
- Wave Z 5A commit `7fc62c066` Z3-relevant scope:
  - `KDSOrderDetailsResource.php` +5 / -3 (gate)
  - `SimpleOrderResource.php` +6 / -1 (gate)
  - `KdsOrderCard.vue` +9 / -1 (PENDING_ guard)
  - `KDSDeliveryEnrichmentTest.php` +5 / -3 (test contract update for
    DINE_IN phone null)
  All scope-minimal, no collateral edits.
- Resource gate semantics: `(int) $this->order_type === OrderType::DELIVERY`
  uses cast-to-int — `OrderType::DELIVERY` value verified at runtime by the
  passing test (which uses `OrderType::DELIVERY` constant in factory).
  `OrderType::DINING_TABLE` factory + null assert at test L190-220 confirms
  the gate fails closed.
- Eager-load at `app/Services/KitchenDisplaySystemOrderService.php:70`
  (`Order::with(['orderItems', 'address', 'user'])`) and
  `app/Services/KdsSyncService.php:60` unchanged — confirmed via
  `php artisan test tests/Feature/KDS/` running 12/12 PASS in 2.04s.
  The Round 1 audit flagged this as a "phone still in JSON payload" concern;
  Sprint 5A heal closes the leak at the Resource layer (correct fix point —
  no need to perturb the eager-load itself).
- Full `tests/Feature/KDS/` suite ran clean: 12/12 passed
  (KDSDeliveryEnrichmentTest 3, KdsAllergenAggregationSplitTest 5,
  KdsSnapshotImmutableTest 4).
- V2 flip + legacy template untouched by Round 2 commits — verified via
  `git log c3ba89863..HEAD -- resources/js/components/admin/kitchenDisplaySystem/`
  shows only `KdsOrderCard.vue` from `7fc62c066`. `KdsV2Grid.vue` and
  `KitchenDisplaySystemComponent.vue` unchanged in Wave Z window.

## Carryovers (V1.0.1 backlog — documented, not blockers)

- **Z3-NEW-001** — V2 KDS dropped Items Board (aggregation pane). Status:
  UNCHANGED. Owner-gate feature decision (chefs' per-station batch-prep
  workflow vs. V2 per-order workflow). V1.0.1 product call.
- **Z3-NEW-002** — Legacy delivery block only in onlineOrder lane. Status:
  UNCHANGED, downgraded P2. Rollback-only (`?v2=0`) risk; V2 default is
  safe.
- **Z3-NEW-003** — Rollback path independently broken (accordion/banners/
  delivery-block-in-3-lanes). Status: UNCHANGED. V1.0.1 backlog or downgrade
  `?v2=0` to dev-tools-only flag.
- **Z3-NEW-005** — `allergens_snapshot` null for pre-cutover orders, no UI
  signal. Status: UNCHANGED. V1.0.1 backfill migration OR
  `allergens_snapshot_present` discriminator field.
- **Z3-NEW-006** — No org-wide V2 kill switch (env / Setting model / admin
  UI). Status: UNCHANGED. V1.0.1 ops hardening.
- **Z3-NEW-007** — Raw FR aria-label fragments. Status: UNCHANGED. V1.0.1
  i18n hygiene (aria-only impact).
- **Z3-AT-001** — Commit 80dbc79c2 subject/diff mismatch (release-hygiene).
  Status: UNCHANGED. Historical audit-trail issue, not a code defect.

## Test debt (pre-existing, non-regression)

2 KDS-name-matched failing tests at `KDS` filter scope:
- `Tests\Feature\AntiGravityTest > t08c_pos_kds_notification_dispatched` —
  201 expected, 422 received at `tests/Feature/AntiGravityTest.php:441`.
- `Tests\Feature\SyncComprehensiveTest > pos_order_appears_in_kds` —
  201 expected, 422 received.

Root cause: both use `payloadWithPosQuote()` from
`tests/Feature/Concerns/HasPosQuoteBinding.php:13` which calls
`/api/admin/pos/quote` first. The 422 is on the **POS quote/store path**,
not on KDS Resource serialization. The Z3 heal modifies only Resource
output (`SimpleOrderResource.php`, `KDSOrderDetailsResource.php`,
`KdsOrderCard.vue`) — none of which influence POS request validation.

Same 422 pattern matches Z1 Round-2 documented test debt (pre-existing
Sprint 1B cash-session-guard / quote-binding regression in legacy
non-Sprint-1B suites). Not a Z3 regression.

## Convergence verdict

**P0 = 0** open · **P1 = 0** open (Round 1 P0 Z3-NEW-001/002 = owner-gate
V1.0.1 per kickoff; Round 1 P1 Z3-NEW-004 + Z9-P1-03 carryover both healed).

Block convergence? **No.**

Wave Z scope for Z3 is the GDPR data-minimization heal (Z3-NEW-004) and
the PENDING_ sentinel cleanup (Z9-P1-03 carryover) — both verified at
file:line and confirmed green by the 3/3 KDSDeliveryEnrichmentTest run.
Items Board (Z3-NEW-001) + legacy-lane delivery gap (Z3-NEW-002) +
rollback-path-broken (Z3-NEW-003) + allergens_snapshot backfill
(Z3-NEW-005) + kill switch (Z3-NEW-006) + raw aria (Z3-NEW-007) are
documented V1.0.1 backlog per kickoff and not Wave Z merge blockers.

Z3 is **GO** for Wave Z convergence.
