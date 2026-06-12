import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-global-2026-06-10';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:390,height:844}})).newPage();
await p.goto('http://127.0.0.1:8097/',{waitUntil:'networkidle'});
await p.waitForTimeout(2000);
const skip = p.locator('text=PASSER').first();
if(await skip.count()) { await skip.click(); await p.waitForTimeout(2000); }
console.log('AFTER SKIP:', (await p.evaluate(()=>document.body.innerText.slice(0,500))).replace(/\n+/g,' | '));
await p.screenshot({path:OUT+'/L-B7-mobile-home.png'});
// list clickable nav labels
const navs = await p.evaluate(()=>[...document.querySelectorAll('nav *,[class*="tab"],[class*="nav"] *,button,a')].map(e=>e.textContent.trim()).filter(t=>t&&t.length<25).slice(0,40));
console.log('NAV:',JSON.stringify([...new Set(navs)]));
const loy = p.locator('text=/fid[ée]lit[ée]/i').first();
if(await loy.count()){ await loy.click(); await p.waitForTimeout(2000);
  await p.screenshot({path:OUT+'/L-B7-mobile-loyalty.png'});
  console.log('LOYALTY:', (await p.evaluate(()=>document.body.innerText.slice(0,1500))).replace(/\n+/g,' | '));
} else console.log('no fidelite link');
await b.close();
