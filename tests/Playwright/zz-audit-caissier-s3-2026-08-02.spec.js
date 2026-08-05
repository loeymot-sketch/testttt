// ZZ-TEST AUDIT CAISSIER S3 2026-08-02 — encaissement counter-collect (web) + annulation borne ciblée.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-logique-2026-08-01/shots');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: false }).catch(() => {});
const WEB_ID = Number(process.env.ZZ_WEB_ID || 6054);
const BORNE_ID = Number(process.env.ZZ_BORNE_ID || 6055);

test.describe.configure({ mode: 'serial' });
test.setTimeout(150_000);

test('S3a — encaissement counter-collect de la commande WEB (espèces, rendu)', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S3a|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);

  // Ouvrir le tracker et cliquer Encaisser sur la ligne EXACTE
  await page.evaluate(() => document.querySelector('[data-testid="pos-tracker-open"]')?.click());
  await page.waitForTimeout(3500);
  const row = await page.evaluate((id) => document.querySelector(`[data-testid="tracker-order-${id}"]`)?.innerText?.replace(/\s+/g, ' ').slice(0, 300) || null, WEB_ID);
  log('row', row);
  const clicked = await page.evaluate((id) => { const b = document.querySelector(`[data-testid="tracker-encaisser-${id}"]`); if (b) { b.click(); return true; } return false; }, WEB_ID);
  log('encaisser_clicked', clicked);
  await page.waitForTimeout(2500);
  await shot(page, 's3a-01-collect-modal.png');

  const modal = await page.evaluate(() => {
    const m = document.querySelector('[data-testid="pos-counter-collect-modal"]');
    return m ? (m.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 900) : null;
  });
  log('modal', modal);
  if (!modal) { fs.writeFileSync(path.join(OUT, 's3a-report.json'), JSON.stringify(R, null, 2)); return; }

  log('modes', await page.evaluate(() => [...document.querySelectorAll('[data-testid^="pos-counter-collect-mode-"]')].map(b => (b.innerText || '').trim())));
  // le champ est readonly (pavé numérique obligatoire) → frapper 1 puis 0
  const numpad = await page.evaluate(() => {
    const modal = document.querySelector('[data-testid="pos-counter-collect-modal"]');
    const keys = [...modal.querySelectorAll('button')].filter(b => /^(\d|00|,|C)$/.test((b.innerText || '').trim()));
    const press = (t) => { const b = keys.find(k => (k.innerText || '').trim() === t); if (b) { b.click(); return true; } return false; };
    const ok1 = press('1'); const ok0 = press('0');
    return { keysFound: keys.length, ok1, ok0, value: document.querySelector('#ccReceivedInput')?.value || null };
  });
  log('numpad_entry', numpad);
  await page.waitForTimeout(900);
  log('change', await page.evaluate(() => document.querySelector('[data-testid="pos-counter-collect-change"]')?.innerText?.replace(/\s+/g, ' ') || null));
  await shot(page, 's3a-02-rendu.png');
  await page.locator('[data-testid="pos-counter-collect-confirm"]').click().catch((e) => log('confirm_fail', e.message.slice(0, 80)));
  await page.waitForTimeout(5000);
  await shot(page, 's3a-03-apres.png');
  log('after', await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 350)));
  fs.writeFileSync(path.join(OUT, 's3a-report.json'), JSON.stringify(R, null, 2));
});

test('S3b — annulation ciblée de la commande BORNE (motif obligatoire)', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S3b|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);

  await page.evaluate(() => document.querySelector('[data-testid="kiosk-cash-open"]')?.click());
  await page.waitForTimeout(2500);
  await shot(page, 's3b-01-panel.png');

  const scoped = await page.evaluate((id) => {
    const collectBtn = document.querySelector(`[data-testid="kiosk-cash-collect-${id}"]`);
    if (!collectBtn) return { found: false, reason: 'no-collect-btn' };
    let card = collectBtn.parentElement;
    for (let i = 0; i < 8 && card; i++) {
      const cancel = card.querySelector('[data-testid="kiosk-cash-cancel-open"]');
      if (cancel) { cancel.click(); return { found: true, card: (card.innerText || '').replace(/\s+/g, ' ').slice(0, 200) }; }
      card = card.parentElement;
    }
    return { found: false, reason: 'no-cancel-in-ancestors' };
  }, BORNE_ID);
  log('cancel_scoped', scoped);
  await page.waitForTimeout(1800);
  await shot(page, 's3b-02-dialog.png');
  const dlg = await page.evaluate(() => document.querySelector('[data-testid="kiosk-cash-cancel-dialog"]')?.innerText?.replace(/\n{2,}/g, '\n').slice(0, 600) || null);
  log('dialog', dlg);

  // preuve : le motif est-il OBLIGATOIRE ? tenter confirmer sans motif
  const confirmDisabledEmpty = await page.evaluate(() => {
    const b = document.querySelector('[data-testid="kiosk-cash-cancel-confirm"]');
    return b ? { disabled: !!b.disabled, aria: b.getAttribute('aria-disabled') } : null;
  });
  log('confirm_without_reason', confirmDisabledEmpty);

  if (dlg && /A0042|13,80/.test(dlg)) {
    await page.locator('[data-testid="kiosk-cash-cancel-reason"]').fill('ZZ-TEST annulation borne audit').catch(() => {});
    await page.waitForTimeout(700);
    await shot(page, 's3b-03-motif.png');
    await page.locator('[data-testid="kiosk-cash-cancel-confirm"]').click().catch((e) => log('confirm_fail', e.message.slice(0, 80)));
    await page.waitForTimeout(4500);
    await shot(page, 's3b-04-apres.png');
    log('done', true);
  } else {
    log('wrong_order_in_dialog', dlg);
  }
  fs.writeFileSync(path.join(OUT, 's3b-report.json'), JSON.stringify(R, null, 2));
});
