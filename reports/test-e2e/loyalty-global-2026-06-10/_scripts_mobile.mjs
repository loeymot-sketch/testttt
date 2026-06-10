import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-global-2026-06-10';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:390,height:844}})).newPage();
await p.goto('http://127.0.0.1:8097/',{waitUntil:'networkidle'});
await p.waitForTimeout(2500);
await p.screenshot({path:OUT+'/L-B7-mobile-home.png'});
console.log('HOME:', (await p.evaluate(()=>document.body.innerText.slice(0,400))).replace(/\n+/g,' | '));
// find loyalty nav
const loy = p.locator('text=/fid[ée]lit[ée]/i').first();
if(await loy.count()){ await loy.click(); await p.waitForTimeout(1500); }
await p.screenshot({path:OUT+'/L-B7-mobile-loyalty.png'});
console.log('LOYALTY:', (await p.evaluate(()=>document.body.innerText.slice(0,1200))).replace(/\n+/g,' | '));
await b.close();
