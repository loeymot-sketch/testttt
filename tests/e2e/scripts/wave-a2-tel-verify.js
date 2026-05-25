// Wave A2 verify — bad tel:PENDING_ hrefs across admin order panels
const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await ctx.newPage();

  // Login admin
  await page.goto('http://127.0.0.1:8000/login');
  await page.locator('#formEmail').fill('admin@lecayenne.fr');
  await page.locator('#formPassword').fill('123456');
  await page.getByRole('button', { name: /connexion|login/i }).click();
  await page.waitForURL(u => !u.toString().includes('/login'), { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(3000);

  const surfaces = [
    '/kds',
    '/admin/order-status-screen',
    '/admin/pos-orders',
    '/admin/online-orders',
    '/admin/table-orders',
    '/admin/customer',
  ];
  let totalBadHrefs = 0;
  const perSurface = {};
  for (const path of surfaces) {
    try {
      await page.goto('http://127.0.0.1:8000' + path);
      await page.waitForTimeout(2500);
      const html = await page.content();
      const matches = html.match(/href=["']tel:PENDING_[^"']*["']/g);
      const c = (matches || []).length;
      perSurface[path] = c;
      console.log(`${path}: ${c} bad tel:PENDING_ hrefs`);
      totalBadHrefs += c;
    } catch (e) {
      perSurface[path] = `nav_fail: ${e.message.slice(0, 60)}`;
      console.log(`${path}: navigation failed (${e.message.slice(0, 60)})`);
    }
  }
  console.log('Total bad tel:PENDING_ hrefs:', totalBadHrefs);
  fs.writeFileSync(
    'tests/e2e/__screenshots__/wave-a2-verify.log',
    JSON.stringify({ totalBadHrefs, perSurface }, null, 2) + '\n'
  );
  await browser.close();
  process.exit(totalBadHrefs === 0 ? 0 : 1);
})();
