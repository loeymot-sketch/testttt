// D1 VAGUE F — caisse take 3 : F-04 POS main propre + F-05b vrai modal encaissement borne
import { BASE, boot, quartet } from './_d1-F-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot({ width: 1440, height: 900 });

async function login() {
  for (let a = 1; a <= 3; a++) {
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('input[autocomplete="email"]', { timeout: 15000 }).catch(() => {});
    await page.fill('input[autocomplete="email"]', 'bm.t2admin@lecayenne.fr').catch(() => {});
    await page.fill('input[type="password"]', '123456').catch(() => {});
    await page.click('button[type="submit"]').catch(() => {});
    await page.waitForTimeout(4000);
    if (!page.url().includes('/login')) return true;
    console.log('login attempt', a, 'failed, url=', page.url());
    await page.waitForTimeout(3000);
  }
  return false;
}
const ok = await login();
console.log('login ok:', ok);

await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
const tiles = await page.waitForSelector('.pos-v5-tile', { timeout: 30000 }).then(() => true).catch(() => false);
console.log('tiles:', tiles, 'url:', page.url());
if (page.url().includes('/login')) { await login(); await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(4000); }
await page.waitForTimeout(2500);
// fermer overlay session si présent (session déjà ouverte au run 1 → pas d'overlay attendu)
await page.keyboard.press('Escape').catch(() => {});
await page.waitForTimeout(600);
const posInfo = await page.evaluate(() => {
  const pill = Array.from(document.querySelectorAll('button')).map((b) => b.innerText.replace(/\s+/g, ' ').trim()).filter((t) => /À encaisser/i.test(t)).slice(0, 2);
  const heads = Array.from(document.querySelectorAll('h1,h2,h3,h4,h5,strong,span,div')).filter((e) => e.childElementCount === 0).map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter((t) => /À ENCAISSER BORNE|PRÊT À LIVRER/i.test(t)).slice(0, 4);
  return { pill, heads };
});
console.log('POS:', JSON.stringify(posInfo));
await quartet(page, consoleLog, netLog, 'F-04-caisse-pos');

// F-05b : vrai modal d'encaissement — bouton "À encaisser" du header ou ligne du panneau borne
let opened = false;
const panelBtn = page.locator('button:has-text("Encaisser")').first();
if (await panelBtn.isVisible().catch(() => false)) {
  await panelBtn.click({ timeout: 6000 }).catch(() => {});
  await page.waitForTimeout(2000);
  opened = await page.evaluate(() => /Encaisser la commande/i.test(document.body.innerText));
}
if (!opened) {
  // via header pill "À encaisser (n)" → liste → premier Encaisser
  const pill = page.locator('button:has-text("À encaisser")').first();
  if (await pill.isVisible().catch(() => false)) {
    await pill.click().catch(() => {});
    await page.waitForTimeout(1800);
    const enc = page.locator('button:has-text("Encaisser")').first();
    if (await enc.isVisible().catch(() => false)) { await enc.click().catch(() => {}); await page.waitForTimeout(1800); }
    opened = await page.evaluate(() => /Encaisser la commande/i.test(document.body.innerText));
  }
}
const modalInfo = await page.evaluate(() => {
  const modal = Array.from(document.querySelectorAll('[role="dialog"], [class*="modal"], [class*="overlay"]')).find((m) => m.offsetHeight > 150 && /Encaisser la commande/i.test(m.innerText));
  if (!modal) return { found: false, bodyHasEnc: /Encaisser la commande/i.test(document.body.innerText) };
  const r = modal.getBoundingClientRect();
  const confirm = Array.from(modal.querySelectorAll('button')).find((b) => /Confirmer/i.test(b.innerText));
  const cr = confirm ? confirm.getBoundingClientRect() : null;
  return {
    found: true,
    modalRect: { top: Math.round(r.top), bottom: Math.round(r.bottom), h: Math.round(r.height) },
    viewportH: window.innerHeight,
    confirmVisible: cr ? cr.bottom <= window.innerHeight && cr.top >= 0 : null,
    scrolls: modal.scrollHeight > modal.clientHeight,
  };
});
console.log('ENC-MODAL:', JSON.stringify(modalInfo));
await quartet(page, consoleLog, netLog, 'F-05b-caisse-encaissement-vrai-modal');

await browser.close();
console.log('F-caisse3 DONE');
