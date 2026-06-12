import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-global-2026-06-10';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:390,height:844}})).newPage();
await p.goto('http://127.0.0.1:8097/',{waitUntil:'networkidle'});
await p.waitForTimeout(2000);
const skip = p.locator('text=PASSER').first();
if(await skip.count()) { await skip.click(); await p.waitForTimeout(1500); }
// phone login
const tel = p.locator('input').first();
await tel.fill('612345678').catch(async()=>{await tel.fill('0612345678')});
await p.locator('text=/RECEVOIR LE CODE/i').first().click();
await p.waitForTimeout(2000);
console.log('OTP SCREEN:', (await p.evaluate(()=>document.body.innerText.slice(0,600))).replace(/\n+/g,' | '));
await p.screenshot({path:OUT+'/L-B7-mobile-otp.png'});
// fill OTP inputs - try demo code visible or 0000/1234
const inputs = await p.locator('input').count();
console.log('inputs on otp screen=',inputs);
await b.close();
