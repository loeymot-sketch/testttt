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
await p.locator('[aria-label^="Profil"]').last().click();
await p.waitForTimeout(2000);
await p.locator('[aria-label^="Carte fidélité"]').first().click();
await p.waitForTimeout(2500);
const grab = async()=> (await p.evaluate(()=>document.body.innerText.match(/(\d+)\s*POINTS/)?.[1]));
console.log('BAL BEFORE=', await grab());
const btn = p.locator('[data-testid="reward-redeem-btn-100"]');
console.log('btn100 count=', await btn.count());
await btn.scrollIntoViewIfNeeded();
await btn.click({force:true});
await p.waitForTimeout(1800);
await p.screenshot({path:OUT+'/L-B7-mobile-redeem-wizard-1.png',fullPage:true});
console.log('STEP1:', (await p.evaluate(()=>document.body.innerText.slice(-1000))).replace(/\n+/g,' | '));
for(let s=0;s<6;s++){
  const btns = await p.locator('button:visible').allTextContents();
  const target = btns.map(t=>t.trim()).find(t=>/^(CONFIRMER|VALIDER|ÉCHANGER|UTILISER|CONTINUER|SUIVANT|JE CONFIRME|OUI|ACTIVER)/i.test(t));
  if(!target){ console.log('btns:',JSON.stringify(btns.map(x=>x.trim()).filter(Boolean).slice(0,15))); break; }
  await p.getByRole('button',{name:target}).first().click({force:true}).catch(()=>{});
  await p.waitForTimeout(1700);
  console.log('clicked:',JSON.stringify(target));
  await p.screenshot({path:OUT+`/L-B7-mobile-redeem-step${s+2}.png`,fullPage:true});
  const t2 = (await p.evaluate(()=>document.body.innerText)).slice(-800);
  if(/utilisé|déduit|appliqué|succès|fait|activé|−100 pts/i.test(t2)) { console.log('END:', t2.replace(/\n+/g,' | ')); break; }
}
const close = p.locator('text=/FERMER|RETOUR|TERMINER|COMPRIS|OK/i').first();
if(await close.count()) { await close.click({force:true}).catch(()=>{}); await p.waitForTimeout(1500); }
console.log('BAL AFTER=', await grab());
await p.screenshot({path:OUT+'/L-B7-mobile-loyalty-after-redeem.png'});
console.log('AFTER TXT:', (await p.evaluate(()=>document.body.innerText.slice(0,700))).replace(/\n+/g,' | '));
await b.close();
