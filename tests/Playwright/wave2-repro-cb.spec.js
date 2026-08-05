// WAVE2 REPRO 2 — 2026-08-05 — « Le paiement carte bleue n'est pas fonctionnel à la caisse »
// Agent de REPRODUCTION : aucun code applicatif modifié. Spec jetable.
// Commandes de test taguées ZZ-WAVE2-CB pour nettoyage éventuel.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-8axes-2026-08-05/wave2');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: false }).catch(() => {});

test.setTimeout(240_000);

async function addFritesToCart(page) {
  await page.evaluate(() => {
    const els = [...document.querySelectorAll('button, div, span, a')];
    const b = els.find((e) => /toutes les cat/i.test((e.innerText || '').trim()) && (e.innerText || '').length < 40);
    if (b) b.click();
  });
  await page.waitForTimeout(800);
  await page.locator('[data-testid="pos-category-tile"]').filter({ hasText: /^Frites/i }).first().click({ timeout: 8000 });
  await page.waitForTimeout(900);
  await page.locator('.pos-v5-tile').filter({ has: page.locator('.pos-v5-tile__name', { hasText: /^Petite Frites$/ }) }).first().click({ timeout: 8000 });
  await page.waitForTimeout(1500);
  const w = await page.evaluate(() => {
    const m = document.querySelector('#item-variation-modal');
    return m && getComputedStyle(m).display !== 'none';
  });
  if (w) {
    await page.evaluate(() => {
      const m = document.querySelector('#item-variation-modal');
      const btn = [...m.querySelectorAll('button')].find((b) => /ajouter/i.test(b.innerText || ''));
      if (btn) btn.click();
    });
    await page.waitForTimeout(1500);
  }
  return await page.locator('.pos-v5-cart-item').count();
}

function modalState(page) {
  return page.evaluate(() => {
    const m = document.querySelector('#orderpayment');
    const visible = m && getComputedStyle(m).display !== 'none';
    const sel = m?.querySelector('[data-testid="pos-payment-terminal-select"]');
    const confirm = m?.querySelector('[data-testid="pos-payment-confirm"]');
    return {
      modalVisible: !!visible,
      tabs: ['cash', 'card', 'multi'].map((k) => {
        const b = m?.querySelector(`[data-testid="pos-payment-mode-${k}"]`);
        return b ? { k, present: true, text: (b.innerText || '').trim().slice(0, 30), cls: String(b.className).slice(0, 90) } : { k, present: false };
      }),
      terminalSelect: sel ? {
        present: true,
        value: sel.value,
        options: [...sel.options].map((o) => ({ v: o.value, t: o.text.trim(), disabled: o.disabled, selected: o.selected })),
      } : { present: false },
      confirmBtn: confirm ? {
        present: true,
        disabled: confirm.disabled,
        ariaDisabled: confirm.getAttribute('aria-disabled'),
        text: (confirm.innerText || '').trim().slice(0, 60),
      } : { present: false },
      modalText: visible ? (m.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 1800) : null,
    };
  });
}

