// D1 VAGUE F — caisse take 2 : gère l'overlay session caisse
import { BASE, boot, quartet } from './_d1-F-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot({ width: 1440, height: 900 });

await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
await page.waitForSelector('input[autocomplete="email"]', { timeout: 15000 });
await page.fill('input[autocomplete="email"]', 'bm.t2admin@lecayenne.fr');
await page.fill('input[type="password"]', '123456');
await page.click('button[type="submit"]');
await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 20000 });
await page.waitForTimeout(2500);

await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);

// Overlay session caisse ?
const overlay = page.locator('[data-testid="cash-session-overlay"]');
if (await overlay.isVisible().catch(() => false)) {
  const ovInfo = await page.evaluate(() => {
    const o = document.querySelector('[data-testid="cash-session-overlay"]');
    return {
      text: o.innerText.replace(/\s+/g, ' ').trim().slice(0, 600),
      buttons: Array.from(o.querySelectorAll('button')).map((b) => b.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean),
      inputs: Array.from(o.querySelectorAll('input')).map((i) => i.placeholder || i.type),
    };
  });
  console.log('OVERLAY:', JSON.stringify(ovInfo));
  await quartet(page, consoleLog, netLog, 'F-04a-caisse-session-overlay');
  // ouvrir la session: remplir le fond de caisse si input, cliquer le bouton "ouvrir"
  const amountInput = overlay.locator('input[type="number"], input[inputmode="decimal"], input[type="text"]').first();
  if (await amountInput.isVisible().catch(() => false)) await amountInput.fill('50').catch(() => {});
  const openBtn = overlay.locator('button', { hasText: /ouvrir|démarrer|commencer/i }).first();
  if (await openBtn.isVisible().catch(() => false)) { await openBtn.click(); await page.waitForTimeout(2500); }
}

// F-04 POS main
await page.waitForSelector('.pos-v5-tile', { timeout: 25000 }).catch(() => {});
await page.waitForTimeout(2000);
const posInfo = await page.evaluate(() => {
  const headerPill = Array.from(document.querySelectorAll('button, a')).map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter((t) => /encaisser/i.test(t)).slice(0, 4);
  const panelHead = Array.from(document.querySelectorAll('*')).map((e) => e.childElementCount === 0 ? e.textContent.replace(/\s+/g, ' ').trim() : '').filter((t) => /À ENCAISSER BORNE|PRÊT À LIVRER/i.test(t)).slice(0, 4);
  return { headerPill, panelHead };
});
console.log('POS:', JSON.stringify(posInfo));
await quartet(page, consoleLog, netLog, 'F-04-caisse-pos');

// F-05 modal encaissement
const encBtn = page.locator('button:has-text("Encaisser")').first();
if (await encBtn.isVisible().catch(() => false)) {
  await encBtn.click({ timeout: 8000 }).catch((e) => console.log('enc click fail', e.message));
  await page.waitForTimeout(2200);
  const modalInfo = await page.evaluate(() => {
    const modal = Array.from(document.querySelectorAll('[role="dialog"], [class*="modal"]')).find((m) => m.offsetHeight > 100 && /Encaisser la commande/i.test(m.innerText));
    if (!modal) return { found: false, anyDialog: document.querySelectorAll('[role="dialog"]').length };
    const r = modal.getBoundingClientRect();
    const confirm = Array.from(modal.querySelectorAll('button')).find((b) => /Confirmer/i.test(b.innerText));
    const cr = confirm ? confirm.getBoundingClientRect() : null;
    const inner = modal.querySelector('[class*="body"], [class*="content"]');
    return {
      found: true,
      modalRect: { top: Math.round(r.top), bottom: Math.round(r.bottom), h: Math.round(r.height) },
      viewportH: window.innerHeight,
      modalOverflowsViewport: r.bottom > window.innerHeight || r.top < 0,
      modalScrolls: inner ? inner.scrollHeight > inner.clientHeight : modal.scrollHeight > modal.clientHeight,
      confirmRect: cr ? { top: Math.round(cr.top), bottom: Math.round(cr.bottom), visible: cr.bottom <= window.innerHeight } : null,
    };
  });
  console.log('MODAL:', JSON.stringify(modalInfo));
  await quartet(page, consoleLog, netLog, 'F-05-caisse-encaissement-modal');
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(800);
} else {
  console.log('WARN: aucun bouton Encaisser');
  await quartet(page, consoleLog, netLog, 'F-05-caisse-encaissement-ABSENT');
}

// F-06 show
await page.goto(BASE + '/admin/pos-orders', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3000);
const showHref = await page.evaluate(() => {
  const a = Array.from(document.querySelectorAll('a[href*="pos-orders/show/"]'))[0];
  return a ? a.getAttribute('href') : null;
});
console.log('show href:', showHref);
if (showHref) {
  await page.goto(showHref.startsWith('http') ? showHref : BASE + showHref, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);
  const showInfo = await page.evaluate(() => {
    const txt = document.body.innerText;
    return {
      numero: (txt.match(/N° Commande:[^\n]+/) || [null])[0],
      totaux: (txt.match(/Sous-Total[\s\S]{0,120}/) || [null])[0],
    };
  });
  console.log('SHOW:', JSON.stringify(showInfo));
  await quartet(page, consoleLog, netLog, 'F-06-caisse-show');
} else {
  await quartet(page, consoleLog, netLog, 'F-06-caisse-show-LISTE-VIDE');
}

await browser.close();
console.log('F-caisse DONE');
