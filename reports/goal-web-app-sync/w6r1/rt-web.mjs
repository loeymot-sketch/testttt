import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/rt';
import fs from 'fs';
fs.mkdirSync(OUT,{recursive:true});
const b=await chromium.launch();
const p=await (await b.newContext({viewport:{width:1280,height:900}})).newPage();
const errs=[];
p.on('console',m=>{if(m.type()==='error')errs.push(m.text());});
p.on('pageerror',e=>errs.push('PAGEERR '+e.message));
await p.goto('http://127.0.0.1:8096/',{waitUntil:'networkidle'});
// data layer counts
const data=await p.evaluate(()=>({items:(window.LC?.menu?.items||window.W_ITEMS||[]).length, cats:(window.W_CATS||window.LC?.menu?.categories||[]).length}));
console.log('DATA', JSON.stringify(data));
// go to menu
await p.getByText('Menu',{exact:true}).first().click().catch(async()=>{await p.click('text=Menu');});
await p.waitForTimeout(1200);
// ensure Tout selected
await p.getByText('Tout',{exact:false}).first().click().catch(()=>{});
await p.waitForTimeout(1000);
// count rendered cards heuristically: elements showing price €
const rendered=await p.evaluate(()=>{
  const txt=[...document.querySelectorAll('*')].filter(e=>e.children.length===0 && /€/.test(e.textContent)&&e.textContent.length<12);
  // count add buttons
  const plus=[...document.querySelectorAll('button')].filter(b=>/^\+$|plus/i.test(b.textContent.trim())||b.querySelector('svg'));
  const res=document.body.innerText.match(/(\d+)\s*r[ée]sultats/);
  return {priceEls:txt.length, resultsText:res?res[1]:null};
});
console.log('MENU-TOUT', JSON.stringify(rendered));
await p.screenshot({path:OUT+'/menu-tout-live.png',fullPage:false});
await p.screenshot({path:OUT+'/menu-tout-full.png',fullPage:true});
console.log('CONSOLE_ERRORS',JSON.stringify(errs.slice(0,20)));
await b.close();
