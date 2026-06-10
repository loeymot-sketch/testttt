import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-global-2026-06-10';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:1440,height:900}})).newPage();
await p.goto('http://127.0.0.1:8096/',{waitUntil:'networkidle'});
await p.waitForTimeout(2500);
// not connected state of Fidélité
await p.locator('a,button').filter({hasText:/^Fidélité$/}).first().click();
await p.waitForTimeout(2000);
await p.screenshot({path:OUT+'/L-B8-web-loyalty-disconnected.png',fullPage:true});
console.log('DISCONNECTED:', (await p.evaluate(()=>document.body.innerText.slice(0,900))).replace(/\n+/g,' | '));
// login
await p.locator('a,button').filter({hasText:/Se connecter/}).first().click();
await p.waitForTimeout(2000);
console.log('LOGIN SCREEN:', (await p.evaluate(()=>document.body.innerText.slice(0,800))).replace(/\n+/g,' | '));
const ins = await p.evaluate(()=>[...document.querySelectorAll('input:not([type=hidden])')].map(e=>({type:e.type,ph:e.placeholder})));
console.log('INPUTS:', JSON.stringify(ins));
const btns = await p.evaluate(()=>[...document.querySelectorAll('button')].map(e=>e.textContent.trim()).filter(Boolean).slice(0,15));
console.log('BTNS:', JSON.stringify(btns));
await p.screenshot({path:OUT+'/L-B8-web-login.png'});
await b.close();
