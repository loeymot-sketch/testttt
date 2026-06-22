# D0 Coverage Matrix — Production Live Validation — 2026-04-27

Verdict: `COVERAGE_BASELINE_READY_WITH_GAPS`

This matrix maps current coverage to D1-D13. It is not a release PASS; it is the baseline for massive validation.

## Matrix

| Domaine | Playwright E2E | Vitest UI | PHPUnit Feature | PHPUnit Unit | Manuel | Gap |
| --- | --- | --- | --- | --- | --- | --- |
| KIOSK process | `tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js`, `kiosk-post-payment-auto-return.spec.js`, legacy kiosk specs | 70+ kiosk specs under `tests/js/kiosk*.spec.js` | `KioskPaymentStateMachineTest`, `KioskRealtimeBroadcastTest`, `OrderPipeline/KioskFullFlowE2ETest`, kiosk security/phase tests | Kiosk-specific unit minimal | Hardware UAT pending | Not yet D4-level 10 scenarios x10, no live TPE, no chaos reboot. |
| POS process | `tests/e2e/pos-full-process/c2-pos-process-audit.spec.js`, `02-pos-cash`, `05-pos-card`, tacos POS flow | 25+ `pos*.spec.js` | `POSComprehensiveTest`, `Pos*`, pricing/discount/tax/floorplan tests | Pricing/tax/calculator unit tests | Hardware printer/cash drawer pending | Not yet D5-level 10 scenarios x10 with 3 concurrent operators. |
| KDS process | `tests/e2e/composer-mega-flow.spec.js`, `04-kds-status`, `tests/Playwright/KdsMultiScreenPlaywrightTest.spec.js` | `kds*.spec.js` suite | `KDSFlowTest`, `KDSOrderItemsTest`, `Kds*` feature tests | none dedicated | KDS hardware screen pending | D6 lacks 10x visual/capacity/reconnect proof. |
| OSS process | Partial OSS route/read-only feature tests | Sparse | `OSSReadOnlyTest`, branch policy sentinels | none | Big-screen UAT pending | No D3 OSS visual baselines; no queue board run-many. |
| Sync cross-channel | `pos-receives-kiosk-realtime.spec.js`, C1/C2 process specs, composer flow | `eventContractDedupe`, `realtimeBroadcastFallback`, `ws*`, KDS/POS handlers | `EventContractTest`, `KioskRealtimeBroadcastTest`, `DispatchAfterCommitTest`, outbox tests | `EventContractUnitTest` | Real Soketi/Pusher hardware pending | D7 still needs live multi-page latency and network loss runs. |
| Stock V2 | C1/C2 stock assertions | `posAvailabilityLiveGuard`, `posRuptureUx`, `kioskRuptureUx` | `tests/Feature/Stock/*`, menu availability tests | none dedicated | Physical multi-device pending | D9 needs 100 parallel stress and 10 kiosk realtime proof. |
| Pricing SSOT | POS/kiosk process specs cover selected paths | `deliveryCharge`, `kioskPricingPreview`, POS cents/cart specs | `PricingIntegrityTest`, `QuoteTamperTest`, `PosPricingSsotProofTest`, `DeliveryFeeForge*`, `OrderRequestDeliveryFeeAuthorityTest` | `DiscountCalculatorTest`, `TaxCalculatorTest`, `DeliveryFeeServiceTest`, `PricingServiceTest` | none | D10 needs full 15 forged attacks x5 and explicit audit-log assertions. |
| Authz / branch | Limited browser auth routing | `wsAuth*`, router lockdown specs | branch isolation, composer authz, KDS scope, payment confirm cross branch, kiosk security tests | rate limiter config | Human role matrix review pending | D11 route x role x branch matrix not exhaustive. |
| Persistence / fiscal | Composer flow touches fiscal path | receipt/duplicata specs | many `tests/Feature/Fiscal/*`, outbox, audit log, order quotes | event contract, service security | Fiscal printer UAT pending | D8 still needs tamper drill, outbox ordering under load, Z report full business day. |
| Design / a11y | No complete screenshot baseline suite | `kioskA11y*`, `posA11y*`, restyle specs | none | static HTML guard | Hardware visual UAT pending | D1-D3 baselines absent for all required viewports/screens. |
| Resilience / chaos | `kiosk-offline-waiting`, some offline/legacy specs | offline queue, reconnect storm specs | cleanup race, queue routing, outbox rescue tests | none | Network unplug UAT pending | D12 chaos matrix mostly absent. |
| Dashboard management | No full E2E CRUD path | `productComposerEditor`, `productComposerSummary`, availability toggle | composer/catalog/photo/availability tests | none | Manager workflow UAT pending | D9 dashboard end-to-end for products/categories/photos/stock not complete. |

## Existing High-Value Test Anchors

Playwright:
- `tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js`: 5 kiosk process scenarios, already run 5x during C1.
- `tests/e2e/pos-full-process/c2-pos-process-audit.spec.js`: 5 POS process scenarios, already run 5x during C2.
- `tests/e2e/kiosk-post-payment-auto-return.spec.js`: C0 auto-return regression.
- `tests/e2e/composer-mega-flow.spec.js`: composer/cash-at-counter integration.
- Legacy critical flows: `01-auth-refresh`, `02-pos-cash`, `03-kiosk-wizard`, `04-kds-status`, `05-pos-card`, `06-staff-only-routing`.

