// =============================================================================
// REVALIDATION E2E LIVE 2026-07-23 — VPS backend
// R4 = /m PIN 2580 (Pixel 7) : rupture Perrier → propagation API web (poll ≤20s,
//      latence mesurée) → RESTORE + preuve zéro résidu.
// R6 = /kiosk/idle 200 + rendu (capture).
// READ-ONLY probe (le toggle est restauré, état final == état initial).
// Run: PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/revalidation-vps-2026-07-23.spec.js --project=chromium
// =============================================================================
const { test, expect, devices } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const VPS = 'https://vps-418872ac.vps.ovh.net';
const API_KEY = 'change-me-long-random-string-local-dev';
const SHOT = path.join(__dirname, '__screenshots__', 'revalidation-2026-07-23');
fs.mkdirSync(SHOT, { recursive: true });
const shot = (n) => path.join(SHOT, n);

const px7 = devices['Pixel 7'] || { ...devices['Pixel 5'], viewport: { width: 412, height: 915 } };
test.use({ ignoreHTTPSErrors: true, ...px7 });
test.describe.configure({ retries: 0, mode: 'serial' });
test.setTimeout(220_000);

async function apiPerrier(page) {
  const r = await page.request.get(VPS + '/api/frontend/item?branch_id=1', { headers: { 'x-api-key': API_KEY } });
  const j = await r.json().catch(() => null);
  const items = Array.isArray(j) ? j : (j && (j.data || []));
  const p = (items || []).find(i => /Perrier/i.test(String(i.name || '')));
  return { status: r.status(), found: !!p, is_available: p ? p.is_available : null };
}

test('R4 — /m PIN 2580 : rupture Perrier → API web → restore (zéro résidu)', async ({ page }) => {
  const obs = { toggleResponses: [], notes: [] };
  page.on('response', async (r) => {
    if (r.url().includes('/m/api/toggle')) {
      const body = await r.text().catch(() => '(illisible)');
      obs.toggleResponses.push({ status: r.status(), body: body.slice(0, 160) });
    }
  });

  // état API initial (référence zéro-résidu)
  obs.apiBefore = await apiPerrier(page);
  console.log('[R4] API avant:', JSON.stringify(obs.apiBefore));

  await page.goto(VPS + '/m', { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await page.waitForTimeout(1_500);
  await page.screenshot({ path: shot('R4-01-pin.png') });

  // PIN 2580
  const input = page.locator('input[type="password"], input[type="tel"], input[inputmode="numeric"], #pin').first();
  if (await input.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await input.fill('2580');
    const go = page.locator('button:has-text("Déverrouiller"), button:has-text("Valider"), button[type="submit"]').first();
    if (await go.isVisible({ timeout: 1_500 }).catch(() => false)) await go.click();
  } else {
    for (const d of ['2', '5', '8', '0']) await page.locator(`button:has-text("${d}")`).first().click();
  }
  await page.waitForTimeout(2_500);
  await page.screenshot({ path: shot('R4-02-stock-catalogue.png') });
  obs.catalogRows = await page.locator('.row').count().catch(() => 0);

  const clickPerrierToggle = () => page.evaluate(() => {
    const rows = Array.from(document.querySelectorAll('.row'));
    const row = rows.find(r => (r.querySelector('.name')?.textContent || '').includes('Perrier'));
    const b = row && row.querySelector('button.toggle');
    if (!b) return 'NOTFOUND';
    b.scrollIntoView({ block: 'center' }); b.click();
    return 'CLICKED:' + b.textContent.trim();
  });

  let toggledOff = false;
  try {
    // ---- RUPTURE ----
    const r1 = await clickPerrierToggle();
    obs.toggleOff = r1;
    expect(r1, 'bouton toggle Perrier trouvé').not.toBe('NOTFOUND');
    toggledOff = true;
    await page.waitForTimeout(2_000);
    await page.screenshot({ path: shot('R4-03-perrier-rupture.png') });

    // ---- POLL API ≤20s ----
    let latencyMs = -1; let unavailable = false;
    const t0 = Date.now();
    for (let k = 0; k < 10; k++) {
      const st = await apiPerrier(page);
      if (st.is_available === false || !st.found) { unavailable = true; latencyMs = Date.now() - t0; obs.apiDuringRupture = st; break; }
      await page.waitForTimeout(2_000);
    }
    obs.propagation = { unavailable, latencyMs: latencyMs >= 0 ? latencyMs : '>20s' };
    console.log('[R4] propagation:', JSON.stringify(obs.propagation));
    expect(unavailable, 'rupture propagée à l\'API web en ≤20s').toBeTruthy();
  } finally {
    // ---- RESTORE (toujours, même si l'assert ci-dessus casse) ----
    if (toggledOff) {
      for (let attempt = 1; attempt <= 3; attempt++) {
        const r2 = await clickPerrierToggle();
        obs['toggleOnAttempt' + attempt] = r2;
        await page.waitForTimeout(2_500);
        let restored = false;
        const t1 = Date.now();
        for (let k = 0; k < 10; k++) {
          const st = await apiPerrier(page);
          if (st.is_available === true) { restored = true; obs.restoreLatencyMs = Date.now() - t1; break; }
          await page.waitForTimeout(2_000);
        }
        if (restored) { obs.restored = true; break; }
        obs.restored = false;
      }
      await page.screenshot({ path: shot('R4-04-perrier-restaure.png') });
      obs.apiAfter = await apiPerrier(page);
      console.log('[R4] API après restore:', JSON.stringify(obs.apiAfter));
    }
    fs.writeFileSync(path.join(SHOT, 'obs-R4.json'), JSON.stringify(obs, null, 2));
  }
  expect(obs.restored, 'Perrier RESTAURÉ côté API (zéro résidu)').toBeTruthy();
  expect(obs.apiAfter && obs.apiAfter.is_available, 'état final == état initial (en stock)').toBe(true);
});

test('R6 — /kiosk/idle répond et rend (borne VPS)', async ({ page }) => {
  const obs = { consoleErrors: [] };
  page.on('pageerror', (e) => obs.consoleErrors.push('PAGEERROR ' + String(e.message).slice(0, 200)));
  page.on('console', (m) => { if (m.type() === 'error') obs.consoleErrors.push(m.text().slice(0, 200)); });
  await page.setViewportSize({ width: 1080, height: 1920 });
  const resp = await page.goto(VPS + '/kiosk/idle', { waitUntil: 'domcontentloaded', timeout: 45_000 });
  obs.status = resp ? resp.status() : 0;
  await page.waitForTimeout(4_000); // hydratation + animation idle
  obs.finalUrl = page.url();
  obs.redirectedToLogin = /\/login(?:$|\?)/.test(page.url());
  obs.bodyTextLen = await page.evaluate(() => (document.body.innerText || '').length).catch(() => 0);
  obs.bodySample = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 300)).catch(() => '');
  await page.screenshot({ path: shot('R6-01-kiosk-idle.png'), fullPage: false });
  fs.writeFileSync(path.join(SHOT, 'obs-R6.json'), JSON.stringify(obs, null, 2));
  console.log('[R6]', JSON.stringify(obs));
  expect(obs.status, 'kiosk/idle HTTP 200').toBe(200);
});
