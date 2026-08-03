import { chromium } from 'playwright';
const b=await chromium.launch();
const p=await (await b.newContext({viewport:{width:1280,height:900}})).newPage();
const net=[];p.on('request',r=>net.push(r.method()+' '+r.url()));
await p.goto('http://127.0.0.1:8096/',{waitUntil:'networkidle'});
await p.click('text=Se connecter'); await p.waitForTimeout(600);
net.length=0;
// grab onclick handler presence for Google/Apple
const handlers=await p.evaluate(()=>{
  const g=[...document.querySelectorAll('button')].find(b=>/Google/.test(b.textContent));
  const a=[...document.querySelectorAll('button')].find(b=>/Apple/.test(b.textContent));
  return {googleDisabled:g?.disabled, appleDisabled:a?.disabled, googleHTML:g?.outerHTML.slice(0,120)};
});
console.log('HANDLERS',JSON.stringify(handlers));
const beforeText=await p.evaluate(()=>document.querySelector('.lc-modal-backdrop')?true:false);
await p.evaluate(()=>{const g=[...document.querySelectorAll('button')].find(b=>/Google/.test(b.textContent)); g&&g.click();});
await p.waitForTimeout(1000);
const state=await p.evaluate(()=>({modalStillOpen:!!document.querySelector('.lc-modal-backdrop'), toast:[...document.querySelectorAll('*')].map(e=>e.textContent).find(t=>/bient[oô]t|indisponible|prochainement/i.test(t||''))?.slice(0,60)}));
console.log('AFTER-GOOGLE-CLICK modalOpenBefore=',beforeText,'state=',JSON.stringify(state));
console.log('NET-DURING (google/oauth):',JSON.stringify(net.filter(u=>/google|oauth|auth|apple/i.test(u)).slice(0,8)));
await b.close();
