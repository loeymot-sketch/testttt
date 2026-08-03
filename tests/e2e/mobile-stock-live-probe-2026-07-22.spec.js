// Preuve LIVE VPS du flux owner : /m → PIN 2580 → toggle rupture Perrier → vérifier menu web → RESTAURER.
// Zéro résidu garanti (restore en fin + vérif). Viewport mobile (Pixel-like).
const { test, expect } = require('@playwright/test');
const path = require('path'); const fs = require('fs');
const SHOT = path.join(__dirname, '__screenshots__', 'mobile-stock-live-2026-07-22');
fs.mkdirSync(SHOT, { recursive: true });
test.setTimeout(150_000);
const VPS = 'https://vps-418872ac.vps.ovh.net';

test('LIVE — /m PIN 2580 → rupture Perrier → propagation web → restore', async ({ page }) => {
  await page.setViewportSize({ width: 412, height: 915 });
  // instrumentation : capturer les réponses /m/api/toggle
  page.on('response', async (r) => {
    if (r.url().includes('/m/api/toggle')) {
      const body = await r.text().catch(()=> '(illisible)');
      console.log('[NET] toggle → HTTP ' + r.status() + ' · ' + body.slice(0, 160).replace(/\n/g,' '));
    }
  });
  await page.goto(VPS + '/m', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: path.join(SHOT, '01-pin.png') });

  // saisir 2580 (pavé ou input)
  const input = page.locator('input[type="password"], input[type="tel"], input[inputmode="numeric"], #pin').first();
  if (await input.isVisible({ timeout: 3000 }).catch(()=>false)) {
    await input.fill('2580');
    const go = page.locator('button:has-text("Déverrouiller"), button:has-text("Valider"), button[type="submit"]').first();
    if (await go.isVisible({ timeout: 1500 }).catch(()=>false)) await go.click();
  } else {
    for (const d of ['2','5','8','0']) await page.locator(`button:has-text("${d}")`).first().click();
  }
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(SHOT, '02-stock.png') });

  // toggle Perrier via DOM (button.toggle dans la ligne contenant 'Perrier')
  const clickPerrierToggle = () => page.evaluate(() => {
    const rows = Array.from(document.querySelectorAll('.row'));
    const row = rows.find(r => (r.querySelector('.name')?.textContent || '').includes('Perrier'));
    const b = row && row.querySelector('button.toggle');
    if (!b) return 'NOTFOUND';
    b.scrollIntoView({ block: 'center' }); b.click(); return 'CLICKED:' + b.textContent.trim();
  });
  const r1 = await clickPerrierToggle();
  console.log('[LIVE] toggle OFF:', r1);
  expect(r1, 'bouton toggle Perrier trouvé').not.toBe('NOTFOUND');
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(SHOT, '03-toggled.png') });

  // vérifier la propagation à l'API web publique — POLL jusqu'à 24s (mesure latence outbox)
  let webUnavailable = false, latencyMs = -1;
  const t0 = Date.now();
  for (let k = 0; k < 12; k++) {
    const webResp = await page.request.get(VPS + '/api/frontend/item?branch_id=1', { headers: { 'x-api-key': 'change-me-long-random-string-local-dev' } });
    const webJson = await webResp.json().catch(()=>null);
    const items = Array.isArray(webJson) ? webJson : (webJson && (webJson.data || []));
    const perrier = (items || []).find(i => /Perrier/.test(String(i.name || '')));
    const un = perrier ? (perrier.is_available === false) : 'ABSENT';
    if (un === true || un === 'ABSENT') { webUnavailable = un; latencyMs = Date.now() - t0; break; }
    await page.waitForTimeout(2000);
  }
  console.log('[LIVE] propagation web: indispo=' + webUnavailable + ' · latence=' + (latencyMs >= 0 ? latencyMs + 'ms' : '>24s JAMAIS'));

  // RESTAURER (retap le toggle via DOM)
  const r2 = await clickPerrierToggle();
  console.log('[LIVE] toggle ON:', r2);
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(SHOT, '04-restored.png') });
  const webResp2 = await page.request.get(VPS + '/api/frontend/item?branch_id=1', { headers: { 'x-api-key': 'change-me-long-random-string-local-dev' } });
  const webJson2 = await webResp2.json().catch(()=>null);
  const items2 = Array.isArray(webJson2) ? webJson2 : (webJson2 && (webJson2.data || []));
  const perrier2 = (items2 || []).find(i => /Perrier/.test(String(i.name || '')));
  console.log('[LIVE] web après restore:', perrier2 ? JSON.stringify({ is_available: perrier2.is_available }) : 'ABSENT');

  // assertions : la rupture a été visible côté web (indispo OU retiré) puis restaurée
  expect(webUnavailable === true || webUnavailable === 'ABSENT', 'rupture propagée au menu web').toBeTruthy();
  expect(perrier2 && perrier2.is_available === true, 'Perrier RESTAURÉ (zéro résidu)').toBeTruthy();
});
