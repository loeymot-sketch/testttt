/* Vérification visuelle des heals AB14-01 / PS-01 / PS-02 / AB14-02. */
const path = require('path');
const { BASE, makePage, uiLogin } = require('./lib.cjs');
const OUT = __dirname;

(async () => {
  const { browser, page } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);

  // 1. AB14-01 — offres : save complet → toast FR, pas de crash
  await page.goto(BASE + '/admin/offers', { waitUntil: 'networkidle', timeout: 45000 });
  await page.getByRole('button', { name: /Ajouter Une Offre/i }).click();
  await page.waitForTimeout(1000);
  await page.locator('input#name, input[name=name]').first().fill('E2E Heal Offre');
  await page.locator('input#amount, input[name=amount]').first().fill('5');
  const dp = page.locator('.dp__input');
  await dp.nth(0).fill('2026-06-11'); await page.keyboard.press('Enter');
  await page.keyboard.press('Escape');
  await dp.nth(1).fill('2026-06-30'); await page.keyboard.press('Enter');
  await page.keyboard.press('Escape');
  let crashed = false;
  page.on('pageerror', (e) => { console.log('PAGEERROR:', e.message); crashed = true; });
  await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).last().click();
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(OUT, '07-offers-403-toast.png') });
  const body1 = await page.evaluate(() => document.body.innerText);
  console.log('offers: crash=', crashed, '| toast module désactivé visible=', body1.includes('désactivé'));

  // 2. PS-01/PS-02 — coupon : save vide → messages FR localisés + IMAGE sans astérisque
  await page.goto(BASE + '/admin/coupons', { waitUntil: 'networkidle', timeout: 45000 });
  await page.getByRole('button', { name: /Ajouter Un Coupon/i }).click();
  await page.waitForTimeout(1000);
  await page.getByRole('button', { name: /Enregistrer|Sauvegarder|Save/i }).last().click();
  await page.waitForTimeout(2000);
  await page.screenshot({ path: path.join(OUT, '07-coupon-validation-fr.png') });
  const body2 = await page.evaluate(() => document.body.innerText);
  console.log('coupon: "date de début" FR=', body2.includes('date de début'),
    '| reste "start date" brut=', /champ start date/i.test(body2),
    '| "minimum order" brut=', /champ minimum order/i.test(body2));
  const imgLabelRequired = await page.evaluate(() => {
    const labels = [...document.querySelectorAll('label')];
    const l = labels.find((x) => x.textContent.trim().toUpperCase() === 'IMAGE');
    return l ? l.className : 'notfound';
  });
  console.log('coupon image label class=', imgLabelRequired);

  // 3. AB14-02 — transactions : heure 24h
  await page.goto(BASE + '/admin/transactions', { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: path.join(OUT, '07-transactions-24h.png') });
  const body3 = await page.evaluate(() => document.body.innerText);
  console.log('transactions: AM/PM encore présent=', /\d{2}:\d{2} [AP]M/.test(body3));

  await browser.close();
  console.log('VERIFY DONE');
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
