# Deferred V1.0.2: Driver Auto-Dispatch + Push + SMS Notifications

**Date**: 2026-05-17
**Sprint**: V1.0.1 Hardening, H3.6 (DEL-9)
**Decision-maker**: Plan owner (per MASTER §9 H3.6 + Sister verdict Sprint 4 scope)
**Status**: DEFERRED to V1.0.2

## Finding

Wave Z Z9 — DEL-9 (P1 carryover from Sister Sprint 4 verdict):

> Assignation livreur 100% manuelle dropdown admin, pas d'auto-dispatch, pas de push/SMS

Current state: an admin manually picks a delivery boy from a dropdown when a DELIVERY order arrives. There is no automatic dispatching algorithm, no push notification to the driver's app, and no SMS fallback. The driver discovers the assignment by opening the admin UI manually.

## Why this is deferred to V1.0.2

1. **Scope**: Auto-dispatch is a multi-week feature. Realistic scope includes:
   - Driver availability state machine (`available` / `assigned` / `en_route` / `delivering` / `returning` / `off-duty`)
   - Dispatching algorithm (nearest-available, round-robin, load-balancing, owner-tunable)
   - Driver app integration (Expo push notifications, deep links to assignment)
   - SMS fallback via Twilio / SenangPay-SMS / Nexmo (provider selection itself is a decision)
   - Operator dashboard for manual override
   - Audit trail for dispatch decisions

   Each of these is its own H-sprint-scale unit of work.

2. **V1.0.1 is V1 Le Cayenne single-restaurant ship**. Le Cayenne uses 2-3 drivers and the manual dropdown is operationally workable. The auto-dispatch becomes critical only at SaaS B2B scale (10+ branches, 50+ drivers, multi-tenant routing).

3. **Risk-of-delay**: bundling auto-dispatch into V1.0.1 would push the merge-to-main milestone by 3-4 weeks. The remaining V1.0.1 hardening (Sprints H4-H6) closes higher-value gaps (KDS V2 finalize, admin polish, test debt).

4. **Telemetry-driven design**: building auto-dispatch BEFORE we observe real driver workload patterns from V1 production usage risks designing for the wrong constraints. V1.0.1 ship → 4 weeks of prod observation → V1.0.2 scope informed by data.

## V1.0.2 scope outline (informational, not authoritative)

When this work resumes, the V1.0.2 sprint should include:

### Phase 1 — Driver state (1 sprint, 5d)
- Migration: `drivers.dispatch_status`, `drivers.last_location_lat/lng`, `drivers.last_seen_at`
- Driver-side endpoint: `POST /api/driver/heartbeat` (location ping every 30s during active shift)
- Admin dashboard: real-time driver-state board

### Phase 2 — Auto-dispatch algorithm (1 sprint, 5d)
- Service `DriverDispatchService::nextAvailable(Branch $branch, Order $order)`
- Strategy enum: `NEAREST_AVAILABLE` (default) | `ROUND_ROBIN` | `LOAD_BALANCED`
- Branch-level setting: `branches.dispatch_strategy`
- Listener on `OrderPaidAtCounter` → fire dispatch
- Manual-override endpoint preserved

### Phase 3 — Notifications (1 sprint, 5d)
- Push: Expo notification token registration + FCM/APNS fallback
- SMS: `NotificationService` with Twilio adapter (provider-pluggable)
- Templates: assignment alert, customer arrival warning, completion confirmation
- Delivery boy app deep-link: `foodking-driver://order/{id}`

**Total estimated effort**: ~15 jours-agent across 3 sprints. Owner decision required at V1.0.2 kickoff on strategy default + SMS provider.

## What V1.0.1 does NOT cover (deliberately)

- Driver mobile app changes
- Push notification infrastructure
- SMS fallback
- Auto-dispatch algorithm
- Driver state machine

## What V1.0.1 DOES cover (delivery-adjacent)

| Item | Status | File |
|------|--------|------|
| Geocode status gate | Sprint 2B + Wave Z 5A | DeliveryQuoteService |
| OrderAddress mandatory throw | Sprint 2B + Wave Z 5A | FrontendOrderService |
| User.phone E.164 strict | Sprint 2B + Wave Z 5A | ValidPhone |
| GDPR phone wire-gate on DELIVERY | Wave Z 5A | SimpleOrderResource + KDSOrderDetailsResource |
| Branch-configurable delivery fee | Sprint H3 DEL-5 | DeliveryFeeService + Branch model |
| FR i18n delivery key parity | Sprint H3 DEL-6 | fr.json + en.json |
| BranchService zone-missing warning | Sprint H3 DEL-7 | BranchService::showByLatLong |
| Branch-configurable minimum order | Sprint H3 DEL-8 | OrderRequest + Branch model |

These give the operator the data + config surface needed to deploy V1.0.1 confidently. Auto-dispatch is the operational automation layer ON TOP of that data — clearly separable.

## References

- Wave Z `reports/test-e2e/wave-z-2026-05-16-claudemax/CONVERGENCE_FINAL.md` §"V1.0.1 polish backlog"
- Sister `reports/audit/ultra-review-2026-05-16/ULTRA_REVIEW_VERDICT.md` Sprint 4 backlog DEL-9
- `plans/v1-0-1-hardening/MASTER_V1_0_1_HARDENING_2026-05-16.md` §9 H3.6
- CLAUDE.md §1 NORTH STAR V1.0.1 backlog

## Reversal trigger

Move from V1.0.2 to V1.0.1 IF and ONLY IF:
- Production prod incident traced to manual-dispatch (e.g., driver loses orders, customer wait > 60min due to forgotten assignment)
- Owner reports recurring operator complaint with telemetry evidence
- SaaS B2B sale signed that requires multi-branch auto-dispatch as deal blocker

Otherwise: scheduled V1.0.2 cycle.
