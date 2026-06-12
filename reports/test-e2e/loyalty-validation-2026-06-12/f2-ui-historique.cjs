/* F2 step 3 — /admin/historique → detail of redeemed order 4512 (F2-RDM-1). */
const { BASE, makePage, uiLogin } = require('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/petits-systemes-2026-06-11/lib.cjs');
const fs = require('fs');
const DIR = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-validation-2026-06-12';
const TOKEN = fs.readFileSync(DIR + '/.f2-token', 'utf8').trim();

(async () => {
  const { browser, page, sink } = await makePage(TOKEN);
  try {
    await uiLogin(page);
    await page.goto(BASE + '/admin/historique', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);
    await page.screenshot({ path: DIR + '/F2-historique-list.png', fullPage: false });

    // Find the row for F2-RDM-1 (order 4512). Try a search box first.
    const search = page.locator('input[type="search"], input[placeholder*="echerch"], input[placeholder*="earch"]').first();
    if (await search.count()) {
      await search.fill('F2-RDM-1');
      await search.press('Enter');
      await page.waitForTimeout(2000);
    }
    await page.screenshot({ path: DIR + '/F2-historique-filtered.png' });

    // Click the row / view link containing the serial.
    const row = page.locator('tr', { hasText: 'F2-RDM-1' }).first();
    if (await row.count()) {
      const link = row.locator('a').first();
      if (await link.count()) { await link.click(); } else { await row.click(); }
      await page.waitForTimeout(2500);
    } else {
      console.log('ROW_NOT_FOUND_FALLBACK direct nav');
      await page.goto(BASE + '/admin/pos-orders/show/4512', { waitUntil: 'networkidle' });
      await page.waitForTimeout(2500);
    }
    await page.screenshot({ path: DIR + '/F2-historique-redeem.png', fullPage: true });
    console.log('URL_FINAL=' + page.url());
    // Extract money lines from DOM for evidence.
    const text = await page.evaluate(() => document.body.innerText);
    const lines = text.split('\n').filter(l => /sous-total|remise|total|fid|discount|tva/i.test(l)).slice(0, 40);
    console.log('MONEY_LINES:\n' + lines.join('\n'));
    console.log('CONSOLE_ERRORS=' + JSON.stringify(sink.console));
    console.log('HTTP_ERRORS=' + JSON.stringify(sink.http));
  } finally {
    await browser.close();
  }
})().catch(e => { console.error('FATAL', e); process.exit(1); });
