/**
 * D5 — Sauce count copy regression (Le Cayenne V2).
 *
 * Locks down Wave Y Round 1 F4 finding: the kiosk sauce step `.kiosk-sauce-extra`
 * badge MUST NEVER show a phantom "14" (audit script misread "+2" as "14" in
 * compressed thumbnails). The actual rendered value is `selectedCount - 1`
 * (paid extras), interpolated via i18n keys `kiosk.wizard.step.sauce.extra_one`
 * and `kiosk.wizard.step.sauce.extra_many` (both already use `{n}` placeholder).
 *
 * This spec opens the Tacos wizard (sauce step has 13 canonical sauces),
 * selects 3 sauces, then asserts the extras copy:
 *   - does NOT contain the literal "14"
 *   - DOES contain "2" (3 selected − 1 free = 2 paid extras)
 *   - DOES NOT exceed the DOM sauce-card count (defensive bound)
 *
 * Capture lives next to the report for diff inspection.
 *
 * Owner trigger: quick-mission D5 (Wave Y heal, 2026-05-21).
 * Frozen-zone: KioskStepSauceComponent.vue is NOT in §7 — safe to read state.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'reports/test-e2e/d5-sauce-count-copy-2026-05-21';

if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

test.use({ viewport: { width: 1366, height: 1200 } });
test.setTimeout(120_000);

async function shoot(page, idx, label) {
  const base = path.join(OUT, `${String(idx).padStart(2, '0')}-${label}`);
  await page.screenshot({ path: `${base}-full.png`, fullPage: true }).catch(() => {});
  await page.screenshot({ path: `${base}-viewport.png`, fullPage: false }).catch(() => {});
}

async function enterKiosk(page) {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);
}

async function openTacosWizard(page) {
  // Navigate to TACOS category if visible
  const cat = page.getByText(/^Tacos$/i).first();
  if (await cat.isVisible().catch(() => false)) {
    await cat.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(1200);
  }
  // Click the Tacos item card
  const item = page.getByText(/^Tacos$/i).first();
  if (await item.isVisible().catch(() => false)) {
    await item.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  const personalize = page.getByText(/Personnaliser/i).first();
  if (await personalize.isVisible().catch(() => false)) {
    await personalize.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(2500);
  }
}

async function clickNext(page) {
  const next = page.getByRole('button', { name: /^suivant$/i }).first();
  if (await next.isVisible().catch(() => false)) {
    await next.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(1500);
    return true;
  }
  return false;
}

test('D5 sauce extras copy reflects selectedCount, never says "14"', async ({ page }) => {
  await enterKiosk(page);
  await shoot(page, 0, 'kiosk-catalog');

  await openTacosWizard(page);
  await shoot(page, 1, 'tacos-step-viande');

  // Step 1 → viande
  const viandeCards = page.locator('.kiosk-viande-card');
  const viandeN = await viandeCards.count();
  if (viandeN > 0) {
    await viandeCards.first().click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(700);
  }
  await clickNext(page);
  await shoot(page, 2, 'tacos-step-sauce');

  // Sauce grid sanity check: DOM count should reflect catalog (13 for Tacos canonical)
  const sauceGrid = page.locator('.kiosk-sauce-grid > *');
  const sauceCount = await sauceGrid.count();
  console.log(`[D5] SAUCE GRID DOM COUNT = ${sauceCount}`);
  expect(sauceCount).toBeGreaterThan(0);
  // Canonical: 13 sauces (12 + Sans Sauce) per owner spec 2026-05-21
  expect(sauceCount).toBeLessThanOrEqual(15); // defensive ceiling

  // Click 3 sauce cards → selectedCount = 3, paid extras = 2
  // Loop with confirmation (.selected class) to defeat click race conditions
  // observed during retry (Vue v-on:click can drop a tap during transition).
  for (let i = 0; i < Math.min(3, sauceCount); i++) {
    let attempts = 0;
    while (attempts < 3) {
      await sauceGrid.nth(i).click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(450);
      const isSelected = await sauceGrid.nth(i).evaluate(
        (el) => el.classList.contains('selected')
      ).catch(() => false);
      if (isSelected) break;
      attempts++;
    }
  }
  // Wait for badge text to reflect final selection count
  await page.waitForFunction(
    () => {
      const el = document.querySelector('.kiosk-sauce-extra');
      return el && /\+\d+/.test((el.textContent || '').trim());
    },
    { timeout: 5000 }
  ).catch(() => {});
  await shoot(page, 3, 'tacos-3-sauces-selected');

  // Read the `.kiosk-sauce-extra` badge text
  const extraBadge = page.locator('.kiosk-sauce-extra').first();
  await expect(extraBadge).toBeVisible({ timeout: 5000 });
  const extraText = (await extraBadge.textContent() || '').trim();
  console.log(`[D5] EXTRA BADGE TEXT = "${extraText}"`);

  // CORE ASSERTIONS
  // 1. Must not contain the false "14"
  expect(extraText).not.toMatch(/\b14\b/);
  // 2. Must reflect 2 paid extras (3 selected − 1 free)
  expect(extraText).toMatch(/\b2\b/);
  // 3. Must mention "sauce" (i18n key resolved, not raw)
  expect(extraText.toLowerCase()).toContain('sauce');
  // 4. Must not be a raw i18n key (Label.X / kiosk.wizard...)
  expect(extraText).not.toMatch(/kiosk\.wizard|Label\./);

  // Defensive: the displayed number must never exceed DOM card count
  const numMatch = extraText.match(/(\d+)/);
  if (numMatch) {
    const displayedN = parseInt(numMatch[1], 10);
    expect(displayedN).toBeLessThanOrEqual(sauceCount);
  }
});
