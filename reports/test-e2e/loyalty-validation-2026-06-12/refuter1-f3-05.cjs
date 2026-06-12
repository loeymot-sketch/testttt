const { BASE, makePage, uiLogin } = require('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/petits-systemes-2026-06-11/lib.cjs');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-validation-2026-06-12';
const TOKEN = '2786|z3t9oyK8BTotkWS9kmXxqMAAEUPDPg5S4TytrbTzb19d126e';
(async () => {
  const { browser, page } = await makePage(TOKEN);
  await uiLogin(page);
  await page.goto(BASE + '/admin/settings/loyalty-setup', { waitUntil: 'networkidle' });
  await page.waitForSelector('#loyalty_points_per_euro', { timeout: 15000 });

  // Case 1: -1 -> expect native HTML5 block, NO XHR
  let xhrSeen = false;
  page.on('request', r => { if (r.url().includes('loyalty-setup') && ['PUT','PATCH','POST'].includes(r.method())) xhrSeen = true; });
  await page.fill('#loyalty_points_per_euro', '-1');
  await page.click('button[type="submit"]').catch(async () => { await page.getByRole('button', { name: /Enregistrer|Sauv|Save/i }).click(); });
  await page.waitForTimeout(1500);
  const v = await page.evaluate(() => {
    const el = document.getElementById('loyalty_points_per_euro');
    return { value: el.value, checkValidity: el.checkValidity(), validationMessage: el.validationMessage };
  });
  console.log(JSON.stringify({ case: 'minus1', xhrSeen, ...v }));
  await page.screenshot({ path: OUT + '/refuter1-F3-05-minus1.png' });

  // Case 2: empty -> native required? input has no `required` attr -> empty passes HTML5, XHR fires, 422 inline
  xhrSeen = false;
  let resp422 = null;
  page.on('response', async r => { if (r.url().includes('loyalty-setup') && r.status() === 422) { try { resp422 = await r.json(); } catch {} } });
  await page.fill('#loyalty_points_per_euro', '');
  await page.click('button[type="submit"]').catch(async () => { await page.getByRole('button', { name: /Enregistrer|Sauv|Save/i }).click(); });
  await page.waitForTimeout(2500);
  const inline = await page.evaluate(() => document.querySelector('.db-field-alert')?.textContent?.trim() ?? null);
  console.log(JSON.stringify({ case: 'empty', xhrSeen, status422: !!resp422, body: resp422, inline }));
  await page.screenshot({ path: OUT + '/refuter1-F3-05-empty.png' });

  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
