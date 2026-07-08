import { chromium } from 'playwright';
const OUT=process.env.OUT;
const b=await chromium.launch();
const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2});
const p=await ctx.newPage();
const errs=[];
p.on('console',m=>{if(m.type()==='error')errs.push(m.text());});
p.on('pageerror',e=>errs.push('PAGEERR '+e.message));
await p.goto('http://127.0.0.1:8087/',{waitUntil:'networkidle',timeout:30000});
await p.waitForTimeout(2500);
await p.screenshot({path:OUT+'/01-splash.png'});
const info=await p.evaluate(()=>{
  const lbl=document.querySelector('[data-screen-label]');
  return {label:lbl?lbl.getAttribute('data-screen-label'):null,
    rootLen:document.getElementById('root')?document.getElementById('root').innerHTML.length:0,
    title:document.title};
});
console.log('INFO',JSON.stringify(info));
console.log('ERRS',JSON.stringify(errs));
await b.close();
