import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r2';
const TOKEN='6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e', PHONE='0697222388';
const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1280,height:2200}}); const p=await ctx.newPage();
await p.goto('http://127.0.0.1:8096/',{waitUntil:'domcontentloaded'});
await p.evaluate(([t,ph])=>{localStorage.setItem('lecayenne.authToken',t);localStorage.setItem('lecayenne.authPhone',ph);},[TOKEN,PHONE]);
await p.goto('http://127.0.0.1:8096/',{waitUntil:'networkidle'}); await p.waitForTimeout(1500);
// click Fidélité nav
const res={};
for(const label of ['Fidélité','Mon compte']){
  try{
    const el=await p.$('nav >> text='+label) || await p.$('text='+label);
    if(el){ await el.click(); await p.waitForTimeout(2000);}    
    const txt=await p.evaluate(()=>document.body.innerText);
    res[label]={ikyes:/Ikyes/i.test(txt), lecay347:/LECAY-347|347 pts|·4242/i.test(txt), visa4242:/4242/.test(txt), leader:!!document.querySelector('.lc-leader')};
    await p.screenshot({path:OUT+'/web-'+label.replace(/\s/g,'_')+'.png',fullPage:true});
    res[label].sample=txt.slice(0,600).replace(/\n+/g,' | ');
  }catch(e){res[label]={err:e.message};}
}
console.log(JSON.stringify(res,null,2));
await b.close();
