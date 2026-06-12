import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-global-2026-06-10';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:1440,height:900}})).newPage();
await p.goto('http://127.0.0.1:8096/',{waitUntil:'networkidle'});
await p.waitForTimeout(2500);
await p.locator('button').filter({hasText:/Se connecter/}).first().click();
await p.waitForTimeout(1500);
await p.locator('input[type=email]').fill('ikyes@example.com');
await p.locator('input[type=password]').fill('motdepasse123');
await p.locator('.lc-modal button').filter({hasText:'Se connecter'}).last().click({force:true});
await p.waitForTimeout(2500);
const go = p.locator('button').filter({hasText:/Commencer à commander/});
if(await go.count()) await go.click({force:true});
await p.waitForTimeout(2000);
console.log('HEADER:', (await p.evaluate(()=>document.querySelector('header')?.innerText.replace(/\n+/g,' | ')))); 
await p.locator('header').locator('a,button').filter({hasText:'Fidélité'}).first().click({force:true});
await p.waitForTimeout(2500);
const t = await p.evaluate(()=>document.body.innerText);
console.log('LOYALTY:', t.slice(0,2400).replace(/\n+/g,' | '));
await p.screenshot({path:OUT+'/L-B8-web-loyalty-connected.png',fullPage:true});
// try redeem if panel present
const use = p.locator('button').filter({hasText:/Utiliser/i}).first();
if(await use.count()){
  console.log('USE BTN FOUND');
  await use.click({force:true});
  await p.waitForTimeout(2000);
  console.log('AFTER USE:', (await p.evaluate(()=>document.body.innerText.slice(0,1500))).replace(/\n+/g,' | '));
  await p.screenshot({path:OUT+'/L-B8-web-redeem.png',fullPage:true});
}
await b.close();
