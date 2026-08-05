// ZZ-TEST AUDIT CAISSIER S4 2026-08-02 — historique/réimpression/n° fiscal + remboursement + rupture 86.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-logique-2026-08-01/shots');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: false }).catch(() => {});
const PAID_ID = Number(process.env.ZZ_PAID_ID || 6053); // commande caisse S1 payée espèces 8,30

test.describe.configure({ mode: 'serial' });
test.setTimeout(150_000);

test('S4a — historique : retrouver une commande, n° fiscal, réimpression', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S4a|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.goto('/admin/historique', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(5000);
  await shot(page, 's4a-01-historique.png');
  log('url', await page.evaluate(() => location.pathname));
  const body = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 2000));
  log('body', body);

  // colonnes / filtres disponibles
  log('chips', await page.evaluate(() => [...document.querySelectorAll('[data-testid^="historique-chip-"]')].map(c => (c.innerText || '').trim())));
  log('headers', await page.evaluate(() => [...document.querySelectorAll('th')].map(t => (t.innerText || '').trim()).filter(Boolean)));

  // recherche de la commande payée
  const searchFilled = await page.evaluate(() => {
    const inp = [...document.querySelectorAll('input')].find(i => /recherch|search|n°|numero/i.test((i.placeholder || '') + (i.getAttribute('aria-label') || '')));
    if (!inp) return null;
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(inp, '0208266053');
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    return inp.placeholder || 'found';
  });
  log('search_input', searchFilled);
  await page.waitForTimeout(3000);
  await shot(page, 's4a-02-recherche.png');
  const rowsAfter = await page.evaluate(() => (document.querySelector('table')?.innerText || document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 1200));
  log('rows_after_search', rowsAfter);

  // n° fiscal visible ?
  log('fiscal_2698_visible', await page.evaluate(() => /2698/.test(document.body.innerText || '')));

  // réimpression
  const reprint = await page.evaluate((id) => {
    const b = document.querySelector(`[data-testid="historique-reprint-${id}"]`);
    if (!b) return { present: false };
    b.click();
    return { present: true, text: (b.innerText || '').trim(), disabled: !!b.disabled };
  }, PAID_ID);
  log('reprint', reprint);
  await page.waitForTimeout(3500);
  await shot(page, 's4a-03-reprint.png');
  log('after_reprint', await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 600)));
  fs.writeFileSync(path.join(OUT, 's4a-report.json'), JSON.stringify(R, null, 2));
});

test('S4b — remboursement d’une commande encaissée (miroir + tiroir)', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S4b|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.goto(`/admin/pos-orders/show/${PAID_ID}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(4000);
  log('url', await page.evaluate(() => location.pathname));
  await shot(page, 's4b-01-order-show.png');
  const bodyTxt = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 1400));
  log('body', bodyTxt);

  const refundCta = await page.evaluate(() => {
    const b = document.querySelector('[data-testid="pos-order-refund-open"]');
    return b ? { text: (b.innerText || '').trim(), disabled: !!b.disabled } : null;
  });
  log('refund_cta', refundCta);
  if (refundCta) {
    await page.evaluate(() => document.querySelector('[data-testid="pos-order-refund-open"]').click());
    await page.waitForTimeout(2000);
    await shot(page, 's4b-02-refund-modal.png');
    const modal = await page.evaluate(() => document.querySelector('[data-testid="pos-refund-modal-overlay"]')?.innerText?.replace(/\n{2,}/g, '\n').slice(0, 900) || null);
    log('refund_modal', modal);
    // confirmer sans motif → bouton actif ?
    log('confirm_state_empty_reason', await page.evaluate(() => {
      const b = document.querySelector('[data-testid="pos-refund-modal-confirm"]');
      return b ? { disabled: !!b.disabled } : null;
    }));
    await page.locator('[data-testid="pos-refund-modal-reason"]').fill('ZZ-TEST remboursement audit caissier').catch((e) => log('reason_fail', e.message.slice(0, 80)));
    await page.waitForTimeout(600);
    await shot(page, 's4b-03-refund-motif.png');
    await page.locator('[data-testid="pos-refund-modal-confirm"]').click().catch((e) => log('confirm_fail', e.message.slice(0, 90)));
    await page.waitForTimeout(5000);
    await shot(page, 's4b-04-apres-refund.png');
    log('after', await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 700)));
  }
  fs.writeFileSync(path.join(OUT, 's4b-report.json'), JSON.stringify(R, null, 2));
});

test('S4c — rupture 86 : marquer indisponible + réactiver', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S4c|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(2500);
  // depuis la caisse : bouton Rupture
  await page.evaluate(() => document.querySelector('[data-testid="pos-availability-panel-open"]')?.click());
  await page.waitForTimeout(3500);
  await shot(page, 's4c-01-rupture-panel.png');
  log('url', await page.evaluate(() => location.pathname));
  log('body', await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 1500)));
  log('banner', await page.evaluate(() => document.querySelector('[data-testid="pos-availability-banner"]')?.innerText?.replace(/\s+/g, ' ') || null));
  log('live_chip', await page.evaluate(() => document.querySelector('[data-testid="pos-availability-live"]')?.innerText?.replace(/\s+/g, ' ') || null));

  // chercher les toggles disponibles
  const toggles = await page.evaluate(() => {
    const els = [...document.querySelectorAll('button, input[type="checkbox"], [role="switch"]')];
    return els.map(e => ({ t: (e.innerText || '').trim().slice(0, 40), testid: e.getAttribute('data-testid'), cls: String(e.className).slice(0, 40) }))
      .filter(x => x.t || x.testid).slice(0, 30);
  });
  log('controls', toggles);
  fs.writeFileSync(path.join(OUT, 's4c-report.json'), JSON.stringify(R, null, 2));
});
