// VAGUE B ROUND 2 — clôture session 22: comptage exact → écart 0 ; +0,50 → écart affiché ; raison ; submit.
// Attendu arithmétique: 50 + 6,90 + 8,90 + 1,50 − 1,50 − 6,90 = 58,90 €.
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login, gotoPos, bodyText } from './_d2-B-lib.mjs';

const L = mkLogger('b5-close');
const { browser, page, state } = await boot();
const api = [];
page.on('response', async (r) => {
  if (/cash-drawer/i.test(r.url()) && ['POST', 'PUT'].includes(r.request().method())) {
    let body = null; try { body = await r.json(); } catch {}
    api.push({ method: r.request().method(), status: r.status(), url: r.url().replace(BASE, ''), body });
    L(`API ${r.request().method()} ${r.status()} ${r.url().replace(BASE, '').slice(0, 110)} :: ${JSON.stringify(body)?.slice(0, 320)}`);
  }
});
const dlg = (tid) => page.locator(`[data-testid="${tid}"]`);

try {
  await login(page, L);
  await gotoPos(page, L);
  await page.waitForTimeout(1500);
  const already = await dlg('cash-session-overlay').isVisible().catch(() => false);
  if (!already) { await dlg('pos-cash-session-open').click(); await page.waitForTimeout(1200); }
  const expStat = await dlg('cash-session-stat-expected').innerText().catch(() => '?');
  L(`stat expected avant clôture: "${expStat.replace(/\s+/g, ' ')}"`);
  await snap(page, state, 'b5-01-session-before-close');

  await dlg('cash-session-go-close').click();
  await page.waitForTimeout(1000);
  const expClose = await dlg('cash-session-close-expected').innerText();
  const expNum = parseFloat(expClose.replace(/[^\d,.-]/g, '').replace(',', '.'));
  L(`close-form expected="${expClose.replace(/\s+/g, ' ')}" → num=${expNum}`);

  // comptage exact → écart 0
  await dlg('cash-session-closing-input').fill(expNum.toFixed(2));
  await page.waitForTimeout(700);
  const var0 = await dlg('cash-session-close-variance').innerText().catch(() => '?');
  const reason0 = await dlg('cash-session-reason-input').isVisible().catch(() => false);
  L(`comptage exact ${expNum.toFixed(2)} → variance="${var0.replace(/\s+/g, ' ')}" raison-visible=${reason0}`);
  await snap(page, state, 'b5-02-close-variance-zero');

  // comptage +0,50 → écart +0,50 attendu (signe ?)
  const counted = (expNum + 0.5).toFixed(2);
  await dlg('cash-session-closing-input').fill(counted);
  await page.waitForTimeout(700);
  const varPlus = await dlg('cash-session-close-variance').innerText().catch(() => '?');
  const reasonVis = await dlg('cash-session-reason-input').isVisible().catch(() => false);
  L(`comptage ${counted} → variance="${varPlus.replace(/\s+/g, ' ')}" raison-visible=${reasonVis}`);
  if (reasonVis) await dlg('cash-session-reason-input').fill('Écart volontaire +0,50 € — test round-2 vague B');
  await page.waitForTimeout(400);
  await snap(page, state, 'b5-03-close-variance-plus050');

  // comptage -1,00 → écart négatif (présentation ?)
  const countedNeg = (expNum - 1).toFixed(2);
  await dlg('cash-session-closing-input').fill(countedNeg);
  await page.waitForTimeout(700);
  const varNeg = await dlg('cash-session-close-variance').innerText().catch(() => '?');
  L(`comptage ${countedNeg} → variance="${varNeg.replace(/\s+/g, ' ')}"`);
  await snap(page, state, 'b5-04-close-variance-minus1');

  // clôture finale au comptage +0,50 (écart volontaire documenté)
  await dlg('cash-session-closing-input').fill(counted);
  await page.waitForTimeout(500);
  const reasonVis2 = await dlg('cash-session-reason-input').isVisible().catch(() => false);
  if (reasonVis2) await dlg('cash-session-reason-input').fill('Écart volontaire +0,50 € — test round-2 vague B');
  await page.waitForTimeout(300);
  await dlg('cash-session-close-submit').click();
  await page.waitForTimeout(3200);
  await snap(page, state, 'b5-05-close-submitted');
  const t = await bodyText(page);
  L(`après clôture (extrait): ${JSON.stringify((t.match(/clôturée|fermée|écart|variance|session/gi) || []).slice(0, 8))}`);
  const openFormBack = await dlg('cash-session-open-form').isVisible().catch(() => false);
  L(`open-form re-visible après clôture: ${openFormBack}`);
  await snap(page, state, 'b5-06-after-close-dialog');
} finally {
  fs.writeFileSync(OUT + '_b5-api-responses.json', JSON.stringify(api, null, 2));
  L(`console cumulés: ${state.consoleBuf.length}`); state.consoleBuf.forEach((c) => L('  ' + c));
  L(`net>=400: ${state.netBuf.length}`); state.netBuf.forEach((n) => L('  ' + n));
  L.flush();
  await browser.close();
}
console.log('DONE');
