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
await p.locator('[aria-label="Profil — Ikyes B."],[aria-label="Profil"]').last().click();
await p.waitForTimeout(2000);
const card = p.locator('[aria-label^="Carte fidélité"]');
if(await card.count()){ await card.first().click(); } else { await p.locator('text=/fid[ée]lit[ée]/i').first().click(); }
await p.waitForTimeout(2500);
const grab = async()=> (await p.evaluate(()=>document.body.innerText.match(/(\d+)\s*POINTS/)?.[1]));
console.log('BAL BEFORE=', await grab());
await p.screenshot({path:OUT+'/L-B7-mobile-loyalty-before-redeem.png'});
await p.locator('text=UTILISER').first().click({force:true});
await p.waitForTimeout(1800);
await p.screenshot({path:OUT+'/L-B7-mobile-redeem-wizard-1.png',fullPage:true});
console.log('WIZARD:', (await p.evaluate(()=>document.body.innerText.slice(-1100))).replace(/\n+/g,' | '));
for(let s=0;s<5;s++){
  const btns = await p.locator('button:visible').allTextContents();
  const target = btns.find(t=>/CONFIRMER|VALIDER|ÉCHANGER|UTILISER MAINTENANT|CONTINUER|SUIVANT|JE CONFIRME|OUI/i.test(t) && !/annuler|retour/i.test(t));
  if(!target){ console.log('no next btn, visible:',JSON.stringify(btns.filter(x=>x.trim()).slice(0,12))); break; }
  await p.getByRole('button',{name:target.trim()}).first().click().catch(async()=>{await p.locator('button:visible',{hasText:target.trim().slice(0,15)}).first().click().catch(()=>{})});
  await p.waitForTimeout(1600);
  console.log('clicked:',JSON.stringify(target.trim()));
  const t2 = (await p.evaluate(()=>document.body.innerText)).slice(-700);
  if(/utilisé|déduit|succès|appliqué|fait/i.test(t2)) { console.log('END:', t2.replace(/\n+/g,' | ')); break; }
}
await p.screenshot({path:OUT+'/L-B7-mobile-redeem-wizard-final.png',fullPage:true});
const close = p.locator('text=/FERMER|RETOUR|VOIR MES POINTS|MES POINTS/i').first();
if(await close.count()) { await close.click().catch(()=>{}); await p.waitForTimeout(1500); }
console.log('BAL AFTER=', await grab());
await p.screenshot({path:OUT+'/L-B7-mobile-loyalty-after-redeem.png'});
await b.close();
