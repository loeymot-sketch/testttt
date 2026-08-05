// ZZ-TEST AUDIT CAISSIER S2b 2026-08-02 — accept web ciblé + encaissement + annulation borne ciblée.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-logique-2026-08-01/shots');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: false }).catch(() => {});

const WEB_ID = Number(process.env.ZZ_WEB_ID || 6054);
const BORNE_ID = Number(process.env.ZZ_BORNE_ID || 6055);

test.setTimeout(240_000);

test('S2b — accept web ciblé, encaissement, contact client, annulation borne ciblée', async ({ page }) => {
  const R = { steps: [] };
  const log = (k, v) => { R.steps.push({ [k]: v }); console.log('S2b|' + k + '|' + JSON.stringify(v)); };

  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);

  // ---- A. ACCEPT via API interne du composant : simuler le clic sur la ligne EXACTE.
  // Le panneau POS n'affiche que 4 lignes (FIFO) → on passe par le tracker « Suivi commandes ».
  await page.evaluate(() => document.querySelector('[data-testid="pos-tracker-open"]')?.click());
  await page.waitForTimeout(3500);
  await shot(page, 's2b-01-tracker.png');
  const trackerText = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 1500));
  log('tracker_text', trackerText);

  const trackerRow = await page.evaluate((id) => {
    const el = document.querySelector(`[data-testid="tracker-order-${id}"]`);
    if (!el) return null;
    el.scrollIntoView({ block: 'center' });
    return (el.innerText || '').replace(/\s+/g, ' ').slice(0, 400);
  }, WEB_ID);
  log('tracker_row_web', trackerRow);

  const trackerPhone = await page.evaluate((id) => {
    const el = document.querySelector(`[data-testid="tracker-customer-phone-${id}"]`);
    return el ? { text: (el.innerText || '').trim(), href: el.getAttribute('href') } : null;
  }, WEB_ID);
  log('tracker_phone_link', trackerPhone);

  // Actions disponibles sur la ligne web PENDING
  const trackerActions = await page.evaluate((id) => {
    const ids = ['accept-web', 'encaisser', 'reprint', 'cancel'];
    const out = {};
    ids.forEach(k => {
      const el = document.querySelector(`[data-testid="tracker-${k}-${id}"]`);
      out[k] = el ? { text: (el.innerText || '').trim(), disabled: !!el.disabled } : null;
    });
    return out;
  }, WEB_ID);
  log('tracker_actions_web', trackerActions);

  // ACCEPTER depuis le tracker
  const acceptClicked = await page.evaluate((id) => {
    const b = document.querySelector(`[data-testid="tracker-accept-web-${id}"]`);
    if (!b) return null;
    b.click(); return (b.innerText || '').trim();
  }, WEB_ID);
  log('tracker_accept_clicked', acceptClicked);
  await page.waitForTimeout(4000);
  await shot(page, 's2b-02-apres-accept.png');

  // ---- B. Retour POS : la commande web doit être dans « À encaisser » avec badge contact
  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  const contact = await page.evaluate((id) => {
    const el = document.querySelector(`[data-testid="pos-shortcut-web-contact-${id}"]`);
    const row = document.querySelector(`[data-testid="pos-shortcut-cash-${id}"]`);
    return {
      contact: el ? (el.innerText || '').trim() : null,
      rowVisible: !!row,
      rowText: row ? (row.innerText || '').replace(/\s+/g, ' ') : null,
    };
  }, WEB_ID);
  log('pos_cash_row_web', contact);
  await shot(page, 's2b-03-encaisser-web.png');

  // Si pas dans le top-4, ouvrir le tracker et encaisser depuis là
  let collectedFrom = 'pos-panel';
  let encClicked = await page.evaluate((id) => {
    const b = document.querySelector(`[data-testid="pos-shortcut-encaisser-${id}"]`);
    if (b) { b.click(); return true; } return false;
  }, WEB_ID);
  if (!encClicked) {
    collectedFrom = 'tracker';
    await page.evaluate(() => document.querySelector('[data-testid="pos-tracker-open"]')?.click());
    await page.waitForTimeout(3500);
    encClicked = await page.evaluate((id) => {
      const b = document.querySelector(`[data-testid="tracker-encaisser-${id}"]`);
      if (b) { b.click(); return true; } return false;
    }, WEB_ID);
  }
  log('encaisser_clicked', { encClicked, collectedFrom });
  await page.waitForTimeout(2500);
  await shot(page, 's2b-04-collect-modal.png');

  const collect = await page.evaluate(() => {
    const m = document.querySelector('[data-testid="pos-counter-collect-modal"]');
    return m ? (m.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 1000) : null;
  });
  log('collect_modal', collect);

  if (collect) {
    // modes de paiement proposés
    const modes = await page.evaluate(() => [...document.querySelectorAll('[data-testid^="pos-counter-collect-mode-"]')].map(b => (b.innerText || '').trim()));
    log('collect_modes', modes);
    await page.locator('[data-testid="pos-counter-collect-received-input"]').fill('10').catch((e) => log('collect_fill_fail', e.message.slice(0, 90)));
    await page.waitForTimeout(800);
    log('collect_change', await page.evaluate(() => document.querySelector('[data-testid="pos-counter-collect-change"]')?.innerText?.replace(/\s+/g, ' ') || null));
    await shot(page, 's2b-05-collect-rendu.png');
    await page.locator('[data-testid="pos-counter-collect-confirm"]').click().catch((e) => log('collect_confirm_fail', e.message.slice(0, 90)));
    await page.waitForTimeout(4500);
    await shot(page, 's2b-06-apres-collect.png');
  }

  // ---- C. ANNULER la commande BORNE 6055 — dialogue SCOPÉ à la bonne ligne
  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  await page.evaluate(() => document.querySelector('[data-testid="kiosk-cash-open"]')?.click());
  await page.waitForTimeout(2500);
  await shot(page, 's2b-07-kiosk-cash-panel.png');

  // Trouver le bouton Annuler DANS la carte de la commande 6055
  const cancelScoped = await page.evaluate((id) => {
    // la carte contient le bouton collect data-testid=kiosk-cash-collect-<id>
    const collectBtn = document.querySelector(`[data-testid="kiosk-cash-collect-${id}"]`);
    if (!collectBtn) return { found: false, reason: 'no-collect-btn-for-order' };
    let card = collectBtn.closest('li, .kiosk-cash-card, article, div');
    // remonter jusqu'à trouver un bouton annuler dans le même conteneur
    for (let i = 0; i < 6 && card; i++) {
      const cancel = card.querySelector('[data-testid="kiosk-cash-cancel-open"]');
      if (cancel) { cancel.click(); return { found: true, cardText: (card.innerText || '').replace(/\s+/g, ' ').slice(0, 200) }; }
      card = card.parentElement;
    }
    return { found: false, reason: 'no-cancel-in-card' };
  }, BORNE_ID);
  log('cancel_scoped', cancelScoped);
  await page.waitForTimeout(1800);
  await shot(page, 's2b-08-cancel-dialog.png');
  const dlg = await page.evaluate(() => document.querySelector('[data-testid="kiosk-cash-cancel-dialog"]')?.innerText?.replace(/\n{2,}/g, '\n').slice(0, 700) || null);
  log('cancel_dialog', dlg);

  if (dlg && /A0042/.test(dlg)) {
    await page.locator('[data-testid="kiosk-cash-cancel-reason"]').fill('ZZ-TEST annulation borne audit').catch(() => {});
    await page.waitForTimeout(600);
    await shot(page, 's2b-09-cancel-motif.png');
    await page.locator('[data-testid="kiosk-cash-cancel-confirm"]').click().catch((e) => log('cancel_confirm_fail', e.message.slice(0, 90)));
    await page.waitForTimeout(4000);
    await shot(page, 's2b-10-apres-cancel.png');
    log('cancel_done', true);
  } else {
    log('cancel_skipped_wrong_order', dlg);
    await page.evaluate(() => document.querySelector('[data-testid="kiosk-cash-cancel-back"]')?.click());
  }

  fs.writeFileSync(path.join(OUT, 's2b-report.json'), JSON.stringify(R, null, 2));
});
