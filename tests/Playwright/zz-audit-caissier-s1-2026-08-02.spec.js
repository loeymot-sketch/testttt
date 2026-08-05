// ZZ-TEST AUDIT CAISSIER S1 2026-08-02 — prise de commande complète + cash + rendu monnaie.
// Jetable. Ne modifie aucun code applicatif.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-logique-2026-08-01/shots');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: false }).catch(() => {});

test.setTimeout(200_000);

// Clique le premier élément court dont le texte matche, dans le modal wizard (ou le doc).
async function clickText(page, reSrc, scopeSel) {
  return await page.evaluate(({ reSrc, scopeSel }) => {
    const re = new RegExp(reSrc, 'i');
    const scope = (scopeSel && document.querySelector(scopeSel)) || document;
    const all = [...scope.querySelectorAll('button, label, [role="button"], [role="option"], li, .btn, div, span')];
    // éléments feuilles courts, visibles
    const cand = all.filter((e) => {
      const t = (e.innerText || '').trim();
      if (!t || t.length > 60 || !re.test(t)) return false;
      const r = e.getBoundingClientRect();
      return r.width > 10 && r.height > 10;
    });
    // le plus profond (feuille)
    cand.sort((a, b) => b.querySelectorAll('*').length - a.querySelectorAll('*').length);
    const el = cand[cand.length - 1];
    if (!el) return null;
    el.scrollIntoView({ block: 'center' });
    const r = el.getBoundingClientRect();
    for (const type of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, clientX: r.x + r.width / 2, clientY: r.y + r.height / 2 }));
    }
    return (el.innerText || '').trim().slice(0, 60) + ' <' + el.tagName + ' class=' + String(el.className).slice(0, 50) + '>';
  }, { reSrc, scopeSel });
}

async function wizardText(page) {
  return await page.evaluate(() => {
    const m = document.querySelector('#item-variation-modal');
    const visible = m && getComputedStyle(m).display !== 'none';
    return { visible: !!visible, text: visible ? (m.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 2500) : null };
  });
}

