// ZZ-TEST AUDIT CAISSIER S2 2026-08-02 — contrôle des commandes WEB + BORNE depuis la caisse.
// Pré-requis : ordre WEB 6054 (A0041, ZZ-TEST-WEB Manon 0699887766) + BORNE 6055 (A0042) créés via API.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-logique-2026-08-01/shots');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: false }).catch(() => {});

const WEB_ID = Number(process.env.ZZ_WEB_ID || 6054);
const BORNE_ID = Number(process.env.ZZ_BORNE_ID || 6055);

test.setTimeout(220_000);

test('S2 — voir/accepter/encaisser/annuler les commandes web + borne', async ({ page }) => {
  const R = { steps: [] };
  const log = (k, v) => { R.steps.push({ [k]: v }); console.log('S2|' + k + '|' + JSON.stringify(v)); };

  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);

  // ---- 1. Panneau COMMANDES WEB : la commande A0041 est-elle visible avec NOM + TEL ?
  const webRow = page.locator(`[data-testid="pos-shortcut-web-${WEB_ID}"]`);
  const webRowVisible = await webRow.isVisible().catch(() => false);
  log('web_row_visible', webRowVisible);
  if (webRowVisible) {
    log('web_row_text', (await webRow.innerText()).replace(/\s+/g, ' '));
  } else {
    // chercher dans tout le panneau
    const panel = await page.evaluate(() => document.querySelector('[data-testid="pos-shortcuts-web"]')?.innerText?.slice(0, 1200) || null);
    log('web_panel_text', panel);
  }
  await shot(page, 's2-01-panneau-web.png');

  // Contact web (badge téléphone)
  const contact = await page.evaluate((id) => {
    const el = document.querySelector(`[data-testid="pos-shortcut-web-contact-${id}"]`);
    return el ? { text: (el.innerText || '').trim(), href: el.getAttribute('href'), title: el.getAttribute('title') } : null;
  }, WEB_ID);
  log('web_contact_badge', contact);

  // ---- 2. Détails de la commande web (bouton Détails / web-open)
  const opened = await page.evaluate((id) => {
    const b = document.querySelector(`[data-testid="pos-shortcut-web-open-${id}"]`);
    if (b) { b.click(); return true; }
    return false;
  }, WEB_ID);
  log('web_details_clicked', opened);
  await page.waitForTimeout(2500);
  await shot(page, 's2-02-web-details.png');
  log('web_details_url', await page.evaluate(() => location.pathname));
  log('web_details_body', await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 1400)));

  // Le caissier peut-il MODIFIER cette commande ici ? (chercher edit/modifier)
  const editControls = await page.evaluate(() => {
    const btns = [...document.querySelectorAll('button, a')].map(b => (b.innerText || '').trim()).filter(t => /modifier|éditer|edit|ajouter article|changer/i.test(t));
    return btns.slice(0, 10);
  });
  log('web_details_edit_controls', editControls);

  // ---- 3. Retour POS et ACCEPTER la commande web
  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const accepted = await page.evaluate((id) => {
    const b = document.querySelector(`[data-testid="pos-shortcut-web-accept-${id}"]`);
    if (b) { b.click(); return (b.innerText || '').trim(); }
    return null;
  }, WEB_ID);
  log('web_accept_clicked', accepted);
  await page.waitForTimeout(3500);
  await shot(page, 's2-03-apres-accept.png');

  // ---- 4. La commande BORNE A0042 dans le panneau à encaisser (kiosk cash) + détails
  await page.evaluate(() => document.querySelector('[data-testid="pos-shortcuts-cash-refresh"]')?.click());
  await page.waitForTimeout(2500);
  const cashPanel = await page.evaluate(() => document.querySelector('[data-testid="pos-shortcuts-cash"]')?.innerText?.replace(/\s+/g, ' ').slice(0, 1500) || null);
  log('cash_panel', cashPanel);
  const borneRow = await page.evaluate((id) => {
    const el = document.querySelector(`[data-testid="pos-shortcut-cash-${id}"]`);
    return el ? (el.innerText || '').replace(/\s+/g, ' ') : null;
  }, BORNE_ID);
  log('borne_row', borneRow);
  await shot(page, 's2-04-panneau-encaisser.png');

  // ---- 5. ENCAISSER la commande WEB (counter-collect) en espèces 7,00 → rendu 3,00 sur 10
  const encWeb = await page.evaluate((id) => {
    const b = document.querySelector(`[data-testid="pos-shortcut-encaisser-${id}"]`);
    if (b) { b.click(); return true; }
    return false;
  }, WEB_ID);
  log('web_encaisser_clicked', encWeb);
  await page.waitForTimeout(2000);
  await shot(page, 's2-05-collect-modal.png');
  const collectVisible = await page.evaluate(() => {
    const m = document.querySelector('[data-testid="pos-counter-collect-modal"]');
    return m ? (m.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 1000) : null;
  });
  log('collect_modal', collectVisible);

  if (collectVisible) {
    await page.locator('[data-testid="pos-counter-collect-received-input"]').fill('10').catch((e) => log('collect_fill_fail', e.message.slice(0, 80)));
    await page.waitForTimeout(700);
    log('collect_change', await page.evaluate(() => document.querySelector('[data-testid="pos-counter-collect-change"]')?.innerText?.replace(/\s+/g, ' ') || null));
    await shot(page, 's2-06-collect-10-rendu.png');
    await page.locator('[data-testid="pos-counter-collect-confirm"]').click().catch((e) => log('collect_confirm_fail', e.message.slice(0, 80)));
    await page.waitForTimeout(4000);
    await shot(page, 's2-07-apres-collect.png');
    log('after_collect_body', await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 400)));
  }

  // ---- 6. ANNULER la commande BORNE avec motif
  // ouvrir le panneau kiosk-cash (À encaisser) → bouton annuler
  await page.evaluate(() => document.querySelector('[data-testid="kiosk-cash-open"]')?.click());
  await page.waitForTimeout(2000);
  await shot(page, 's2-08-kiosk-cash-panel.png');
  const panelTxt = await page.evaluate(() => (document.body.innerText || '').match(/A0042[\s\S]{0,120}/)?.[0] || null);
  log('kiosk_cash_panel_A0042', panelTxt);

  // développer la commande borne + bouton annuler
  await page.evaluate((id) => document.querySelector(`[data-testid="kiosk-cash-expand-${id}"]`)?.click(), BORNE_ID);
  await page.waitForTimeout(1200);
  await shot(page, 's2-09-borne-details-expand.png');
  const borneDetail = await page.evaluate((id) => document.querySelector(`[data-testid="kiosk-cash-details-${id}"], [data-testid="kiosk-cash-detail-${id}"]`)?.innerText?.replace(/\s+/g, ' ').slice(0, 600) || null, BORNE_ID);
  log('borne_detail', borneDetail);

  const cancelOpen = await page.evaluate(() => {
    const b = document.querySelector('[data-testid="kiosk-cash-cancel-open"]');
    if (b) { b.click(); return true; } return false;
  });
  log('cancel_open', cancelOpen);
  await page.waitForTimeout(1500);
  await shot(page, 's2-10-cancel-dialog.png');
  const cancelDialog = await page.evaluate(() => document.querySelector('[data-testid="kiosk-cash-cancel-dialog"]')?.innerText?.replace(/\n{2,}/g, '\n').slice(0, 800) || null);
  log('cancel_dialog', cancelDialog);

  if (cancelDialog) {
    // le dialog liste les commandes annulables — cliquer celle A0042 puis motif + confirmer
    const pickedA42 = await page.evaluate(() => {
      const dlg = document.querySelector('[data-testid="kiosk-cash-cancel-dialog"]');
      const el = [...(dlg?.querySelectorAll('button, [role="button"], li, label, div') || [])].find(e => /A0042/.test(e.innerText || '') && (e.innerText || '').length < 200);
      if (el) { el.click(); return (el.innerText || '').replace(/\s+/g, ' ').slice(0, 120); }
      return null;
    });
    log('cancel_picked', pickedA42);
    await page.waitForTimeout(900);
    await page.locator('[data-testid="kiosk-cash-cancel-reason"]').fill('ZZ-TEST client parti sans payer').catch((e) => log('cancel_reason_fail', e.message.slice(0, 90)));
    await page.waitForTimeout(500);
    await shot(page, 's2-11-cancel-motif.png');
    await page.locator('[data-testid="kiosk-cash-cancel-confirm"]').click().catch((e) => log('cancel_confirm_fail', e.message.slice(0, 90)));
    await page.waitForTimeout(3500);
    await shot(page, 's2-12-apres-cancel.png');
    log('after_cancel_body', await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 300)));
  }

  fs.writeFileSync(path.join(OUT, 's2-report.json'), JSON.stringify(R, null, 2));
});
