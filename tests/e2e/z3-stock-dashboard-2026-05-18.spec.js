// Z-3 STOCK FULLSYS — E2E real Playwright + axe-core capture for the
// /admin/stock/rupture dashboard.
//
// Date: 2026-05-18
// Owner sub-agent: Z-3 master (Stock fullsys HEAL).
// Goal: capture admin desktop viewport (1366x768) + axe-core scan +
// console + raw-label sweep + per-locale verification.

const path = require('path');
const fs = require('fs');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

const OUT_BASE = path.resolve(
  __dirname,
  '../../reports/test-e2e/goal-complement-2026-05-18/Z-3-STOCK/round-1'
);

async function injectAxe(page) {
  const axePath = path.resolve(__dirname, '../../node_modules/axe-core/axe.min.js');
  if (!fs.existsSync(axePath)) {
    throw new Error('axe-core missing in node_modules — install axe-core to run this spec');
  }
  await page.addScriptTag({ path: axePath });
}

async function runAxe(page) {
  return await page.evaluate(async () => {
    // eslint-disable-next-line no-undef
    return await axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    });
  });
}

test.describe('Z-3 Stock dashboard — admin desktop capture + axe + raw-label sweep', () => {
  test.use({ viewport: { width: 1366, height: 768 } });

  test('captures /admin/stock/rupture + axe-core + console + raw-label sweep', async ({ page }) => {
    fs.mkdirSync(OUT_BASE, { recursive: true });
    const consoleErrors = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push({ type: msg.type(), text: msg.text() });
    });
    page.on('pageerror', (e) => consoleErrors.push({ type: 'pageerror', text: String(e?.message || e) }));

    await loginAsAdmin(page);
    await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
    // SPA + API polling roundtrip
    await page.waitForTimeout(3500);

    const screenshotPath = path.join(OUT_BASE, '01-dashboard-1366x768.png');
    await page.screenshot({ path: screenshotPath, fullPage: true });

    // DOM snapshot
    const html = await page.content();
    fs.writeFileSync(path.join(OUT_BASE, '01-dashboard.html'), html, 'utf8');

    // Raw-label sweep on the visible body innerText
    const bodyText = await page.locator('body').innerText();
    const rawLabelPattern = /(admin\.stock_rupture\.[a-z_.]+|label\.[a-z_.]+|stock\.reason\.[a-z_]+|kiosk\.[a-z_.]+|undefined|\bnull\b\s*$)/m;
    const rawLabelMatch = bodyText.match(rawLabelPattern);

    fs.writeFileSync(
      path.join(OUT_BASE, '01-bodytext.txt'),
      bodyText,
      'utf8'
    );

    // Inject + run axe
    await injectAxe(page);
    const axeResult = await runAxe(page);
    fs.writeFileSync(
      path.join(OUT_BASE, '01-axe.json'),
      JSON.stringify(axeResult, null, 2),
      'utf8'
    );

    const criticalViolations = (axeResult.violations || []).filter(
      (v) => v.impact === 'critical' || v.impact === 'serious'
    );

    // Persist console
    fs.writeFileSync(
      path.join(OUT_BASE, '01-console-errors.json'),
      JSON.stringify(consoleErrors, null, 2),
      'utf8'
    );

    // Persist findings summary
    const summary = {
      url: page.url(),
      timestamp: new Date().toISOString(),
      console_errors_count: consoleErrors.length,
      console_errors: consoleErrors,
      raw_label_leak_found: rawLabelMatch ? rawLabelMatch[0] : null,
      axe_total_violations: (axeResult.violations || []).length,
      axe_critical_serious: criticalViolations.length,
      axe_critical_ids: criticalViolations.map((v) => v.id),
      heading_first: await page.locator('h2').first().innerText().catch(() => null),
      dashboard_visible: await page.locator('[data-testid="stock-rupture-dashboard"]').isVisible().catch(() => false),
    };
    fs.writeFileSync(
      path.join(OUT_BASE, 'SUMMARY.json'),
      JSON.stringify(summary, null, 2),
      'utf8'
    );

    // Hard gates (will fail the test → trigger correction loop step 9)
    expect(rawLabelMatch, `Raw label leak: ${rawLabelMatch?.[0]}`).toBeNull();
    expect(criticalViolations, `Axe critical/serious: ${criticalViolations.map((v) => v.id).join(',')}`).toHaveLength(0);
    expect(consoleErrors.length, `Console errors: ${JSON.stringify(consoleErrors)}`).toBe(0);
  });
});
