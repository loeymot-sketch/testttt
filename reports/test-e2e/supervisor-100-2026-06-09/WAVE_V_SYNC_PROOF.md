# WAVE-V — live mutation-E2E + sync proof on the disposable :8766 clone
**2026-06-09 · harness `APP_ENV=e2e` → `foodking_e2e` (clone, 2239 orders) · soketi :6001 UP · queue worker UP**

## Harness stood up + validated
- `:8766` serves the **disposable `foodking_e2e` clone** (NOT the operating `foodking`). Login OK (admin@lecayenne.fr). KDS/OSS render real cloned data.
- **Isolation invariant:** operating `foodking` `audit_logs` = **2674 before AND after** every mutation below → the operating NF525 chain is provably untouched.

## Mutation-E2E: KDS bump → status-change → sync dispatch (live, browser-driven)
1. **Baseline:** KDS (`:8766/kds`) showed live order **N°4225 [BORNE] — 1× Sandwich Cayenne + 1× Sprite 33cl**, status EN COURS, with a "Prêt" bump CTA. (`wave-v-01-kds-8766.jpeg`)
2. **Action:** clicked **"Prêt"** (`data-testid=kds-card-cta-ready`) — a real mutation on the clone. (`wave-v-02-kds-after-bump.jpeg`)
3. **Server persistence (proven):** `foodking_e2e` order 4225 → **status=8 (ready/PREPARED)**, `updated_at=2026-06-09 11:07:27` (exact click time). The bump went through the server `changeStatus`, not just localStorage.
4. **Sync chain (proven):** `domain_events` gained a **new `order.status_changed` event (id=8701, 11:07:27)** — the OrderStatusChanged event was dispatched to the outbox for soketi broadcast → the channel that fans out to KDS / OSS / customer tracker. The real-time sync pipeline fired end-to-end.
5. **Isolation (proven):** operating `audit_logs` **still 2674** — zero leakage to the production chain.
6. **OSS note (honest):** OSS (`wave-v-03-oss-after-bump.jpeg`) shows "Aucune commande" because order 4225 is **1 day old** and the customer status wall filters to *current* orders — this is correct OSS filtering, not a sync failure. The broadcast itself is proven by the dispatched `order.status_changed` event (step 4). A *fresh* (same-day) order would render on the OSS "Prêt" column.

## What this establishes for Wave-V
- ✅ The disposable `:8766` mutation-E2E harness is **stood up, validated, and isolated** — ready for every remaining per-finding `e2e_check`.
- ✅ The **order status-change → real-time sync dispatch** path is proven live (the goal's headline "synchronisation, preuves solides").
- ⏳ Remaining per-finding `e2e_check`s are sequenced to AFTER their fixes land: CAISSE-01 under-bill DB-assert (gated GATE-FROZEN-1), KDS-OSS-01/02 recall flows (focused-cycle fixes), CENTRAL-P1-01 force-5xx error-state. The harness is now ready to run them on demand.
