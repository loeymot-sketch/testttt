import { chromium } from 'playwright';
const OUT=process.env.OUT;
const b=await chromium.launch();
const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2});
const p=await ctx.newPage();
const errs=[];
p.on('pageerror',e=>errs.push('PAGEERR '+e.message));
await p.addInitScript(()=>{localStorage.setItem('lecayenne.auth',JSON.stringify({token:'6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e',phone:'0697222388',user_id:189}));localStorage.setItem('lecayenne.onboarding_seen','true');});
await p.goto('http://127.0.0.1:8087/',{waitUntil:'networkidle',timeout:30000});
await p.waitForTimeout(1800);
// go MENU
await p.evaluate(()=>{const el=[...document.querySelectorAll('.lc-tab')].find(b=>/MENU/.test(b.innerText));el&&el.click();});
await p.waitForTimeout(1200);
// click first product arrow (the round orange button in a card)
await p.evaluate(()=>{const cards=[...document.querySelectorAll('.lc-device *')].filter(e=>/Cayenne/.test(e.innerText||'')&&e.getBoundingClientRect().width>250&&e.getBoundingClientRect().width<380&&e.getBoundingClientRect().height<200);(cards[0]||{click(){}}).click();});
await p.waitForTimeout(1200);
await p.screenshot({path:OUT+'/12b-item.png'});
const lbl1=await p.evaluate(()=>{const l=document.querySelector('[data-screen-label]');return l&&l.getAttribute('data-screen-label');});
console.log('ITEM label',lbl1);
await b.close();
