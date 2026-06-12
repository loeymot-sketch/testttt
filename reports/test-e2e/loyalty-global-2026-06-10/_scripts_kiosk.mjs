import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-global-2026-06-10';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:1080,height:1700}})).newPage();
await p.goto('http://127.0.0.1:8767/kiosk/idle',{waitUntil:'networkidle'}).catch(e=>console.log('nav err',e.message));
await p.waitForTimeout(3000);
console.log('url=',p.url());
await p.screenshot({path:OUT+'/L-A6-kiosk-idle.png'});
// look for loyalty entry
const txt = await p.evaluate(()=>document.body.innerText.slice(0,600));
console.log('BODY:',txt.replace(/\n+/g,' | '));
const loy = p.locator('text=/fid[ée]lit[ée]/i').first();
if(await loy.count()){ await loy.click(); await p.waitForTimeout(2000); await p.screenshot({path:OUT+'/L-A6-kiosk-loyalty.png'}); console.log('LOYALTY PAGE:', (await p.evaluate(()=>document.body.innerText.slice(0,600))).replace(/\n+/g,' | ')); }
else console.log('NO loyalty entry visible');
await b.close();
