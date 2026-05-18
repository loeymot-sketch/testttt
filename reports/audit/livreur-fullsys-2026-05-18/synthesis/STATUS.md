# LIVREUR FULL-SYSTEM ULTRA-DEEP AUDIT — STATUS

**Branch:** `heal/cms-pr1-quickwins-2026-05-18`
**HEAD:** `4ad1adba8` (last sibling: `4ad1adba8 docs+heal(wave-A 4 POS intersections)`)
**Wave:** B (parallel with Kiosk Couche 1 + Admin Dashboard masters)
**Specialists:** Architect, Security, UX/A11y, DBA, RED — all read-only.
**Method:** 5 specialist JSONs (avg ~1.2K words each), no heal commits in this round (see §5 Heal Decision).

---

## 1. Sub-system inventory + state

| ID | Sub-system | Status | Notes |
|----|------------|--------|-------|
| 14.1 | Driver app frontend (claim + status updates) | OK | `Frontend/DeliveryBoyOrderController` clean, status whitelist Z-4 healed, mobile UI standalone (carte blanche owner). |
| 14.2 | Admin assignment (manual + auto-dispatch V1.0.X) | OK | `selectDeliveryBoy` ownership+cross-branch checked; audit-log written; auto-dispatch is V1.0.X backlog. |
| 14.3 | Delivery fee (branch-aware) | OK | Z-4 heal commits `04a9454f6` + `ab04839ec` complete; fallback formula preserved. |
| 14.4 | Zones de livraison | PARTIAL | Distance-based proxy via Haversine; no polygon enforcement. Acceptable for single-restaurant V1. |
| 14.5 | Notifications cascade (push + sms + mail) | OK with idempotency caveat | 3 separate events, `status==101` magic gate (P2). |
| 14.6 | Cash session driver (NEW Sub6.3 Build 1) | OK admin-only | Triple-defended lifecycle; livreur self-service deferred to Wave 6b-1.3b (P1 V1.0.2). |

---

## 2. Pre-existing failures — RESOLVED

Brief mentioned "3 DeliveryBoyCashSessionControllerTest failures predate Z-4 heals". **Status: ALREADY HEALED.**

```
php artisan test --filter='DeliveryBoyCashSessionControllerTest'
→ 18 passed, 0 failed in 3.50s
```

Confirmed via runtime: `0c824ddbd FormRequest authz followup` and `d86eb9e74 NEW DeliveryBoyCashSession Sprint Sub6.3 Build 1` closed all 3.

Additional sentinel suites all green:
- `DeliveryBoyCashSessionLifecycleTest` — 5/5
- `DeliveryBoyCashSessionConcurrentOpenTest` — passes triple-layer concurrency guards
- `DeliveryBoyCashSessionAuditChainTest` — passes
- `DeliveryBoyCashSessionBranchIsolationTest` — passes
- `DeliveryBoyAddressPermissionSplitTest` — passes (Z-4 RBAC split)
- `DeliveryStatusTransitionWhitelistTest` — passes (Z-4 status whitelist)
- `DeliveryFeeBranchWireupSentinelTest` — passes (Z-4 fee wire-up)
- `DeliveryMinimumOrderTest` / `DeliveryFeeConfigurableTest` / `DeliveryValidationTest` / `DeliveryBoyOrderStatusOrderingTest` / `PosWalkInAndDeliveryFeeTest` / `BranchZoneFallbackTest` / `GeocodeFailureBlocksOrderTest` — all green.

Total LIVREUR-scoped feature tests: **119 passed** (Delivery|DeliveryBoy|CashSession|delivery_boy_cash|DeliveryFee|DeliveryQuote filter).

---

## 3. Findings consolidated — 4-list

### P0 — None
No P0 in any of the 5 specialist reports.

