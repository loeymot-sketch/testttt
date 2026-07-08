import { chromium } from 'playwright';
const OUT=process.env.OUT;
const b=await chromium.launch();
const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2});
const p=await ctx.newPage();
const errs=[];
p.on('console',m=>{if(m.type()==='error')errs.push(m.text());});
p.on('pageerror',e=>errs.push('PAGEERR '+e.message));
await p.addInitScript(()=>{
  localStorage.setItem('lecayenne.auth', JSON.stringify({token:'6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e',phone:'0697222388',user_id:189}));
  localStorage.setItem('lecayenne.onboarding_seen', 'true');
});
await p.goto('http://127.0.0.1:8087/',{waitUntil:'networkidle',timeout:30000});
await p.waitForTimeout(2500);
// dump tab bar + buttons
const dump=await p.evaluate(()=>{
  const btns=[...document.querySelectorAll('button,[role=button],a')].map(b=>({t:(b.innerText||'').trim().slice(0,24),cls:b.className}));
  const lbl=document.querySelector('[data-screen-label]');
  return {label:lbl&&lbl.getAttribute('data-screen-label'), btns:btns.slice(0,40)};
});
console.log(JSON.stringify(dump,null,1));
await p.screenshot({path:OUT+'/10-home.png'});
console.log('ERRS',JSON.stringify(errs));
await b.close();
