// ADVERSARIAL repro A-RED-1 : remise 10% + Espèces -> POST order ? + comportement caissier visible
import { boot, login, gotoPos, cartState } from './_d1-A-lib.mjs';
import path from 'path';

const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-1/A-caisse-vente/', import.meta.url).pathname;
const { browser, page, netLog } = await boot();

const quoteMeta = [];
page.on('response', async (r) => {
  if (r.url().includes('/api/admin/pos/quote')) {
    const h = r.headers();
    quoteMeta.push({ status: r.status(), limit: h['x-ratelimit-limit'] || null, remaining: h['x-ratelimit-remaining'] || null });
  }
});

async function completeWizardIfAny() {
  for (let round = 0; round < 3; round++) {
    await page.waitForTimeout(900);
    if (!(await page.evaluate(() => !!document.querySelector('.pos-wizard')))) return;
    await page.evaluate(() => {
      document.querySelectorAll('.wizard-section').forEach((s) => {
        if (/obligatoire/i.test(s.innerText.slice(0, 200)) && !s.querySelector('.wizard-option.selected, .wizard-option[class*="active"]')) s.querySelector('.wizard-option')?.click();
      });
    });
    await page.waitForTimeout(500);
    await page.evaluate(() => document.querySelector('.wizard-btn-cart')?.click());
  }
}
async function addItem(cat, tile) {
  await page.locator(`button.pos-v5-category[aria-label="${cat}"]`).first().click().catch(() => {});
  await page.waitForTimeout(1200);
  await page.locator(`.pos-v5-tile:has-text("${tile}")`).first().click();
  await completeWizardIfAny();
  await page.waitForTimeout(800);
}

try {
  await login(page);
  await gotoPos(page);
  await addItem('Desserts', 'Tiramisu');
  await addItem('Frites', 'Grande Frites');
  console.log('CART:', JSON.stringify(await cartState(page)));

  // zoom panneau remise AVANT application (lisibilité APPLIQUER / (obligatoire))
  const panel = page.locator('[data-testid="pos-discount-input"]').first();
  await panel.scrollIntoViewIfNeeded().catch(() => {});
  const discountBox = await page.evaluate(() => {
    const el = document.querySelector('[data-testid="pos-discount-input"]')?.closest('div')?.parentElement;
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { x: Math.max(0, r.x - 8), y: Math.max(0, r.y - 8), width: Math.min(560, r.width + 16), height: Math.min(260, r.height + 16) };
  });
  if (discountBox) await page.screenshot({ path: path.join(OUT, '_red01-discount-panel.png'), clip: discountBox });
  const discountPanelText = await page.evaluate(() => document.querySelector('[data-testid="pos-discount-input"]')?.closest('div')?.parentElement?.innerText?.replace(/\n/g, ' | '));
  console.log('DISCOUNT-PANEL-TEXT:', JSON.stringify(discountPanelText));

  await page.fill('[data-testid="pos-discount-input"]', '10');
  await page.fill('[data-testid="pos-discount-reason"]', 'repro adversarial R1');
  await page.waitForTimeout(400);
  await page.locator('[data-testid="pos-discount-apply"]').click();
  await page.waitForTimeout(1200);
  console.log('CART après remise:', JSON.stringify((await cartState(page)).totals));

  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2500);
  await page.fill('#cashInput', '10');
  await page.waitForTimeout(400);

  const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/api/admin/pos'), { timeout: 20000 }).catch(() => null);
  await page.locator('[data-testid="pos-payment-confirm"]').click();

  // sonde toasts/alertes pendant 6s (qu'est-ce que le caissier VOIT ?)
  const seen = new Set();
  const probe = (async () => {
    for (let i = 0; i < 30; i++) {
      const msgs = await page.evaluate(() =>
        Array.from(document.querySelectorAll('[role=alert], .toast, .toastify, [class*="toast"], .swal2-popup, .alert')).map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean)
      ).catch(() => []);
      msgs.forEach((m) => seen.add(m.slice(0, 200)));
      await page.waitForTimeout(200);
    }
  })();
  const resp = await respP;
  if (resp) {
    console.log('ORDER-POST:', resp.status(), '| body:', (await resp.text().catch(() => '')).slice(0, 300));
  } else console.log('ORDER-POST: AUCUN POST CAPTURÉ');
  await probe;
  console.log('ALERTES VISIBLES pendant 6s:', JSON.stringify([...seen]));
  console.log('URL après confirm:', page.url());
  await page.screenshot({ path: path.join(OUT, '_red01-after-confirm.png') });

  // état panier après re-login
  await login(page).catch((e) => console.log('relogin fail', e.message));
  await gotoPos(page);
  const after = await cartState(page);
  console.log('CART après re-login:', JSON.stringify(after.items), '| pay:', after.payLabel);
  await page.screenshot({ path: path.join(OUT, '_red01-cart-after-relogin.png') });

  console.log('QUOTE-META:', JSON.stringify(quoteMeta));
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 12)));
} finally {
  await browser.close();
}
