import { chromium } from 'playwright';
const OUT=process.env.OUT;
const b=await chromium.launch();
const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2});
const p=await ctx.newPage();
const errs=[];
p.on('pageerror',e=>errs.push('PAGEERR '+e.message));
await p.goto('http://127.0.0.1:8087/',{waitUntil:'networkidle',timeout:30000});
await p.waitForTimeout(1500);
async function next(){await p.evaluate(()=>{const els=[...document.querySelectorAll('button,[role=button],a')];const el=els.find(b=>{const r=b.getBoundingClientRect();return r.width>40&&r.width<90&&r.height>40&&r.bottom>700;})||els.find(b=>/→|Suivant|Continuer|Commencer/.test(b.innerText));el&&el.click();});await p.waitForTimeout(900);}
await next(); await p.screenshot({path:OUT+'/02-onb2.png'});
await next(); await p.screenshot({path:OUT+'/03-onb3.png'});
await next(); await p.screenshot({path:OUT+'/04-onb4.png'});
await next(); await p.waitForTimeout(600);
const lbl=await p.evaluate(()=>{const l=document.querySelector('[data-screen-label]');return l&&l.getAttribute('data-screen-label');});
console.log('after onb label',lbl);
await p.screenshot({path:OUT+'/05-login.png'});
// dump login text for hardcoded phone/otp
const logintxt=await p.evaluate(()=>document.querySelector('.lc-device').innerText.slice(0,400));
console.log('LOGIN TXT:',JSON.stringify(logintxt));
// type phone and try to reach OTP (may need backend)
await p.evaluate(()=>{const el=document.querySelector('.lc-device input[inputmode=tel]');if(el){el.focus();}});
const inp=await p.$('.lc-device input[inputmode=tel]');
if(inp){await inp.fill('0612345678');}
await p.screenshot({path:OUT+'/05b-login-filled.png'});
console.log('ERRS',JSON.stringify(errs));
await b.close();
