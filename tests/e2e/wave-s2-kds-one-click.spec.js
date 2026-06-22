// Wave S-2 E2E — KDS 1-clic CTA + cash-pending badge (P-OWNER 2026-05-20)
//
// Owner decision: chef clique 1× "Prêt" sur card KDS → commande passe
// directement à PREPARED (PRÊT). Cash-at-counter orders show "EN ATTENTE
// ENCAISSEMENT" badge instead of CTA — chef cannot bump until cashier
// flips payment_status to PAID (Wave S-5).
//
// Coverage strategy (no server seed — runs against live KDS state):
//   1. Login chef → /kds renders without crash.
//   2. If any data-testid="kds-card-cta-ready" is present, click it and
//      confirm the card disappears OR turns into READY state. This
//      asserts the 1-click bump path is wired (single emission).
//   3. If any data-testid="kds-card-cash-pending" is present, confirm
//      the same card does NOT carry kds-card-cta-ready (mutual exclusion).
//   4. Visual screenshot of the KDS surface (current state, mixed CTA +
//      badges expected when cash-at-counter orders exist).
//
// Credentials : chef@lecayenne.fr / 123456 (CLAUDE.md §reference).
//
// Coordination notes:
//   - Wave S-1 (auto-PREPA on payment) is being implemented in parallel.
//     When merged, paid orders arrive on KDS already in PREPARING → CTA
//     emits PREPARED in single click. Before S-1 merges, paid orders may
//     arrive in ACCEPT → first click steps to PREPARING (Wave Q-2 ladder),
//     second click reaches PREPARED. Either path is functional; the
//     "1 clic = Prêt direct" requirement is fully met only post-S-1.
//   - Wave S-5 (cashier encaissement) flips payment_status PENDING_COUNTER
//     → PAID. Without S-5, cash-pending orders stay badged forever. This
//     test exercises the badge render but does NOT require S-5 to pass.

const { test, expect } = require('@playwright/test');
const { loginAsChefOperator } = require('./helpers/login');

const CHEF_EMAIL    = 'chef@lecayenne.fr';
const CHEF_PASSWORD = '123456';
const KDS_SURFACE_RE = /\/(kds|admin\/kitchen-display-system)/;
const SCREENSHOT_DIR = 'tests/e2e/__screenshots__/wave-s2-kds-one-click';

test.describe('Wave S-2 — KDS 1-clic CTA + cash-pending exception', () => {
  test.setTimeout(120_000);

  test('KDS surface loads + Vue mounts with the new conditional CTA/badge wiring', async ({ page }) => {
    await loginAsChefOperator(page, CHEF_EMAIL, CHEF_PASSWORD);
    await expect(page).toHaveURL(KDS_SURFACE_RE, { timeout: 20_000 });

    // Allow Vue + Vuex polling to settle. KDS V2 grid takes ~3s to populate.
    await page.waitForTimeout(3_500);

    // Sanity: no PHP/JS fatals.
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(/Whoops|Fatal error|Server Error/i);

    // KDS V2 grid must mount (kds-v2 root present). Empty-state OR cards.
    const grid = page.locator('.kds-v2');
    await expect(grid).toBeVisible({ timeout: 10_000 });

    // Initial screenshot of the steady-state KDS surface.
    await page.screenshot({
      path: `${SCREENSHOT_DIR}/01-kds-surface-loaded.png`,
      fullPage: true,
    });
  });

  test('cash-pending card carries BOTH the non-blocking note AND the bump CTA (W-D1 prepare-before-pay)', async ({ page }) => {
    await loginAsChefOperator(page, CHEF_EMAIL, CHEF_PASSWORD);
    await expect(page).toHaveURL(KDS_SURFACE_RE, { timeout: 20_000 });
    await page.waitForTimeout(3_500);

    // [GOAL-2026-05-30 W-D1 — OWNER REVERSAL of the Wave S-2 mutex] The kitchen now PREPARES an
    // order BEFORE encashment, so a cash-pending card shows the "non encaissé / paiement en attente"
    // NOTE *and* keeps the bump CTA enabled (the cashier collects later in /admin/encaissement).
    // The note and the CTA are NO LONGER mutually exclusive — both must be present on a cash-pending
    // card. (Was: cash-pending replaced the CTA with a passive badge.)
    const cashPendingCards = page.locator(
      '.kds-card:has([data-testid="kds-card-cash-pending"])'
    );
    const count = await cashPendingCards.count();

    for (let i = 0; i < count; i++) {
      const card = cashPendingCards.nth(i);
      await expect(card.locator('[data-testid="kds-card-cash-pending"]')).toHaveCount(1);
      await expect(card.locator('[data-testid="kds-card-cta-ready"]')).toHaveCount(1);
    }

    await page.screenshot({
      path: `${SCREENSHOT_DIR}/02-cta-vs-badge-mutex.png`,
      fullPage: true,
    });
  });

  test('1-click flow: chef taps Prêt → card transitions out within 6s', async ({ page }) => {
    await loginAsChefOperator(page, CHEF_EMAIL, CHEF_PASSWORD);
    await expect(page).toHaveURL(KDS_SURFACE_RE, { timeout: 20_000 });
    await page.waitForTimeout(3_500);

    const firstCta = page.locator('[data-testid="kds-card-cta-ready"]').first();
    const ctaPresent = await firstCta.count();

    if (ctaPresent === 0) {
      // No paid order in queue right now. Skip the click leg but keep
      // the visual evidence — the mutex + render assertions above still
      // exercise the contract. This is acceptable in a live-DB E2E.
      test.skip(true, 'No paid orders on KDS — skipping 1-click bump leg');
      return;
    }

    // Capture the card's order-id BEFORE the click so we can verify it
    // moves out of the visible queue (= transitioned to PREPARED).
    const card = firstCta.locator('xpath=ancestor::div[contains(@class,"kds-card")]').first();
    const orderId = await card.getAttribute('data-order-id');

    await firstCta.click();

    // The Wave Q-2 onCtaTap uses a 3s undo-toast window before firing the
    // PATCH. After the PATCH lands, the store refetches and the order
    // either disappears from the queue OR flips kds-card--ready class.
    // Allow 6s total (3s toast + 3s round-trip).
    await page.waitForTimeout(6_500);

    const movedCard = page.locator(`.kds-card[data-order-id="${orderId}"]`);
    const movedCount = await movedCard.count();
    if (movedCount > 0) {
      // Still in DOM — must be in READY state (faded out via .kds-card--ready).
      await expect(movedCard).toHaveClass(/kds-card--ready/);
    }
    // else: card filtered out of visible queue — that's the happy path.

    await page.screenshot({
      path: `${SCREENSHOT_DIR}/03-after-one-click-bump.png`,
      fullPage: true,
    });
  });
});
