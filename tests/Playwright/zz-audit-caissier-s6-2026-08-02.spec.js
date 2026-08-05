// ZZ-TEST AUDIT CAISSIER S6 2026-08-02 — session de caisse, ouverture tiroir (no-sale),
// sorties hors-vente (repas personnel / pertes), santé système.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-logique-2026-08-01/shots');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: false }).catch(() => {});

test.describe.configure({ mode: 'serial' });
test.setTimeout(120_000);

test('S6a — session de caisse : dialog, fond, mouvements, clôture', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S6a|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);

  await page.evaluate(() => document.querySelector('[data-testid="pos-cash-session-open"]')?.click());
  await page.waitForTimeout(2500);
  await shot(page, 's6a-01-session-dialog.png');
  const dlg = await page.evaluate(() => {
    const mods = [...document.querySelectorAll('.modal, [role="dialog"], [class*="overlay"]')].filter(m => getComputedStyle(m).display !== 'none' && m.offsetHeight > 60);
    return mods.map(m => (m.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 1200));
  });
  log('dialogs', dlg);
  const controls = await page.evaluate(() => [...document.querySelectorAll('button, input')]
    .filter(e => { const r = e.getBoundingClientRect(); return r.width > 5 && r.height > 5; })
    .map(e => ({ tag: e.tagName, t: (e.innerText || e.placeholder || '').trim().slice(0, 45), testid: e.getAttribute('data-testid') }))
    .filter(x => x.t || x.testid).slice(0, 40));
  log('controls', controls);
  fs.writeFileSync(path.join(OUT, 's6a-report.json'), JSON.stringify(R, null, 2));
});

test('S6b — ouvrir le tiroir sans vente (no-sale) : tracé ?', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S6b|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);
  await page.evaluate(() => document.querySelector('[data-testid="pos-no-sale"]')?.click());
  await page.waitForTimeout(3000);
  await shot(page, 's6b-01-no-sale.png');
  log('after', await page.evaluate(() => ({
    toast: document.querySelector('.alert, [class*="alert"], .toast')?.innerText?.replace(/\s+/g, ' ').slice(0, 250) || null,
    modal: [...document.querySelectorAll('.modal, [role="dialog"]')].filter(m => getComputedStyle(m).display !== 'none' && m.offsetHeight > 60).map(m => (m.innerText || '').replace(/\s+/g, ' ').slice(0, 300)),
  })));
  fs.writeFileSync(path.join(OUT, 's6b-report.json'), JSON.stringify(R, null, 2));
});

test('S6c — sortie hors-vente (repas personnel / perte)', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S6c|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(2500);
  await page.evaluate(() => document.querySelector('[data-testid="pos-tracker-open"]')?.click());
  await page.waitForTimeout(3500);
  await page.evaluate(() => document.querySelector('[data-testid="pos-tracker-outflow"]')?.click());
  await page.waitForTimeout(2500);
  await shot(page, 's6c-01-outflow-modal.png');
  const modal = await page.evaluate(() => {
    const s = document.querySelector('[data-testid="pso-item-search"]');
    const root = s ? s.closest('.modal, [role="dialog"], div[class*="overlay"]') : null;
    return root ? (root.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 900) : null;
  });
  log('modal', modal);
  if (modal) {
    await page.locator('[data-testid="pso-item-search"]').fill('Petite Frites').catch((e) => log('search_fail', e.message.slice(0, 80)));
    await page.waitForTimeout(1800);
    await shot(page, 's6c-02-recherche.png');
    const picked = await page.evaluate(() => {
      const s = document.querySelector('[data-testid="pso-item-search"]');
      const root = s.closest('.modal, [role="dialog"], div[class*="overlay"]') || document;
      const opt = [...root.querySelectorAll('li, option, button, [role="option"]')].find(e => /petite frites/i.test(e.innerText || '') && (e.innerText || '').length < 80);
      if (opt) { opt.click(); return (opt.innerText || '').trim().slice(0, 60); }
      return null;
    });
    log('picked_item', picked);
    await page.waitForTimeout(900);
    await page.evaluate(() => document.querySelector('[data-testid="pso-type-staff"]')?.click());
    await page.waitForTimeout(500);
    await shot(page, 's6c-03-type-repas.png');
    const submitState = await page.evaluate(() => {
      const b = document.querySelector('[data-testid="pso-submit"]');
      return b ? { disabled: !!b.disabled, text: (b.innerText || '').trim() } : null;
    });
    log('submit_state', submitState);
    await page.evaluate(() => document.querySelector('[data-testid="pso-submit"]')?.click());
    await page.waitForTimeout(3500);
    await shot(page, 's6c-04-apres-submit.png');
    log('after_submit', await page.evaluate(() => {
      const s = document.querySelector('[data-testid="pso-item-search"]');
      const root = s ? s.closest('.modal, [role="dialog"], div[class*="overlay"]') : null;
      return { modalText: root ? (root.innerText || '').replace(/\s+/g, ' ').slice(0, 500) : null, toast: document.querySelector('.alert, [class*="alert"]')?.innerText?.slice(0, 200) || null };
    }));
  }
  fs.writeFileSync(path.join(OUT, 's6c-report.json'), JSON.stringify(R, null, 2));
});

test('S6d — santé système (pastille + page dédiée)', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S6d|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);
  const pill = await page.evaluate(() => {
    const el = [...document.querySelectorAll('[class*="health"], [data-testid*="health"]')].filter(e => e.offsetHeight > 0);
    return el.map(e => (e.innerText || '').replace(/\s+/g, ' ').slice(0, 200));
  });
  log('health_pill', pill);
  const api = await page.evaluate(async () => {
    try { const r = await window.axios.get('admin/pos/system-health'); return { status: r.status, data: r.data }; }
    catch (e) { return { status: e?.response?.status, data: e?.response?.data }; }
  });
  log('system_health_api', api);
  await shot(page, 's6d-01-health.png');
  fs.writeFileSync(path.join(OUT, 's6d-report.json'), JSON.stringify(R, null, 2));
});
