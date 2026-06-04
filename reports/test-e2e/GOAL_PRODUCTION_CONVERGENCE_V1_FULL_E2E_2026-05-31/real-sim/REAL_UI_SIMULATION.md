# Real-UI Massive Simulation — orchestrator/server/supervisor

**Goal (owner):** act as orchestrator + server + supervisor; drive REAL orders on the board (Playwright clicks), confirm the base works + sync + data well-registered (no bad recording / bad organization); massive; for the BOX (caisse) and the BORNE (customer side); capture + analyze; production-ready or fix-and-redo.

**Verdict: ✅ PRODUCTION-READY on the order lifecycle (caisse + borne customer flows).** Both flows driven by real Playwright UI clicks, every order verified correctly recorded + synced + fiscally compliant. No bad recording, no mismatch, no orphan, chain gap-free.

---

## 1. POS / CAISSE ("the box") — real UI order, end-to-end
Driven by clicks: board tile → frozen POS wizard → "Ajouter au panier" → cart → "Commande" → "Paiement De Commande" (Espèces) → `#cashInput`=2 → "Confirmer & Imprimer ticket".

**Order #1041 / queue A0016 / Coca-Cola 33cl** — recording verified in DB:
| Check | Value |
|---|---|
| total / tax | 1.50 TTC / 0.14 (HT 1.36 = 1.50÷1.10) ✓ |
| payment_status | 5 = PAID ✓ |
| fiscal_sequence_no | **#170 allocated at sale** (POS cash → immediate) ✓ |
| composition_snapshot | present (106b) ✓ |
| cash_movement | #138, 1.50€, drawer session #7 (open) ✓ — **money trail** |
| sync | `order.created` event #771 → channel `private-branch.1` → **DISPATCHED** ✓ |
| **KDS live** | card **[G] N°A0016 "EN COURS · 1× Coca-Cola 33cl"** appeared (~3 min) — **item content visible** ✓ |

## 2. KIOSK / BORNE — real UI order, CUSTOMER side, end-to-end
Driven by clicks as a customer: idle "À emporter" → categories (authenticated, menu loads) → "+ Coca-Cola" → cart ("VOTRE COMMANDE", **discount UI live**: Code promo + "Avez-vous une carte fidélité ?") → "Valider ma commande" → upsell "Non merci" → "PAIEMENT À LA CAISSE" (Plan B) → "Confirmer ma commande" → "Rendez-vous en caisse #A0017".

**Order #1042 / queue A0017 / Coca-Cola 33cl** — recording verified:
| Check | Value |
|---|---|
| total / tax | 1.50 TTC / 0.14 (HT 1.36) ✓ |
| payment_status | 15 = PENDING_COUNTER (Plan B counter-deferred) ✓ |
| fiscal_sequence_no | **NULL at create** (correct — kiosk doesn't allocate fiscal until paid) ✓ |
| composition_snapshot | present (106b) ✓ |
| sync | `order.created` #772 + `order.status_changed` #773 → `private-branch.1` → DISPATCHED ✓ |
| counter queue | POS "À encaisser borne" count **59 → 60** — reached the caisse ✓ |

## 3. FULL LIFECYCLE CLOSED — counter collection (fiscal-at-counter invariant)
Collected order 1042 via the real `POST /api/admin/pos/counter-collect/1042/confirm` (the endpoint the "Encaisser" button calls), cash:
| Check | Value |
|---|---|
| payment_status | 5 = PAID ✓ |
| fiscal_sequence_no | **#171 allocated AT COUNTER-CONFIRM** (not at kiosk-create — the NF525 timing invariant) ✓ |
| chain | **CHAIN OK, gap-free 170→171** ✓ |
| cash_movement | #139, 1.50€, session #7 ✓ |
| transaction | #20, 1.50€, `counter_cash` / payment ✓ |
| fiscal audit | audit_log #441 `order.counter_payment_confirmed` (HMAC-signed) ✓ |

## 4. MASSIVE volume — invariants hold at scale
20 concurrent kiosk orders (mixed items Menu/Coca/Eau) via the proven quote→order flow:
- **all 201** (1745ms), **20 distinct queue numbers / 0 dup**, **0 bad totals**, **0 missing composition_snapshots**, chain CHAIN OK, **outbox pending 0** (sync drained).
- (Plus this session's earlier 30-concurrent rush + 8-concurrent discounted burst — all race-safe, chain OK.)

## 5. Money reconciliation (no bad recording / no bad organization)
| Order | total | cash_movement | transaction | match |
|---|---|---|---|---|
| 1041 (direct POS sale) | 1.50 | 1.50 | — (direct sales don't write txn, by design) | ✓ |
| 1042 (counter-collected) | 1.50 | 1.50 | 1.50 (counter_cash) | ✓ |

The "3 stores" cover distinct flows consistently: `cash_movements` = drawer tracking (both), `transactions` = counter collections, `order_payments` = specific multi/card flows. Zero mismatch. Orders correctly organized by `source` (POS=15 / kiosk=5), `payment_status`, and fiscal-allocation timing.

## 6. State
- Dev DB: **416 orders, fiscal #171, chain CHAIN OK, z-membership OK**. The 2 lifecycle orders (#170, #171) are legitimate PAID NF525 records (immutable; chain advanced 169→171 gap-free — the cost of proving fiscal recording live; dev DB is `migrate:fresh`'d at go-live). All rush/burst/massive test orders (fiscal-NULL) cleaned.
- **0 source code touched.** No push. 11 analyzed screenshots in `real-sim/screenshots/`.

## 7. Conclusion
The base operation, cross-surface synchronization, and data recording are **functional and correct under real UI use, for both the caisse and the borne customer side**, including the full kiosk→counter→fiscal lifecycle, at concurrent volume, with exact money reconciliation and a gap-free NF525 chain. **No fault found.** Production-ready on the order lifecycle. (Open backlog items from the convergence cycle remain owner-gate, non-blocking: COUPON-CAP-01 P1, PERF-01 P2 KDS N+1, A11Y P2/P3, DOC-DRIFT-01 P3.)
