// Wave Q-5 — POS UI polish (loyalty button + parked overflow + category images)
//
// Owner mandate Wave Q-5 (2026-05-20):
//   Q5.1 — "Appliquer une réduction fidélité" greyed out. Owner wants to
//          understand WHY. Heal: keep the existing wave-E-1 LOCK gate (CTA
//          only after an order is in flight, fiscally correct), but add a
//          clear disabled-state tooltip via `pos.loyalty.redeem.disabled_hint`.
//   Q5.2 — "Commandes en attente" text truncated inside the 2-col cart
//          shortcuts grid. Heal: short label "En attente" in the button +
//          full label preserved as title/aria-label + CSS nowrap rule for
//          .pos-v5-btn--park-toggle.
//   Q5.3 — Category tab images invisible. Root cause = config/menu_images.php
//          `categories` map used legacy slugs (nos-tacos, nos-burgers) but
//          the live DB ships V1 slugs (sandwich-cayenne, burgers, tacos…).
//          Heal: add V1 slugs to the map → real PNGs for all 11 tabs.
//
// Frozen-zone STRICT: this spec reads UI state only, never writes to:
//   - PaymentComponent.vue
//   - PosV5TrancheRow.vue
//   - public/js/pos-wizard.js

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');
const fs = require('fs');
const path = require('path');

const SHOT_DIR = 'reports/test-e2e/wave-q5-2026-05-20/screenshots';

test.use({ viewport: { width: 1440, height: 900 } });

test.describe.configure({ mode: 'serial' });