### P1 — 1 finding (re-rated up from RED's initial P3)
| ID | Title | Owner | Action |
|----|-------|-------|--------|
| **LIV-RED-08 / LIV-ARCH-02** | Doorstep cash-collection → `DeliveryBoyCashMovement` recordMovement wire-up MISSING. Reconcile variance calc sums movements only; without per-order collection rows, variance ≈ 0 (expected = opening only) regardless of EUR actually collected. **The feature's primary data path is missing — not a polish gap.** Workaround requires admin to post one `adjustment` movement PER cash-on-delivery order at shift close — operationally heavy; viable only at very low driver/order count (Le Cayenne single-restaurant). Not acceptable for multi-driver or SaaS multi-tenant. | Backend | V1.0.2 Sub-6.3 BUILD-2: wire `OrderService::deliveryBoyOrderChangeStatus` (DELIVERED + payment=CASH_ON_DELIVERY) → `DeliveryBoyCashSessionService::recordMovement(type=order_collect, direction=in, amount=order.total, strict=false)`. |

### P2 — 9 findings
| ID | Title | Specialist | Severity rationale |
|----|-------|------------|--------------------|
| LIV-ARCH-05 | Magic `status==101` in 3 builders is fragile, not OrderStatus enum | Architect | Hidden coupling, enum refactor risk |
| LIV-ARCH-07 | Notification cascade non-idempotent at builder layer (mail/sms/push) | Architect | Spam risk on queue retry / outbox replay |
| LIV-SEC-04 | Driver PII (phone) exposed to all KDS roles; per-role split needed (GDPR) | Security | Acceptable single-branch, SaaS GDPR finding |
| LIV-SEC-09 | Variance-override permission shared with `delivery-boys` — no dedicated gate | Security | Conflates auditor with override-grantor |
| LIV-UX-03 | Cash session list shows raw integer IDs not driver/branch names | UX/A11y | Ergonomics, no a11y blocker |
| LIV-UX-04 | Variance polarity has color cue only, no sr-only label | UX/A11y | WCAG 1.4.1 (info not by color alone) |
| LIV-UX-06 | No customer-facing cash receipt for cash-on-delivery completion | UX/A11y | FR consumer law expectation for cash >€15 |
| LIV-DBA-05 | MySQL prod DELETE triggers MISSING for delivery_boy_cash_* tables | DBA | Deploy-doc only — needs MySQL migration |
| LIV-DBA-06 | No FK from delivery_boy_cash_sessions to users(id)/branches(id) | DBA | Tombstone rows possible on user deletion |
| LIV-RED-01 | `selectDeliveryBoy` mutation NOT wrapped in DB::transaction + lockForUpdate | RED | Double-assign race, last-writer-wins |
| LIV-RED-03 | Fee bypass via direct API — `branch_id` user-input on quote | RED | "Shop-cheapest-branch" risk for multi-branch SaaS |
| LIV-RED-05 | Notification spam via outbox/queue replay (duplicates LIV-ARCH-07) | RED | Same root cause as ARCH-07 |
| LIV-UX-05 | BUILD-1 has NO driver-facing UI for self-service shift mgmt (Wave 6b-1.3b) | UX/A11y | Workflow gap (was tagged P1 in UX, downgraded P2 here — admin-mediated workflow patches the gap) |

### P3 — 3 findings
| ID | Title |
|----|-------|
| LIV-ARCH-08 | Delivery zones 14.4 are distance-only — acceptable for V1 single-restaurant |
| LIV-DBA-07 | `decimal(10,2)` cash columns — adequate for V1 EUR range |
| LIV-SEC-07 | `DeliveryBoyAddressController` owner-of-address check is implicit (in `UserAddressService`) not asserted in controller |
| LIV-UX-07 | Verify `fr.json` + `ar.json` have new `delivery_cash_*` i18n keys |

