/**
 * GOAL FINAL CONVERGENCE SMOKE — 2026-05-25
 * Isolated browser per surface to avoid contention.
 * Read-only verification across 7 surfaces.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const SHOTS = 'tests/e2e/__screenshots__/goal-final-smoke-2026-05-25';
const REPORT_DIR = 'reports/test-e2e/goal-final-smoke-2026-05-25';
const BASE = 'http://127.0.0.1:8000';

if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });
if (!fs.existsSync(REPORT_DIR)) fs.mkdirSync(REPORT_DIR, { recursive: true });

// Reset pending log
fs.writeFileSync(path.join(SHOTS, '_pending_check.log'), '');

async function snap(page, name) {
  await page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true });
  fs.writeFileSync(path.join(SHOTS, name + '.dom.html'), await page.content());
  console.log('SHOT ' + name);
}

async function pendingCheck(page, name) {
  const html = await page.content();
  const hasPending = /PENDING_CREATE/.test(html);
  fs.appendFileSync(path.join(SHOTS, '_pending_check.log'),
    `${name}: PENDING_CREATE present = ${hasPending}\n`);
  return hasPending;
}

async function login(page) {
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForSelector('#formEmail', { timeout: 20000 });
  await page.locator('#formEmail').fill('admin@lecayenne.fr');
  await page.locator('#formPassword').fill('123456');
  await page.getByRole('button', { name: /connexion|login/i }).click();
  await page.waitForURL(u => !u.toString().includes('/login'), { timeout: 25000 }).catch(() => {});
  await page.waitForTimeout(3500);
}

(async () => {
  const findings = { surfaces: {}, errors: [], summary: {} };

  // === S1 BORNE ===
  try {
    console.log('--- S1 BORNE ---');
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await ctx.newPage();
    await page.goto(BASE + '/kiosk/idle', { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);
    await snap(page, 'F1-01-borne-idle');
    const bienvenueCount = await page.locator('text=/Bienvenue/i').count();
    const html = await page.content();
    const hasCatalog = /catalog|menu|burger|tacos|sandwich/i.test(html);
    findings.surfaces['S1'] = {
      borne_idle_visible: bienvenueCount > 0,
      catalog_signals_in_dom: hasCatalog,
    };
    // Try clicking a kiosk start CTA if any
    try {
      const btn = page.getByRole('button').first();
      if (await btn.count()) {
        await btn.click({ timeout: 2000 });
        await page.waitForTimeout(2000);
        await snap(page, 'F1-02-borne-after-click');
      }
    } catch (e) {}
    await browser.close();
  } catch (e) {
    findings.errors.push({ surface: 'S1', error: String(e) });
  }

  // === S2 POS + profile dropdown PENDING_CREATE check ===
  try {
    console.log('--- S2 POS ---');
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await ctx.newPage();
    await login(page);
    await snap(page, 'F2-01-dashboard');
    await page.goto(BASE + '/admin/pos', { waitUntil: 'networkidle' });
    await page.waitForTimeout(4000);
    await snap(page, 'F2-02-pos-catalog');
    const buttonCount = await page.locator('button').count();
    const itemCount = await page.locator('[class*="item"], [data-item-id]').count();
    findings.surfaces['S2'] = {
      pos_loaded: true,
      button_count: buttonCount,
      item_dom_elements: itemCount,
    };
    // Try clicking the first visible category button to populate items
    try {
      const cats = page.locator('button:visible');
      if (await cats.count() > 3) {
        await cats.nth(3).click({ timeout: 2000 });
        await page.waitForTimeout(2000);
        await snap(page, 'F2-03-pos-category-click');
      }
    } catch (e) {}
    // Open profile dropdown (header button with img+b)
    try {
      await page.evaluate(() => {
        const headers = document.querySelectorAll('header button, nav button');
        for (const h of headers) {
          if (h.querySelector('img') && (h.querySelector('b') || h.querySelector('span'))) {
            h.click();
            return;
          }
        }
        // fallback: click any button containing an img in header area
        const fallback = document.querySelector('header button img, nav button img');
        if (fallback && fallback.closest('button')) fallback.closest('button').click();
      });
      await page.waitForTimeout(1800);
    } catch (e) {}
    await snap(page, 'F2-04-profile-dropdown');
    const hasPending = await pendingCheck(page, 'F2-04');
    findings.surfaces['S2'].pending_create_leak = hasPending;
    await browser.close();
  } catch (e) {
    findings.errors.push({ surface: 'S2', error: String(e) });
  }

  // === S4 OSS ===
  try {
    console.log('--- S4 OSS ---');
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await ctx.newPage();
    await login(page);
    await page.goto(BASE + '/admin/order-status-screen', { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);
    await snap(page, 'F4-01-oss');
    const html = await page.content();
    // strip the admin email and search for other emails / phone-like patterns
    const stripped = html.replace(/admin@lecayenne\.fr/g, '');
    const phoneLeak = /\b0[1-9](?:[\s.\-]?\d{2}){4}\b/.test(stripped);
    const emailLeak = /[\w.+-]+@[\w-]+\.[\w.-]+/.test(stripped);
    findings.surfaces['S4'] = {
      oss_loaded: true,
      phone_leak_suspected: phoneLeak,
      email_leak_suspected: emailLeak,
    };
    await browser.close();
  } catch (e) {
    findings.errors.push({ surface: 'S4', error: String(e) });
  }

  // === S5 Cash Overview ===
  try {
    console.log('--- S5 Cash Overview ---');
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await ctx.newPage();
    await login(page);
    await page.goto(BASE + '/admin/cash-overview', { waitUntil: 'networkidle' });
    await page.waitForTimeout(3500);
    await snap(page, 'F5-01-cash-overview');
    const html = await page.content();
    const hasAutre = />Autre</.test(html) || /option[^>]*>\s*Autre\s*</i.test(html);
    findings.surfaces['S5'] = {
      cash_overview_loaded: true,
      has_autre_mode: hasAutre,
    };
    await browser.close();
  } catch (e) {
    findings.errors.push({ surface: 'S5', error: String(e) });
  }

  // === S6 Stock Rupture ===
  try {
    console.log('--- S6 Stock Rupture ---');
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await ctx.newPage();
    await login(page);
    await page.goto(BASE + '/admin/stock-rupture-dashboard', { waitUntil: 'networkidle' });
    await page.waitForTimeout(3500);
    await snap(page, 'F6-01-stock-rupture');
    findings.surfaces['S6'] = { stock_loaded: true };
    await browser.close();
  } catch (e) {
    findings.errors.push({ surface: 'S6', error: String(e) });
  }

  // === S7 Admin Dashboard ===
  try {
    console.log('--- S7 Admin Dashboard ---');
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await ctx.newPage();
    await login(page);
    await snap(page, 'F7-01-dashboard');
    await page.goto(BASE + '/admin/items', { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);
    await snap(page, 'F7-02-items');
    findings.surfaces['S7'] = { dashboard_loaded: true };
    await browser.close();
  } catch (e) {
    findings.errors.push({ surface: 'S7', error: String(e) });
  }

  // Final summary
  findings.summary = {
    pending_create_leak_remaining: findings.surfaces.S2?.pending_create_leak ?? null,
    surfaces_tested: Object.keys(findings.surfaces).length,
    errors_count: findings.errors.length,
    timestamp: new Date().toISOString(),
  };
  fs.writeFileSync(path.join(REPORT_DIR, 'findings.json'), JSON.stringify(findings, null, 2));
  console.log('DONE. Findings summary:', JSON.stringify(findings.summary, null, 2));
})();
