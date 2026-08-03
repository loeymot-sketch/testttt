const { chromium } = require('playwright');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/mobile';
const log=(...a)=>console.log(...a);
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport:{width:390,height:844}, deviceScaleFactor:2 });
  const page = await ctx.newPage();
  await page.addInitScript(() => {
    localStorage.setItem('lecayenne.auth', JSON.stringify({ token:'6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e', phone:'0697222388', user_id:189 }));
    localStorage.setItem('lecayenne.onboarding_seen','true');
  });
  await page.goto('http://127.0.0.1:8087/',{waitUntil:'networkidle',timeout:30000});
  await page.waitForTimeout(3500);
  // Reach profile then loyalty via role button aria-label 'Carte fidélité'
  await page.evaluate(()=>{const n=[...document.querySelectorAll('[data-testid],button,[role="button"]')].find(e=>/PROFIL/.test((e.textContent||'').trim())&&(e.textContent||'').trim().length<10);if(n)n.click();});
  await page.waitForTimeout(1200);
  await page.evaluate(()=>{const n=[...document.querySelectorAll('[aria-label]')].find(e=>/Carte fidélité/.test(e.getAttribute('aria-label')||''));if(n)n.click();});
  await page.waitForTimeout(2500);
  // click the real tab testid
  const clicked = await page.evaluate(()=>{const t=document.querySelector('[data-testid="loyalty-tab-rewards"]');if(t){t.click();return true;}return false;});
  await page.waitForTimeout(1800);
  await page.screenshot({path:`${OUT}/v4-reductions-panel.png`,fullPage:true});
  const r = await page.evaluate(()=>{
    const b=document.body.innerText;
    const panel=document.querySelector('#loyalty-tabpanel-rewards, [id*="rewards"]');
    return {
      tabRewardsExists: !!document.querySelector('[data-testid="loyalty-tab-rewards"]'),
      redeemValue: document.querySelector('[data-testid="redeem-points-value"]')?.textContent||null,
      lockedMsg: /il te manque|minimum \d+ pts|Connecte-toi/i.test(b),
      continuous: /pts?\s*=\s*1\s*€|tranche de|1 € de réduction/i.test(b),
      mockNames:['Frites offertes','Boisson offerte','Café offert','Dessert offert','Burger offert'].filter(n=>b.includes(n)),
      panelText: panel? panel.innerText.slice(0,400): '(panel not found; body slice) '+b.slice(0,400)
    };
  });
  log('REDUCTIONS_PANEL =',JSON.stringify(r,null,2),'clicked=',clicked);
  await browser.close();
})().catch(e=>{console.error('FATAL',e);process.exit(1);});