### INFO — 12 confirmations (no action)
RBAC split clean (LIV-SEC-01), FormRequest double-gate (LIV-SEC-02), cross-branch open guard (LIV-SEC-03), driver change-status fortified (LIV-SEC-05), `selectDeliveryBoy` audit row (LIV-SEC-06), BranchScope coverage on new models (LIV-SEC-08), Path B duplication documented (LIV-ARCH-03), status whitelist defense-in-depth (LIV-ARCH-04), fee wire-up Z-4 (LIV-ARCH-06), driver/admin controller separation clean (LIV-ARCH-01), schema mirrors POS (LIV-DBA-02, 04), restrictOnDelete + indexes (LIV-DBA-03, 08), i18n present in en.json (LIV-UX-07), forms have proper labels (LIV-UX-01), defended attack surfaces (LIV-RED-02 cash double-open, LIV-RED-04 auth gate, LIV-RED-06 status whitelist, LIV-RED-07 reconcile-without-close).

---

## 4. Out-of-scope cross-cutting finding — KDS

While running the full delivery suite, **3 failures observed in `tests/Feature/KDS/KDSDeliveryEnrichmentTest`** (NOT in LIVREUR scope):

- `delivery_order_payload_includes_address_and_customer_contact` — KDS list returns empty (assertion: `payload` not empty)
- `dine_in_order_payload_omits_address_block` — `firstWhere('id', $order->id)` returns null
- `eager_loaded_relations_are_present_on_the_underlying_query` — only 0/3 delivery rows returned

**Root-cause hypothesis (not verified — handoff):**

The test seeds rows at `now()` with `payment_status=PAID`, `status=ACCEPT` for DELIVERY and `status=PREPARING` for DINE_IN — all within `KitchenReleaseRule::visibleStatuses()`. The KDS list query at `app/Services/KitchenDisplaySystemOrderService.php:104-120` applies a Paris-TZ-aware UTC window for `order_datetime` (Wave 3b heal `148dbebce`). The test's `now()` uses `app.timezone='Europe/Paris'` while the test DB (SQLite memory) has no `mysql.timezone` mismatch — so the TZ-conversion to UTC should be a no-op.

More likely culprit: the BranchScope on Order + the test admin's `branch_id = $this->branch->id` (non-zero). The `auth()->user()->branch_id ?? 0` at L63 + `if ($userBranchId > 0) $query->where('branch_id', $userBranchId)` at L83-85 means the admin must match each order's branch_id. The test seeds orders with `'branch_id' => $this->branch->id` matching admin — so this should work.

