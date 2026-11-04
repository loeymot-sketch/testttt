/**
 * [FINITION 2026-11-04] Client mystère : je prends la place du caissier (caisse) puis du client (borne)
 * et je capture chaque écran du parcours. Aucune commande n'est créée. LIRE les PNG : l'analyse est le but.
 *
 *   FP_OUT=/chemin PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
 *     npx playwright test tests/Playwright/finition-parcours-2026-11-04.spec.js --workers=1 --reporter=line
 */
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const OUT = process.env.FP_OUT || path.resolve(__dirname, '../captures/finition-parcours-2026-11-04');
fs.mkdirSync(OUT, { recursive: true });

function journal(page, tag) {
  const errs = [];
  page.on('pageerror', (e) => errs.push(`pageerror ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error') errs.push(`console ${m.text().slice(0, 200)}`); });
  page.on('response', (r) => { if (r.status() >= 400 && !/9100|favicon/.test(r.url())) errs.push(`${r.status()} ${r.url().replace(BASE, '')}`); });
  return () => { fs.writeFileSync(path.join(OUT, `${tag}-erreurs.txt`), errs.join('\n')); console.log(`[${tag}] erreurs =`, errs.length, errs.slice(0, 12)); };
}

for (const vp of [{ w: 1366, h: 768 }, { w: 1920, h: 1080 }]) {
  test(`caisse ${vp.w}x${vp.h} — parcours caissier`, async ({ page }) => {
    test.setTimeout(150_000);
    await page.setViewportSize({ width: vp.w, height: vp.h });
    const fin = journal(page, `caisse-${vp.w}`);
    const shot = (n) => page.screenshot({ path: path.join(OUT, `caisse-${vp.w}-${n}.png`) });
    await loginAsPosOperator(page);
    await page.waitForTimeout(3500);
    await shot('01-accueil');
    const cats = await page.locator('button, [role="button"]').evaluateAll((els) => els.filter((e) => e.offsetParent).map((e) => (e.innerText || '').trim().split('\n')[0]).filter(Boolean).slice(0, 60));
    console.log('boutons visibles =', JSON.stringify(cats));
    for (const cat of ['Tacos', 'Burgers']) {
      const t = page.getByText(cat, { exact: true }).first();
      if (await t.count()) { await t.click({ timeout: 6000 }).catch(() => {}); await page.waitForTimeout(1800); await shot(`02-cat-${cat}`); }
    }
    // Ouvrir le premier produit visible (wizard caisse — observé, jamais modifié : zone gelée)
    const tuile = page.locator('.pos-v5-item, .pos-item-card, [data-testid*="pos-item"]').first();
    if (await tuile.count()) { await tuile.click({ timeout: 6000 }).catch(() => {}); await page.waitForTimeout(2500); await shot('03-wizard'); }
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(800);
    await shot('04-apres-fermeture');
    fin();
  });
}

test('borne 1080x1920 — parcours client', async ({ page, request }) => {
  test.setTimeout(150_000);
  await page.setViewportSize({ width: 1080, height: 1920 });
  const fin = journal(page, 'borne');
  const shot = (n) => page.screenshot({ path: path.join(OUT, `borne-${n}.png`) });
  const login = await request.post(`${BASE}/api/auth/kiosk-login`, {
    headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
    data: { username: 'kiosk-lecayenne', password: 'kiosk123' },
  });
  const j = await login.json();
  await page.addInitScript(({ token, machineId, branchId }) => {
    localStorage.setItem('vuex', JSON.stringify({ kioskCart: { kioskToken: token, kioskMachineId: machineId, branchId } }));
  }, { token: j.token, machineId: j.kiosk?.machine_id, branchId: j.kiosk?.branch_id || 1 });
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await shot('01-accueil');
  await page.locator('[data-testid="kiosk-idle-touch-btn"]').click({ timeout: 5000, force: true }).catch(() => {});
  await page.waitForTimeout(1200);
  await shot('02-type-commande');
  await page.locator('[data-testid="kiosk-order-type-takeaway"]').click({ timeout: 8000, force: true }).catch(() => {});
  await page.locator('.kiosk-product-card').first().waitFor({ timeout: 12000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await shot('03-catalogue');
  await page.evaluate(() => { const c = document.querySelector('.kiosk-product-card'); if (c) c.click(); });
  await page.waitForTimeout(2000);
  // Ce qui occupe le bas de l'écran pendant l'assistant produit (bande sous la barre d'actions ?).
  console.log('bas assistant =', JSON.stringify(await page.evaluate(() => [1700, 1760, 1810, 1860, 1910].map((y) => {
    let e = document.elementFromPoint(540, y); const c = [];
    while (e && c.length < 5) { const r = e.getBoundingClientRect(); c.push(`${e.tagName}.${String(e.className || '').split(' ')[0]}[${Math.round(r.top)}-${Math.round(r.bottom)}]`); e = e.parentElement; }
    return `${y}: ${c.join(' < ')}`;
  }))));
  for (let i = 0; i < 14; i++) {
    await shot(`04-etape-${String(i).padStart(2, '0')}`);
    const titre = (await page.evaluate(() => (document.querySelector('.kiosk-step-content .kiosk-step-title')?.innerText || '').toLowerCase())).trim();
    await page.evaluate((stepTitle) => {
      const root = document.querySelector('.kiosk-step-content');
      if (!root) return;
      if (/viande\s*2/.test(stepTitle || '')) return;
      const menuCards = Array.from(root.querySelectorAll('.kiosk-menu-card'));
      if (menuCards.length) { const none = menuCards.find((c) => /sans menu/i.test(c.innerText || '')); (none || menuCards[menuCards.length - 1]).click(); return; }
      const groups = Array.from(root.querySelectorAll('.kiosk-step-viande [role="group"]')).filter((n) => /choisir/i.test(n.innerText || ''));
      const p = groups[0] || root.querySelector('.kiosk-step-pain [role="radio"][aria-checked="false"]')
        || root.querySelector('.kiosk-step-sauce [role="checkbox"][aria-checked="false"]') || root.querySelector('.kiosk-generic-choice');
      if (p) p.click();
    }, titre).catch(() => {});
    await page.waitForTimeout(400);
    const next = page.locator('.kiosk-btn-next').last();
    let adv = false;
    if (await next.count()) adv = await next.evaluate((n) => { if (n.disabled) return false; const c = n.classList.contains('kiosk-btn-next--cart'); n.click(); return c; }).catch(() => false);
    await page.waitForTimeout(1300);
    if (adv) break;
  }
  await shot('05-apres-ajout');
  await page.evaluate(() => { const b = document.querySelector('[data-testid="kiosk-bottom-cart"], .kiosk-bottom-cart'); if (b) b.click(); });
  await page.waitForTimeout(1500);
  await shot('06-panier');
  await page.evaluate(() => { const b = document.querySelector('[data-testid="kiosk-cart-checkout"]'); if (b) b.click(); });
  await page.waitForTimeout(3000);
  await shot('07-apres-valider');
  for (let i = 0; i < 4 && !/\/kiosk\/payment/.test(page.url()); i++) {
    await page.evaluate(() => { const b = document.querySelector('[data-testid*="skip"], [data-testid*="no-thanks"], [data-testid="kiosk-upsell-skip"], [data-testid="kiosk-loyalty-skip"]'); if (b) b.click(); });
    await page.waitForTimeout(1500);
    await shot(`08-intermediaire-${i}`);
  }
  await page.waitForTimeout(1200);
  await shot('09-paiement');
  console.log('URL finale', page.url());
  fin();
});
