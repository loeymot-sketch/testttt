import { chromium } from 'playwright';
const b=await chromium.launch();
const ctx=await b.newContext({viewport:{width:390,height:844}});
const p=await ctx.newPage();
await p.goto('http://127.0.0.1:8087/',{waitUntil:'networkidle',timeout:30000});
await p.waitForTimeout(1500);
const d=await p.evaluate(()=>{
  const m=window.LC.menu;
  const cats=m.categories.filter(c=>(c.slug||'').includes('frite'));
  const fcid=cats.map(c=>c.id);
  const frites=m.items.filter(i=>fcid.includes(i.category_id));
  return frites.map(i=>({name:i.name,price:i.price,keys:Object.keys(i),
    steps:i.steps?i.steps.length:undefined, addons:i.addons, options:i.options,
    raw:JSON.stringify(i).slice(0,600)}));
});
console.log(JSON.stringify(d,null,1));
await b.close();
