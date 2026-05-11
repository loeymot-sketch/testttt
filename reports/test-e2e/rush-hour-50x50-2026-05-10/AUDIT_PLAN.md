# AUDIT_PLAN — Rush-hour 50x50 + Mixed-20 + Rupture
Run id: `rush-hour-50x50-2026-05-10`
Owner: SorrowPatty724@outlook.com
Branch: `feature/mobile-app-le-cayenne-2026-05-10`
Plan agent date: 2026-05-10

## 0. Mission anchor (owner FR, verbatim)
> « pour simulation de heure de rush que ferons 50 commande par caisse et 50 commande par borne en simulation réel plusieur et differente client et commande audit massive pour determiner les point faible à améliorer pour la gestion et mettre tout le focus sur la coté technique et visuelle ! et une fois tout corrigé et validé passe en test 20 commande mixte et test de mettre un produit et un suplément en repture et voie si vraiment prise en compte et voir tout le flux et raisonne fort pour chaque test réel web E2E ! toujour ultra plan avant chaque act et gstack team agent work ! return to que tout est validé ! »

Phase 1 = 50 POS + 50 Kiosk rush. Phase 2 (gated on Phase 1 GREEN) = 20 mixed + rupture cascade on 1 item + 1 supplement.

## 1. Architectural decision (hybrid UI + API)

100 orders via UI on `workers:1` is impractical (~50 min/surface). We adopt a **hybrid pattern**:
- **12 UI orders/surface** drive the full visual + interactive flow.
- **38 API orders/surface** go through the **same Laravel controller endpoints** as the UI — they exercise `PricingService`, `FiscalSequenceService`, `OrderStateMachine`, `composition_snapshot`, `BranchScope`, idempotency middleware. No factory bypass.

## 2. Endpoints (confirmed by code review)

| Surface | Quote | Create | Confirm | Notes |
|---|---|---|---|---|
| POS  | `POST /api/admin/pos/quote` (PosController::quote, `throttle:pos-quote` = **120/min**) | `POST /api/admin/pos` (PosController::store, `throttle:pos-order-create` = **60/min** + `idempotency` middleware + `permission:pos`) | implicit | `token` field = customer call-out name (distinctness vector). |
| Kiosk | `POST /api/frontend/order/quote` (also PosController::quote with surface=kiosk, `throttle:kiosk-orders`) | `POST /api/frontend/order` (Frontend\OrderController::store, `throttle:kiosk-orders` + `idempotency`) | `POST /api/frontend/order/{frontendOrder}/payment-confirm` | `kiosk-orders` = **5/min** by default. |
| KDS  | `GET /api/admin/kds-order`; advance `POST /api/admin/kds-order/change-status/{id}` | — | — | |
| OSS  | `GET /api/admin/oss-order` | — | — | |
| Rupture | toggle item `POST /api/admin/menu/availability/toggle`; toggle extra `POST /api/admin/menu/availability/extra/toggle`; snapshot `GET /api/admin/menu/availability/branch/{branch}` | — | — | |

## 3. Rate-limit & pacing strategy

| Bucket | Limit | Plan |
|---|---|---|
| `pos-order-create` | 60/min/user | Pace at ≤1 req/s; call `clearFoodKingRateLimits()` between batches of 30 API orders. |
| `kiosk-orders` | **5/min/(userId\|ip)** | DOMINANT constraint. Strategy: `clearFoodKingRateLimits()` after every **4 API orders**. Do NOT alter env. |
| `pos-quote` | 120/min | Comfortable. |
| `admin-mutation` | 30/min (60 for availability sub-bucket) | Phase 2 fine. |

Each spec runs `clearFoodKingRateLimits()` in `beforeAll` and before bursts.

## 4. Token / orphan-cleanup contract

`Iter15CleanupTestOrdersCommand::TOKEN_PATTERNS` matches `AUDIT-%`, `RED-TEAM-%`, `ZZ-TEST-%`, `TEST-%`, `E2E-%`. To stay sweepable:

