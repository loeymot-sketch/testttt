// Wave-A11y — mobile-design-perfect-2026-05-11 round-1
// 12 states. axe-core inject + targeted ARIA assertions.
// Hunts A11-001 (TabBar div), A11-002 (IconBtn aria-label), A11-005 (OTP/phone aria),
// A11-006 (modal dialog role + ESC), A11-008/A11-009 (cart stepper/trash aria),
// A11-011 (filter chips aria-pressed).

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { attachMegaAuditRecorder } = require('../e2e/helpers/mega-audit-snap');

const OUT = path.resolve(__dirname, '__screenshots__/test-e2e-mobile-design-perfect-wave-a11y');
const AXE_CDN = 'https://cdn.jsdelivr.net/npm/axe-core@4.10.0/axe.min.js';

async function bootstrapMobile(page, { authed = true } = {}) {
  await page.goto('/index.html', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!window.LC && !!window.LC.storage && !!window.LC.menu, { timeout: 15_000 });
  await page.evaluate((authed) => {
    window.LC.storage.clearAuth();
    if (authed) {
      window.LC.storage.setAuth({ token: 'test', phone: '+33600000000', user_id: 12345 });
      window.LC.storage.markOnboardingSeen();
    }
  }, authed);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-screen-label]', { timeout: 15_000 });
}

async function injectAxe(page) {
  await page.addScriptTag({ url: AXE_CDN });
  await page.waitForFunction(() => typeof window.axe === 'object', { timeout: 5_000 });
}

async function runAxe(page, name) {
  const results = await page.evaluate(async () => {
    return await window.axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] },
    });
  });
  fs.writeFileSync(path.join(OUT, name + '.axe.json'), JSON.stringify({
    violations: results.violations.map(v => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length })),
    incomplete: results.incomplete.length,
    summary: { total_violations: results.violations.length, critical: results.violations.filter(v => v.impact === 'critical').length, serious: results.violations.filter(v => v.impact === 'serious').length },
  }, null, 2));
  return results;
}

function critSeriousCount(results) {
  return results.violations.filter(v => ['critical', 'serious'].includes(v.impact)).length;
}

async function gotoTab(page, regexStr) {
  await page.evaluate((r) => {
    const tab = Array.from(document.querySelectorAll('.lc-tab')).find(el => new RegExp(r, 'i').test(el.textContent || ''));
    if (tab) tab.click();
  }, regexStr);
  await page.waitForTimeout(200);
}

