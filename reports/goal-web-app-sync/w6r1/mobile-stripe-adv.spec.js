const { test, expect } = require('@playwright/test');
const path = require('path'), fs = require('fs');
const SHOTS = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1';

test('MOBILE Stripe flag OFF/ON — ModalPayChoice + ScreenStripe guards', async ({ page }) => {
  const errs = [];
  page.on('pageerror', e => errs.push('pageerror: ' + e.message));
  await page.goto('http://127.0.0.1:8087/index.html');
  await page.waitForFunction(() => window.ModalPayChoice && window.ScreenStripe && window.React && window.ReactDOM && window.LC && window.LC.config, null, { timeout: 30000 });

  // Runtime default flag value
  const defFlag = await page.evaluate(() => window.LC.config.onlineCardEnabled);
  console.log('MOB_DEFAULT_FLAG=' + defFlag);
  expect(defFlag, 'défaut OFF').toBe(false);

  // Helper: render a component into a fixed full-screen host and return its innerHTML
  const renderInto = (comp, props) => page.evaluate(({ comp, props }) => {
    let host = document.getElementById('advhost');
    if (host) { host._root && host._root.unmount && host._root.unmount(); host.remove(); }
    host = document.createElement('div');
    host.id = 'advhost';
    host.style.cssText = 'position:fixed;inset:0;z-index:99999;background:#f6f3ee';
    document.body.appendChild(host);
    const noop = () => {};
    const p = Object.assign({}, props, { onClose: noop, onPickCounter: noop, onPickCard: noop, go: noop });
    const root = window.ReactDOM.createRoot(host);
    host._root = root;
    root.render(window.React.createElement(window[comp], p));
    return new Promise(r => setTimeout(() => r(document.getElementById('advhost').innerHTML), 400));
  }, { comp, props });

  // 1) ModalPayChoice flag OFF (reads window.LC.config) — NO 'Payer maintenant'
  await page.evaluate(() => { window.LC.config.onlineCardEnabled = false; });
  const payOff = await renderInto('ModalPayChoice', { total: 32.1 });
  const hasPayNowOff = /Payer maintenant/.test(payOff);
  const hasCardBtnOff = /pay-card-online/.test(payOff);
  const hasSoonOff = /pay-online-soon|arrive bientôt/.test(payOff);
  console.log('MOB_MODAL_OFF hasPayNow=' + hasPayNowOff + ' hasCardBtn=' + hasCardBtnOff + ' hasSoon=' + hasSoonOff);
  expect(hasPayNowOff, 'OFF: pas de « Payer maintenant »').toBe(false);
  expect(hasCardBtnOff, 'OFF: pas de bouton pay-card-online').toBe(false);
  expect(hasSoonOff, 'OFF: micro-copy « arrive bientôt »').toBe(true);
  await page.screenshot({ path: path.join(SHOTS, 'mob-01-modal-OFF.png') });

  // 2) ScreenStripe flag OFF — garde 'stripe-unavailable'
  const stripeOff = await renderInto('ScreenStripe', { total: 32.1 });
  const guarded = /stripe-unavailable|indisponible/.test(stripeOff);
  const hasCardForm = /4242 4242 4242 4242|3D-Secure/.test(stripeOff);
  console.log('MOB_STRIPE_OFF guarded=' + guarded + ' hasCardForm=' + hasCardForm);
  expect(guarded, 'OFF: ScreenStripe = état indisponible').toBe(true);
  expect(hasCardForm, 'OFF: aucun formulaire carte / 3D-Secure').toBe(false);
  await page.screenshot({ path: path.join(SHOTS, 'mob-02-stripe-OFF-guard.png') });

  // 3) ModalPayChoice flag ON — 'Payer maintenant' réapparaît
  await page.evaluate(() => { window.LC.config.onlineCardEnabled = true; });
  const payOn = await renderInto('ModalPayChoice', { total: 32.1 });
  const hasPayNowOn = /Payer maintenant/.test(payOn);
  const hasCardBtnOn = /pay-card-online/.test(payOn);
  console.log('MOB_MODAL_ON hasPayNow=' + hasPayNowOn + ' hasCardBtn=' + hasCardBtnOn);
  expect(hasPayNowOn, 'ON: « Payer maintenant » présent').toBe(true);
  expect(hasCardBtnOn, 'ON: bouton pay-card-online présent').toBe(true);
  await page.screenshot({ path: path.join(SHOTS, 'mob-03-modal-ON.png') });

  console.log('MOB_ERRS=' + JSON.stringify(errs));
  expect(errs, '0 pageerror').toEqual([]);
});
