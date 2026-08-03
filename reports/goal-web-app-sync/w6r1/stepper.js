const { chromium } = require('playwright');
const fs = require('fs');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/mobile/';
const TARGETS = [
  { name: 'Tacos M',        file: 'tacosM' },
  { name: 'Cayenne',        file: 'cayenne' },
  { name: 'Chicken Burger', file: 'burger' },
  { name: 'Bol Riz',        file: 'bolRiz' },
  { name: 'Bol Frites',     file: 'bolFrites' },
];
const clean = s => (s||'').replace(/\n{2,}/g,'\n').trim();
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  await p.goto('http://127.0.0.1:8087/index.html', { waitUntil: 'networkidle' });
  await p.waitForTimeout(600);
  await p.evaluate(() => { window.LC.storage.setAuth({ token:'e2e', phone:'0600000000', user_id:1 }); window.LC.storage.markOnboardingSeen(); });

  const report = {};
  for (const t of TARGETS) {
    await p.evaluate(() => window.LC.storage.setCart([]));
    await p.reload({ waitUntil: 'networkidle' }); await p.waitForTimeout(600);
    await p.locator('[role=tab][aria-label="Menu"]').first().click(); await p.waitForTimeout(600);
    const card = p.locator(`[aria-label^="Voir ${t.name}"]`).first();
    if (!(await card.count())) { report[t.name] = { error:'no-card' }; continue; }
    await card.scrollIntoViewIfNeeded(); await card.click(); await p.waitForTimeout(800);

    const steps = [];
    for (let i=0; i<8; i++) {
      const step = await p.evaluate(() => {
        const txt = document.body.innerText;
        const m = txt.match(/(\d+)\s*\/\s*(\d+)/);
        const head = (document.querySelector('h1,h2,h3,h4')||{}).textContent||'';
        return { idx: m?m[1]:'?', total: m?m[2]:'?', head: head.trim(), txt };
      });
      const fn = `${t.file}-s${step.idx}-${(step.head||'x').replace(/[^a-zA-Z0-9]/g,'').slice(0,16)}`;
      await p.screenshot({ path: OUT+fn+'.png', fullPage: true });
      steps.push({ idx: step.idx, total: step.total, head: step.head, file: fn+'.png', txt: clean(step.txt).slice(0,1400) });
      // advance
      const nextBtn = p.locator('button', { hasText: /^Suivant|Ajouter au panier|Ajouter/ }).last();
      // try clicking Suivant; if disabled, pick first choice card
      let advanced = false;
      const before = step.idx;
      // pick first choice option on the step (safe: satisfies required)
      const choice = p.locator('[role=radio], [role=checkbox]').first();
      if (await choice.count()) { try { await choice.click({timeout:1500}); await p.waitForTimeout(300); } catch(e){} }
      const nb = p.locator('button:has-text("Suivant")').first();
      const addb = p.locator('button:has-text("Ajouter")').first();
      if (await nb.count()) {
        try { await nb.click({ timeout: 2000 }); await p.waitForTimeout(700); advanced = true; } catch(e){}
      } else if (await addb.count()) {
        // reached final step (add to cart) — stop after capture
        break;
      }
      const after = await p.evaluate(() => { const m=document.body.innerText.match(/(\d+)\s*\/\s*(\d+)/); return m?m[1]:'?'; });
      if (after === before && !advanced) break;
      if (after === before) { // couldn't advance further
        // maybe last step; break
        const stillNext = await p.locator('button:has-text("Suivant")').count();
        if (!stillNext) break;
      }
    }
    report[t.name] = { steps: steps.map(s=>({idx:s.idx,total:s.total,head:s.head,file:s.file})), detail: steps };
  }
  fs.writeFileSync(OUT+'_stepper.json', JSON.stringify(report,null,1));
  for (const [k,v] of Object.entries(report)) {
    console.log('\n=================== '+k+' ===================');
    if (v.error){console.log('ERR',v.error);continue;}
    for (const s of v.detail) {
      console.log(`\n--- STEP ${s.idx}/${s.total} : ${s.head}  [${s.file}] ---`);
      console.log(s.txt);
    }
  }
  await b.close();
})();
