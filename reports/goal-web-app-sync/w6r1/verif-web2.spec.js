// Independent adversarial reproduction — payment page (flag OFF), account "Mon compte" tab, forgot. READ-ONLY.
const { test } = require('@playwright/test');
const fs = require('fs');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/web';
const FIX = JSON.parse(fs.readFileSync('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/e2e-fixtures.json','utf8'));
const TOKEN = FIX.clients[0].token, PHONE = FIX.clients[0].phone;

function wire(page, bag, tag){
  page.on('console', m => { if(m.type()==='error') bag.push(`[${tag}] ${m.text()}`); });
  page.on('pageerror', e => bag.push(`[${tag}][PAGEERROR] ${e.message}`));
}

async function reachPayment(page){
  await page.locator('.lc-nav-link', { hasText: 'Menu' }).click();
  await page.waitForTimeout(800);
  await page.locator('.lc-menu-side-link', { hasText: 'Sandwichs' }).click();
  await page.waitForTimeout(600);
  await page.locator('.lc-card-item', { hasText: 'Cayenne' }).first().click();
  await page.waitForTimeout(800);
  await page.getByRole('button', { name: /Personnaliser/ }).click();
  await page.waitForTimeout(600);
  // walk wizard: click first choice each step, until "Ajouter au panier"
  for (let i=0;i<12;i++){
    const next = page.locator('.lc-wiz-foot-next');
    const label = (await next.textContent().catch(()=> '')) || '';
    if (label.includes('Ajouter au panier')) { await next.click(); break; }
    const sansFormule = page.locator('.lc-wiz-choice', { hasText: 'Sans formule' });
    if (await sansFormule.count() && await sansFormule.first().isVisible().catch(()=>false)) await sansFormule.first().click();
    if (await next.isDisabled().catch(()=>true)) {
      const opt = page.locator('.lc-wiz-options .lc-wiz-choice').first();
      if (await opt.count()) await opt.click();
    }
    await next.click();
    await page.waitForTimeout(300);
  }
  await page.waitForTimeout(800);
  // panier
  await page.locator('.lc-nav-cart, [class*="cart"]').first().click().catch(()=>{});
  await page.waitForTimeout(600);
  await page.getByRole('button', { name: /Passer commande/ }).click().catch(()=>{});
  await page.waitForTimeout(700);
  // upsell skip
  for (let i=0;i<4;i++){
    const skip = page.getByRole('button', { name: /Non merci|Passer|Continuer sans|Ignorer/ });
    if (await skip.first().isVisible().catch(()=>false)) { await skip.first().click(); await page.waitForTimeout(400); } else break;
  }
  await page.getByRole('button', { name: /Continuer vers paiement/ }).click().catch(()=>{});
  await page.waitForTimeout(1200);
}

test('PAY reproduce flag OFF', async ({ page }) => {
  const errors=[]; const report={}; wire(page, errors, 'pay');
  await page.goto('/'); await page.waitForTimeout(2200);
  await reachPayment(page);
  report.dom = await page.evaluate(()=>{
    const t=document.body.innerText;
    return {
      isPayment: !!document.querySelector('.lcf-paymethods'),
      methods: [...document.querySelectorAll('.lcf-paymethod-name')].map(x=>x.textContent.trim()),
      stripe: /stripe/i.test(t), applePay:/apple\s*pay/i.test(t), googlePay:/google\s*pay/i.test(t),
      threeDS:/3d[\s-]?secure/i.test(t), payerSurPlace:/payer sur place/i.test(t), comptoir:/comptoir/i.test(t),
      cardForm: !!document.querySelector('.lcf-cardform'),
      preauth:/pré-?autoris|réservé sur ta carte|débit à la préparation/i.test(t),
    };
  });
  await page.screenshot({ path: `${OUT}/REPRO-payment.png`, fullPage:true });
  const dump = await page.locator('.lcf-paymethods').innerHTML().catch(()=> 'NO-PAYMETHODS');
  fs.writeFileSync(`${OUT}/REPRO-paymethods-OFF.html`, dump);
  fs.writeFileSync(`${OUT}/verif-pay2.json`, JSON.stringify({report, errors}, null, 2));
  console.log('PAY2', JSON.stringify(report)); console.log('PAY2ERR', JSON.stringify(errors));
});

test('ACCOUNT Mon compte tab', async ({ page }) => {
  const errors=[]; const report={}; wire(page, errors, 'acct');
  await page.goto('/');
  await page.evaluate(([t,p])=>{ localStorage.setItem('lecayenne.authToken',t); localStorage.setItem('lecayenne.authPhone',p); }, [TOKEN, PHONE]);
  await page.reload(); await page.waitForTimeout(2500);
  await page.locator('.lc-nav-link', { hasText: 'Fidélité' }).click();
  await page.waitForTimeout(2500);
  // click "Mon compte" tab
  await page.getByRole('tab', { name: /Mon compte/ }).click().catch(async()=>{
    await page.evaluate(()=>{ const e=[...document.querySelectorAll('button')].find(x=>/^mon compte$/i.test(x.textContent.trim())); if(e) e.click(); });
  });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/REPRO-account-moncompte.png`, fullPage:true });
  report.dom = await page.evaluate(()=>{
    const t=document.body.innerText;
    return {
      logout:/se déconnecter|déconnexion/i.test(t),
      fakeCard:/4242|visa\s*····|mastercard\s*····/i.test(t),
      aucuneCarte:/aucune carte enregistrée/i.test(t),
      stripe:/stripe/i.test(t),
      deleteInert: !!([...document.querySelectorAll('button')].find(b=>/supprimer mon compte/i.test(b.textContent))),
      profilName: (document.querySelector('.lc-profile-name')||{}).textContent||null,
    };
  });
  fs.writeFileSync(`${OUT}/verif-acct2.json`, JSON.stringify({report, errors}, null, 2));
  console.log('ACCT2', JSON.stringify(report)); console.log('ACCT2ERR', JSON.stringify(errors));
});

test('FORGOT honest', async ({ page }) => {
  const errors=[]; const report={}; wire(page, errors, 'forgot');
  await page.goto('/'); await page.waitForTimeout(2000);
  // open account modal (header Se connecter / compte)
  await page.evaluate(()=>{ const e=[...document.querySelectorAll('a,button')].find(x=>/se connecter|connexion/i.test(x.textContent.trim())); if(e) e.click(); });
  await page.waitForTimeout(1200);
  // click "Oublié ?" button
  await page.getByRole('button', { name: /^Oublié \?$/ }).click().catch(async()=>{
    await page.evaluate(()=>{ const e=[...document.querySelectorAll('button')].find(x=>/^oublié \?$/i.test(x.textContent.trim())); if(e) e.click(); });
  });
  await page.waitForTimeout(1200);
  await page.screenshot({ path: `${OUT}/REPRO-forgot.png` });
  report.dom = await page.evaluate(()=>{
    const t=document.body.innerText;
    return {
      honestNoPassword:/pas de mot de passe/i.test(t),
      fakeLinkSent:/lien envoyé|e-?mail envoyé|vérifie ta boîte|réinitialis/i.test(t),
      mentionsSMS:/sms|code/i.test(t),
    };
  });
  fs.writeFileSync(`${OUT}/verif-forgot2.json`, JSON.stringify({report, errors}, null, 2));
  console.log('FORGOT2', JSON.stringify(report)); console.log('FORGOT2ERR', JSON.stringify(errors));
});
