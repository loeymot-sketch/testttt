// META-DISPUTE V-4/V-5 — modal encaissement à 1366×768 + datepicker cash-sessions-report — jetable
import { chromium } from 'playwright';
import fs from 'fs';

const BASE = 'http://127.0.0.1:8768';
const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-1/meta/', import.meta.url).pathname;
fs.mkdirSync(OUT, { recursive: true });
const lines = [];
const L = (m) => { const s = `[${new Date().toISOString().slice(11, 19)}] ${m}`; lines.push(s); console.log(s); };

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const context = await browser.newContext({ viewport: { width: 1366, height: 768 }, locale: 'fr-FR' });
const page = await context.newPage();

async function login(email) {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('input[autocomplete="email"]', { timeout: 15000 });
  await page.fill('input[autocomplete="email"]', email);
  await page.fill('input[type="password"]', '123456');
  await page.click('button[type="submit"]');
  await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 20000 });
  await page.waitForTimeout(2500);
}
await login('bm.t2admin@lecayenne.fr');
L(`login OK → ${page.url().replace(BASE, '')}`);

// V-4 : POS 1366×768 → modal encaissement borne → mesure CTA
await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3000);
for (let i = 0; i < 3; i++) {
  const overlay = page.locator('[data-testid="cash-session-overlay"]');
  if (!(await overlay.isVisible().catch(() => false))) break;
  // si une session existe le dialog se ferme via ✕ ; sinon ouvrir la caisse
  const closed = await page.locator('[data-testid="cash-session-close"]').click().then(() => true).catch(() => false);
  await page.waitForTimeout(1200);
  if (!closed) break;
}
// si l'overlay bloque encore (aucune session), ouvrir une session 50€
if (await page.locator('[data-testid="cash-session-overlay"]').isVisible().catch(() => false)) {
  L('overlay session toujours présent → tentative ouverture session');
  await page.locator('button:has-text("Ouvrir la caisse")').last().click().catch(() => {});
  await page.waitForTimeout(2500);
  await page.locator('[data-testid="cash-session-close"]').click().catch(() => {});
  await page.waitForTimeout(1200);
}
const shortcut = page.locator('[data-testid^="pos-shortcut-encaisser-"]').first();
if (await shortcut.isVisible().catch(() => false)) { await shortcut.click(); }
else {
  L('WARN shortcut testid absent → fallback bouton Encaisser');
  await page.locator('button:has-text("Encaisser")').first().click().catch(() => L('WARN aucun bouton Encaisser'));
}
await page.waitForTimeout(2500);
const modalInfo = await page.evaluate(() => {
  const btns = Array.from(document.querySelectorAll('div[class*="modal"] button, [role="dialog"] button'))
    .filter((b) => b.textContent.trim().length > 0)
    .map((b) => {
      const r = b.getBoundingClientRect();
      const visible = b.offsetParent !== null && r.height > 0;
      return { text: b.textContent.trim().slice(0, 44), top: Math.round(r.top), bottom: Math.round(r.bottom), visible };
    });
  return { btns: btns.slice(0, 20), vh: window.innerHeight, vw: window.innerWidth };
});
L(`V-4 modal @${modalInfo.vw}x${modalInfo.vh}:`);
modalInfo.btns.forEach((b) => L(`  btn "${b.text}" top=${b.top} bottom=${b.bottom} ${!b.visible ? 'NON-RENDU' : b.bottom <= modalInfo.vh && b.top >= 0 ? 'VISIBLE' : 'HORS-VIEWPORT'}`));
const cta = modalInfo.btns.find((b) => /Confirmer/i.test(b.text));
L(`V-4 CTA Confirmer: ${cta ? JSON.stringify(cta) : 'INTROUVABLE'} (viewport ${modalInfo.vh}px)`);
await page.screenshot({ path: `${OUT}meta-V4-modal-768.png` });
fs.writeFileSync(`${OUT}_V4-modal-768.json`, JSON.stringify(modalInfo, null, 2));
// fermer le modal sans encaisser (READ-ONLY sur ce point)
await page.keyboard.press('Escape').catch(() => {});
await page.waitForTimeout(800);

// V-5 : datepicker cash-sessions-report FR
await page.goto(BASE + '/admin/cash-sessions-report', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3000);
const dateInput = page.locator('.dp__input, input[placeholder*="date" i], input[type="date"], input[class*="date"]').first();
let dpText = '(champ date non trouvé)';
if (await dateInput.isVisible().catch(() => false)) {
  const nativeType = await dateInput.getAttribute('type').catch(() => null);
  L(`V-5 input type=${nativeType} class=${await dateInput.getAttribute('class').catch(() => '')}`);
  await dateInput.click();
  await page.waitForTimeout(1200);
  dpText = await page.evaluate(() => {
    const dp = document.querySelector('.dp__menu, .flatpickr-calendar.open, .datepicker, [class*="calendar"]');
    return dp ? dp.innerText.replace(/\n/g, ' ').slice(0, 300) : '(datepicker overlay non détecté)';
  });
}
L(`V-5 datepicker cash-sessions-report: "${dpText}"`);
const isFR = /lu\s+ma\s+me\s+je\s+ve\s+sa\s+di|janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre/i.test(dpText);
L(`V-5 verdict FR=${isFR}`);
await page.screenshot({ path: `${OUT}meta-V5-datepicker-sessions.png` });

fs.writeFileSync(`${OUT}_V45-log.txt`, lines.join('\n') + '\n');
await browser.close();