test.describe('Wave Q-5 POS UI polish', () => {
  test.setTimeout(180_000);

  test.beforeAll(() => {
    fs.mkdirSync(SHOT_DIR, { recursive: true });
  });

  test('Q5.1 + Q5.2 + Q5.3 — loyalty tooltip + parked label + category images', async ({ page }) => {
    const findings = { errors: [], assertions: [] };

    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        findings.errors.push({ kind: 'console', text: msg.text() });
      }
    });
    page.on('pageerror', (err) => findings.errors.push({ kind: 'page', text: err.message }));

    const shot = async (name) => {
      await page.screenshot({ path: `${SHOT_DIR}/${name}.png`, fullPage: true }).catch(() => {});
    };

    // -------- Login --------
    await loginAsAdmin(page);
    await page.waitForTimeout(600);

    // -------- Land on /admin/pos --------
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await shot('01-pos-landing');

    // Dismiss cash drawer dialog if it auto-opened (Q-5 doesn't need it open).
    try {
      const openingInput = page.locator('[data-testid="cash-session-opening-input"]').first();
      if (await openingInput.isVisible({ timeout: 2000 }).catch(() => false)) {
        await openingInput.fill('50');
        const submitBtn = page.locator('[data-testid="cash-session-open-submit"]').first();
        if (await submitBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
          await submitBtn.click({ timeout: 4000 }).catch(() => {});
          await page.waitForTimeout(2000);
        }
      }
      await page.keyboard.press('Escape').catch(() => {});
      await page.waitForTimeout(500);
    } catch (_e) {}
    await shot('02-pos-after-drawer');

    // ==================================================================
    // Q5.1 — Loyalty button state + tooltip
    // ==================================================================
    const loyaltyBtn = page.locator('[data-testid="pos-loyalty-redeem-main-cta-open"]').first();
    const loyaltyVisible = await loyaltyBtn.isVisible({ timeout: 5000 }).catch(() => false);
    findings.assertions.push({ id: 'Q5.1-button-visible', ok: loyaltyVisible });
    expect(loyaltyVisible, 'Loyalty CTA must be visible on POS landing').toBe(true);

    // No order in flight on landing → button must be disabled, with the
    // dedicated disabled hint as title / aria-label.
    const loyaltyDisabled = await loyaltyBtn.getAttribute('disabled');
    const loyaltyAriaDisabled = await loyaltyBtn.getAttribute('aria-disabled');
    const loyaltyIsDisabled = loyaltyDisabled !== null || loyaltyAriaDisabled === 'true';
    findings.assertions.push({ id: 'Q5.1-disabled-on-landing', ok: loyaltyIsDisabled });
    expect(loyaltyIsDisabled, 'Loyalty CTA must be disabled when no order in flight').toBe(true);

    const loyaltyTitle = await loyaltyBtn.getAttribute('title');
    const loyaltyAriaLabel = await loyaltyBtn.getAttribute('aria-label');
    const hintRx = /commande.*fid|fid.*commande/i;
    const hintInTitle = !!loyaltyTitle && hintRx.test(loyaltyTitle);
    const hintInAria = !!loyaltyAriaLabel && hintRx.test(loyaltyAriaLabel);
    findings.assertions.push({
      id: 'Q5.1-disabled-hint-present',
      ok: hintInTitle || hintInAria,
      title: loyaltyTitle,
      aria: loyaltyAriaLabel,
    });
    expect(hintInTitle || hintInAria, 'Disabled hint must mention creating an order').toBe(true);

    await loyaltyBtn.hover({ timeout: 1500 }).catch(() => {});
    await page.waitForTimeout(300);
    await shot('03-loyalty-disabled-tooltip');

    // ==================================================================
    // Q5.2 — Parked orders button: short label + no overflow + full title
    // ==================================================================
    // Open the mobile cart drawer if hidden behind the breakpoint (1440 keeps it open).
    const parkedBtn = page
      .locator('.pos-v5-btn--park-toggle, button:has-text("En attente"):has(.pos-v5-btn__badge)')
      .first();
    const parkedVisible = await parkedBtn.isVisible({ timeout: 5000 }).catch(() => false);
    findings.assertions.push({ id: 'Q5.2-parked-button-visible', ok: parkedVisible });
    expect(parkedVisible, 'Parked-orders button must be visible in cart panel').toBe(true);

    const parkedLabelText = (await parkedBtn.locator('.pos-v5-btn__label').innerText().catch(() => '')).trim();
    findings.assertions.push({
      id: 'Q5.2-short-label',
      ok: /en attente/i.test(parkedLabelText),
      text: parkedLabelText,
    });
    expect(parkedLabelText, 'Short label must read "En attente"').toMatch(/en attente/i);

    const parkedTitle = await parkedBtn.getAttribute('title');
    const parkedAria = await parkedBtn.getAttribute('aria-label');
    const fullRx = /commandes en attente/i;
    const fullPresent = (parkedTitle && fullRx.test(parkedTitle)) || (parkedAria && fullRx.test(parkedAria));
    findings.assertions.push({ id: 'Q5.2-full-title-preserved', ok: !!fullPresent });
    expect(!!fullPresent, 'Full canonical label "Commandes en attente" must stay in title/aria-label').toBe(true);

    // Overflow assertion: scrollWidth must NOT exceed clientWidth (no truncation).
    const overflowReport = await parkedBtn.evaluate((el) => {
      const label = el.querySelector('.pos-v5-btn__label');
      const labelMetrics = label
        ? { sw: label.scrollWidth, cw: label.clientWidth, text: label.textContent }
        : null;
      return {
        button: { sw: el.scrollWidth, cw: el.clientWidth },
        label: labelMetrics,
      };
    });
    findings.assertions.push({ id: 'Q5.2-no-overflow', metrics: overflowReport });
    // Button itself must fit (allow 1px rounding leeway).
    expect(overflowReport.button.sw - overflowReport.button.cw,
      `Park button content overflows: ${JSON.stringify(overflowReport)}`).toBeLessThanOrEqual(2);

    await shot('04-parked-button');

    // ==================================================================
    // Q5.3 — Category tabs render real images (not the default fallback)
    // ==================================================================
    await page.waitForTimeout(1200);
    const catImages = page.locator('.pos-v5-category .pos-v5-category__visual img');
    const catImgCount = await catImages.count();
    findings.assertions.push({ id: 'Q5.3-cat-img-count', count: catImgCount });
    expect(catImgCount, 'At least 4 category tabs must render images').toBeGreaterThanOrEqual(4);

    // Sample first 6 category images: src must NOT point to the generic default.
    const sampleSrcs = [];
    const sampleLimit = Math.min(6, catImgCount);
    for (let i = 0; i < sampleLimit; i++) {
      const src = await catImages.nth(i).getAttribute('src');
      sampleSrcs.push(src);
    }
    findings.assertions.push({ id: 'Q5.3-cat-img-srcs', sample: sampleSrcs });

    const nonDefault = sampleSrcs.filter(
      (s) => s && !/item-default\.svg/i.test(s) && !/category\/thumb\.png/i.test(s),
    );
    findings.assertions.push({ id: 'Q5.3-cat-img-non-default-count', count: nonDefault.length });
    expect(
      nonDefault.length,
      `Category tabs still show default fallback SVG: ${sampleSrcs.join(', ')}`,
    ).toBeGreaterThanOrEqual(Math.max(3, sampleLimit - 1));

    // Verify images actually load (HTTP 200).
    const ok200 = [];
    for (const src of nonDefault) {
      try {
        const r = await page.request.get(src);
        ok200.push({ src, status: r.status() });
      } catch (e) {
        ok200.push({ src, error: String(e).slice(0, 120) });
      }
    }
    findings.assertions.push({ id: 'Q5.3-cat-img-http', results: ok200 });
    const allOk = ok200.every((r) => r.status === 200);
    expect(allOk, `Some category image URLs failed: ${JSON.stringify(ok200)}`).toBe(true);

    await shot('05-category-tabs-with-images');

    // ==================================================================
    // Final write of findings
    // ==================================================================
    findings.errors = findings.errors.slice(0, 50);
    fs.writeFileSync(
      `${SHOT_DIR}/findings.json`,
      JSON.stringify(findings, null, 2),
    );
  });
});
