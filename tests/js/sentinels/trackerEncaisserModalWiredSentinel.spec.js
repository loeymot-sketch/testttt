import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

/**
 * [GOAL-2026-05-29 DEAD-BUTTON-FIX] The "Suivi commandes" tracker
 * (/admin/pos-orders-tracker) showed an "Encaisser" CTA per cash-pending order,
 * but openEncaissement() ONLY dispatched a `foodking:pos:open-encaissement`
 * CustomEvent that NOTHING in the app listened for (grep-verified: zero
 * addEventListener) — and PosComponent (the would-be host) is not mounted on
 * that standalone page. Result: dead button (owner: "je clique Encaisser, rien").
 *
 * Fix: mount the shared PosCounterCollectModal in the tracker so it is
 * self-sufficient for encashment. This sentinel locks the wiring against
 * regression. (Behavioural proof = the live driven E2E in the convergence
 * report; this is the CI regression net.)
 */
const src = readFileSync(
  resolve(process.cwd(), 'resources/js/components/admin/pos/PosOrdersTrackerComponent.vue'),
  'utf-8',
);

describe('PosOrdersTracker — Encaisser CTA is wired to a real modal (not a dead CustomEvent)', () => {
  it('imports the shared PosCounterCollectModal', () => {
    expect(src).toMatch(/import\s+PosCounterCollectModal\s+from\s+['"]\.\/PosCounterCollectModal\.vue['"]/);
  });

  it('registers it as a component', () => {
    expect(src).toMatch(/components:\s*\{[^}]*PosCounterCollectModal[^}]*\}/);
  });

  it('renders <PosCounterCollectModal> bound to encaisseOrder with @confirmed + @cancel', () => {
    expect(src).toMatch(/<PosCounterCollectModal[\s\S]*?:order=["']encaisseOrder["']/);
    expect(src).toMatch(/@confirmed=["']onEncaisseConfirmed["']/);
  });

  it('openEncaissement opens the local modal (sets encaisseOrder), not only a CustomEvent', () => {
    expect(src).toMatch(/this\.encaisseOrder\s*=\s*\{\s*\.\.\.order/);
  });

  it('onEncaisseConfirmed refreshes the board (fetchOrders)', () => {
    expect(src).toMatch(/onEncaisseConfirmed\s*\(\)\s*\{[\s\S]*?this\.fetchOrders\(\)/);
  });
});
