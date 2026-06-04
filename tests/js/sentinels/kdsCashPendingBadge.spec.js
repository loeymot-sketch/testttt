import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-WAVE-S-2-KDS-CASH-PENDING-001 (REVERSED by GOAL-2026-05-30 D1)
 * @source P-OWNER Wave S-2 (2026-05-20) → REVERSED P-OWNER 2026-05-30
 *
 * OWNER REVERSAL (2026-05-30): the earlier Wave S-2 rule "the kitchen MUST NOT bump a
 * cash-at-counter order until the cashier collects" is REVERSED. The owner now wants the
 * kitchen to PREPARE an order BEFORE encashment (the cashier collects later in the unified
 * /admin/encaissement page). So for a `payment_pending_counter` order the card now shows a
 * NON-blocking "Non encaissé / paiement en attente" NOTE AND keeps the bump CTA enabled.
 *
 * Structural invariants this sentinel now locks (NEW behavior):
 *   1. KdsOrderCard exposes `isCashPending` bound to `payment_pending_counter === true`
 *      — it now drives only the informational NOTE, not a bump gate.
 *   2. The cash-pending NOTE renders `v-if="isCashPending"` (role="status", non-interactive).
 *   3. The bump CTA renders UNCONDITIONALLY (no `v-else` mutually-exclusive gating) — when an
 *      order is cash-pending, BOTH the note and the CTA show.
 *   4. `onCta` does NOT gate on isCashPending — it always emits 'ready' (the chef can bump unpaid).
 *   5. KdsV2Grid.onKey [A]–[H] does NOT skip cash-pending slots.
 *   6. i18n keys `kds_card_cash_pending` + `_aria` still exist (the note text).
 *
 * Anti-régression : if a future change re-introduces a payment gate on the bump path, this
 * sentinel fails — the owner explicitly wants prep-before-cash (revenue risk accepted; cash is
 * collected at the unified caisse page, fiscal-seq still allocated only at collection).
 */
describe('KDS cash-pending NOTE (non-blocking) — owner reversal GOAL-2026-05-30 D1', () => {
    const cardSource = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue'),
        'utf8',
    );
    const gridSource = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue'),
        'utf8',
    );

    it('KdsOrderCard exposes isCashPending computed bound to payment_pending_counter', () => {
        expect(cardSource).toMatch(/isCashPending\s*\(\)\s*\{[\s\S]*?payment_pending_counter\s*===\s*true/);
    });

    it('Template renders the cash-pending NOTE with v-if="isCashPending"', () => {
        expect(cardSource).toMatch(/v-if="isCashPending"[\s\S]{0,260}kds-card__cash-pending/);
    });

    it('Cash-pending element is a NOTE (role="status", not a button)', () => {
        expect(cardSource).toMatch(/kds-card__cash-pending[\s\S]{0,260}role="status"/);
    });

    it('Bump CTA renders UNCONDITIONALLY (no v-else mutually-exclusive gate)', () => {
        // The CTA button must NOT be guarded by v-else (which would hide it for cash-pending).
        expect(cardSource).not.toMatch(/v-else[\s\S]{0,200}kds-card__cta/);
        expect(cardSource).toMatch(/class="kds-card__cta"[\s\S]{0,200}data-testid="kds-card-cta-ready"/);
    });

    it('data-testids present (kds-card-cash-pending note + kds-card-cta-ready CTA)', () => {
        expect(cardSource).toMatch(/data-testid="kds-card-cash-pending"/);
        expect(cardSource).toMatch(/data-testid="kds-card-cta-ready"/);
    });

    it('onCta does NOT gate on isCashPending — it always emits ready (bump allowed when unpaid)', () => {
        const onCta = cardSource.match(/onCta\s*\([^)]*\)\s*\{([\s\S]*?)\},/);
        expect(onCta, 'onCta method found').toBeTruthy();
        expect(onCta[1]).toMatch(/\$emit\(\s*['"]ready['"]/);
        expect(onCta[1]).not.toMatch(/this\.isCashPending[\s\S]*?return/);
    });

    it('Card root @keydown.enter is wired to onCardKeydownEnter', () => {
        expect(cardSource).toMatch(/@keydown\.enter="onCardKeydownEnter"/);
    });

    it('KdsV2Grid.onKey [A]–[H] does NOT skip cash-pending slots', () => {
        const onKey = gridSource.match(/onKey\s*\([^)]*\)\s*\{([\s\S]*?)\n        \},/);
        expect(onKey, 'onKey method found').toBeTruthy();
        expect(onKey[1]).not.toMatch(/payment_pending_counter\s*===\s*true[\s\S]*?return/);
    });

    it('i18n key kds_card_cash_pending exists in fr / en / ar admin languages', () => {
        const fr = readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf8');
        const en = readFileSync(resolve(process.cwd(), 'resources/js/languages/en.json'), 'utf8');
        const ar = readFileSync(resolve(process.cwd(), 'resources/js/languages/ar.json'), 'utf8');
        expect(fr).toMatch(/"kds_card_cash_pending":\s*"[^"]+"/);
        expect(en).toMatch(/"kds_card_cash_pending":\s*"[^"]+"/);
        expect(ar).toMatch(/"kds_card_cash_pending":\s*"[^"]+"/);
    });
});
