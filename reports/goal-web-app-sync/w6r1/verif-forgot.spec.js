const { test } = require('@playwright/test');
const fs = require('fs');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/web';
test('FORGOT honest independent', async ({ page }) => {
  const errors=[]; const report={};
  page.on('console', m=>{ if(m.type()==='error') errors.push(m.text()); });
  page.on('pageerror', e=> errors.push('[PE] '+e.message));
  await page.goto('http://127.0.0.1:8096/'); await page.waitForTimeout(2500);
  report.opened = await page.evaluate(()=>{ const b=document.querySelector('.lc-nav-btn-account'); if(b){ b.click(); return true;} return false; });
  await page.waitForTimeout(1200);
  report.modalCount = await page.locator('.lcf-tabs, .lc-acc').count();
  // click "Oublié ?"
  report.forgotClicked = await page.evaluate(()=>{ const b=[...document.querySelectorAll('button')].find(x=>/^oublié \?$/i.test(x.textContent.trim())); if(b){ b.click(); return true;} return false; });
  await page.waitForTimeout(1000);
  await page.screenshot({ path: `${OUT}/REPRO-forgot.png` });
  report.dom = await page.evaluate(()=>{ const t=document.body.innerText; return {
    honestNoPassword:/pas de mot de passe/i.test(t),
    fakeLinkSent:/lien envoyé|e-?mail envoyé|vérifie ta boîte|réinitialis/i.test(t),
    recevoirUnCode:/recevoir un code/i.test(t),
  };});
  fs.writeFileSync(`${OUT}/verif-forgot2.json`, JSON.stringify({report, errors},null,2));
  console.log('FORGOT2', JSON.stringify(report)); console.log('FORGOT2ERR', JSON.stringify(errors));
});
