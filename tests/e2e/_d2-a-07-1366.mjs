// R2 VAGUE A — capture POS 1366×768 modal paiement (constat seulement — gate A-RED-12 frozen)
import { boot, login, gotoPos, quartet, addItem } from './_d2-a-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot({ width: 1366, height: 768 });
try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1200);
  await addItem(page, 'Tacos', 'Tacos', ['Poulet mariné', 'Algérienne', 'Menu']);
  await page.waitForTimeout(800);
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2000);
  await page.locator('[data-testid="pos-payment-mode-cash"]').click();
  await page.waitForTimeout(700);
  await page.fill('#cashInput', '10');
  await page.waitForTimeout(500);
  // mesure visibilité CTA confirm dans le viewport
  const geo = await page.evaluate(() => {
    const btn = document.querySelector('[data-testid="pos-payment-confirm"]');
    const modal = document.querySelector('.pos-v5-payment-modal');
    if (!btn) return { btn: 'ABSENT' };
    const r = btn.getBoundingClientRect();
    const mr = modal?.getBoundingClientRect();
    return { ctaBottom: Math.round(r.bottom), ctaTop: Math.round(r.top), vh: window.innerHeight, ctaBelowFold: r.bottom > window.innerHeight, modalHeight: mr ? Math.round(mr.height) : null };
  });
  console.log('1366x768 CTA-GEO:', JSON.stringify(geo));
  await quartet(page, consoleLog, netLog, '1366-01-paymodal-cash');
} finally {
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 10)));
  await browser.close();
}
