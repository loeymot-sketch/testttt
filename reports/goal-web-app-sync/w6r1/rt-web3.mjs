import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/rt';
const b=await chromium.launch();
const p=await (await b.newContext({viewport:{width:375,height:812},deviceScaleFactor:2})).newPage();
await p.goto('http://127.0.0.1:8096/',{waitUntil:'networkidle'});
const ov=await p.evaluate(()=>({sw:document.documentElement.scrollWidth,cw:document.documentElement.clientWidth}));
console.log('HOME375', JSON.stringify(ov), ov.sw>ov.cw+1?'HORIZ-SCROLL':'ok');
await p.screenshot({path:OUT+'/m375-home.png'});
// find nav trigger (burger)
const navBtns=await p.evaluate(()=>[...document.querySelectorAll('header button, nav button')].map(b=>({txt:b.textContent.trim().slice(0,10),aria:b.getAttribute('aria-label'),vis:b.offsetParent!==null})));
console.log('NAVBTNS',JSON.stringify(navBtns));
const wide=await p.evaluate(()=>{const vw=innerWidth;return [...document.querySelectorAll('*')].filter(e=>e.getBoundingClientRect().width>vw+2&&e.offsetParent).slice(0,6).map(e=>({tag:e.tagName,cls:(e.className||'').toString().slice(0,40),w:Math.round(e.getBoundingClientRect().width)}));});
console.log('WIDE-ELS',JSON.stringify(wide));
// focus check desktop-size
await p.setViewportSize({width:1280,height:900});
await p.goto('http://127.0.0.1:8096/',{waitUntil:'networkidle'});
let focs=[];
for(let i=0;i<5;i++){await p.keyboard.press('Tab');const f=await p.evaluate(()=>{const a=document.activeElement;const s=getComputedStyle(a);return{txt:(a.textContent||'').trim().slice(0,16),outline:s.outlineStyle,ow:s.outlineWidth,bs:s.boxShadow.slice(0,30)};});focs.push(f);}
console.log('FOCUS',JSON.stringify(focs,null,1));
await b.close();
