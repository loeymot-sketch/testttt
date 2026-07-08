import { chromium } from 'playwright';
const OUT=process.env.OUT;
const b=await chromium.launch();
const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2});
const p=await ctx.newPage();
await p.addInitScript(()=>{
  localStorage.setItem('lecayenne.auth',JSON.stringify({token:'6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e',phone:'0697222388',user_id:189}));
  localStorage.setItem('lecayenne.onboarding_seen','true');
  localStorage.setItem('lecayenne.cart',JSON.stringify([{id:101,name:'Cayenne',price:7.4,qty:1,lineTotal:7.4,sups:[]}]));
});
await p.goto('http://127.0.0.1:8087/',{waitUntil:'networkidle',timeout:30000});
await p.waitForTimeout(1500);
await p.evaluate(()=>{const el=[...document.querySelectorAll('.lc-tab')].find(b=>/MENU/.test(b.innerText));el&&el.click();});
await p.waitForTimeout(1200);
await p.screenshot({path:OUT+'/17-menu-withcart.png'});
// dump any element mentioning panier/voir/total
const bars=await p.evaluate(()=>[...document.querySelectorAll('*')].filter(e=>{const t=(e.innerText||'');return /panier|voir mon|commander|7,40|passer/i.test(t)&&e.getBoundingClientRect().bottom>700;}).map(e=>({t:(e.innerText||'').slice(0,40),tag:e.tagName})).slice(0,8));
console.log('BOTTOM BARS',JSON.stringify(bars,null,1));
await b.close();
