/* F2 step 3 retry — historique list → detail order 4512 with proper waits. */
const { BASE, makePage, uiLogin } = require('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/petits-systemes-2026-06-11/lib.cjs');
const fs = require('fs');
const DIR = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-validation-2026-06-12';
const TOKEN = fs.readFileSync(DIR + '/.f2-token', 'utf8').trim();

(async () => {
  const { browser, page, sink } = await makePage(TOKEN);
  try {
    await uiLogin(page);
    await page.goto(BASE + '/admin/historique', { waitUntil: 'networkidle' });
    // wait until a row with a serial appears
    await page.waitForSelector('tbody tr', { timeout: 30000 });
    await page.waitForTimeout(3000);
    await page.screenshot({ path: DIR + '/F2-historique-list.png' });
    const hasRow = await page.locator('tr', { hasText: 'F2-RDM-1' }).count();
    console.log('LIST_HAS_F2_ROW=' + hasRow);
    if (hasRow) {
      const row = page.locator('tr', { hasText: 'F2-RDM-1' }).first();
      const actions = row.locator('a, button');
      console.log('ROW_ACTIONS=' + await actions.count());
      await actions.first().click();
    } else {
      await page.goto(BASE + '/admin/pos-orders/show/4512');
    }
    await page.waitForFunction(() => document.body.innerText.includes('F2-RDM-1'), null, { timeout: 30000 }).catch(() => console.log('DETAIL_TEXT_TIMEOUT'));
    await page.waitForTimeout(3000);
    console.log('URL_FINAL=' + page.url());
    await page.screenshot({ path: DIR + '/F2-historique-redeem.png', fullPage: true });
    const text = await page.evaluate(() => document.body.innerText);
    const lines = text.split('\n').map(s => s.trim()).filter(l => /sous.total|remise|discount|total|fid|tva|F2-RDM/i.test(l)).slice(0, 40);
    console.log('MONEY_LINES:\n' + lines.join('\n'));
    console.log('CONSOLE_ERRORS=' + JSON.stringify(sink.console));
    console.log('HTTP_ERRORS=' + JSON.stringify(sink.http));
  } finally {
    await browser.close();
  }
})().catch(e => { console.error('FATAL', e); process.exit(1); });
