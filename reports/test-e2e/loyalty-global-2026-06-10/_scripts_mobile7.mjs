import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-global-2026-06-10';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:390,height:844}})).newPage();
await p.goto('http://127.0.0.1:8097/',{waitUntil:'networkidle'});
await p.waitForTimeout(2000);
const skip = p.locator('text=PASSER').first();
if(await skip.count()) { await skip.click(); await p.waitForTimeout(1500); }
await p.locator('input[placeholder*="12 34"]').click({force:true});
await p.keyboard.type('612345678',{delay:30});
await p.locator('text=/RECEVOIR LE CODE/i').first().click();
await p.waitForTimeout(2000);
const boxes = p.locator('input[maxlength="1"]');
for(let i=0;i<4;i++){ await boxes.nth(i).click({force:true}); await p.keyboard.type(String(i+1)); }
await p.waitForTimeout(2500);
// go to loyalty
let card = p.locator('[aria-label^="Carte fidélité"]');
if(!(await card.count())){
  // maybe profile tab first
  const prof = p.locator('text=/compte|profil/i').first();
  if(await prof.count()){ await prof.click(); await p.waitForTimeout(1500); }
  card = p.locator('[aria-label^="Carte fidélité"]');
}
console.log('card count=',await card.count());
if(await card.count()){ await card.first().click(); await p.waitForTimeout(2000); }
await p.screenshot({path:OUT+'/L-B7-mobile-loyalty.png',fullPage:true});
const txt = await p.evaluate(()=>document.body.innerText);
console.log('LOYALTY TXT:', txt.slice(0,1800).replace(/\n+/g,' | '));
await b.close();
