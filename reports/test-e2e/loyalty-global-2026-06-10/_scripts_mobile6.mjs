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
// OTP: 4 boxes
const boxes = p.locator('input[maxlength="1"]');
for(let i=0;i<4;i++){ await boxes.nth(i).click({force:true}); await p.keyboard.type(String(i+1)); }
await p.waitForTimeout(2500);
console.log('AFTER OTP:', (await p.evaluate(()=>document.body.innerText.slice(0,500))).replace(/\n+/g,' | '));
await p.screenshot({path:OUT+'/L-B7-mobile-after-otp.png'});
// any continue button
for(const t of ['VALIDER','CONTINUER','C\'EST PARTI','GO']){ const l=p.locator(`text=/${t}/i`).first(); if(await l.count()){ await l.click(); await p.waitForTimeout(1500); break; } }
console.log('HOME:', (await p.evaluate(()=>document.body.innerText.slice(0,500))).replace(/\n+/g,' | '));
await p.screenshot({path:OUT+'/L-B7-mobile-home.png'});
// nav to fidelite
const loy = p.locator('text=/fid[ée]lit[ée]/i').first();
if(await loy.count()){ await loy.click(); await p.waitForTimeout(2000);
  await p.screenshot({path:OUT+'/L-B7-mobile-loyalty.png',fullPage:true});
  console.log('LOYALTY:', (await p.evaluate(()=>document.body.innerText.slice(0,2000))).replace(/\n+/g,' | '));
} else console.log('NO fidelite nav. body:', (await p.evaluate(()=>document.body.innerText.slice(0,800))).replace(/\n+/g,' | '));
await b.close();
