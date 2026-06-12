// D1 VAGUE F — captures fraîches caisse: POS / encaissement modal / show (1440×900)
import { BASE, boot, quartet } from './_d1-F-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot({ width: 1440, height: 900 });

// login caisse
await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
await page.waitForSelector('input[autocomplete="email"]', { timeout: 15000 });
await page.fill('input[autocomplete="email"]', 'bm.t2admin@lecayenne.fr');
await page.fill('input[type="password"]', '123456');
await page.click('button[type="submit"]');
await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 20000 });
await page.waitForTimeout(2500);

// F-04 POS main
await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
await page.waitForSelector('.pos-v5-tile', { timeout: 25000 }).catch(() => {});
await page.waitForTimeout(2500);
const posInfo = await page.evaluate(() => {
  const txt = (s) => document.querySelector(s)?.innerText?.replace(/\s+/g, ' ')?.trim() ?? null;
  const all = (s) => Array.from(document.querySelectorAll(s)).map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 90));
  const headerPill = Array.from(document.querySelectorAll('button, a')).map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter((t) => /encaisser/i.test(t)).slice(0, 4);
  const panelHead = Array.from(document.querySelectorAll('h1,h2,h3,h4,[class*="panel"] [class*="title"], [class*="head"]')).map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter((t) => /ENCAISSER|LIVRER/i.test(t)).slice(0, 6);
  return { headerPill, panelHead, categories: all('.pos-v5-category').slice(0, 14) };
});
console.log('POS:', JSON.stringify(posInfo));
await quartet(page, consoleLog, netLog, 'F-04-caisse-pos');

// F-05 encaissement modal — clic premier "Encaisser" du panneau borne
const encBtn = page.locator('button:has-text("Encaisser")').first();
if (await encBtn.isVisible().catch(() => false)) {
  await encBtn.click();
  await page.waitForTimeout(2000);
  const modalInfo = await page.evaluate(() => {
    const modal = document.querySelector('[class*="modal"]:not([style*="display: none"]), [role="dialog"]');
    if (!modal) return { found: false };
    const r = modal.getBoundingClientRect();
    const confirm = Array.from(modal.querySelectorAll('button')).find((b) => /Confirmer/i.test(b.innerText));
    const cr = confirm ? confirm.getBoundingClientRect() : null;
    return {
      found: true,
      modalRect: { top: Math.round(r.top), bottom: Math.round(r.bottom), h: Math.round(r.height) },
      viewportH: window.innerHeight,
      overflows: r.bottom > window.innerHeight,
      confirmRect: cr ? { top: Math.round(cr.top), bottom: Math.round(cr.bottom) } : null,
      tiles: Array.from(modal.querySelectorAll('[class*="tile"], [class*="method"], label')).map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 70)).filter(Boolean).slice(0, 8),
    };
  });
  console.log('MODAL:', JSON.stringify(modalInfo));
  await quartet(page, consoleLog, netLog, 'F-05-caisse-encaissement-modal');
} else {
  console.log('WARN: aucun bouton Encaisser visible');
  await quartet(page, consoleLog, netLog, 'F-05-caisse-encaissement-ABSENT');
}

// F-06 admin show — première commande de la liste
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
    const m = (re) => (txt.match(re) || [null])[0];
    return {
      numero: m(/N° Commande:[^\n]+/),
      sousTotal: m(/Sous-Total[\s\S]{0,30}?€/),
      total: m(/Total[\s\S]{0,20}?€/),
      boutons: Array.from(document.querySelectorAll('button, a.btn, [class*="btn"]')).map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter((t) => t && t.length < 40).slice(0, 14),
    };
  });
  console.log('SHOW:', JSON.stringify(showInfo));
  await quartet(page, consoleLog, netLog, 'F-06-caisse-show');
} else {
  await quartet(page, consoleLog, netLog, 'F-06-caisse-show-LISTE-VIDE');
}

await browser.close();
console.log('F-caisse DONE');
