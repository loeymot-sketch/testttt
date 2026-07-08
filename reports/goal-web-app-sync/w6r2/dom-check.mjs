import { chromium } from 'playwright';
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r2';
const TOKEN = '6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e';
const PHONE = '0697222388';
const b = await chromium.launch();
const ctx = await b.newContext({ viewport:{width:1280,height:2000} });
const p = await ctx.newPage();
const errs=[];
p.on('console', m=>{ if(m.type()==='error') errs.push(m.text()); });
// seed localStorage before app boot
await p.goto('http://127.0.0.1:8096/', {waitUntil:'domcontentloaded'});
await p.evaluate(([t,ph])=>{ localStorage.setItem('lecayenne.authToken',t); localStorage.setItem('lecayenne.authPhone',ph); }, [TOKEN,PHONE]);
await p.goto('http://127.0.0.1:8096/', {waitUntil:'networkidle'});
await p.waitForTimeout(2500);
const home = await p.evaluate(()=>document.body.innerText);
await p.screenshot({path:OUT+'/web-home-authed.png', fullPage:true});
const hasIkyesHome = /Ikyes/i.test(home);
// Try navigate to loyalty/account. Web is hash or client-route? inspect nav
const routes = await p.evaluate(()=>{
  return [...document.querySelectorAll('a,button')].map(e=>({t:(e.innerText||'').trim(), href:e.getAttribute('href')})).filter(x=>x.t).slice(0,60);
});
// click fidelite/compte link if exists
let acctText='';
try {
  const link = await p.$('text=/fid[ée]lit[ée]|compte|mon compte/i');
  if(link){ await link.click(); await p.waitForTimeout(2000); }
  acctText = await p.evaluate(()=>document.body.innerText);
  await p.screenshot({path:OUT+'/web-loyalty-authed.png', fullPage:true});
}catch(e){ acctText='(nav fail '+e.message+')'; }
const hasIkyesAcct=/Ikyes/i.test(acctText);
// check for standalone 'IB' pastille (avatar initials) - search DOM elements text exactly 'IB'
const ibPastille = await p.evaluate(()=>{
  return [...document.querySelectorAll('*')].some(e=>e.children.length===0 && e.textContent.trim()==='IB');
});
const leaderboardRendered = await p.evaluate(()=>!!document.querySelector('.lc-leader'));
console.log(JSON.stringify({hasIkyesHome, hasIkyesAcct, ibPastille, leaderboardRendered, errs:errs.slice(0,8), routes:routes.map(r=>r.t).slice(0,40)},null,2));
await b.close();
