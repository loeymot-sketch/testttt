// ADVERSARIAL re-verification — Vague E cross-surface (dispute round 1)
// Cibles: (1) Vue Caisse Unifiée GRAND TOTAL vs DB 89,17€ ; (2) /admin/transactions sans ventes POS ;
// (3) élément topbar "5,80 €" non résolu ; (4) cartes A0011 dupliquées sans date sur /admin/encaissement.
import { boot, quartet, makeLogger, login, gotoAdmin, bodyText, BASE } from './_d1-E-lib.mjs';

const L = makeLogger('E-RED-verify');
const { browser, page, consoleBuf, netBuf } = await boot();

try {
  await login(page);
  L(`login OK → ${page.url().replace(BASE, '')}`);

  // (3) topbar amount mystery — identify the element containing "5,80 €" (or any € amount in header)
  await gotoAdmin(page, '/admin/pos-orders-tracker');
  await page.waitForTimeout(2500);
  const topbarInfo = await page.evaluate(() => {
    const hits = [];
    const walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    while (walk.nextNode()) {
      const t = walk.currentNode.textContent.trim();
      if (/^\d+,\d{2}\s*€$/.test(t)) {
        const el = walk.currentNode.parentElement;
        const path = [];
        let p = el;
        for (let i = 0; p && i < 5; i++) { path.push(`${p.tagName}.${(p.className || '').toString().slice(0, 60)}`); p = p.parentElement; }
        const ctx = el.closest('header, nav, [class*="navbar"], [class*="topbar"], [class*="header"]');
        hits.push({ text: t, inHeader: !!ctx, ctxText: (ctx?.innerText || '').replace(/\n/g, ' | ').slice(0, 220), path: path.join(' < ') });
      }
    }
    return hits.slice(0, 8);
  });
  L(`TOPBAR amounts: ${JSON.stringify(topbarInfo, null, 1)}`);
  await quartet(page, consoleBuf, netBuf, 'E-RED-01-tracker-topbar');

  // (1) cash-overview today
  await gotoAdmin(page, '/admin/cash-overview');
  await page.waitForTimeout(3500);
  const cashText = await bodyText(page);
  L('CASH-OVERVIEW LIVE:\n' + cashText.split('\n').filter(s => s.trim()).slice(0, 45).join('\n'));
  await quartet(page, consoleBuf, netBuf, 'E-RED-02-cash-overview-live');

  // (2) transactions today
  await gotoAdmin(page, '/admin/transactions');
  await page.waitForTimeout(3000);
  const txnText = await bodyText(page);
  const has4543 = txnText.includes('4543') || txnText.includes('1206264543');
  const lines = txnText.split('\n').filter(s => s.trim());
  L(`TRANSACTIONS LIVE: contient 4543/1206264543=${has4543}`);
  L('TRANSACTIONS rows:\n' + lines.slice(0, 40).join('\n'));
  await quartet(page, consoleBuf, netBuf, 'E-RED-03-transactions-live');

  // (4) encaissement — duplicated A0011 cards, check for any date display
  await gotoAdmin(page, '/admin/encaissement');
  await page.waitForTimeout(3000);
  const a11 = await page.evaluate(() => {
    const cards = [...document.querySelectorAll('[class*="card"], [class*="order"], [data-testid]')]
      .filter(el => /A0011/.test(el.innerText || '') && el.innerText.length < 400);
    return cards.slice(0, 4).map(el => (el.innerText || '').replace(/\n/g, ' | ').slice(0, 300));
  });
  L(`ENCAISSEMENT cartes A0011 (${a11.length}): ${JSON.stringify(a11, null, 1)}`);
  await quartet(page, consoleBuf, netBuf, 'E-RED-04-encaissement-a0011');
} catch (e) {
  L(`FATAL: ${e.message}`);
} finally {
  L.flush();
  await browser.close();
}