test('REPRO2 — paiement CARTE + onglet MULTI à la caisse', async ({ page }) => {
  const R = { network: [], consoleErrors: [] };
  const log = (k, v) => { R[k] = v; console.log('R2|' + k + '|' + JSON.stringify(v)); };

  page.on('console', (msg) => {
    if (msg.type() === 'error') R.consoleErrors.push(msg.text().slice(0, 300));
  });
  page.on('response', async (res) => {
    const u = res.url();
    if (res.request().method() === 'POST' && /\/(pos|admin)\//.test(u)) {
      let body = null;
      try { body = (await res.text()).slice(0, 1200); } catch (e) { body = 'unreadable'; }
      R.network.push({ url: u.replace(/^https?:\/\/[^/]+/, ''), status: res.status(), body });
      console.log('R2|POST|' + res.status() + '|' + u);
    }
  });

  await loginAsPosOperator(page);
  await page.waitForTimeout(2500);

  const lines = await addFritesToCart(page);
  log('cart_lines', lines);
  await shot(page, 'repro2-01-panier.png');

  // Tag client pour retrouver la commande
  await page.locator('[data-testid="pos-customer-name"]').fill('ZZ-WAVE2-CB').catch(() => {});

  // Ouvrir le checkout (ENCAISSER)
  await page.evaluate(() => {
    const b = document.querySelector('[data-testid="pos-v5-pay"]');
    if (b) { b.scrollIntoView({ block: 'center' }); b.click(); }
  });
  await page.waitForTimeout(2500);
  log('modal_initial', await modalState(page));
  await shot(page, 'repro2-02-modal-paiement.png');

  // === Onglet CARTE ===
  await page.locator('[data-testid="pos-payment-mode-card"]').click().catch((e) => log('card_tab_fail', e.message.slice(0, 120)));
  await page.waitForTimeout(1500);
  const cardState = await modalState(page);
  log('card_state', cardState);
  await shot(page, 'repro2-03-onglet-carte.png');

  // Tenter la confirmation telle quelle (sans toucher au dropdown — repro du geste caissier)
  await page.evaluate(() => {
    const b = document.querySelector('[data-testid="pos-payment-confirm"]');
    if (b) { b.scrollIntoView({ block: 'center' }); b.click(); }
  });
  await page.waitForTimeout(900);
  await shot(page, 'repro2-03b-toast-immediat.png');
  const earlyToasts = await page.evaluate(() =>
    [...document.querySelectorAll('[class*="toast"], [class*="alert"], [role="alert"], [class*="notification"], [class*="iziToast"]')]
      .map((t) => (t.innerText || '').replace(/\s+/g, ' ').trim()).filter(Boolean).slice(0, 6));
  log('early_toasts', earlyToasts);
  await page.waitForTimeout(4100);
  await shot(page, 'repro2-04-apres-confirm-carte.png');
  const afterCard = await page.evaluate(() => {
    const pay = document.querySelector('#orderpayment');
    const rec = document.querySelector('#receiptModal');
    const toasts = [...document.querySelectorAll('[class*="toast"], [class*="alert"], [role="alert"]')]
      .map((t) => (t.innerText || '').replace(/\s+/g, ' ').trim()).filter(Boolean).slice(0, 5);
    return {
      payModalOpen: pay ? getComputedStyle(pay).display !== 'none' : null,
      receiptVisible: !!(rec && getComputedStyle(rec).display !== 'none'),
      receiptExcerpt: rec && getComputedStyle(rec).display !== 'none' ? (rec.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 900) : null,
      toasts,
      cartLines: document.querySelectorAll('.pos-v5-cart-item').length,
    };
  });
  log('after_card_confirm', afterCard);
  await shot(page, 'repro2-05-resultat-carte.png');

  // Contre-épreuve : saisir les 4 chiffres puis re-confirmer → passe-t-il ?
  if (afterCard.payModalOpen) {
    await page.evaluate(() => {
      const i = document.querySelector('#cardInput');
      if (i) {
        i.value = '1234';
        i.dispatchEvent(new Event('input', { bubbles: true }));
        i.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
    await page.waitForTimeout(600);
    await shot(page, 'repro2-05b-carte-4-chiffres.png');
    await page.evaluate(() => document.querySelector('[data-testid="pos-payment-confirm"]')?.click());
    await page.waitForTimeout(5000);
    await shot(page, 'repro2-05c-carte-apres-confirm-avec-chiffres.png');
    const retry = await page.evaluate(() => {
      const pay = document.querySelector('#orderpayment');
      const rec = document.querySelector('#receiptModal');
      const recVisible = rec && getComputedStyle(rec).display !== 'none';
      return {
        payModalOpen: pay ? getComputedStyle(pay).display !== 'none' : null,
        receiptVisible: !!recVisible,
        receiptExcerpt: recVisible ? (rec.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 600) : null,
        cartLines: document.querySelectorAll('.pos-v5-cart-item').length,
      };
    });
    log('card_retry_with_digits', retry);
  }

  // Fermer le ticket éventuel pour enchaîner sur MULTI
  await page.evaluate(() => {
    const rec = document.querySelector('#receiptModal');
    if (rec && getComputedStyle(rec).display !== 'none') {
      const close = [...rec.querySelectorAll('button')].find((b) => /fermer|nouvelle|close|ok/i.test(b.innerText || '')) || rec.querySelector('button');
      if (close) close.click();
    }
  });
  await page.waitForTimeout(1500);

  // === Onglet MULTI (2 tranches carte + espèces) ===
  const lines2 = await addFritesToCart(page).catch(() => 0);
  log('cart_lines_multi', lines2);
  await page.locator('[data-testid="pos-customer-name"]').fill('ZZ-WAVE2-CB-MULTI').catch(() => {});
  await page.evaluate(() => document.querySelector('[data-testid="pos-v5-pay"]')?.click());
  await page.waitForTimeout(2500);

  const multiTab = await page.locator('[data-testid="pos-payment-mode-multi"]').count();
  log('multi_tab_present', multiTab);
  if (multiTab > 0) {
    await page.locator('[data-testid="pos-payment-mode-multi"]').click().catch((e) => log('multi_tab_fail', e.message.slice(0, 120)));
    await page.waitForTimeout(1500);
    await shot(page, 'repro2-06-onglet-multi.png');
    log('multi_state', await modalState(page));
    // Structure des tranches
    const tranches = await page.evaluate(() => {
      const m = document.querySelector('#orderpayment');
      const rows = [...(m?.querySelectorAll('[data-testid="pos-payment-split-block"] [class*="tranche"], .pos-v5-tranche-row') || [])];
      return rows.map((r) => (r.innerText || '').replace(/\s+/g, ' ').slice(0, 200));
    });
    log('tranches_initial', tranches);
    // Répartir également en 2 puis auto-équilibrer si dispo
    await page.locator('[data-testid="pos-payment-split-equal"]').click().catch(() => {});
    await page.waitForTimeout(900);
    await page.locator('[data-testid="pos-payment-auto-balance"]').click().catch(() => {});
    await page.waitForTimeout(900);
    // Passer la 2e tranche en carte si un sélecteur de méthode existe
    await page.evaluate(() => {
      const m = document.querySelector('#orderpayment');
      const selects = [...(m?.querySelectorAll('select') || [])].filter((s) => [...s.options].some((o) => /carte|card/i.test(o.text)));
      const target = selects[selects.length - 1];
      if (target) {
        const opt = [...target.options].find((o) => /carte|card/i.test(o.text));
        if (opt) { target.value = opt.value; target.dispatchEvent(new Event('change', { bubbles: true })); }
      }
    });
    await page.waitForTimeout(900);
    await shot(page, 'repro2-07-multi-2-tranches.png');
    log('multi_state_ready', await modalState(page));
    await page.evaluate(() => document.querySelector('[data-testid="pos-payment-confirm"]')?.click());
    await page.waitForTimeout(5000);
    await shot(page, 'repro2-08-multi-apres-confirm.png');
    const afterMulti = await page.evaluate(() => {
      const pay = document.querySelector('#orderpayment');
      const rec = document.querySelector('#receiptModal');
      const toasts = [...document.querySelectorAll('[class*="toast"], [class*="alert"], [role="alert"]')]
        .map((t) => (t.innerText || '').replace(/\s+/g, ' ').trim()).filter(Boolean).slice(0, 5);
      return {
        payModalOpen: pay ? getComputedStyle(pay).display !== 'none' : null,
        receiptVisible: !!(rec && getComputedStyle(rec).display !== 'none'),
        toasts,
      };
    });
    log('after_multi_confirm', afterMulti);
  }

  fs.writeFileSync(path.join(OUT, 'repro2-report.json'), JSON.stringify(R, null, 2));
});
