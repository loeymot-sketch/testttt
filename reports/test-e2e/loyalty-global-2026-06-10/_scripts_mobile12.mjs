import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-global-2026-06-10';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:390,height:844}})).newPage();
await p.goto('http://127.0.0.1:8097/',{waitUntil:'networkidle'});
await p.waitForTimeout(2500);
let body = await p.evaluate(()=>document.body.innerText);
if(!/BONSOIR|BONJOUR/i.test(body)){
  const skip = p.locator('text=PASSER').first();
  if(await skip.count()) { await skip.click(); await p.waitForTimeout(2000); }
  const tel = p.locator('input[placeholder*="12 34"]');
  await tel.click({force:true}); await p.keyboard.type('612345678',{delay:30});
  await p.locator('text=/RECEVOIR LE CODE/i').first().click(); await p.waitForTimeout(2500);
  const boxes = p.locator('input[maxlength="1"]');
  for(let i=0;i<4;i++){ await boxes.nth(i).click({force:true}); await p.keyboard.type(String(i+1)); }
  await p.waitForTimeout(3000);
}
await p.locator('[aria-label^="Profil"]').last().click(); await p.waitForTimeout(2000);
await p.locator('[aria-label^="Carte fidélité"]').first().click(); await p.waitForTimeout(2500);
const grab = async()=> (await p.evaluate(()=>document.body.innerText.match(/(\d+)\s*POINTS/)?.[1]));
console.log('BAL BEFORE=', await grab());
const btn = p.locator('[data-testid="reward-redeem-btn-100"]');
await btn.scrollIntoViewIfNeeded(); await btn.click({force:true}); await p.waitForTimeout(1500);
await p.screenshot({path:OUT+'/L-B7-mobile-redeem-step1.png',fullPage:true});
await p.locator('text=/CONTINUER/i').last().click({force:true}); await p.waitForTimeout(1700);
await p.screenshot({path:OUT+'/L-B7-mobile-redeem-step2.png',fullPage:true});
await p.locator('text=Appliquer maintenant').first().click({force:true}); await p.waitForTimeout(2200);
let t = (await p.evaluate(()=>document.body.innerText)).slice(-900);
console.log('STEP3:', t.replace(/\n+/g,' | '));
await p.screenshot({path:OUT+'/L-B7-mobile-redeem-step3.png',fullPage:true});
for(const re of [/FERMER/i,/TERMINER/i,/COMPRIS/i,/VOIR MES POINTS/i,/^OK$/i,/RETOUR/i]){
  const l = p.locator('button:visible').filter({hasText:re}).last();
  if(await l.count()){ await l.click({force:true}); await p.waitForTimeout(1500); break; }
}
console.log('BAL AFTER=', await grab());
await p.screenshot({path:OUT+'/L-B7-mobile-loyalty-after-redeem.png'});
console.log('HIST CHECK:', (await p.evaluate(()=>document.body.innerText.slice(0,400))).replace(/\n+/g,' | '));
await b.close();
