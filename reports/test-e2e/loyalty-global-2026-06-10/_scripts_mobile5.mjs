import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-global-2026-06-10';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:390,height:844}})).newPage();
await p.goto('http://127.0.0.1:8097/',{waitUntil:'networkidle'});
await p.waitForTimeout(2000);
const skip = p.locator('text=PASSER').first();
if(await skip.count()) { await skip.click(); await p.waitForTimeout(1500); }
const tel = p.locator('input[placeholder*="12 34"]');
await tel.click({force:true});
await p.keyboard.type('612345678',{delay:40});
await p.waitForTimeout(500);
await p.locator('text=/RECEVOIR LE CODE/i').first().click();
await p.waitForTimeout(2500);
console.log('OTP:', (await p.evaluate(()=>document.body.innerText.slice(0,700))).replace(/\n+/g,' | '));
await p.screenshot({path:OUT+'/L-B7-mobile-otp.png'});
const ins = await p.evaluate(()=>[...document.querySelectorAll('input')].map(e=>({ph:e.placeholder,type:e.type,max:e.maxLength})));
console.log('INPUTS:',JSON.stringify(ins));
await b.close();
