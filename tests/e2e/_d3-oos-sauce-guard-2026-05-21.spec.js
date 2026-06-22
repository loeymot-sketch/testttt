/**
 * D3 — OOS sauce click guard regression spec (Wave Y Round 1 F3 closer).
 *
 * Bug: out-of-stock sauce (e.g., "Algérienne") displays the ÉPUISÉ badge but
 * still becomes selectable on click — orange selection-order badge appears,
 * the .selected class is applied, and the sauce enters the composition recap.
 *
 * Fix: KioskStepSauceComponent.vue toggleSauce — unconditional early return
 * when isSauceOos(sauce) is true (previous form was narrowed to first-click
 * only via `!this.localSelections[...]` which still let stray reactivity
 * paths leak the selection through).
 *
 * Setup: the Tacos sauce variation "Algérienne" (id=311) is already in
 * stock_rupture state in this dev DB (StockLevel.on_hand=0 → resolver flags
 * is_available=false) so no extra fixture is needed. The spec asserts the
 * badge is visible (proof the OOS state is rendered), then exercises the
 * click and confirms no selection state is produced.
 *
 * Mission: QUICK-MISSION FIX D3 — Le Cayenne V2 (2026-05-21).
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'tests/captures/d3-oos-sauce-guard-2026-05-21';
if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

test.use({ viewport: { width: 1366, height: 1200 } });
test.setTimeout(180_000);

async function shoot(page, idx, label) {
  await page.screenshot({
    path: path.join(OUT, `${String(idx).padStart(2, '0')}-${label}.png`),
    fullPage: true,
  }).catch(() => {});
}

test('D3 — OOS sauce variation must not be selectable in kiosk Tacos wizard', async ({ page }) => {
  page.on('console', (msg) => {
    if (msg.type() === 'error') console.log('[browser-error]', msg.text());
  });
  page.on('pageerror', (e) => console.log('[pageerror]', e.message));

  // Reach kiosk idle and enter
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2000);
  await shoot(page, 1, 'catalog');

  // Open Tacos wizard
  await page.getByText('Tacos', { exact: true }).first().click({ timeout: 6000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await page.getByText(/Personnaliser/i).first().click({ timeout: 6000 }).catch(() => {});
  await page.waitForTimeout(3000);
  await shoot(page, 2, 'wizard-open');

  // Pick a viande to allow advancing
  const viandeCard = page.locator('.kiosk-viande-card, .kiosk-option-card').first();
  await viandeCard.click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(800);

  // Advance — click SUIVANT until the sauce grid appears (some templates have
  // intermediate steps between viande and sauce).
  for (let i = 0; i < 4; i++) {
    if (await page.locator('.kiosk-sauce-grid').count() > 0) break;
    const next = page.getByRole('button', { name: /^suivant$/i }).first();
    if (await next.isVisible().catch(() => false)) {
      await next.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(1500);
    } else {
      break;
    }
  }
  await shoot(page, 3, 'sauce-step');

  // Find Algérienne card
  const sauceCards = page.locator('.kiosk-sauce-grid > .kiosk-option-card');
  const total = await sauceCards.count();
  console.log('[D3] sauce cards count =', total);
  expect(total, 'sauce grid must be populated').toBeGreaterThan(0);

  const algCard = sauceCards.filter({ hasText: /algérienne/i }).first();
  await expect(algCard, 'Algérienne sauce card must exist').toBeVisible({ timeout: 5000 });

  // Assert OOS badge is rendered (proof isSauceOos returned true)
  const oosBadge = algCard.locator('[data-testid="kiosk-extra-oos-badge"]');
  await expect(oosBadge, 'ÉPUISÉ badge must render on Algérienne (proof OOS state is live)').toBeVisible();
  await shoot(page, 4, 'algerienne-oos-badge-visible');

  // Read DOM state BEFORE click
  const beforeSelected = await algCard.evaluate((el) => el.classList.contains('selected'));
  const beforeOrderCount = await algCard.locator('.kiosk-sauce-order').count();
  console.log('[D3] before click — selected=', beforeSelected, 'order-badge=', beforeOrderCount);
  expect(beforeSelected, 'Algérienne must not start selected').toBe(false);

  // Attempt click (forced — Playwright would skip due to cursor:not-allowed otherwise)
  await algCard.click({ force: true, timeout: 4000 }).catch(() => {});
  await page.waitForTimeout(700);
  await shoot(page, 5, 'after-first-click');

  // Second click — verify second attempt also blocked (no toggle leak)
  await algCard.click({ force: true, timeout: 4000 }).catch(() => {});
  await page.waitForTimeout(700);
  await shoot(page, 6, 'after-second-click');

  // ASSERTIONS — guard must hold
  const afterSelected = await algCard.evaluate((el) => el.classList.contains('selected'));
  expect(afterSelected, 'OOS Algérienne must NOT carry .selected class after clicks').toBe(false);

  const orderBadgeCount = await algCard.locator('.kiosk-sauce-order').count();
  expect(orderBadgeCount, 'OOS Algérienne must NOT receive a selection-order badge').toBe(0);

  // Composition recap (top of wizard) must not list Algérienne.
  // The strip class varies — search the whole document for the sauce name
  // inside elements that look like composition / recap wrappers.
  const recap = await page.evaluate(() => {
    const candidates = Array.from(document.querySelectorAll(
      '[class*="composition"], [class*="recap"], [class*="resume"]'
    ));
    return candidates.map((el) => el.innerText).join('\n');
  });
  expect(
    /algérienne/i.test(recap),
    `Composition recap must not contain Algérienne. Recap dump:\n${recap}`
  ).toBe(false);

  // Also verify no kiosk.sauceOrder entry mentions variation 311
  // by querying the live Vue selection state via window if exposed; if not, we
  // rely on DOM checks above which are sufficient.

  console.log('[D3] PASS — OOS sauce guard blocked click; no selection leaked.');
});
