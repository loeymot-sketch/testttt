import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/rt';
const b=await chromium.launch();
const p=await (await b.newContext({viewport:{width:1280,height:900}})).newPage();
await p.goto('http://127.0.0.1:8096/',{waitUntil:'networkidle'});
await p.click('text=Menu'); await p.waitForTimeout(800);
// click Boissons category
await p.getByText('Boissons',{exact:false}).first().click().catch(()=>{});
await p.waitForTimeout(800);
// inspect the add buttons: bg color + whether disabled
const info=await p.evaluate(()=>{
  const cards=[...document.querySelectorAll('button')].filter(b=>{
     const s=getComputedStyle(b); return b.offsetWidth>20&&b.offsetWidth<60&&b.offsetHeight>20&&b.offsetHeight<60;
  });
  return cards.slice(0,6).map(b=>{const s=getComputedStyle(b);return{bg:s.backgroundColor,color:s.color,disabled:b.disabled,txt:b.textContent.trim().slice(0,4),op:s.opacity};});
});
console.log('BOISSONS-ADD-BTNS',JSON.stringify(info,null,1));
await p.screenshot({path:OUT+'/boissons-live.png'});
// contrast of product title on drink card
const titles=await p.evaluate(()=>{
  const els=[...document.querySelectorAll('*')].filter(e=>/Coca-Cola 33cl|Coca Cherry/i.test(e.textContent)&&e.children.length===0);
  return els.slice(0,3).map(e=>{const s=getComputedStyle(e);return{t:e.textContent.trim(),color:s.color,size:s.fontSize};});
});
console.log('DRINK-TITLES',JSON.stringify(titles));
await b.close();
