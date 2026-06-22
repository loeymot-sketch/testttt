const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const SHOTS = 'tests/e2e/__screenshots__/fix-pending-create';
const BASE = 'http://127.0.0.1:8000';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

async function snap(page, name) {
  await page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true });
  fs.writeFileSync(path.join(SHOTS, name + '.dom.html'), await page.content());
  console.log('📸 ' + name);
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await ctx.newPage();

  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForSelector('#formEmail', { timeout: 20000 });
  await page.locator('#formEmail').fill('admin@lecayenne.fr');
  await page.locator('#formPassword').fill('123456');
  await page.getByRole('button', { name: /connexion|login/i }).click();
  await page.waitForURL(u => !u.toString().includes('/login'), { timeout: 25000 }).catch(()=>{});
  await page.waitForTimeout(3500);
  console.log('Logged in. URL:', page.url());

  await snap(page, '01-admin-dashboard');

  await page.evaluate(() => {
    const headers = document.querySelectorAll('header button');
    for (const h of headers) {
      if (h.querySelector('img') && h.querySelector('b')) { h.click(); break; }
    }
  });
  await page.waitForTimeout(1500);
  await snap(page, '02-profile-dropdown-open');

  const html = await page.content();
  const hasPending = /PENDING_CREATE/i.test(html);
  console.log('PENDING_CREATE in DOM after fix:', hasPending);
  fs.writeFileSync(path.join(SHOTS, '03-pending-check.txt'), 'PENDING_CREATE present after fix: ' + hasPending);

  await page.goto(BASE + '/admin/profile/edit-profile', { waitUntil: 'networkidle' });
  await page.waitForTimeout(2500);
  await snap(page, '04-profile-edit-form');

  const phoneVal = await page.evaluate(() => {
    const inp = document.querySelector('input[type="tel"], input[name="phone"], input[placeholder*="phone" i]');
    return inp ? inp.value : 'NO_INPUT_FOUND';
  });
  console.log('Profile edit phone input value:', phoneVal);
  fs.writeFileSync(path.join(SHOTS, '05-phone-input-value.txt'), 'phone input value: ' + phoneVal);

  await browser.close();
  console.log('DONE');
})();
