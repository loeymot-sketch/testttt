import { chromium } from 'playwright';
const b=await chromium.launch();
const ctx=await b.newContext({viewport:{width:390,height:844}});
const p=await ctx.newPage();
await p.goto('http://127.0.0.1:8087/',{waitUntil:'networkidle',timeout:30000});
await p.waitForTimeout(1500);
const data=await p.evaluate(()=>{
  const m=window.LC && window.LC.menu;
  if(!m) return {err:'no menu'};
  const items=m.items||[];
  const cats=m.categories||[];
  const byCat={};
  const catName={};
  cats.forEach(c=>catName[c.id]=c.slug||c.name);
  items.forEach(i=>{const k=catName[i.category_id]||i.category_id;byCat[k]=(byCat[k]||0)+1;});
  return {nItems:items.length,nCats:cats.length,byCat,
    names:items.map(i=>({n:i.name,p:i.price,c:catName[i.category_id]}))};
});
console.log(JSON.stringify(data,null,1));
await b.close();
