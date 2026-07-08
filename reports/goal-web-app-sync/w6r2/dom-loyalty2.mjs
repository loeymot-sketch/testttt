import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r2';
const TOKEN='6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e', PHONE='0697222388';
const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:1280,height:2200}}); const p=await ctx.newPage();
await p.goto('http://127.0.0.1:8096/',{waitUntil:'domcontentloaded'});
await p.evaluate(([t,ph])=>{localStorage.setItem('lecayenne.authToken',t);localStorage.setItem('lecayenne.authPhone',ph);},[TOKEN,PHONE]);
await p.goto('http://127.0.0.1:8096/',{waitUntil:'networkidle'}); await p.waitForTimeout(1500);
const res={};
async function grab(name){
  const txt=await p.evaluate(()=>document.body.innerText);
  const leader=await p.evaluate(()=>!!document.querySelector('.lc-leader'));
  const ib=await p.evaluate(()=>[...document.querySelectorAll('*')].some(e=>e.children.length===0&&e.textContent.trim()==='IB'));
  await p.screenshot({path:OUT+'/web-'+name+'.png',fullPage:true});
  res[name]={ikyes:/Ikyes/i.test(txt),visa4242:/4242/.test(txt),lecay347:/LECAY-347/i.test(txt),leader,ibPastille:ib,sample:txt.slice(0,500).replace(/\n+/g,' | ')};
}
try{ await p.getByRole('link',{name:'Fidélité'}).first().click(); await p.waitForTimeout(2000);}catch(e){res.navFidErr=e.message;}
await grab('fidelite');
try{ await p.getByText('Mon compte').first().click(); await p.waitForTimeout(2000);}catch(e){res.navCompteErr=e.message;}
await grab('compte');
console.log(JSON.stringify(res,null,2));
await b.close();
