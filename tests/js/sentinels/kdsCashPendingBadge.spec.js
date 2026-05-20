import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-WAVE-S-2-KDS-CASH-PENDING-001
 * @source P-OWNER Wave S-2 (2026-05-20)
 *
 * Sentinel for the KDS 1-clic CTA + cash-pending exception. Owner decision:
 * - Paid orders → chef sees big "Prêt" CTA → 1 click = PREPARING→PREPARED
 *   (assuming Wave S-1 auto-promotes ACCEPT→PREPARING on payment; if S-1
 *   isn't yet shipped, the Wave Q-2 step ladder in KdsV2Grid.onCtaTap
 *   degrades gracefully to 2 clicks = ACCEPT→PREPARING→PREPARED, still
 *   functional and never broken).
 * - Cash-at-counter orders (payment_pending_counter=true) → CTA replaced
 *   by a passive amber badge "EN ATTENTE ENCAISSEMENT". Chef cannot bump
 *   until Wave S-5 cashier flow flips payment_status to PAID. The keyboard
 *   shortcut [A]–[H] in KdsV2Grid.onKey also skips cash-pending slots.
 *
 * The structural invariants this sentinel locks:
 *   1. KdsOrderCard.vue exposes an `isCashPending` computed bound to
 *      `order.payment_pending_counter === true`.
 *   2. The template renders the cash-pending badge `v-if="isCashPending"`
 *      and the Prêt CTA `v-else` (mutually exclusive, no double-render).
 *   3. The badge uses role="status" (NOT button — chef cannot interact).
 *   4. `onCta` and `onCardKeydownEnter` both guard on isCashPending (no
 *      emit when cash-pending — defense-in-depth in case the badge somehow
 *      doesn't render).
 *   5. KdsV2Grid.onKey [A]–[H] handler skips slots where
 *      `payment_pending_counter === true`.
 *   6. i18n keys `kds_card_cash_pending` and `kds_card_cash_pending_aria`
 *      exist in fr.json / en.json / ar.json (admin surfaces).
 *
 * Anti-régression : if any of these invariants drift, the kitchen could
 * either prepare food before cash is collected (revenue leak) OR could
 * stall paid orders behind a phantom cash gate (kitchen blocked).
 */
describe('KDS cash-pending badge — Wave S-2 (FK-WAVE-S-2-KDS-CASH-PENDING-001)', () => {
    const cardSource = readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue',
        ),
        'utf8',
    );
    const gridSource = readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue',
        ),
        'utf8',
    );

    it('KdsOrderCard exposes isCashPending computed bound to payment_pending_counter', () => {
        expect(cardSource).toMatch(/isCashPending\s*\(\)\s*\{[\s\S]*?payment_pending_counter\s*===\s*true/);
    });

    it('Template renders cash-pending badge with v-if="isCashPending"', () => {
        expect(cardSource).toMatch(/v-if="isCashPending"[\s\S]{0,200}kds-card__cash-pending/);
    });

    it('Template renders Prêt CTA with v-else (mutually exclusive)', () => {
        expect(cardSource).toMatch(/v-else[\s\S]{0,200}kds-card__cta/);
    });

    it('Cash-pending badge uses role="status" (not a button)', () => {
        expect(cardSource).toMatch(/kds-card__cash-pending[\s\S]{0,200}role="status"/);
    });

    it('Cash-pending badge has data-testid="kds-card-cash-pending" for E2E hooks', () => {
        expect(cardSource).toMatch(/data-testid="kds-card-cash-pending"/);
    });

    it('CTA button has data-testid="kds-card-cta-ready" for E2E hooks', () => {
        expect(cardSource).toMatch(/data-testid="kds-card-cta-ready"/);
    });

    it('onCta guards on isCashPending (defense-in-depth — never emits when pending)', () => {
        expect(cardSource).toMatch(/onCta\s*\([^)]*\)\s*\{[\s\S]*?this\.isCashPending[\s\S]*?return/);
    });

    it('onCardKeydownEnter guards on isCashPending', () => {
        expect(cardSource).toMatch(/onCardKeydownEnter\s*\([^)]*\)\s*\{[\s\S]*?this\.isCashPending[\s\S]*?return/);
    });

    it('Card root @keydown.enter is wired to onCardKeydownEnter (not onCta directly)', () => {
        expect(cardSource).toMatch(/@keydown\.enter="onCardKeydownEnter"/);
        expect(cardSource).not.toMatch(/@keydown\.enter="onCta"/);
    });

    it('KdsV2Grid.onKey [A]–[H] skips cash-pending slots', () => {
        expect(gridSource).toMatch(/onKey\s*\([^)]*\)\s*\{[\s\S]*?payment_pending_counter\s*===\s*true[\s\S]*?return/);
    });

    it('i18n key kds_card_cash_pending exists in fr / en / ar admin languages', () => {
        const fr = readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf8');
        const en = readFileSync(resolve(process.cwd(), 'resources/js/languages/en.json'), 'utf8');
        const ar = readFileSync(resolve(process.cwd(), 'resources/js/languages/ar.json'), 'utf8');
        expect(fr).toMatch(/"kds_card_cash_pending":\s*"[^"]+"/);
        expect(en).toMatch(/"kds_card_cash_pending":\s*"[^"]+"/);
        expect(ar).toMatch(/"kds_card_cash_pending":\s*"[^"]+"/);
        expect(fr).toMatch(/"kds_card_cash_pending_aria":\s*"[^"]+"/);
        expect(en).toMatch(/"kds_card_cash_pending_aria":\s*"[^"]+"/);
        expect(ar).toMatch(/"kds_card_cash_pending_aria":\s*"[^"]+"/);
    });
});
