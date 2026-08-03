const { test, expect } = require('@playwright/test');
const fs = require('fs');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/web';
test('PAY flag OFF independent repro', async ({ page }) => {
  const errors=[]; const report={};
  page.on('console', m=>{ if(m.type()==='error') errors.push(m.text()); });
  page.on('pageerror', e=> errors.push('[PE] '+e.message));
  await page.goto('http://127.0.0.1:8096/'); await page.waitForTimeout(2000);
  await page.locator('.lc-nav-link', { hasText: 'Menu' }).click();
  await expect(page.locator('.lc-menu-side').first()).toBeVisible({ timeout: 15000 });
  await page.locator('.lc-menu-side-link', { hasText: 'Sandwichs' }).click();
  const cayenne = page.locator('.lc-card-item', { hasText: /Cayenne/ }).first();
  await cayenne.click();
  await page.getByRole('button', { name: /Personnaliser/ }).click();
  await expect(page.locator('.lc-wiz-title')).toBeVisible({ timeout: 15000 });
  await page.locator('.lc-wiz-foot-next').click();
  for (let g=0; g<14; g++){
    const next = page.locator('.lc-wiz-foot-next');
    const label = (await next.textContent().catch(()=> '')) || '';
    if (label.includes('Ajouter au panier')) { await next.click(); break; }
    const title = (await page.locator('.lc-wiz-title').textContent().catch(()=> '')) || '';
    if (title.includes('Faire un menu')) { await page.locator('.lc-wiz-choice', { hasText: 'Sans formule' }).click().catch(()=>{}); await page.waitForTimeout(300); continue; }
    if (await next.isDisabled().catch(()=>true)) await page.locator('.lc-wiz-options .lc-wiz-choice').first().click().catch(()=>{});
    await next.click().catch(()=>{});
    await page.waitForTimeout(200);
  }
  await expect(page.getByRole('button', { name: /Passer commande/ })).toBeVisible({ timeout: 10000 });
  await page.getByRole('button', { name: /Passer commande/ }).click();
  for (let g=0; g<6; g++){
    if (await page.getByText('Continuer vers paiement').isVisible().catch(()=>false)) break;
    const skip = page.getByRole('button', { name: 'Non merci' });
    if (await skip.isVisible().catch(()=>false)) { await skip.click(); await page.waitForTimeout(300); continue; }
    await page.waitForTimeout(400);
  }
  await page.getByRole('button', { name: /Continuer vers paiement/ }).click();
  await expect(page.getByText('Payer sur place')).toBeVisible({ timeout: 15000 });
  report.methodCount = await page.locator('.lcf-paymethod').count();
  report.dom = await page.evaluate(()=>{ const t=document.body.innerText; return {
    methods:[...document.querySelectorAll('.lcf-paymethod-name')].map(x=>x.textContent.trim()),
    stripe:/stripe/i.test(t), applePay:/apple\s*pay/i.test(t), googlePay:/google\s*pay/i.test(t),
    threeDS:/3d[\s-]?secure/i.test(t), preauth:/pré-?autoris|réservé sur ta carte/i.test(t),
    payerSurPlace:/payer sur place/i.test(t), comptoir:/comptoir/i.test(t),
    onlineCardEnabled: window.LC.api.config.onlineCardEnabled,
  };});
  await page.evaluate(()=>window.scrollTo(0,0)); await page.waitForTimeout(300);
  await page.screenshot({ path: `${OUT}/REPRO-payment.png`, fullPage:true });
  fs.writeFileSync(`${OUT}/REPRO-paymethods-OFF.html`, await page.locator('.lcf-paymethods').innerHTML());
  fs.writeFileSync(`${OUT}/verif-pay2.json`, JSON.stringify({report, errors},null,2));
  console.log('PAY2', JSON.stringify(report)); console.log('PAY2ERR', JSON.stringify(errors));
});
