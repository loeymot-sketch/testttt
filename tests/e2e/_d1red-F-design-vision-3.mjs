// ADVERSAIRE D1 round-1 vague F — hit-test des touches keypad sous les boutons (modal encaissement)
import { BASE, boot, quartet } from './_d1-F-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot({ width: 1440, height: 900 });

await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
await page.waitForSelector('input[autocomplete="email"]', { timeout: 15000 });
await page.fill('input[autocomplete="email"]', 'bm.t2admin@lecayenne.fr');
await page.fill('input[type="password"]', '123456');
await page.click('button[type="submit"]');
await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 20000 });
await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(4000);
await page.locator('[data-testid="cash-session-close"]').click().catch(() => {});
await page.locator('[data-testid^="pos-shortcut-encaisser-"]').first().click();
await page.waitForTimeout(2500);

const hit = await page.evaluate(() => {
  const dialogs = Array.from(document.querySelectorAll('[role="dialog"], [class*="modal"]'));
  const modal = dialogs.find((d) => /MONTANT|PAIEMENT/i.test(d.innerText) && d.getBoundingClientRect().height > 100);
  if (!modal) return { found: false };
  const keys = Array.from(modal.querySelectorAll('button')).filter((b) => /^[0-9]$|^00$|^,$/.test(b.innerText.trim()));
  const res = keys.map((k) => {
    const r = k.getBoundingClientRect();
    const cx = Math.round(r.left + r.width / 2), cy = Math.round(r.top + r.height / 2);
    const el = document.elementFromPoint(cx, cy);
    const hitsKey = el === k || k.contains(el);
    let interceptor = null;
    if (!hitsKey && el) interceptor = (el.closest('button')?.innerText || el.className || el.tagName).toString().replace(/\s+/g, ' ').slice(0, 50);
    return { key: k.innerText.trim(), cx, cy, clickable: hitsKey, interceptor };
  });
  // scroll state du conteneur
  const cc = modal.querySelector('.cc-modal') || modal;
  return {
    found: true,
    keys: res,
    blocked: res.filter((x) => !x.clickable).map((x) => x.key + '←' + x.interceptor),
    scrollTop: cc.scrollTop, scrollHeight: cc.scrollHeight, clientHeight: cc.clientHeight,
  };
});
console.log('HIT-TEST:', JSON.stringify(hit, null, 1));

// tenter de taper 7 via clic réel playwright (auto-scroll par actionability) puis relire le champ
const before = await page.evaluate(() => document.querySelector('[class*="modal"] input')?.value ?? null);
let clickErr = null;
try { await page.locator('button:text-is("7")').first().click({ timeout: 4000 }); } catch (e) { clickErr = e.message.slice(0, 120); }
await page.waitForTimeout(600);
const after = await page.evaluate(() => document.querySelector('[class*="modal"] input')?.value ?? null);
console.log('TYPE-7:', JSON.stringify({ before, after, clickErr }));
await quartet(page, consoleLog, netLog, 'RED-F-encaisse-keypad-hittest');

await browser.close();
console.log('RED F live 3 DONE');
