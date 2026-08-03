/**
 * GOAL E2E — KIOSK customer journey (2026-05-28)
 *
 * Mission: 5 commandes as a real customer client across categories
 *  1. Sandwich Cayenne (item 22)
 *  2. Big Cayenne (item 36)
 *  3. Tacos (item 26)
 *  4. Bowl Riz Poulet mariné (item 45)
 *  5. Sandwich Classique (item 25)
 *
 * Captures visual + technical evidence (HTML, console errors, screenshots)
 * for each step. Hostile framing — assume things broken, find them.
 *
 * Output:
 *   - PNG → reports/test-e2e/goal-functional-validation-2026-05-28/KIOSK/screenshots/
 *   - DOM dumps for diagnosis
 *
 * Frozen-zone respect: AUDIT ONLY. No frozen-zone touch.
 */
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsKiosk } = require('./helpers/login');

const REPORT_ROOT = path.join(
  process.cwd(),
  'reports/test-e2e/goal-functional-validation-2026-05-28/KIOSK',
);
const SHOT_DIR = path.join(REPORT_ROOT, 'screenshots');
const DOM_DIR = path.join(REPORT_ROOT, 'dom-dumps');
fs.mkdirSync(SHOT_DIR, { recursive: true });
fs.mkdirSync(DOM_DIR, { recursive: true });

const consoleErrors = [];
const failedRequests = [];

function attachInstrumentation(page, label) {
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      consoleErrors.push({ label, text: msg.text() });
    }
  });
  page.on('requestfailed', (req) => {
    failedRequests.push({ label, url: req.url(), failure: req.failure()?.errorText });
  });
}

async function snap(page, name) {
  const file = path.join(SHOT_DIR, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

async function dumpDOM(page, name) {
  const file = path.join(DOM_DIR, `${name}.html`);
  const html = await page.content();
  fs.writeFileSync(file, html);
  return file;
}

test.describe('KIOSK Customer Journey — 5 commandes', () => {
  test.setTimeout(180_000);

  test('01 — Idle screen + auth + category load', async ({ page }) => {
    attachInstrumentation(page, 'idle');

    // Step 1: Idle screen (kiosk wakeup)
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await snap(page, '01_01_kiosk_idle');
    await dumpDOM(page, '01_01_idle');

    // Step 2: Try the catalog entry path
    await page.goto('/kiosk', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await snap(page, '01_02_kiosk_root');

    // Step 3: Attempt machine login
    await loginAsKiosk(page).catch((e) => console.warn('[kiosk-login]', e.message));
    await page.waitForTimeout(2_500);
    await snap(page, '01_03_kiosk_post_login');
    await dumpDOM(page, '01_03_post_login');

    // Step 4: Capture catalog / menu surface
    const catalogCandidates = ['/kiosk/menu', '/kiosk/catalogue', '/kiosk/wizard'];
    for (const route of catalogCandidates) {
      await page.goto(route, { waitUntil: 'domcontentloaded' }).catch(() => null);
      await page.waitForTimeout(1_500);
      const slug = route.replace(/\//g, '_');
      await snap(page, `01_04_route${slug}`);
    }
  });

  test('02 — Real customer journey: idle → À emporter → catalog', async ({ page }) => {
    attachInstrumentation(page, 'real-flow');

    await loginAsKiosk(page).catch(() => null);
    await page.goto('/kiosk', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);

    await snap(page, '02_01_idle_arrived');
    await dumpDOM(page, '02_01_idle');

    // Click "À emporter" — real customer first action
    const aEmporter = page.getByText('À emporter', { exact: false }).first();
    if (await aEmporter.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await aEmporter.click();
      await page.waitForTimeout(2_500);
      await snap(page, '02_02_after_a_emporter_click');
      await dumpDOM(page, '02_02_after_emporter');
    } else {
      await snap(page, '02_02_a_emporter_NOT_VISIBLE');
    }

    // Capture entire body innertext after entering catalog
    const catalogBody = await page.locator('body').innerText().catch(() => '');
    fs.writeFileSync(
      path.join(DOM_DIR, '02_catalog_innertext.txt'),
      catalogBody.slice(0, 8000),
    );

    // Probe sidebar categories
    const cats = ['Sandwich Cayenne', 'Tacos', 'Bols Gourmands', 'Sandwich Classique', 'Burgers', 'Galette', 'Frites', 'Boissons', 'Desserts'];
    for (const cat of cats) {
      const candidate = page.getByText(cat, { exact: false }).first();
      const slug = cat.replace(/\s+/g, '_').toLowerCase();
      if (await candidate.isVisible({ timeout: 1500 }).catch(() => false)) {
        await candidate.click().catch(() => null);
        await page.waitForTimeout(1500);
        await snap(page, `02_03_cat_${slug}_clicked`);
        const innerText = await page.locator('body').innerText().catch(() => '');
        fs.writeFileSync(path.join(DOM_DIR, `02_03_cat_${slug}_innertext.txt`), innerText.slice(0, 5000));
      } else {
        await snap(page, `02_03_cat_${slug}_NOT_VISIBLE`);
      }
    }
  });

  test('03 — Backend menu API: capture catalog payload kiosk client receives', async ({ page, request }) => {
    attachInstrumentation(page, 'api-capture');
    // Login machine first to get kiosk:order token
    await loginAsKiosk(page).catch(() => null);
    await page.goto('/kiosk', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);

    // Capture token from page context if exposed
    const ctxData = await page.evaluate(() => {
      const out = {};
      try { out.localStorage = JSON.parse(JSON.stringify(localStorage)); } catch (e) { out.localStorage_err = String(e); }
      try { out.cookies = document.cookie; } catch (e) { out.cookies_err = String(e); }
      try { out.foodkingConfig = window.foodkingConfig || null; } catch (e) { out.cfg_err = String(e); }
      return out;
    });
    fs.writeFileSync(
      path.join(DOM_DIR, '03_context_capture.json'),
      JSON.stringify(ctxData, null, 2),
    );

    // Probe the unified menu API in-browser (uses session/auth automatically)
    const apiResp = await page.evaluate(async () => {
      try {
        const r = await fetch('/api/v1/frontend/menu', {
          headers: { Accept: 'application/json' },
          credentials: 'include',
        });
        const ct = r.headers.get('content-type');
        const txt = await r.text();
        return { status: r.status, content_type: ct, body_preview: txt.slice(0, 4000) };
      } catch (e) {
        return { error: String(e) };
      }
    });
    fs.writeFileSync(
      path.join(REPORT_ROOT, '03_kiosk_menu_api.json'),
      JSON.stringify(apiResp, null, 2),
    );
  });

  test.afterAll(() => {
    fs.writeFileSync(
      path.join(REPORT_ROOT, 'console-errors.json'),
      JSON.stringify(consoleErrors, null, 2),
    );
    fs.writeFileSync(
      path.join(REPORT_ROOT, 'failed-requests.json'),
      JSON.stringify(failedRequests, null, 2),
    );
  });
});
