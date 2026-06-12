import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-global-2026-06-10';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:390,height:844}})).newPage();
await p.goto('http://127.0.0.1:8097/',{waitUntil:'networkidle'});
await p.waitForTimeout(2500);
let body = await p.evaluate(()=>document.body.innerText);
console.log('STATE0:', body.slice(0,200).replace(/\n+/g,' | '));
if(!/BONSOIR|BONJOUR|CATEGORIES/i.test(body)){
  const skip = p.locator('text=PASSER').first();
  if(await skip.count()) { await skip.click(); await p.waitForTimeout(2000); }
  const tel = p.locator('input[placeholder*="12 34"]');
  if(await tel.count()){
    await tel.click({force:true}); await p.keyboard.type('612345678',{delay:30});
    await p.locator('text=/RECEVOIR LE CODE/i').first().click(); await p.waitForTimeout(2500);
    const boxes = p.locator('input[maxlength="1"]');
    for(let i=0;i<4;i++){ await boxes.nth(i).click({force:true}); await p.keyboard.type(String(i+1)); }
    await p.waitForTimeout(3000);
  }
}
body = await p.evaluate(()=>document.body.innerText);
console.log('STATE1:', body.slice(0,300).replace(/\n+/g,' | '));
const labels = await p.evaluate(()=>[...document.querySelectorAll('[aria-label]')].map(e=>e.getAttribute('aria-label')).slice(0,40));
console.log('ARIA:', JSON.stringify(labels));
await p.screenshot({path:OUT+'/_dbg-mobile-state.png'});
await b.close();
