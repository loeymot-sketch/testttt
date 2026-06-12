// VAGUE A — Commande (b) corrigée: Tacos via wizard [FROZEN observe] viande+sauce+Voir tous → Multi TR
import { boot, login, gotoPos, quartet, cartState } from './_d1-A-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot();

const stepInfo = () => page.evaluate(() => {
  const steps = Array.from(document.querySelectorAll('.wizard-step')).map((s, i) => ({
    i,
    title: s.querySelector('.wizard-step-header, h3, h4, [class*="title"]')?.innerText?.replace(/\s+/g, ' ').trim().slice(0, 60) || s.innerText.split('\n')[0].slice(0, 60),
    options: s.querySelectorAll('.wizard-option').length,
    selected: s.querySelectorAll('.wizard-option.selected, .wizard-option[class*="active"], .wizard-option[aria-checked="true"]').length,
  }));
  return steps;
});

try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1500);

  await page.locator('button.pos-v5-category[aria-label="Tacos"]').first().click().catch(() => {});
  await page.waitForTimeout(1200);
  await page.locator('.pos-v5-tile:has-text("Tacos")').first().click();
  await page.waitForTimeout(2000);
  console.log('STEPS:', JSON.stringify(await stepInfo(), null, 1));

  // --- PROBE: Ajouter au panier SANS sauce (feedback de validation ?) ---
  await page.evaluate(() => document.querySelector('.wizard-option')?.click()); // 1re viande
  await page.waitForTimeout(500);
  await page.evaluate(() => document.querySelector('.wizard-btn-cart')?.click());
  await page.waitForTimeout(1200);
  const feedback = await page.evaluate(() => {
    const wiz = !!document.querySelector('.wizard-btn-cart');
    const toasts = Array.from(document.querySelectorAll('[class*="toast"], [class*="alert"], [role="alert"], [class*="error"]')).map((t) => t.innerText.replace(/\s+/g, ' ').trim().slice(0, 120)).filter(Boolean);
    const shake = Array.from(document.querySelectorAll('[class*="invalid"], [class*="shake"], [class*="missing"]')).length;
    return { wizardStillOpen: wiz, toasts: toasts.slice(0, 6), invalidMarks: shake };
  });
  console.log('INVALID-ADD-FEEDBACK:', JSON.stringify(feedback));
  await quartet(page, consoleLog, netLog, 'b10-invalid-add-no-sauce');

  // --- « Voir tous (+4) » sauces ---
  const before = await page.evaluate(() => document.querySelectorAll('.wizard-option').length);
  await page.evaluate(() => { const b = Array.from(document.querySelectorAll('button')).find((e) => /voir tous/i.test(e.innerText)); b && b.click(); });
  await page.waitForTimeout(800);
  const after = await page.evaluate(() => document.querySelectorAll('.wizard-option').length);
  console.log('VOIR-TOUS sauces: options', before, '->', after);
  await quartet(page, consoleLog, netLog, 'b11-wizard-voir-tous-sauces');

  // --- Sélection sauce (2e step) puis Ajouter ---
  await page.evaluate(() => {
    const sections = document.querySelectorAll('.wizard-section');
    let sauce = null;
    sections.forEach((s) => { if (/choisis ta sauce/i.test(s.innerText.slice(0, 60))) sauce = s; });
    (sauce || sections[1])?.querySelector('.wizard-option')?.click();
  });
  await page.waitForTimeout(600);
  await quartet(page, consoleLog, netLog, 'b12-wizard-viande-sauce-selected');
  await page.evaluate(() => document.querySelector('.wizard-btn-cart')?.click());
  await page.waitForTimeout(1500);
  const cart = await cartState(page);
  console.log('CART-B:', JSON.stringify(cart, null, 1));
  await quartet(page, consoleLog, netLog, 'b13-cart-tacos');

  if (!cart.payLabel) throw new Error('cart still empty after valid wizard add');

  // --- Commander -> Multi-paiement tranche TR ---
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2500);
  const payTotal = await page.locator('.pos-v5-payment-total-value').first().textContent().catch(() => 'ABSENT');
  console.log('PAYMENT-MODAL-TOTAL:', JSON.stringify(payTotal?.trim()));
  await page.locator('[data-testid="pos-payment-mode-multi"]').click();
  await page.waitForTimeout(1000);
  await quartet(page, consoleLog, netLog, 'b14-payment-multi');

  // Ajouter une tranche d'abord
  await page.locator('[data-testid="pos-payment-tranche-add"]').click();
  await page.waitForTimeout(800);
  const trancheInfo = await page.evaluate(() => Array.from(document.querySelectorAll('[data-testid="pos-payment-split-block"] select, #multi select')).map((s) => Array.from(s.options).map((o) => `${o.value}:${o.text.trim()}`)));
  console.log('TRANCHE-SELECT-OPTIONS:', JSON.stringify(trancheInfo));
  await page.evaluate(() => {
    const sel = document.querySelector('[data-testid="pos-payment-split-block"] select, #multi select');
    if (sel) { const opt = Array.from(sel.options).find((o) => /ticket/i.test(o.text)); if (opt) { sel.value = opt.value; sel.dispatchEvent(new Event('change', { bubbles: true })); } }
  });
  await page.waitForTimeout(800);
  const covered = await page.locator('[data-testid="pos-payment-total-covered"]').textContent().catch(() => 'ABSENT');
  const remaining = await page.locator('[data-testid="pos-payment-remaining-due"]').textContent().catch(() => 'ABSENT');
  console.log('MULTI covered:', covered?.trim(), '| remaining:', remaining?.trim());
  // si tranche montant vide -> suggérer/égaliser
  const eq = page.locator('[data-testid="pos-payment-auto-balance"]');
  if (await eq.count()) { await eq.click().catch(() => {}); await page.waitForTimeout(600); }
  const covered2 = await page.locator('[data-testid="pos-payment-total-covered"]').textContent().catch(() => 'ABSENT');
  console.log('MULTI covered after auto-balance:', covered2?.trim());
  await quartet(page, consoleLog, netLog, 'b15-payment-multi-tr');

  const respP = page.waitForResponse((r) => r.request().method() === 'POST' && /api\/admin\/pos\b/.test(r.url()), { timeout: 20000 }).catch(() => null);
  await page.locator('[data-testid="pos-payment-confirm"]').click();
  const resp = await respP;
  if (resp) {
    console.log('ORDER-POST:', resp.status(), resp.url());
    try { const j = await resp.json(); console.log('ORDER-JSON id/serial/total:', j?.data?.id, j?.data?.order_serial_no, j?.data?.total); } catch { try { console.log('ORDER-BODY:', (await resp.text()).slice(0, 500)); } catch {} }
  } else console.log('NO ORDER-POST CAPTURED');
  await page.waitForTimeout(3500);
  await quartet(page, consoleLog, netLog, 'b16-after-confirm');
  const receiptText = await page.evaluate(() => document.querySelector('#print-receipt-client')?.innerText || 'ABSENT');
  console.log('=== RECEIPT B ===\n' + receiptText.slice(0, 2500));
  await quartet(page, consoleLog, netLog, 'b17-receipt-tr');
} finally {
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 15)));
  await browser.close();
}
