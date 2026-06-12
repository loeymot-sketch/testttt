// D2 VAGUE F — take 3: re-capture F2-08 show + F2-09 cash-overview (session perdue au take 2)
import { BASE, boot, quartet } from './_d2-F-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot({ width: 1440, height: 900 });

await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
await page.waitForSelector('input[autocomplete="email"]', { timeout: 15000 });
await page.fill('input[autocomplete="email"]', 'bm.t2admin@lecayenne.fr');
await page.fill('input[type="password"]', '123456');
await page.click('button[type="submit"]');
await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 20000 });
await page.waitForTimeout(2500);

// F2-08 show
await page.goto(BASE + '/admin/pos-orders', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);
const showHref = await page.evaluate(() => document.querySelector('a[href*="pos-orders/show/"]')?.getAttribute('href') ?? null);
console.log('show href:', showHref);
if (showHref) {
  await page.goto(showHref.startsWith('http') ? showHref : BASE + showHref, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3200);
  const showProbe = await page.evaluate(() => {
    const txt = document.body.innerText;
    return {
      numeroLigne: (txt.match(/N° Commande:[^\n]+/) || [null])[0],
      refInterne: (txt.match(/Référence interne[^\n]+/) || [null])[0],
      instruction: (txt.match(/Instruction:[^\n]+/) || [null])[0],
      statut: (txt.match(/Statut[^\n]+/) || [null])[0],
      boutons: Array.from(document.querySelectorAll('button, a.btn')).map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter((t) => t && t.length < 40).slice(0, 14),
    };
  });
  console.log('SHOW:', JSON.stringify(showProbe, null, 1));
  await quartet(page, consoleLog, netLog, 'F2-08-caisse-show');
}

// F2-09 cash-overview
await page.goto(BASE + '/admin/cash-overview', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(4000);
const coProbe = await page.evaluate(() => {
  const txt = document.body.innerText.replace(/\s+/g, ' ');
  return {
    grandTotal: (txt.match(/GRAND TOTAL[^€]*€[^€]{0,30}/i) || [null])[0],
    reconciliation: (txt.match(/session en cours[^€]*€/i) || [null])[0],
    attendues: (txt.match(/attendues[^€]*€/i) || [null])[0],
    aVenir: /\(à venir\)/i.test(txt),
    caisse: (txt.match(/CAISSE[^€]*€[^€]{0,20}/i) || [null])[0],
  };
});
console.log('CASH-OVERVIEW:', JSON.stringify(coProbe, null, 1));
await quartet(page, consoleLog, netLog, 'F2-09-caisse-cash-overview');

await browser.close();
console.log('F2-caisse3 DONE');
