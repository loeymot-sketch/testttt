import { chromium } from 'playwright';
const OUT=process.env.OUT;
const b=await chromium.launch();
const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2});
const p=await ctx.newPage();
const errs=[];
p.on('pageerror',e=>errs.push('PAGEERR '+e.message));
await p.addInitScript(()=>{
  localStorage.setItem('lecayenne.auth',JSON.stringify({token:'6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e',phone:'0697222388',user_id:189}));
  localStorage.setItem('lecayenne.onboarding_seen','true');
  localStorage.setItem('lecayenne.cart',JSON.stringify([{id:101,name:'Cayenne',price:7.4,qty:1,lineTotal:7.4,sups:[]}]));
});
await p.goto('http://127.0.0.1:8087/',{waitUntil:'networkidle',timeout:30000});
await p.waitForTimeout(1800);
// navigate to cart: from home there is usually a cart icon; force via clicking any 'panier'/cart. Use menu tab then a cart affordance. Simplest: dispatch a custom nav by clicking header cart.
// Try to find cart entrypoint
await p.evaluate(()=>{const el=[...document.querySelectorAll('*')].find(e=>/panier|cart/i.test(e.getAttribute&&(e.getAttribute('aria-label')||''))||/🛒/.test(e.innerText||''));el&&el.click&&el.click();});
await p.waitForTimeout(1000);
let lbl=await p.evaluate(()=>{const l=document.querySelector('[data-screen-label]');return l&&l.getAttribute('data-screen-label');});
console.log('after cart-icon attempt:',lbl);
await p.screenshot({path:OUT+'/16-cartnav.png'});
console.log('ERRS',JSON.stringify(errs));
await b.close();
