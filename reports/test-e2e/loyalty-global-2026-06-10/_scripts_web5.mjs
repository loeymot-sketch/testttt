import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:1440,height:900}})).newPage();
await p.goto('http://127.0.0.1:8096/',{waitUntil:'networkidle'});
await p.waitForTimeout(2500);
await p.locator('button').filter({hasText:/Se connecter/}).first().click();
await p.waitForTimeout(1500);
const html = await p.evaluate(()=>{
  const f = document.querySelector('input[type=email]')?.closest('form') || document.querySelector('input[type=email]')?.closest('div[class*="modal"],dialog,[role=dialog]');
  return f ? f.outerHTML.slice(0,2500) : 'NO FORM';
});
console.log(html);
await b.close();
