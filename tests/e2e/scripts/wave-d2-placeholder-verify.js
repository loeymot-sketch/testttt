const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto('http://127.0.0.1:8000/login');
  await page.locator('#formEmail').fill('admin@lecayenne.fr');
  await page.locator('#formPassword').fill('123456');
  await page.getByRole('button', { name: /connexion|login/i }).click();
  await page.waitForURL(u => !u.toString().includes('/login'));
  await page.waitForTimeout(3500);

  await page.goto('http://127.0.0.1:8000/admin/profile/edit-profile', { waitUntil: 'networkidle' });
  await page.waitForTimeout(3500);

  const placeholder = await page.locator('#phone').getAttribute('placeholder').catch(() => null);
  console.log('Phone input placeholder:', placeholder);
  fs.writeFileSync('tests/e2e/__screenshots__/wave-d2-verify.log', `placeholder: ${placeholder}\n`);
  await browser.close();
})();
