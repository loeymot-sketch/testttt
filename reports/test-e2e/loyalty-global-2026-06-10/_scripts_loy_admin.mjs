import { chromium } from 'playwright';
const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-global-2026-06-10';
const b = await chromium.launch();
const ctx = await b.newContext({viewport:{width:1440,height:900}});
const p = await ctx.newPage();
await p.goto('http://127.0.0.1:8767/login',{waitUntil:'networkidle'});
await p.getByLabel(/email/i).fill('admin@lecayenne.fr');
await p.getByLabel(/mot de passe/i).fill('123456');
await p.keyboard.press('Enter');
await p.waitForTimeout(4000);
console.log('after login url=',p.url());
await p.goto('http://127.0.0.1:8767/admin/settings/loyalty-setup',{waitUntil:'networkidle'});
await p.waitForTimeout(2500);
await p.screenshot({path:OUT+'/L-A2-admin-loyalty-setup.png',fullPage:true});
// dump visible form labels + input values
const fields = await p.evaluate(()=>{
  const out=[];
  document.querySelectorAll('label').forEach(l=>{
    const input = l.closest('div')?.querySelector('input,select,textarea');
    out.push({label:l.textContent.trim(), value: input? input.value : null});
  });
  return out;
});
console.log(JSON.stringify(fields,null,1));
await b.close();