**Highest-probability hypothesis:** the `with(['orderItems', 'address', 'user'])` eager-load at L70 may be applying BranchScope to `address` or `user` (via FrontendOrder or via User model's BranchScope) — silently dropping rows where the join finds nothing. OR the test admin is missing the `Spatie\Permission` permission `kitchen-display-system` even though `givePermissionTo` is called L57 — the permission may need cache flush in test setup.

**Recommended handoff action:** KDS owner runs `php artisan test --filter='KDSDeliveryEnrichmentTest' -vvv` with `DB_LOG_QUERIES=true` to dump the actual SQL and identify why the query returns 0 rows when 1-3 are expected.

**This master DID NOT heal these 3 KDS failures** because:
1. They are NOT in LIVREUR scope (KDS sub-system).
2. The KDS Couche 1 / Kiosk Couche 1 master may already be working on them.
3. Read-only mandate on DIRTY tree (any KDS resource change touches shared code that another master may be modifying).

---

## 5. Heal decision — NO HEAL THIS ROUND

**Decision:** Read-only. No heal commits.

**Rationale:**
- All P0/P1/P2 LIVREUR findings either (a) are V1.0.2 backlog scope by design (LIV-RED-08 / LIV-ARCH-02 — doorstep movement wire-up touches OrderService.php which is multi-master-shared), or (b) require non-trivial design choices (LIV-SEC-04 GDPR split, LIV-RED-05 idempotency table), or (c) are deploy-doc gaps (LIV-DBA-05 MySQL triggers — owner-gated production migration).
- The cash-session implementation itself is well-defended (triple-layer concurrency control, NF525 audit chain, BranchScope, FormRequest double-gate). No surgical low-risk heal targets.
- Three KDS failures observed are explicitly out-of-LIVREUR-scope.
- Parallel wave-B masters (Kiosk Couche 1 + Admin Dashboard) may be modifying overlapping files.

---

## 6. Verdict — GO V1 single-restaurant Le Cayenne

- **0 P0** — no critical regression in LIVREUR.
- **1 P1** — doorstep cash collection → movement wire-up missing. The reconcile variance calc is meaningless without it (always ≈ 0). Workaround = admin manually creates one `adjustment` movement PER cash-on-delivery order at shift close. Operationally heavy; viable only at very low order count (Le Cayenne single-restaurant in initial-launch volume). Multi-driver or SaaS deployment requires the wire-up BEFORE go-live.
- **9 P2** — V1.0.2 hardening backlog (notification idempotency, GDPR per-role split, MySQL prod triggers, FK to users/branches, double-assign lockForUpdate, fee branch_id auth check, customer cash receipt, driver self-service UI, raw integer IDs in list).
- **3 P3** — V1.0.x polish.

**Strongest evidence:** 119 LIVREUR-scoped Feature tests passing; 5 sentinels including 3 NF525-adjacent cash-session lifecycle tests; HEAD `4ad1adba8` builds clean.

**Production readiness for LIVREUR (single-restaurant V1):** SHIPPABLE.

**Production readiness for LIVREUR (SaaS V1.x):** GO-CONDITIONAL — needs LIV-DBA-05 (MySQL prod triggers), LIV-RED-03 (branch_id auth), LIV-SEC-04 (GDPR per-role split), LIV-RED-01 (double-assign lockForUpdate).

---

## 7. Deliverables index

- `reports/audit/livreur-fullsys-2026-05-18/round-1/LIV-1-architect/architect.json` (~1100 words)
- `reports/audit/livreur-fullsys-2026-05-18/round-1/LIV-2-security/security.json` (~1250 words)
- `reports/audit/livreur-fullsys-2026-05-18/round-1/LIV-3-uxa11y/uxa11y.json` (~1050 words)
- `reports/audit/livreur-fullsys-2026-05-18/round-1/LIV-4-dba/dba.json` (~1180 words)
- `reports/audit/livreur-fullsys-2026-05-18/round-1/LIV-5-red/red.json` (~1490 words)
- `reports/audit/livreur-fullsys-2026-05-18/synthesis/STATUS.md` (this file)

---

## 8. Recommended V1.0.2 LIVREUR backlog (priority order)

1. **LIV-RED-08 / LIV-ARCH-02 (P1)** — Wire doorstep DELIVERED + CASH_ON_DELIVERY → `DeliveryBoyCashSessionService::recordMovement`. Test sentinel: round-trip variance ≠ 0 when collections recorded.
2. **LIV-DBA-05 (P2)** — Ship MySQL-prod DELETE triggers migration for `delivery_boy_cash_*` tables (NF525 immutability parity with audit_logs).
3. **LIV-RED-01 (P2)** — Wrap `selectDeliveryBoy` mutation in DB::transaction + lockForUpdate.
4. **LIV-SEC-09 (P2)** — Split variance-override into dedicated permission `delivery.cash.reconcile.variance.override`.
5. **LIV-RED-05 / LIV-ARCH-07 (P2)** — Add notification builder idempotency claim (table or `ShouldBeUnique`).
6. **LIV-UX-05 (P2)** — Build Wave 6b-1.3b driver self-service surface (`/api/frontend/delivery-boy-shift/*` + mobile screens).
7. **LIV-DBA-06 (P2)** — Add FK from session→users + branches with restrictOnDelete.
8. **LIV-SEC-04 (P2)** — Split KDS resource by role (chef vs dispatcher PII access).
9. **LIV-RED-03 (P2)** — Auth-gate branch_id input on delivery quote.
10. **LIV-UX-06 (P2)** — Customer-facing cash receipt template (NF525 + FR consumer law).
11. **LIV-ARCH-05 (P2)** — Replace magic `status==101` with typed enum.
12. **LIV-UX-03/04 (P2/P3)** — Cash session list ergonomics + variance sr-only label.

---

**END STATUS**
