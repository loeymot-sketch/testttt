import { BASE, boot, mkLogger, login } from './_d2-B-lib.mjs';
const L = mkLogger('b1c-probe');
const { browser, page } = await boot();
await login(page, L);
await page.goto(BASE + '/admin/transactions', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);
const probe = await page.evaluate(() => {
  const f = document.querySelector('#transaction-filter');
  const pm = document.querySelector('#payment_method');
  const btns = Array.from(document.querySelectorAll('.db-card-filter button, .db-card-filter a')).map(b => (b.className||'') + ' :: ' + (b.innerText||'').trim().slice(0,30));
  return {
    filterDiv: f ? { vis: getComputedStyle(f).visibility, h: getComputedStyle(f).height, display: getComputedStyle(f).display } : null,
    paymentMethodExists: !!pm,
    filterButtons: btns,
  };
});
L(JSON.stringify(probe, null, 1));
// clic sur le bouton filtre identifié puis re-probe
await page.evaluate(() => { document.querySelectorAll('.table-filter-btn').forEach(b => b.click()); });
await page.waitForTimeout(900);
const probe2 = await page.evaluate(() => {
  const f = document.querySelector('#transaction-filter');
  const pm = document.querySelector('#payment_method');
  return { vis: f && getComputedStyle(f).visibility, h: f && getComputedStyle(f).height, pmVisible: pm ? !!(pm.offsetWidth || pm.offsetHeight || pm.getClientRects().length) : 'ABSENT' };
});
L(JSON.stringify(probe2));
L.flush();
await browser.close();
console.log('DONE');
