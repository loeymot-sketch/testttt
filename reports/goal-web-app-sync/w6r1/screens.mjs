import { chromium } from 'playwright';
const OUT=process.env.OUT;
const b=await chromium.launch();
const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2});
const p=await ctx.newPage();
const errs=[];
p.on('console',m=>{if(m.type()==='error')errs.push('CON '+m.text());});
p.on('pageerror',e=>errs.push('PAGEERR '+e.message));
await p.addInitScript(()=>{
  localStorage.setItem('lecayenne.auth', JSON.stringify({token:'6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e',phone:'0697222388',user_id:189}));
  localStorage.setItem('lecayenne.onboarding_seen','true');
});
await p.goto('http://127.0.0.1:8087/',{waitUntil:'networkidle',timeout:30000});
await p.waitForTimeout(2000);
async function clickText(t){ await p.evaluate((t)=>{const el=[...document.querySelectorAll('button,[role=button],a,.lc-tab,.lc-tap')].find(b=>(b.innerText||'').trim().includes(t)); if(el)el.click();}, t); }
// black-on-black detector: elements with visible text where color≈bg
async function contrastScan(tag){
  return await p.evaluate((tag)=>{
    function lum(c){const m=c.match(/\d+/g);if(!m)return null;const[r,g,bl]=m.map(Number);return 0.299*r+0.587*g+0.114*bl;}
    const bad=[];
    for(const el of document.querySelectorAll('*')){
      const txt=(el.childNodes.length&&[...el.childNodes].some(n=>n.nodeType===3&&n.textContent.trim()))?el.textContent.trim():'';
      if(!txt||txt.length>60)continue;
      const s=getComputedStyle(el);
      if(s.visibility==='hidden'||s.opacity==='0')continue;
      const fg=lum(s.color); if(fg==null)continue;
      // find effective bg
      let node=el,bg=null;
      while(node){const bs=getComputedStyle(node).backgroundColor;if(bs&&bs!=='rgba(0, 0, 0, 0)'&&bs!=='transparent'){bg=lum(bs);break;}node=node.parentElement;}
      if(bg==null)bg=255;
      if(Math.abs(fg-bg)<32){const r=el.getBoundingClientRect();if(r.width>0&&r.height>0&&r.top<844)bad.push({tag,txt:txt.slice(0,40),fg:Math.round(fg),bg:Math.round(bg)});}
    }
    return bad.slice(0,15);
  }, tag);
}
const results={};
// MENU
await clickText('MENU'); await p.waitForTimeout(1200); await p.screenshot({path:OUT+'/11-menu.png'}); results.menu=await contrastScan('menu');
// ITEM (click first product card)
await p.evaluate(()=>{const el=[...document.querySelectorAll('.lc-device *')].find(b=>/7,90|Tacos|Sandwich|Ajouter|Choisir/i.test(b.innerText||'')&&b.getBoundingClientRect().width>100&&b.getBoundingClientRect().width<380); if(el)el.click();});
await p.waitForTimeout(1000); await p.screenshot({path:OUT+'/12-item.png'}); results.item=await contrastScan('item');
// back to menu then PROFIL
await clickText('MENU'); await p.waitForTimeout(600);
await clickText('PROFIL'); await p.waitForTimeout(1200); await p.screenshot({path:OUT+'/13-profil.png'}); results.profil=await contrastScan('profil');
// loyalty (click a fidelite element)
await clickText('fidélité'); await p.waitForTimeout(500); await clickText('Fidélité'); await p.waitForTimeout(500); await clickText('points'); await p.waitForTimeout(900);
await p.screenshot({path:OUT+'/14-loyalty.png'}); results.loyalty=await contrastScan('loyalty');
// COMMANDES
await clickText('COMMANDES'); await p.waitForTimeout(1200); await p.screenshot({path:OUT+'/15-orders.png'}); results.orders=await contrastScan('orders');
console.log('CONTRAST',JSON.stringify(results,null,1));
console.log('ERRS',JSON.stringify(errs.slice(0,15),null,1));
await b.close();
