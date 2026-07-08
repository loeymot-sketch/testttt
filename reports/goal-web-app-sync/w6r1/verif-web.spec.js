// Adversarial verification — web checkout & account (:8096). READ-ONLY.
const { test } = require('@playwright/test');
const fs = require('fs');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1/web';
const FIX = JSON.parse(fs.readFileSync('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/e2e-fixtures.json','utf8'));
const TOKEN = FIX.clients[0].token;
const PHONE = FIX.clients[0].phone;

function wireErrors(page, bag, tag){
  page.on('console', m => { if(m.type()==='error') bag.push(`[${tag}] ${m.text()}`); });
  page.on('pageerror', e => bag.push(`[${tag}][PAGEERROR] ${e.message}`));
}

test('PAYMENT page flag OFF', async ({ page }) => {
  const errors = [];
  const report = {};
  wireErrors(page, errors, 'pay');
  await page.goto('/');
  await page.waitForTimeout(2500);
  report.onlineCardEnabled = await page.evaluate(()=> (window.LC&&window.LC.api&&window.LC.api.config)?window.LC.api.config.onlineCardEnabled:'no-config');
  report.metaFlag = await page.evaluate(()=>{ const m=document.querySelector('meta[name="feature-online-card"]'); return m?m.getAttribute('content'):null; });

  // go to menu
  await page.evaluate(()=>{ const e=[...document.querySelectorAll('a,button')].find(x=>/^menu$/i.test(x.textContent.trim())); if(e) e.click(); });
  await page.waitForTimeout(1500);
  // click the "+" add button on the first card (direct add-to-cart)
  const addRes = await page.evaluate(()=>{
    const plus=document.querySelector('.lc-menu-grid .lc-card-item .lc-card-item-add, .lc-menu-grid button.lc-card-item-add');
    if(plus){ plus.click(); return 'add-class'; }
    // fallback: a "+" button inside a card
    const b=[...document.querySelectorAll('.lc-menu-grid button')].find(x=>x.textContent.trim()==='+'||/plus|ajouter/i.test(x.getAttribute('aria-label')||''));
    if(b){ b.click(); return 'add-fallback'; }
    return 'no-add';
  });
  report.addRes = addRes;
  await page.waitForTimeout(1500);
  // open cart
  await page.evaluate(()=>{ const e=[...document.querySelectorAll('button,a')].find(x=>/panier/i.test(x.getAttribute('aria-label')||'')|| x.className.includes('cart')); if(e) e.click(); });
  await page.waitForTimeout(1200);
  await page.screenshot({ path: `${OUT}/VERIF-cart-filled.png` });
  // click checkout / commander in cart drawer
  await page.evaluate(()=>{ const e=[...document.querySelectorAll('button')].find(x=>/commander|passer|checkout|valider|payer|finaliser/i.test(x.textContent)&&!x.disabled); if(e) e.click(); });
  await page.waitForTimeout(1800);
  await page.screenshot({ path: `${OUT}/VERIF-afterCheckoutClick.png`, fullPage:true });
  // if an upsell modal appears, skip it
  await page.evaluate(()=>{ const e=[...document.querySelectorAll('button')].find(x=>/non merci|passer|continuer|ignorer|skip/i.test(x.textContent)&&!x.disabled); if(e) e.click(); });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/VERIF-checkoutpage.png`, fullPage:true });
  // proceed to payment: click next CTA
  await page.evaluate(()=>{ const e=[...document.querySelectorAll('button')].find(x=>/continuer|suivant|paiement|payer|valider/i.test(x.textContent)&&!x.disabled); if(e) e.click(); });
  await page.waitForTimeout(1800);
  // Inspect payment methods DOM
  report.payDom = await page.evaluate(()=>{
    const isPayment = !!document.querySelector('.lcf-paymethods');
    const methods = [...document.querySelectorAll('.lcf-paymethod .lcf-paymethod-name')].map(x=>x.textContent.trim());
    const bodyText = document.body.innerText;
    return {
      isPayment,
      methods,
      mentionsStripe: /stripe/i.test(bodyText),
      mentionsApplePay: /apple\s*pay/i.test(bodyText),
      mentionsGooglePay: /google\s*pay/i.test(bodyText),
      mentions3DS: /3d[\s-]?secure/i.test(bodyText),
      mentionsPayerSurPlace: /payer sur place/i.test(bodyText),
      mentionsComptoir: /comptoir/i.test(bodyText),
      hasCardForm: !!document.querySelector('.lcf-cardform'),
    };
  });
  await page.screenshot({ path: `${OUT}/VERIF-payment.png`, fullPage:true });
  fs.writeFileSync(`${OUT}/verif-payment.json`, JSON.stringify({report, errors}, null, 2));
  console.log('PAY-REPORT', JSON.stringify(report));
  console.log('PAY-ERRORS', JSON.stringify(errors));
});

test('ACCOUNT page (authed) + logout + saved cards honest', async ({ page }) => {
  const errors = [];
  const report = {};
  wireErrors(page, errors, 'acct');
  // Inject real Sanctum token to boot authed
  await page.goto('/');
  await page.evaluate(([t,p])=>{ localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); }, [TOKEN, PHONE]);
  await page.reload();
  await page.waitForTimeout(2500);
  report.isAuthed = await page.evaluate(()=> window.LC&&window.LC.api?window.LC.api.isAuthed():null);
  // go to loyalty (profile) route
  await page.evaluate(()=>{ const e=[...document.querySelectorAll('a,button')].find(x=>/^fidélité$|^fidelite$|compte|profil/i.test(x.textContent.trim())); if(e) e.click(); });
  await page.waitForTimeout(3000);
  await page.screenshot({ path: `${OUT}/VERIF-account-top.png` });
  await page.screenshot({ path: `${OUT}/VERIF-account-full.png`, fullPage:true });
  report.acctDom = await page.evaluate(()=>{
    const t=document.body.innerText;
    return {
      hasLogout: /se déconnecter|déconnexion/i.test(t),
      hasFakeCard: /4242|••••\s*4242|visa\s*····/i.test(t),
      hasAucuneCarte: /aucune carte enregistrée/i.test(t),
      mentionsStripe: /stripe/i.test(t),
      hasDeleteInert: /supprimer mon compte/i.test(t),
      loyaltyPointsVisible: /pts|points/i.test(t),
    };
  });
  fs.writeFileSync(`${OUT}/verif-account.json`, JSON.stringify({report, errors}, null, 2));
  console.log('ACCT-REPORT', JSON.stringify(report));
  console.log('ACCT-ERRORS', JSON.stringify(errors));
});

test('FORGOT password honest message', async ({ page }) => {
  const errors = [];
  const report = {};
  wireErrors(page, errors, 'forgot');
  await page.goto('/');
  await page.waitForTimeout(2000);
  // open account modal via header "Se connecter"
  await page.evaluate(()=>{ const e=[...document.querySelectorAll('a,button')].find(x=>/se connecter|connexion|compte/i.test(x.textContent.trim())); if(e) e.click(); });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/VERIF-login-modal.png` });
  // click "mot de passe oublié"
  const clicked = await page.evaluate(()=>{ const e=[...document.querySelectorAll('a,button,span,div')].find(x=>/mot de passe oublié|oublié/i.test(x.textContent.trim())&&x.textContent.trim().length<40); if(e){ e.click(); return e.textContent.trim(); } return null; });
  report.forgotClicked = clicked;
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/VERIF-forgot.png` });
  report.forgotDom = await page.evaluate(()=>{
    const t=document.body.innerText;
    return {
      honestNoPassword: /pas de mot de passe/i.test(t),
      fakeLinkSent: /lien envoyé|e-mail envoyé|email envoyé|vérifie ta boîte/i.test(t),
      mentionsSMS: /sms|code/i.test(t),
    };
  });
  fs.writeFileSync(`${OUT}/verif-forgot.json`, JSON.stringify({report, errors}, null, 2));
  console.log('FORGOT-REPORT', JSON.stringify(report));
  console.log('FORGOT-ERRORS', JSON.stringify(errors));
});
