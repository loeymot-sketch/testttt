const { BASE, makePage, uiLogin } = require('./lib.cjs');
(async () => {
  const { browser, page } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(BASE + '/admin/employees', { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);
  await page.getByRole('button', { name: /Ajouter/i }).first().click();
  await page.waitForTimeout(2000);
  const html = await page.evaluate(() => {
    const lab = [...document.querySelectorAll('label')].find(l => /R[ÔO]LE/i.test(l.innerText));
    return lab ? lab.parentElement.innerHTML.slice(0, 1200) : 'label not found';
  });
  console.log(html);
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