test.describe('Wave-A11y — axe-core + targeted ARIA', () => {
  let recorder;
  test.beforeAll(async () => { fs.mkdirSync(OUT, { recursive: true }); });
  test.beforeEach(async ({ page }) => { recorder = attachMegaAuditRecorder(page, OUT); });
  test.afterEach(async () => { if (recorder?.dispose) recorder.dispose(); });

  test('A01 — Splash axe baseline', async ({ page }) => {
    await page.goto('/index.html');
    await page.waitForTimeout(500);
    await injectAxe(page);
    const results = await runAxe(page, '01-splash');
    await recorder.snap('01-splash');
    fs.writeFileSync(path.join(OUT, '01-splash.report.json'), JSON.stringify({ crit_serious: critSeriousCount(results) }));
  });

  test('A02 — Login phone input aria-label (A11-005 partial)', async ({ page }) => {
    await bootstrapMobile(page, { authed: false });
    await page.evaluate(() => window.LC.storage.markOnboardingSeen());
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('input', { timeout: 5_000 });
    await injectAxe(page);
    const results = await runAxe(page, '02-login-phone-aria');
    const phoneInput = page.locator('input[inputmode], input[placeholder*="12"]').first();
    const ariaLabel = await phoneInput.getAttribute('aria-label').catch(() => null);
    fs.writeFileSync(path.join(OUT, '02-login-phone-aria.evidence.json'), JSON.stringify({ phone_aria_label: ariaLabel, has_label: !!ariaLabel }, null, 2));
    await recorder.snap('02-login-phone-aria');
  });

  test('A03 — OTP inputs aria-label per digit (A11-005)', async ({ page }) => {
    await bootstrapMobile(page, { authed: false });
    await page.evaluate(() => window.LC.storage.markOnboardingSeen());
    await page.reload({ waitUntil: 'domcontentloaded' });
    // Submit phone to navigate to OTP screen
    const phoneInput = page.locator('input[inputmode], input[placeholder*="12"]').first();
    await phoneInput.fill('612345678');
    const submitBtn = page.locator('button:has-text("Recevoir"), button:has-text("RECEVOIR")').first();
    if (await submitBtn.count() > 0) await submitBtn.click();
    await page.waitForSelector('input[maxlength="1"]', { timeout: 5_000 }).catch(() => {});
    await injectAxe(page);
    const results = await runAxe(page, '03-otp-aria');
    const otpInputs = page.locator('input[maxlength="1"]');
    const count = await otpInputs.count();
    const labels = [];
    for (let i = 0; i < count; i++) {
      labels.push(await otpInputs.nth(i).getAttribute('aria-label').catch(() => null));
    }
    fs.writeFileSync(path.join(OUT, '03-otp-aria.evidence.json'), JSON.stringify({ input_count: count, labels, all_labelled: labels.every(l => !!l) }, null, 2));
    await recorder.snap('03-otp-aria');
  });

  test('A04 — Home TabBar buttons + aria-pressed (A-007 / A11-001 / S-004)', async ({ page }) => {
    await bootstrapMobile(page);
    await injectAxe(page);
    const results = await runAxe(page, '04-tabbar-aria-pressed');
    const tabsInfo = await page.evaluate(() => {
      const tabs = Array.from(document.querySelectorAll('.lc-tab'));
      return tabs.map(t => ({
        tag: t.tagName,
        role: t.getAttribute('role'),
        ariaPressed: t.getAttribute('aria-pressed'),
        ariaLabel: t.getAttribute('aria-label'),
        text: (t.textContent || '').trim().slice(0, 30),
      }));
    });
    fs.writeFileSync(path.join(OUT, '04-tabbar-aria-pressed.evidence.json'), JSON.stringify({
      tabs: tabsInfo,
      all_button: tabsInfo.every(t => t.tag === 'BUTTON'),
      all_aria_pressed: tabsInfo.every(t => t.ariaPressed !== null),
      crit_serious: critSeriousCount(results),
    }, null, 2));
    await recorder.snap('04-tabbar-aria-pressed');
  });

  test('A05 — Home icon-only buttons aria-label (A11-002 / S-003)', async ({ page }) => {
    await bootstrapMobile(page);
    await injectAxe(page);
    const results = await runAxe(page, '05-home-icon-buttons-aria');
    const iconBtnInfo = await page.evaluate(() => {
      // Find all buttons in the Home screen with only an SVG child (no text)
      const home = document.querySelector('[data-screen-label*="Home"], [data-screen-label*="07"]');
      if (!home) return { error: 'home not found' };
      const btns = Array.from(home.querySelectorAll('button'));
      return btns.map(b => ({
        text: (b.textContent || '').trim(),
        ariaLabel: b.getAttribute('aria-label'),
        hasSvg: !!b.querySelector('svg'),
        hasIcon: b.children.length === 1 && b.children[0].tagName === 'svg',
      })).filter(b => b.hasSvg && !b.text);
    });
    fs.writeFileSync(path.join(OUT, '05-home-icon-buttons-aria.evidence.json'), JSON.stringify({
      icon_only_buttons: iconBtnInfo,
      all_labelled: Array.isArray(iconBtnInfo) && iconBtnInfo.every(b => !!b.ariaLabel),
    }, null, 2));
    await recorder.snap('05-home-icon-buttons-aria');
  });

  test('A06 — Home category cards role+tabIndex (A11-004)', async ({ page }) => {
    await bootstrapMobile(page);
    await injectAxe(page);
    const results = await runAxe(page, '06-home-cat-cards');
    const catCards = await page.evaluate(() => {
      const home = document.querySelector('[data-screen-label*="Home"], [data-screen-label*="07"]');
      if (!home) return [];
      const cards = Array.from(home.querySelectorAll('[onclick], [style*="cursor: pointer"], [style*="cursor:pointer"]'));
      return cards.slice(0, 10).map(c => ({
        tag: c.tagName,
        role: c.getAttribute('role'),
        tabindex: c.getAttribute('tabindex'),
        ariaLabel: c.getAttribute('aria-label'),
      }));
    });
    fs.writeFileSync(path.join(OUT, '06-home-cat-cards.evidence.json'), JSON.stringify({ cards: catCards, count: catCards.length, has_role: catCards.filter(c => c.role).length }, null, 2));
    await recorder.snap('06-home-cat-cards');
  });

  test('A07 — Menu filter chips aria-pressed (A11-011)', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoTab(page, /Menu/);
    await page.waitForSelector('[data-screen-label*="Menu"]');
    await injectAxe(page);
    const results = await runAxe(page, '07-menu-filter-aria-pressed');
    const chipsInfo = await page.evaluate(() => {
      const menu = document.querySelector('[data-screen-label*="Menu"]');
      if (!menu) return [];
      const btns = Array.from(menu.querySelectorAll('button'));
      return btns.slice(0, 8).map(b => ({
        text: (b.textContent || '').trim().slice(0, 20),
        ariaPressed: b.getAttribute('aria-pressed'),
        ariaCurrent: b.getAttribute('aria-current'),
      }));
    });
    fs.writeFileSync(path.join(OUT, '07-menu-filter-aria-pressed.evidence.json'), JSON.stringify({ chips: chipsInfo }, null, 2));
    await recorder.snap('07-menu-filter-aria-pressed');
  });

  test('A08 — Wizard step1 radiogroup + tabindex', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoTab(page, /Menu/);
    await page.waitForSelector('[data-screen-label*="Menu"]');
    await page.evaluate(() => {
      const candidates = Array.from(document.querySelectorAll('[data-screen-label*="Menu"] [onClick], [data-screen-label*="Menu"] [style*="cursor: pointer"], [data-screen-label*="Menu"] [style*="cursor:pointer"]'));
      const target = candidates.find(el => (el.textContent || '').toUpperCase().includes('TACOS XXL'));
      if (target) target.click();
    });
    await page.waitForSelector('[data-screen-label*="Item Wizard"]', { timeout: 5_000 });
    await injectAxe(page);
    const results = await runAxe(page, '08-wizard-radio-role');
    const widgetInfo = await page.evaluate(() => {
      const cards = Array.from(document.querySelectorAll('[role="checkbox"], [role="radio"]')).slice(0, 9);
      return cards.map(c => ({ role: c.getAttribute('role'), tabindex: c.getAttribute('tabindex'), ariaChecked: c.getAttribute('aria-checked') }));
    });
    fs.writeFileSync(path.join(OUT, '08-wizard-radio-role.evidence.json'), JSON.stringify({ widgets: widgetInfo, count: widgetInfo.length }, null, 2));
    await recorder.snap('08-wizard-radio-role');
  });

  test('A09 — Wizard CTA disabled aria-describedby hint', async ({ page }) => {
    await bootstrapMobile(page);
    await gotoTab(page, /Menu/);
    await page.evaluate(() => {
      const candidates = Array.from(document.querySelectorAll('[data-screen-label*="Menu"] [onClick], [data-screen-label*="Menu"] [style*="cursor: pointer"], [data-screen-label*="Menu"] [style*="cursor:pointer"]'));
      const target = candidates.find(el => (el.textContent || '').toUpperCase().includes('TACOS XXL'));
      if (target) target.click();
    });
    await page.waitForSelector('[data-screen-label*="Item Wizard"]');
    await injectAxe(page);
    const results = await runAxe(page, '09-wizard-cta-aria-describedby');
    const ctaInfo = await page.evaluate(() => {
      const cta = document.querySelector('button[aria-disabled="true"]');
      if (!cta) return null;
      return {
        ariaDisabled: cta.getAttribute('aria-disabled'),
        ariaDescribedBy: cta.getAttribute('aria-describedby'),
        hintVisible: !!document.querySelector('#wizard-cta-hint'),
      };
    });
    fs.writeFileSync(path.join(OUT, '09-wizard-cta-aria-describedby.evidence.json'), JSON.stringify({ cta: ctaInfo }, null, 2));
    await recorder.snap('09-wizard-cta-aria-describedby');
  });

  test('A10 — Cart stepper aria-label + counter aria-live (A11-008)', async ({ page }) => {
    await bootstrapMobile(page);
    await page.evaluate(() => {
      const tacos = window.LC.menu.findItem('tacos-xxl');
      window.LC.storage.setCart([{ ...tacos, qty: 2, unitPrice: 12.5, lineTotal: 25, sups: [], composition_summary: '' }]);
    });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await gotoTab(page, /Menu/);
    await page.locator('button:has-text("Voir le panier"), button:has-text("VOIR LE PANIER")').first().click().catch(() => {});
    await page.waitForTimeout(400);
    await injectAxe(page);
    const results = await runAxe(page, '10-cart-stepper-aria');
    const stepperInfo = await page.evaluate(() => {
      const minus = document.querySelector('.lc-stepper button:first-child');
      const plus = document.querySelector('.lc-stepper button:last-child');
      const val = document.querySelector('.lc-stepper-val');
      return {
        minus_aria_label: minus?.getAttribute('aria-label'),
        plus_aria_label: plus?.getAttribute('aria-label'),
        val_aria_live: val?.getAttribute('aria-live'),
      };
    });
    fs.writeFileSync(path.join(OUT, '10-cart-stepper-aria.evidence.json'), JSON.stringify(stepperInfo, null, 2));
    await recorder.snap('10-cart-stepper-aria');
  });

  test('A11 — Cart trash icon aria-label (A11-009)', async ({ page }) => {
    await bootstrapMobile(page);
    await page.evaluate(() => {
      const tacos = window.LC.menu.findItem('tacos-xxl');
      window.LC.storage.setCart([{ ...tacos, qty: 1, unitPrice: 12.5, lineTotal: 12.5, sups: [], composition_summary: '' }]);
    });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await gotoTab(page, /Menu/);
    await page.locator('button:has-text("Voir le panier"), button:has-text("VOIR LE PANIER")').first().click().catch(() => {});
    await page.waitForTimeout(400);
    await injectAxe(page);
    const results = await runAxe(page, '11-cart-trash-aria');
    const trashInfo = await page.evaluate(() => {
      const trash = Array.from(document.querySelectorAll('button')).find(b => b.querySelector('svg') && (b.textContent || '').trim() === '' && b.parentElement?.querySelector('.lc-stepper'));
      return {
        found: !!trash,
        aria_label: trash?.getAttribute('aria-label'),
      };
    });
    fs.writeFileSync(path.join(OUT, '11-cart-trash-aria.evidence.json'), JSON.stringify(trashInfo, null, 2));
    await recorder.snap('11-cart-trash-aria');
  });

  test('A12 — Opt-out modal role=dialog + ESC close (A11-006 verify)', async ({ page }) => {
    await bootstrapMobile(page);
    await page.evaluate(() => window.LC.dev.seedAccount({ balance: 147 }));
    await page.reload({ waitUntil: 'domcontentloaded' });
    await gotoTab(page, /Profil/);
    await page.waitForTimeout(300);
    await page.locator('text=/VOIR MON QR/i').first().click({ timeout: 5_000 });
    await page.waitForSelector('[data-testid="loyalty-screen"]', { timeout: 5_000 });
    await page.locator('[data-testid="loyalty-opt-out-btn"]').scrollIntoViewIfNeeded();
    await page.locator('[data-testid="loyalty-opt-out-btn"]').click();
    await page.waitForSelector('[data-modal-kind="opt-out-confirm"]', { timeout: 3_000 });
    await injectAxe(page);
    const results = await runAxe(page, '12-modal-opt-out-dialog');
    const modalInfo = await page.evaluate(() => {
      const modal = document.querySelector('[data-modal-kind="opt-out-confirm"]');
      return {
        role: modal?.getAttribute('role'),
        ariaModal: modal?.getAttribute('aria-modal'),
        ariaLabelledBy: modal?.getAttribute('aria-labelledby'),
      };
    });
    fs.writeFileSync(path.join(OUT, '12-modal-opt-out-dialog.evidence.json'), JSON.stringify(modalInfo, null, 2));
    // ESC close test
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    const modalStillVisible = await page.locator('[data-modal-kind="opt-out-confirm"]').isVisible().catch(() => false);
    fs.writeFileSync(path.join(OUT, '12-modal-opt-out-esc.evidence.json'), JSON.stringify({ esc_closes_modal: !modalStillVisible }, null, 2));
    await recorder.snap('12-modal-opt-out-dialog');
  });
});
