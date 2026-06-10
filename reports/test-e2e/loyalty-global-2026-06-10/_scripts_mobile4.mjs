import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({viewport:{width:390,height:844}})).newPage();
await p.goto('http://127.0.0.1:8097/',{waitUntil:'networkidle'});
await p.waitForTimeout(2000);
const skip = p.locator('text=PASSER').first();
if(await skip.count()) { await skip.click(); await p.waitForTimeout(1500); }
const info = await p.evaluate(()=>[...document.querySelectorAll('input,textarea,[contenteditable]')].map(e=>({tag:e.tagName,type:e.type,id:e.id,cls:e.className,ph:e.placeholder,visible:!!(e.offsetWidth||e.offsetHeight)})));
console.log(JSON.stringify(info,null,1));
await b.close();
