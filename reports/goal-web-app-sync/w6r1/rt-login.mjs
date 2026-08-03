import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/rt';
const b=await chromium.launch();
const p=await (await b.newContext({viewport:{width:1280,height:900}})).newPage();
const errs=[];p.on('pageerror',e=>errs.push(e.message));p.on('console',m=>{if(m.type()==='error')errs.push('C:'+m.text());});
await p.goto('http://127.0.0.1:8096/',{waitUntil:'networkidle'});
await p.click('text=Se connecter'); await p.waitForTimeout(800);
// enumerate modal buttons + inputs
const modal=await p.evaluate(()=>{
  const btns=[...document.querySelectorAll('button')].filter(b=>b.offsetParent).map(b=>b.textContent.trim().slice(0,20)).filter(t=>t);
  const inputs=[...document.querySelectorAll('input')].filter(i=>i.offsetParent).map(i=>({type:i.type,ph:i.placeholder,name:i.name}));
  return {btns:[...new Set(btns)],inputs};
});
console.log('MODAL',JSON.stringify(modal,null,1));
// try Google button, capture what happens (new tab? nothing?)
const before=p.context().pages().length;
await p.click('text=Google').catch(e=>console.log('GOOGLE-CLICK-ERR',e.message));
await p.waitForTimeout(1200);
console.log('AFTER-GOOGLE pages=',p.context().pages().length,'(before',before,') errs=',JSON.stringify(errs.slice(0,5)));
// fill email+password and submit, see result
await p.fill('input[type=email], input[placeholder*="exemple"]','test@test.fr').catch(()=>{});
await p.fill('input[type=password]','password123').catch(()=>{});
await p.click('button:has-text("Se connecter")').catch(e=>console.log('SUBMIT-ERR',e.message));
await p.waitForTimeout(1500);
const after=await p.evaluate(()=>document.body.innerText.replace(/\s+/g,' ').slice(0,400));
console.log('AFTER-EMAIL-SUBMIT:',after);
await p.screenshot({path:OUT+'/login-after-emailsubmit.png'});
console.log('ERRS',JSON.stringify(errs.slice(0,8)));
await b.close();
