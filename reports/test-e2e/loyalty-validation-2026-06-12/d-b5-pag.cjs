const fs=require('fs'); const path=require('path'); const {chromium}=require('playwright');
const BASE='http://127.0.0.1:8767'; const OUT='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-validation-2026-06-12';
const EXE='/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';
const TOKEN=process.argv[2];
function log(s){fs.appendFileSync(path.join(OUT,'D-B5-divers.md'),s+'\n');console.log(s);}
(async()=>{
  const browser=await chromium.launch({headless:true,executablePath:fs.existsSync(EXE)?EXE:undefined});
  const ctx=await browser.newContext({viewport:{width:1280,height:900}});
  await ctx.route(/\/api\//,(route)=>{const h={...route.request().headers()};if(!route.request().url().includes('/api/auth/login'))h.authorization='Bearer '+TOKEN;route.continue({headers:h});});
  const page=await ctx.newPage();
  await page.goto(BASE+'/login',{waitUntil:'networkidle'});await page.waitForTimeout(1200);
  const tb=page.getByRole('textbox');await tb.nth(0).fill('admin@lecayenne.fr');await tb.nth(1).fill('123456');
  await page.getByRole('button',{name:/Connexion/i}).click();await page.waitForURL(/admin/,{timeout:20000});await page.waitForLoadState('networkidle');
  await page.goto(BASE+'/admin/coupons',{waitUntil:'networkidle'});
  await page.waitForFunction(()=>document.body.innerText.includes('Affichage de'),null,{timeout:15000});
  const r1=await page.evaluate(()=>[...document.querySelectorAll('tbody tr td:first-child')].map(t=>t.innerText.trim()));
  await page.getByRole('button',{name:'2',exact:true}).or(page.getByRole('link',{name:'2',exact:true})).first().click();
  await page.waitForTimeout(2000);
  const r2=await page.evaluate(()=>({rows:[...document.querySelectorAll('tbody tr td:first-child')].map(t=>t.innerText.trim()),aff:(document.body.innerText.match(/Affichage[^\n]+/)||[null])[0]}));
  log(`\n## STAGE 4b pagination réelle\n- p1 first3=${JSON.stringify(r1.slice(0,3))}\n- click "2" -> rows=${JSON.stringify(r2.rows)} aff=${JSON.stringify(r2.aff)} changed=${JSON.stringify(r1)!==JSON.stringify(r2.rows)}`);
  await page.screenshot({path:path.join(OUT,'D-B5-divers-coupons-p2-proof.png')});
  await browser.close(); log('STAGE4b DONE');
})().catch(e=>{log('FATAL4b '+String(e).slice(0,300));process.exit(1);});