test('S1 — commande complète Cayenne + cash + rendu monnaie + ticket', async ({ page }) => {
  const R = { steps: [] };
  const log = (k, v) => { R.steps.push({ [k]: v }); console.log('S1|' + k + '|' + JSON.stringify(v)); };
  page.on('console', (msg) => { if (msg.type() === 'error') console.log('S1|console-error|' + msg.text().slice(0, 180)); });

  await loginAsPosOperator(page);
  await page.waitForTimeout(2500);

  // Catégorie Sandwichs
  try {
    await page.locator('[data-testid="pos-category-tile"]').filter({ hasText: /sandwichs/i }).first().click({ timeout: 8000 });
  } catch (e) { log('cat_click_fail', e.message.slice(0, 120)); }
  await page.waitForTimeout(1200);

  // Tuile produit Cayenne
  try {
    await page.locator('.pos-v5-tile').filter({ has: page.locator('.pos-v5-tile__name', { hasText: /^Cayenne$/ }) }).first().click({ timeout: 8000 });
  } catch (e) { log('tile_click_fail', e.message.slice(0, 160)); }
  await page.waitForTimeout(2000);
  await shot(page, 's1-03-wizard-ouvert.png');
  let w = await wizardText(page);
  log('wizard_opened', { visible: w.visible, excerpt: (w.text || '').slice(0, 500) });

  // Sélections (le wizard single-page affiche viandes/sauces/crudités/suppléments)
  log('pick_mixte', await clickText(page, 'Mixte', '#item-variation-modal'));
  await page.waitForTimeout(500);
  log('pick_algerienne', await clickText(page, '^Alg[ée]rienne$', '#item-variation-modal'));
  await page.waitForTimeout(500);
  // Le bloc Suppléments est un accordéon replié → l'ouvrir d'abord
  log('open_supplements', await clickText(page, 'Suppl[ée]ments', '#item-variation-modal'));
  await page.waitForTimeout(600);
  log('pick_cheddar', await clickText(page, '^Cheddar(\\s|$)', '#item-variation-modal'));
  await page.waitForTimeout(500);
  await shot(page, 's1-04-wizard-selections.png');
  w = await wizardText(page);
  log('wizard_after_picks', (w.text || '').slice(0, 1600));

  // Ajouter au panier (bouton footer)
  log('add_btn', await clickText(page, 'Ajouter', '#item-variation-modal'));
  await page.waitForTimeout(1800);
  await shot(page, 's1-05-panier-1-ligne.png');
  w = await wizardText(page);
  log('wizard_still_open', w.visible);
  if (w.visible) {
    // peut-être une étape suivante (Suivant / Valider)
    log('next_btn', await clickText(page, 'Suivant|Valider|Continuer', '#item-variation-modal'));
    await page.waitForTimeout(1200);
    log('add_btn2', await clickText(page, 'Ajouter', '#item-variation-modal'));
    await page.waitForTimeout(1500);
  }

  // Retour catégories → Frites → Petite Frites
  await clickText(page, 'Toutes les cat[ée]gories');
  await page.waitForTimeout(900);
  try {
    await page.locator('[data-testid="pos-category-tile"]').filter({ hasText: /^Frites/i }).first().click({ timeout: 6000 });
    await page.waitForTimeout(900);
    await page.locator('.pos-v5-tile').filter({ has: page.locator('.pos-v5-tile__name', { hasText: /^Petite Frites$/ }) }).first().click({ timeout: 6000 });
    await page.waitForTimeout(1500);
    const w2 = await wizardText(page);
    if (w2.visible) {
      log('frites_wizard', (w2.text || '').slice(0, 300));
      log('frites_add', await clickText(page, 'Ajouter', '#item-variation-modal'));
      await page.waitForTimeout(1500);
      const w3 = await wizardText(page);
      log('frites_wizard_after_add', w3.visible);
      if (w3.visible) {
        // wizard resté ouvert ? → capturer, fermer via Annuler
        await shot(page, 's1-06b-frites-wizard-bloque.png');
        log('frites_cancel', await clickText(page, '^Annuler$', '#item-variation-modal'));
        await page.waitForTimeout(900);
      }
    } else { log('frites_direct_add', true); }
  } catch (e) { log('frites_fail', e.message.slice(0, 120)); }

  // État DOM : backdrop restant ? bouton pay présent ?
  const domState = await page.evaluate(() => ({
    backdrops: document.querySelectorAll('.modal-backdrop').length,
    modalsOpen: [...document.querySelectorAll('.modal')].filter(m => getComputedStyle(m).display !== 'none').map(m => m.id || m.className.slice(0, 40)),
    payBtn: !!document.querySelector('[data-testid="pos-v5-pay"]'),
    cartLines: document.querySelectorAll('.pos-v5-cart-item').length,
  }));
  log('dom_state', domState);

  await shot(page, 's1-06-panier-2-lignes.png');
  const cart = await page.evaluate(() => {
    const items = [...document.querySelectorAll('.pos-v5-cart-item, [class*="cart-item"]')].map(e => (e.innerText || '').replace(/\s+/g, ' ').slice(0, 250));
    const totalEl = document.querySelector('[data-testid="pos-grand-total"]');
    const ticket = document.querySelector('[class*="ticket"], aside');
    return { items, grandTotal: totalEl ? (totalEl.innerText || '').replace(/\s+/g, ' ') : null, ticketText: ticket ? (ticket.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 1200) : null };
  });
  log('cart', cart);

  // Tag client pour retrouver la commande en DB
  await page.locator('[data-testid="pos-customer-name"]').fill('ZZ-TEST-CAISSIER-S1').catch((e) => log('name_fill_fail', e.message.slice(0, 80)));
  await page.locator('[data-testid="pos-customer-phone"]').fill('0611223344').catch(() => {});
  await page.waitForTimeout(400);

  // ENCAISSER (JS click direct — évite les blocages d'overlay ; l'existence a été loggée avant)
  const payClicked = await page.evaluate(() => {
    const b = document.querySelector('[data-testid="pos-v5-pay"]');
    if (!b) return false;
    b.scrollIntoView({ block: 'center' });
    b.click();
    return (b.innerText || '').trim();
  });
  log('pay_clicked', payClicked);
  await page.waitForTimeout(2000);
  await shot(page, 's1-07-modal-paiement.png');
  const payText = await page.evaluate(() => {
    const m = document.querySelector('#orderpayment');
    return m ? (m.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 1500) : null;
  });
  log('payment_modal', payText);

  // Mode CASH + saisir 20 dans #cashInput (numpad = même input)
  await page.locator('[data-testid="pos-payment-mode-cash"]').click().catch(() => {});
  await page.waitForTimeout(700);
  await page.locator('#cashInput').fill('20').catch((e) => log('cash_fill_fail', e.message.slice(0, 100)));
  await page.waitForTimeout(800);
  const change = await page.evaluate(() => {
    const m = document.querySelector('#orderpayment');
    const chg = m && m.querySelector('.pos-v5-payment-change');
    return {
      changeBlock: chg ? (chg.innerText || '').replace(/\s+/g, ' ') : null,
      cashInputValue: document.querySelector('#cashInput')?.value || null,
      totalShown: (m?.innerText.match(/MONTANT TOTAL\s*([\d,.]+\s*€)/i) || [])[1] || null,
    };
  });
  log('change_display', change);
  await shot(page, 's1-08-cash-20-rendu.png');

  // CONFIRMER
  try { await page.locator('[data-testid="pos-payment-confirm"]').click({ timeout: 6000 }); } catch (e) { log('confirm_fail', e.message.slice(0, 140)); }
  await page.waitForTimeout(5000);
  await shot(page, 's1-09-apres-confirm.png');
  const post = await page.evaluate(() => {
    const pay = document.querySelector('#orderpayment');
    const rec = document.querySelector('#receiptModal');
    const recVisible = rec && getComputedStyle(rec).display !== 'none';
    return {
      payModalOpen: pay ? getComputedStyle(pay).display !== 'none' : null,
      receiptVisible: !!recVisible,
      receiptText: recVisible ? (rec.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 2200) : null,
      cartLines: document.querySelectorAll('.pos-v5-cart-item').length,
    };
  });
  log('post_confirm', post);
  await page.waitForTimeout(1200);
  await shot(page, 's1-10-ticket.png');

  fs.writeFileSync(path.join(OUT, 's1-report.json'), JSON.stringify(R, null, 2));
});