Vitest:
- Kiosk: `kioskWaitingAutoReturn`, `kioskConfirmationCountdown`, `kioskCounterPaymentFlow`, `kioskRuptureUx`, `kioskWizardComposerProfile`, `kioskA11y*`, `kioskOfflineQueue*`.
- POS: `PosComponent`, `posRuptureUx`, `posAvailabilityLiveGuard`, `posPaymentComponentContract`, `posFloorplan`, `posCart*`, `posWizardComposerProfile`.
- KDS/realtime: `kds*`, `eventContractDedupe`, `realtimeBroadcastFallback`, `wsReconnectStorm*`, `wsAuth*`.
- Dashboard composer: `productComposerEditor`, `productComposerSummary`, `adminAvailabilityToggle`.

PHPUnit Feature:
- Stock: all `tests/Feature/Stock/*`.
- Fiscal/audit: all `tests/Feature/Fiscal/*`.
- Pricing/quotes: `PricingIntegrityTest`, `Quote*`, `PosPricingSsotProofTest`, `DeliveryFeeForge*`.
- Branch/authz: `Branch*`, `ComposerAuthzMinimalTest`, `KioskSecurity/*`, `KDSScopeRestrictionTest`, payment cross-branch tests.
- Events/outbox: `EventContractTest`, `Outbox*`, `DispatchAfterCommitTest`, `AfterCommitDispatchTest`.
- KDS: `KDS*`, `Kds*`.

## Precise Gaps for D1-D13

1. D1: Kiosk lacks screenshot baselines for 14 required screens at 1920x1080, 1080x1920, and 2160x3840.
2. D1: Kiosk visual tests do not yet run 5x per screen with snapshot diff.
3. D1: Kiosk axe checks exist in Vitest but not browser-level Playwright axe on every screen.
4. D1: Kiosk i18n FR/EN scan is not tied to every required route.
5. D1: Kiosk tap-target measurement across every visible button is not complete.
6. D1: Login screen lacks stable `data-testid` coverage.
7. D1: Wizard step internals have partial selectors; step-level visual baselines absent.
8. D2: POS lacks complete stable testids for item grid/cart/payment/floorplan.
9. D2: POS visual baseline suite absent.
10. D2: POS keyboard accessibility and focus order not covered across the whole surface.
11. D2: POS payment modal a11y not browser-axe validated.
12. D2: POS counter-collect visual badge panel is not covered by screenshots.
13. D2: POS receipt/print layout visual regression not complete.
14. D3: KDS visual baseline suite absent.
15. D3: KDS high-density 30-ticket layout not browser-validated.
16. D3: KDS 4K kitchen viewport not validated.
17. D3: OSS big-screen queue readability not screenshot-validated.
18. D4: Kiosk C1 covers 5 scenarios, but D4 requires 10 scenarios x10 plus 3 concurrent sessions.
19. D4: Kiosk promo/loyalty/upsell combined order flow lacks run-many coverage.
20. D4: Kiosk physical TPE success/refusal/timeout still needs hardware or simulator.
21. D4: Kiosk abandon/restart covered once in C1 but not 10x with state cleanup assertions.
22. D5: POS C2 covers 5 scenarios, but D5 requires 10 scenarios x10 plus 3 concurrent cashiers.
23. D5: POS refund partial/total browser flow not covered in process spec.
24. D5: POS parked order lifecycle not included in C2 run-many.
25. D5: POS manual discount authorization and audit path not included in C2 run-many.
26. D6: KDS PENDING_COUNTER badge has integration coverage but lacks run-many direct visual proof.
27. D6: KDS status transition race with two screens not 10x browser validated.
28. D6: KDS station filter + allergen snapshot not covered in one end-to-end browser flow.
29. D7: Real websocket latency budget <2s is not measured in multi-page browser test.
30. D7: WebSocket reconnect with queued local kiosk order not run in C1/C2.
31. D7: Multi-branch simultaneous kiosk/POS/KDS session not fully browser-tested.
32. D8: Audit-log tamper drill exists in features but no production-live consolidated tamper script/report.
33. D8: Outbox ordering/idempotency under concurrent workers needs run-many proof.
34. D8: `composition_snapshot` broadcast payload is not explicitly asserted in every outbox broadcast.
35. D8: Full Z day close after kiosk+POS+counter-deferred mix not covered as D8 scenario.
36. D9: Stock stress is feature-tested but not 100 parallel process-level browser/API stress.
37. D9: Stock rupture realtime across 10 kiosks and POS screen not proven.
38. D9: Partial refund stock release edge cases need broader coverage.
39. D10: Full forged payload matrix has not been generated as a single spec.
40. D10: Delivery geocode 422 is tested, but Google Maps real integration remains UAT.
41. D10: Frontend preview helpers need explicit non-authoritative assertions in D10.
42. D11: Role x route x own/foreign branch matrix is not exhaustive.
43. D11: Composer permission seeder grants four roles while earlier spec expected two; governance decision remains P2.
44. D11: Counter-collect inline closures are functional but not controller-policy structured; P2 cleanup.
45. D12: DB lock/slow transaction chaos not simulated.
46. D12: Kiosk reboot mid-payment/mid-order not simulated.
47. D12: Queue overflow / 50 parallel order allocations only partly covered by feature concurrency.
48. D12: Clock skew and fiscal date boundary chaos absent.
49. D12: Broadcast server down/recovery with real Echo server not complete.
50. D13: No consolidated go/no-go live report exists after D1-D12.

## D0 Coverage Decision

Current C0-C2 and backend sync audits are strong enough to proceed to massive validation, but they are not a live-release substitute. D1-D3 should start with design/test harnesses; D4-D12 should only claim PASS after run-many evidence is produced and attached to D13.