| Wave | Token / instruction prefix |
|---|---|
| A (POS rush) | POS `token`: `AUDIT-RUSH-A-{seq}-{ts}` |
| B (Kiosk rush) | items[*].instruction: `AUDIT-RUSH-B-{seq}-{ts}`; idempotency key `AUDIT-RUSH-B-{seq}-{ts}-IDK` |
| C (KDS+OSS) | reuses A+B orders |
| D (sampling) | samples from A+B |
| E (Mixed-20+rupture) | POS: `AUDIT-MIXED-E-POS-{seq}-{ts}`; Kiosk: instruction `AUDIT-MIXED-E-KIOSK-{seq}-{ts}` |

## 5. Distinct-customer encoding

- **POS Wave A** — `token` = `AUDIT-RUSH-A-{seq}-{ts}` (POS uses `token` as printed customer call-out name)
- **Kiosk Wave B** — items[*].instruction first line carries `AUDIT-RUSH-B-{seq}-{ts}`; X-Idempotency-Key unique; varied items rotation across catalog
- **Mixed Phase 2 (Wave E)** — 10 POS prefix `AUDIT-MIXED-E-POS-{seq}`, 10 kiosk instruction `AUDIT-MIXED-E-KIOSK-{seq}`

## 6. FROZEN zones (reviewers MUST respect)

| File / area | Why frozen | Reviewer rule |
|---|---|---|
| `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php` | Owner declared "design parfait". | Defects flagged with `"frozen_zone_deferred": true` — counted in `summary.P*` but EXCLUDED from `summary.open_P0/open_P1`. |
| `app/Services/Fiscal/*`, `BranchScope`, `IdempotencyKeyMiddleware`, `PricingService`, `OrderStateMachine` | NF525 / multi-tenant / business invariants. | Read-only audit. |
| **B-001** silent viande default substitution in pos-wizard.js | Owner-deferred. | Do not flag. |
| **B-002** ESC does not dismiss pos-wizard | Owner-deferred. | Do not flag. |

## 7. Out-of-scope

- Delivery flow (TAKEAWAY only for POS, KIOSK only for kiosk).
- Dine-in / floor-plan (`pos.dine_in_enabled=false`).
- Online ordering / customer mobile app.
- Loyalty redemption.
- Split payment.
- Refund / counter-entry / Z-report.

## 8. Pre-flight (orchestrator runs ONCE before wave A)

