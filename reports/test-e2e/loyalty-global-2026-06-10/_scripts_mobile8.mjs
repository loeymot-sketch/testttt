import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-global-2026-06-10';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:390,height:844}})).newPage();
async function loginIfNeeded(){
  await p.goto('http://127.0.0.1:8097/',{waitUntil:'networkidle'});
  await p.waitForTimeout(2500);
  const body = await p.evaluate(()=>document.body.innerText);
  if(/BONSOIR|BONJOUR|CATEGORIES/i.test(body)) return;
  const skip = p.locator('text=PASSER').first();
  if(await skip.count()) { await skip.click(); await p.waitForTimeout(2000); }
  const tel = p.locator('input[placeholder*="12 34"]');
  await tel.waitFor({timeout:10000});
  await tel.click({force:true});
  await p.keyboard.type('612345678',{delay:30});
  await p.locator('text=/RECEVOIR LE CODE/i').first().click();
  await p.waitForTimeout(2500);
  const boxes = p.locator('input[maxlength="1"]');
  await boxes.first().waitFor({timeout:10000});
  for(let i=0;i<4;i++){ await boxes.nth(i).click({force:true}); await p.keyboard.type(String(i+1)); }
  await p.waitForTimeout(3000);
}
await loginIfNeeded();
const card = p.locator('[aria-label^="Carte fidélité"]');
await card.first().waitFor({timeout:15000});
await card.first().click();
await p.waitForTimeout(2500);
const grab = async()=> (await p.evaluate(()=>document.body.innerText.match(/(\d+)\s*POINTS/)?.[1]));
console.log('BAL BEFORE=', await grab());
await p.screenshot({path:OUT+'/L-B7-mobile-loyalty-before-redeem.png'});
await p.locator('text=UTILISER').first().click();
await p.waitForTimeout(1800);
await p.screenshot({path:OUT+'/L-B7-mobile-redeem-wizard-1.png',fullPage:true});
console.log('WIZARD:', (await p.evaluate(()=>document.body.innerText.slice(-1200))).replace(/\n+/g,' | '));
// step through wizard buttons
for(let s=0;s<5;s++){
  const btns = await p.locator('button:visible').allTextContents();
  const target = btns.find(t=>/CONFIRMER|VALIDER|ÉCHANGER|UTILISER|CONTINUER|SUIVANT|OUI/i.test(t) && !/annuler|retour/i.test(t));
  if(!target) break;
  await p.locator(`button:visible`,{hasText:target.trim().slice(0,20)}).first().click().catch(()=>{});
  await p.waitForTimeout(1500);
  console.log('clicked:',JSON.stringify(target.trim()));
  const t2 = await p.evaluate(()=>document.body.innerText);
  if(/utilisé|déduit|succès|appliqué|−100|fait !|FAIT/i.test(t2.slice(-600))) { console.log('END:', t2.slice(-500).replace(/\n+/g,' | ')); break; }
}
await p.screenshot({path:OUT+'/L-B7-mobile-redeem-wizard-final.png',fullPage:true});
// close wizard if needed, read balance
const close = p.locator('button[aria-label*="ermer"],text=/FERMER|RETOUR À MES POINTS|VOIR MES POINTS/i').first();
if(await close.count()) { await close.click().catch(()=>{}); await p.waitForTimeout(1500); }
console.log('BAL AFTER=', await grab());
console.log('FINAL TXT:', (await p.evaluate(()=>document.body.innerText.slice(0,900))).replace(/\n+/g,' | '));
await p.screenshot({path:OUT+'/L-B7-mobile-loyalty-after-redeem.png'});
await b.close();
