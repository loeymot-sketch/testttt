import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/rt';
const b=await chromium.launch();
const p=await (await b.newContext({viewport:{width:1280,height:900}})).newPage();
await p.goto('http://127.0.0.1:8096/',{waitUntil:'networkidle'});
// Tab to the "Menu" nav link (skip-link, logo, Accueil, Menu = 4 tabs)
for(let i=0;i<4;i++)await p.keyboard.press('Tab');
const full=await p.evaluate(()=>{const a=document.activeElement;const s=getComputedStyle(a);const sb=getComputedStyle(a,'::before');const sa=getComputedStyle(a,'::after');
 return{txt:a.textContent.trim().slice(0,12),tag:a.tagName,
  outline:`${s.outlineStyle} ${s.outlineWidth} ${s.outlineColor}`,
  boxShadow:s.boxShadow, bg:s.backgroundColor, color:s.color, textDecoration:s.textDecorationLine,
  afterContent:sa.content, afterBg:sa.backgroundColor, afterH:sa.height, beforeContent:sb.content};});
console.log('FOCUSED-MENU',JSON.stringify(full,null,1));
// screenshot header region focused
await p.screenshot({path:OUT+'/focus-menu-link.png',clip:{x:0,y:0,width:1280,height:80}});
// compare hover state colors
await b.close();
