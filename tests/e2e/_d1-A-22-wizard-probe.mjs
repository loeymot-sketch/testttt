// VAGUE A — probe structure DOM wizard Tacos (FROZEN, lecture seule)
import { boot, login, gotoPos } from './_d1-A-lib.mjs';

const { browser, page } = await boot();
try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1200);
  await page.locator('button.pos-v5-category[aria-label="Tacos"]').first().click().catch(() => {});
  await page.waitForTimeout(1200);
  await page.locator('.pos-v5-tile:has-text("Tacos")').first().click();
  await page.waitForTimeout(2000);
  const probe = await page.evaluate(() => {
    const opt = document.querySelector('.wizard-option');
    if (!opt) return { err: 'no .wizard-option' };
    // remonter la hiérarchie depuis une option
    const chain = [];
    let el = opt;
    for (let i = 0; i < 6 && el; i++) { chain.push(el.className?.toString().slice(0, 120)); el = el.parentElement; }
    // groupes: parents directs des wizard-options distincts
    const groups = new Map();
    document.querySelectorAll('.wizard-option').forEach((o) => {
      const p = o.closest('.wizard-options') || o.parentElement;
      if (!groups.has(p)) groups.set(p, []);
      groups.get(p).push(o.innerText.split('\n')[0].trim().slice(0, 30));
    });
    const groupInfo = Array.from(groups.entries()).map(([p, opts]) => {
      // titre = heading précédent
      let t = p;
      let title = '';
      for (let i = 0; i < 4 && t; i++) {
        t = t.previousElementSibling || t.parentElement;
        if (t && /choisis|oblig/i.test(t.innerText?.slice(0, 80) || '')) { title = t.innerText.split('\n')[0].slice(0, 60); break; }
      }
      return { title, parentClass: p?.className?.toString().slice(0, 80), opts };
    });
    return { chain, groupInfo };
  });
  console.log(JSON.stringify(probe, null, 1));
} finally { await browser.close(); }
