// ADVERSAIRE D1 round-1 vague F — re-vérification LIVE modal encaissement (f10 claim « modal déborde, à confirmer frais » jamais confirmé par le GStack)
import { BASE, boot, quartet } from './_d1-F-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot({ width: 1440, height: 900 });

await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
await page.waitForSelector('input[autocomplete="email"]', { timeout: 15000 });
await page.fill('input[autocomplete="email"]', 'bm.t2admin@lecayenne.fr');
await page.fill('input[type="password"]', '123456');
await page.click('button[type="submit"]');
await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 20000 });
await page.waitForTimeout(2000);

await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(4000);

// fermer overlay session si présent
const goOn = page.locator('[data-testid="cash-session-open-submit"], [data-testid="cash-session-close"]');
if (await goOn.first().isVisible().catch(() => false)) {
  await page.locator('[data-testid="cash-session-close"]').click().catch(() => {});
  await page.waitForTimeout(1000);
}

// ouvrir le modal d'encaissement via le bouton Encaisser du PANNEAU borne
const btn = page.locator('[data-testid^="pos-shortcut-encaisser-"]').first();
if (!(await btn.isVisible().catch(() => false))) {
  console.log('FAIL: aucun bouton pos-shortcut-encaisser visible');
  await quartet(page, consoleLog, netLog, 'RED-F-encaisse-modal-ABSENT');
  await browser.close();
  process.exit(0);
}
await btn.click();
await page.waitForTimeout(2500);

const info = await page.evaluate(() => {
  // le modal d'encaissement = celui qui contient "MONTANT REÇU" ou "CHOISIR LE PAIEMENT"
  const dialogs = Array.from(document.querySelectorAll('[role="dialog"], [class*="modal"]'));
  const modal = dialogs.find((d) => /MONTANT|PAIEMENT/i.test(d.innerText) && d.getBoundingClientRect().height > 100);
  if (!modal) return { found: false, dialogCount: dialogs.length };
  const mr = modal.getBoundingClientRect();
  const keys = Array.from(modal.querySelectorAll('button')).filter((b) => /^[0-9]$|^00$|^,$|^\.$/.test(b.innerText.trim()));
  const keyInfo = keys.map((k) => { const r = k.getBoundingClientRect(); return { key: k.innerText.trim(), top: Math.round(r.top), bottom: Math.round(r.bottom), visibleInViewport: r.bottom <= innerHeight && r.top >= 0 }; });
  const confirm = Array.from(modal.querySelectorAll('button')).find((b) => /Confirmer/i.test(b.innerText));
  const cr = confirm ? confirm.getBoundingClientRect() : null;
  // scrollabilité du conteneur
  let scroller = modal; let scrollable = false;
  const nodes = [modal, ...modal.querySelectorAll('*')];
  for (const n of nodes) { if (n.scrollHeight > n.clientHeight + 8 && /(auto|scroll)/.test(getComputedStyle(n).overflowY)) { scroller = n; scrollable = true; break; } }
  return {
    found: true,
    viewportH: innerHeight,
    modalRect: { top: Math.round(mr.top), bottom: Math.round(mr.bottom), h: Math.round(mr.height) },
    modalOverflowsViewport: mr.bottom > innerHeight || mr.top < 0,
    scrollable,
    scrollerClass: scrollable ? (scroller.className || '').toString().slice(0, 80) : null,
    keyCount: keys.length,
    keysBelowViewport: keyInfo.filter((k) => !k.visibleInViewport).map((k) => k.key),
    keyInfo: keyInfo.slice(0, 14),
    confirmRect: cr ? { top: Math.round(cr.top), bottom: Math.round(cr.bottom), visible: cr.bottom <= innerHeight } : null,
    confirmText: confirm ? confirm.innerText.trim() : null,
  };
});
console.log('ENCAISSE-MODAL:', JSON.stringify(info, null, 1));
await quartet(page, consoleLog, netLog, 'RED-F-encaisse-modal-live');

await browser.close();
console.log('RED F live 2 DONE');