1. Server health 200.
2. `mysql -uroot foodking -e "SELECT COUNT(*) FROM items WHERE status=5 AND is_available=1;"` → 63.
3. `php artisan iter15:cleanup-test-orders --apply` (run again before each wave's `beforeAll`).
4. `clearFoodKingRateLimits()` (called by each wave's `beforeAll`).
5. Kiosk machine active: `SELECT id, status, branch_id FROM kiosk_machines WHERE username='kiosk-lecayenne';` → status=5, branch_id=1.
6. Branch isolation probe.
7. Fiscal seq baseline: `SELECT COALESCE(MAX(fiscal_sequence_no),0) FROM orders WHERE branch_id=1;` recorded.

## 9. Item triplet (live catalog, all status=5/ACTIVE, is_available=1)

| id | Name | Price (TTC) | Has options | Use case |
|---|---|---|---|---|
| **361** | Frites Seules | 2.00€ | 2 free extras, 0 variations | Simple item, no wizard |
| **363** | Tacos M (1 Viande) | 6.50€ | 24 variations + 23 extras | Wizard-heavy |
| **375** | Burger Poulet | 6.00€ | 15 variations + 23 extras | Medium wizard |

API order rotation across 38 orders/surface: 12× id 361, 16× id 363, 10× id 375.

## 10. Wave structure

### Wave A — POS rush 50 orders
- **Spec**: `tests/e2e/test-e2e-rush-hour-50x50-2026-05-10-wave-A.spec.js`
- **Screenshots**: `tests/e2e/__screenshots__/test-e2e-rush-hour-50x50-A/`
- **Contexts**: posCtx (pos@lecayenne.fr) + chefCtx (cross-check KDS)
- **Orphan prefix**: `AUDIT-RUSH-A-{seq}-{ts}`
- **Wallclock**: ~16 min
- **States (18)**:
  1. `01-A-pos-baseline`
  2. `02-A-pos-wizard-tacos-open`
  3. `03-A-pos-wizard-tacos-step-viande`
  4. `04-A-pos-wizard-tacos-step-sauces`
  5. `05-A-pos-wizard-tacos-step-extras`
  6. `06-A-pos-cart-tacos-added`
  7. `07-A-pos-payment-modal-cb`
  8. `08-A-pos-receipt-tacos`
  9. `09-A-pos-mid-rush-cart-25`
  10. `10-A-pos-payment-modal-especes`
  11. `11-A-pos-receipt-especes`
  12. `11b-A-pos-tracker-during-rush`
  13. `12-A-pos-payment-modal-tpe`
  14. `13-A-pos-parked-order-recall`
  15. `14-A-pos-post-rush-tracker-50`
  16. `15-A-pos-tracker-filter-by-status`
  17. `16-A-pos-search-by-token`
  18. `17-A-kds-pile-from-A`
- **Cross-surface assertions**:
  - **NUMERIC-A1 (P0)** — POS receipt total === KDS card total === DB `orders.total_amount_price` (5 samples).
  - **FISCAL-A2 (P0)** — `fiscal_sequence_no` monotonic gap-free (delta i+1 - i == 1).
  - **BRANCH-A3 (P0)** — every order's `branch_id == 1`.
  - **TIMING-A4 (P1)** — KDS receives each order within 8s p95.
  - **COMPOSITION-A5 (P0)** — `composition_snapshot` non-null for Tacos M orders.
- **MUST-PASS**: A1, A2, A3, A4, A5, zero unexpected 4xx/5xx, zero silent errors.

### Wave B — Kiosk rush 50 orders
- **Spec**: `tests/e2e/test-e2e-rush-hour-50x50-2026-05-10-wave-B.spec.js`
- **Screenshots**: `tests/e2e/__screenshots__/test-e2e-rush-hour-50x50-B/`
- **Contexts**: kioskCtx + chefCtx
- **Orphan**: items[*].instruction = `AUDIT-RUSH-B-{seq}-{ts}`; X-Idempotency-Key `AUDIT-RUSH-B-{seq}-{ts}-IDK-{uuid}`
- **Wallclock**: ~18 min
- **States (16)**:
  1. `01-B-kiosk-idle`
  2. `02-B-kiosk-language-fr`
  3. `03-B-kiosk-order-type`
  4. `04-B-kiosk-categories-root`
  5. `05-B-kiosk-tacos-detail`
  6. `06-B-kiosk-wizard-viande-step`
  7. `07-B-kiosk-wizard-sauces-step`
  8. `08-B-kiosk-cart-review`
  9. `09-B-kiosk-upsell-screen`
  10. `10-B-kiosk-payment-modal`
  11. `11-B-kiosk-confirmation`
  12. `12-B-kiosk-mid-rush-after-25`
  13. `13-B-kiosk-burger-order`
  14. `14-B-kiosk-final-confirmation`
  15. `15-B-kiosk-idle-after-burst`
  16. `16-B-kds-pile-from-B`
- **Cross-surface assertions**:
  - **NUMERIC-B1 (P0)** — kiosk confirmation total === KDS card total === DB total (5 samples).
  - **QUEUE-B2 (P0)** — `queue_number` monotonic gap-free.
  - **TOKEN-B3 (P0)** — `transaction_id` non-null on all 50.
  - **TIMING-B4 (P1)** — KDS reflects each order within 8s p95.
  - **CATALOG-B5 (P1)** — kiosk catalog returns 200 throughout burst (no user-visible 429).
- **Hard pacing**: after every 4 API orders, `clearFoodKingRateLimits()`. Retry 429 with same idempotency key (idempotent).

### Wave C — KDS + OSS under load
- **Spec**: `tests/e2e/test-e2e-rush-hour-50x50-2026-05-10-wave-C.spec.js`
- **Screenshots**: `tests/e2e/__screenshots__/test-e2e-rush-hour-50x50-C/`
- **Contexts**: chefCtx (KDS) + ossCtx (admin OSS)
- **Precondition**: Waves A + B finished, 100 orders live on KDS pile.
- **Wallclock**: ~12 min
- **States (14)**:
  1. `01-C-kds-pile-100-orders` (full-page screenshot)
  2. `02-C-kds-pile-takeaway-column`
  3. `03-C-kds-card-detail-expanded`
  4. `04-C-kds-advance-to-preparing` (10 orders)
  5. `05-C-kds-mixed-status-pile`
  6. `06-C-kds-advance-to-prepared` (5 → PREPARED)
  7. `07-C-kds-filter-by-station`
  8. `08-C-oss-queue-100`
  9. `09-C-oss-preparing-section`
  10. `10-C-oss-ready-section`
  11. `11-C-kds-scroll-stress`
  12. `12-C-oss-realtime-update-after-status-change`
  13. `13-C-kds-overflow-layout-check`
  14. `14-C-network-quiescence`
- **Cross-surface assertions**:
  - **PROPAGATION-C1 (P0)** — KDS → PREPARING reflected on OSS within 8s p95.
  - **PROPAGATION-C2 (P0)** — KDS → PREPARED in OSS "READY" within 8s p95.
  - **LAYOUT-C3 (P1)** — no element overlap at 100-order pile.
  - **CONSOLE-C4 (P1)** — no `pageerror` during 12-min window (excluding Pusher/vendor noise).

### Wave D — Cross-surface integrity sampling
- **Spec**: `tests/e2e/test-e2e-rush-hour-50x50-2026-05-10-wave-D.spec.js`
- **Screenshots**: `tests/e2e/__screenshots__/test-e2e-rush-hour-50x50-D/`
- **Contexts**: posCtx + chefCtx + ossCtx (3 contexts)
- **Precondition**: Waves A+B+C done.
- **Wallclock**: ~10 min
- **Sample**: 5 from `AUDIT-RUSH-A-%`, 5 from `AUDIT-RUSH-B-%`. Varied lifecycle stages.
- **States (12)**:
  1. `01-D-RUSH-1-pos-receipt`
  2. `02-D-RUSH-1-kds-card`
  3. `03-D-RUSH-1-oss-card`
  4. `04-D-RUSH-1-db-snapshot`
  5. `05-D-RUSH-2-pos-receipt`
  6. `06-D-RUSH-2-kds-card-ready`
  7. `07-D-RUSH-2-oss-ready`
  8. `08-D-RUSH-3-kiosk-confirmation-replay`
  9. `09-D-RUSH-3-kds-card`
  10. `10-D-RUSH-3-oss-card`
  11. `11-D-RUSH-4-kiosk-payment-confirm-payload`
  12. `12-D-cross-surface-numeric-grid`
- **Assertions**:
  - **NUMERIC-D1 (P0)** — for 10 sampled orders, all four totals equal (centime).
  - **STATE-D2 (P0)** — DB status === KDS status === OSS section.
  - **BRANCH-D3 (P0)** — every sampled order has `branch_id=1`.
  - **FISCAL-D4 (P0)** — `fiscal_sequence_no` non-null on POS orders; null OK for kiosk (POS-only by design).

### Wave E — Mixed 20 + rupture cascade (PHASE 2)
- **Spec**: `tests/e2e/test-e2e-rush-hour-50x50-2026-05-10-wave-E.spec.js`
- **Screenshots**: `tests/e2e/__screenshots__/test-e2e-rush-hour-50x50-E/`
- **Contexts**: adminCtx + posCtx + kioskCtx + chefCtx (4 contexts)
- **Orphan**: POS `token` = `AUDIT-MIXED-E-POS-{seq}-{ts}`; kiosk instruction = `AUDIT-MIXED-E-KIOSK-{seq}-{ts}`
- **Wallclock**: ~18 min
- **Rupture targets**:
  - Item: `id=362 Boisson Seule` (2.00€)
  - Supplement: `id=175 Jambon de dinde` (item_id=363, 1.00€)
- **Cleanup**: afterAll forces both back available + cleanup-test-orders sweep.
- **States (20)**:
  1. `01-E-pre-rupture-baseline-admin`
  2. `02-E-pre-rupture-pos-catalog`
  3. `03-E-pre-rupture-kiosk-catalog`
  4. `04-E-rupture-toggle-item-362-admin-click`
  5. `05-E-rupture-toggle-extra-175-admin-click`
  6. `06-E-cascade-pos-item-362-hidden`
  7. `07-E-cascade-pos-wizard-tacos-extra-175-disabled`
  8. `08-E-cascade-kiosk-item-362-hidden`
  9. `09-E-cascade-kiosk-wizard-tacos-extra-175-disabled`
  10. `10-E-mixed-pos-order-1-burger`
  11. `11-E-mixed-pos-order-2-tacos-with-extra`
  12. `12-E-mixed-pos-api-attempt-ruptured-item-blocked`
  13. `13-E-mixed-pos-api-attempt-ruptured-extra-blocked`
  14. `14-E-mixed-kiosk-order-1-frites`
  15. `15-E-mixed-kiosk-order-2-tacos-attempt-ruptured`
  16. `16-E-mixed-kiosk-api-attempt-ruptured-blocked`
  17. `17-E-after-20-orders-kds-pile`
  18. `18-E-restore-item-362-admin-click`
  19. `19-E-restore-extra-175-admin-click`
  20. `20-E-post-restore-catalog-pos-and-kiosk`
- **Assertions**:
  - **RUPTURE-E1 (P0)** — within 8s of admin toggle, item 362 ruptured/hidden on POS.
  - **RUPTURE-E2 (P0)** — same on Kiosk.
  - **RUPTURE-E3 (P0)** — within 8s, supplement 175 disabled in POS wizard.
  - **RUPTURE-E4 (P0)** — same in Kiosk wizard.
  - **BYPASS-E5 (P0)** — direct API POST with ruptured item/extra → 4xx descriptive.
  - **CASCADE-LAG-E6 (P1)** — admin-click→render latency ≤8s p95.
  - **REVERSIBILITY-E7 (P0)** — restore-toggle → both surfaces show available within 8s.
  - **NUMERIC-E8 (P0)** — 20 mixed orders pass cart=receipt=KDS=DB.

## 11. Phase 1 → Phase 2 gate

Each spec independently runnable. Phase-1→Phase-2 gate:
- Round 1+ of Waves A,B,C,D.
- Adversarial JSONs round-N/wave-X-findings.json.
- Aggregator computes `open_P0/open_P1` (frozen-zone-deferred excluded).
- 2 consecutive identical-set GREEN rounds → `PHASE1_GREEN.flag` → launch Wave E.

## 12. Risk register (top 5)

| # | Risk | Likelihood | Mitigation |
|---|---|---|---|
| R1 | Kiosk-orders 5/min rate-limit | High | Clear every 4 orders, retry 429 same idempotency |
| R2 | fiscal_sequence_no gap-free | Medium | tinker probe; gap → halt + escalate |
| R3 | Rupture cascade slow on cold cache | Medium | Document as P1, don't loop |
| R4 | KDS Pusher absent dev | Medium | 2s polling fallback |
| R5 | Idempotency key collision | Low | UUID suffix |

## 13. Convergence + cleanup

- Each wave runs `cleanupOrphanTestOrders()` in `beforeAll` + token-prefix cleanup in `afterAll`.
- Aggregator excludes `frozen_zone_deferred:true` and ids B-001/B-002.
- Final `CONVERGENCE_FINAL.md` after 2 identical GREEN rounds.

## 14. Cross-surface scenario catalog

| ID | Surfaces | Wave |
|---|---|---|
| RUSH-1 | POS + KDS | A |
| RUSH-2 | Kiosk + KDS | B |
| RUSH-3 | KDS | C |
| RUSH-4 | OSS | C |
| RUSH-5 | POS+KDS+OSS+DB | D |
| MIXED-1 | POS + Kiosk + KDS | E |
| RUPTURE-1..6 | Admin + POS + Kiosk | E |

## 15. Total state count

| Wave | States | Artifacts/round |
|---|---|---|
| A | 18 | 72 |
| B | 16 | 64 |
| C | 14 | 56 |
| D | 12 | 48 |
| E | 20 | 80 |
| **Total** | **80** | **320** |

— END OF PLAN —
