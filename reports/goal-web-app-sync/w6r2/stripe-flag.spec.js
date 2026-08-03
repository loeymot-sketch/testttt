// [W6R2 adversarial] Stripe invisible flag OFF — 2 surfaces (web :8096 PaymentPage, mobile :8087
// ModalPayChoice). Prouve : (1) flag OFF défaut ⇒ aucune option carte dans le DOM ;
// (2) flag ON runtime ⇒ l'option carte RÉAPPARAÎT (donc c'est bien un flag, pas une suppression morte).
// Rend les VRAIS composants prod exportés sur window contre le VRAI window.LC/api.config.
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r2';
fs.mkdirSync(OUT, { recursive: true });

async function waitReactReady(page, needle) {
  await page.waitForFunction((n) => !!(window.React && window.ReactDOM && window[n]), needle, { timeout: 30000 });
}

test.describe('Stripe flag OFF/ON — web PaymentPage (:8096)', () => {
  test('web: OFF=aucune carte, ON runtime=carte réapparait', async ({ page }) => {
    await page.goto('http://127.0.0.1:8096/');
    await waitReactReady(page, 'PaymentPage');

    // Flag runtime réel lu par le composant.
    const defFlag = await page.evaluate(() => !!(window.LC && window.LC.api && window.LC.api.config && window.LC.api.config.onlineCardEnabled));
    expect(defFlag, 'web LC.api.config.onlineCardEnabled défaut doit être false').toBe(false);

    // Helper de rendu du VRAI composant PaymentPage exporté (funnel.jsx:796).
    await page.evaluate(() => {
      window.__renderPay = (flag) => {
        window.LC.api.config.onlineCardEnabled = flag;
        let root = document.getElementById('__payroot');
        if (root) { try { window.__payRootObj && window.__payRootObj.unmount(); } catch (e) {} root.remove(); }
        root = document.createElement('div'); root.id = '__payroot';
        document.body.innerHTML = ''; document.body.appendChild(root);
        const e = window.React.createElement;
        const ctx = { fulfillment: 'pickup', method: 'counter', discount: 0, deliveryQuote: null };
        const el = e(window.PaymentPage, {
          cart: [{ id: 1, name: 'Test', price: 10, qty: 1 }],
          ctx, setCtx: () => {}, isAuth: true,
          onBack: () => {}, onNext: () => {}, onAccount: () => {},
        });
        window.__payRootObj = window.ReactDOM.createRoot(root);
        window.__payRootObj.render(el);
      };
    });

    // ---- OFF ----
    await page.evaluate(() => window.__renderPay(false));
    await page.waitForTimeout(600);
    await expect(page.getByText('Payer sur place')).toBeVisible({ timeout: 15000 });
    const offDom = await page.evaluate(() => document.getElementById('__payroot').innerText);
    fs.writeFileSync(path.join(OUT, 'web-off-dom.txt'), offDom);
    expect(offDom).not.toMatch(/Carte bancaire \(en ligne\)/);
    expect(offDom).not.toMatch(/Apple Pay/i);
    expect(offDom).not.toMatch(/Google Pay/i);
    expect(offDom).not.toMatch(/Stripe|3D-?Secure/i);
    await page.screenshot({ path: path.join(OUT, 'web-01-off.png'), fullPage: false });

    // ---- ON runtime ----
    await page.evaluate(() => window.__renderPay(true));
    await page.waitForTimeout(600);
    const onDom = await page.evaluate(() => document.getElementById('__payroot').innerText);
    fs.writeFileSync(path.join(OUT, 'web-on-dom.txt'), onDom);
    expect(onDom, 'flag ON ⇒ option carte doit réapparaitre').toMatch(/Carte bancaire \(en ligne\)/);
    await page.screenshot({ path: path.join(OUT, 'web-02-on.png'), fullPage: false });
  });
});

test.describe('Stripe flag OFF/ON — mobile ModalPayChoice (:8087)', () => {
  test('mobile: OFF=aucune CB, ON runtime=CB réapparait', async ({ page }) => {
    await page.goto('http://127.0.0.1:8087/');
    await waitReactReady(page, 'ModalPayChoice');

    const defFlag = await page.evaluate(() => !!(window.LC && window.LC.config && window.LC.config.onlineCardEnabled));
    expect(defFlag, 'mobile LC.config.onlineCardEnabled défaut doit être false').toBe(false);

    await page.evaluate(() => {
      window.__renderModal = (flag) => {
        window.LC.config.onlineCardEnabled = flag;
        let root = document.getElementById('__mroot');
        if (root) { try { window.__mRootObj && window.__mRootObj.unmount(); } catch (e) {} root.remove(); }
        root = document.createElement('div'); root.id = '__mroot';
        document.body.appendChild(root);
        const e = window.React.createElement;
        // Reproduit l'appel prod exact (index.html:323) : flag={window.LC.config.onlineCardEnabled}
        const el = e(window.ModalPayChoice, {
          total: 33, flag: window.LC.config.onlineCardEnabled,
          onClose: () => {}, onPickCounter: () => {}, onPickCard: () => {},
        });
        window.__mRootObj = window.ReactDOM.createRoot(root);
        window.__mRootObj.render(el);
      };
    });

    // ---- OFF ----
    await page.evaluate(() => window.__renderModal(false));
    await page.waitForTimeout(500);
    await expect(page.getByText('Comment', { exact: false }).first()).toBeVisible({ timeout: 15000 });
    const offCard = await page.locator('[data-testid="pay-card-online"]').count();
    const offSoon = await page.locator('[data-testid="pay-online-soon"]').count();
    const offDom = await page.evaluate(() => document.getElementById('__mroot').innerText);
    fs.writeFileSync(path.join(OUT, 'mobile-off-dom.txt'), offDom);
    expect(offCard, 'OFF: bouton pay-card-online absent du DOM').toBe(0);
    expect(offSoon, 'OFF: micro-copy « arrive bientôt » présente').toBe(1);
    await page.screenshot({ path: path.join(OUT, 'mobile-01-off.png'), fullPage: false });

    // ---- ON runtime ----
    await page.evaluate(() => window.__renderModal(true));
    await page.waitForTimeout(500);
    const onCard = await page.locator('[data-testid="pay-card-online"]').count();
    const onDom = await page.evaluate(() => document.getElementById('__mroot').innerText);
    fs.writeFileSync(path.join(OUT, 'mobile-on-dom.txt'), onDom);
    expect(onCard, 'ON: bouton pay-card-online réapparait').toBe(1);
    expect(onDom).toMatch(/Payer maintenant/i);
    await page.screenshot({ path: path.join(OUT, 'mobile-02-on.png'), fullPage: false });
  });
});
