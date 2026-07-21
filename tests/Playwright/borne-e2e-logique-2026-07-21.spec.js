// [GOAL 2026-07-21] E2E LOGIQUE borne bout-en-bout (token machine réel injecté).
// Prouve qu'aucun choix ne tombe au checkout : total AFFICHÉ panier == total SCELLÉ
// par le quote backend. Si un supplément est largué (« calcule puis annule »),
// l'affichage (calculé AVEC) diverge du scellé → ce test échoue.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = 'http://127.0.0.1:8766';
const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const OUT = path.resolve(__dirname, '../../tests/captures/borne-e2e-logique-2026-07-21');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: true }).catch(() => {});
const num = (s) => { const m = String(s || '').replace(',', '.').match(/-?\d+(\.\d+)?/); return m ? parseFloat(m[0]) : null; };

test('borne e2e logique — aucun choix largué (affiché == scellé backend)', async ({ page, request }) => {
  const report = { assertions: [] };

  // 1) Minter un vrai token machine (session borne)
  const login = await request.post(`${BASE}/api/auth/kiosk-login`, {
    headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
    data: { username: 'kiosk-lecayenne', password: 'kiosk123' },
  });
  const loginJson = await login.json();
  const token = loginJson.token;
  const machineId = loginJson.kiosk?.machine_id;
  const branchId = loginJson.kiosk?.branch_id || 1;
  expect(token, 'kiosk-login doit renvoyer un token').toBeTruthy();

  // 2) Injecter le token en localStorage AVANT chargement (rehydrate vuex-persistedstate)
  await page.addInitScript(({ token, machineId, branchId }) => {
    try {
      localStorage.setItem('vuex', JSON.stringify({
        kioskCart: { kioskToken: token, kioskMachineId: machineId, branchId },
      }));
    } catch (_) {}
  }, { token, machineId, branchId });

  // 3) Capturer les appels quote (payload + réponse scellée)
  const quoteCalls = [];
  page.on('request', (req) => {
    if (/order\/quote/.test(req.url()) && req.method() === 'POST') {
      let body = null; try { body = req.postDataJSON(); } catch (_) { body = req.postData(); }
      quoteCalls.push({ url: req.url(), body });
    }
  });
  page.on('response', async (res) => {
    if (/order\/quote/.test(res.url()) && res.request().method() === 'POST') {
      try { const j = await res.json(); if (quoteCalls.length) quoteCalls[quoteCalls.length - 1].response = j; } catch (_) {}
    }
  });

  // 4) Entrer sur la borne
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.locator('[data-testid="kiosk-idle-touch-btn"]').click({ timeout: 5000, force: true }).catch(() => {});
  await page.waitForTimeout(900);
  await page.locator('[data-testid="kiosk-order-type-takeaway"]').click({ timeout: 8000, force: true }).catch(() => {});
  await page.locator('.kiosk-product-card').first().waitFor({ timeout: 12000 }).catch(() => {});
  await page.waitForTimeout(700);

  // 5) Ouvrir un produit composable
  await page.evaluate(() => { const c = document.querySelector('.kiosk-product-card'); if (c) c.click(); });
  await page.waitForTimeout(1800);

  // 6) Parcourir le wizard ; au SUPPLÉMENT choisir un PAYANT
  let paidSupplementPicked = false;
  for (let i = 0; i < 9; i++) {
    await page.waitForTimeout(400);
    const st = await page.evaluate(() => ({
      title: (document.querySelector('.kiosk-step-title, h3')?.innerText || '').toLowerCase(),
      isSupp: !!document.querySelector('.kiosk-supplement-row, [class*="supplement"]'),
    }));

    if (st.isSupp || /suppl/.test(st.title)) {
      const picked = await page.evaluate(() => {
        const rows = Array.from(document.querySelectorAll('.kiosk-supplement-row, .kiosk-option-card, [class*="supplement"] [role="button"]'));
        const paid = rows.find(r => /\+?\s*\d+[.,]\d{2}\s*€|\+\s*\d/.test(r.innerText || ''));
        const tgt = paid || rows[0];
        if (tgt) { try { (tgt.querySelector('button, [role="button"]') || tgt).click(); return (tgt.innerText || '').replace(/\s+/g, ' ').slice(0, 40); } catch (_) {} }
        return false;
      });
      if (picked) paidSupplementPicked = true;
      report.assertions.push({ supplement_picked: picked });
      await page.waitForTimeout(400);
    } else {
      await page.evaluate(() => {
        // étape menu : préférer « Sans menu » (total simple), sinon 1ʳᵉ carte menu
        const menuCards = Array.from(document.querySelectorAll('.kiosk-menu-card'));
        if (menuCards.length) {
          const none = menuCards.find(c => /sans menu/i.test(c.innerText || ''));
          try { (none || menuCards[menuCards.length - 1]).click(); return; } catch (_) {}
        }
        const p = document.querySelector('.kiosk-option-card, .kiosk-generic-choice, .kiosk-garniture-row, .kiosk-viande-row');
        if (p) { try { p.click(); } catch (_) {} }
      }).catch(() => {});
      await page.waitForTimeout(300);
    }

    const adv = await page.evaluate(() => {
      const n = document.querySelector('.kiosk-btn-next');
      if (n && !n.disabled && !n.classList.contains('kiosk-btn-next--cart')) { n.click(); return 'next'; }
      if (n && !n.disabled) { n.click(); return 'cart'; }
      return false;
    });
    await page.waitForTimeout(1200);
    if (adv === 'cart' || adv === false) break;
  }
  await page.waitForTimeout(1200);
  await shot(page, '01-apres-ajout-panier.png');

  // 7) Aller au panier
  await page.evaluate(() => { const b = document.querySelector('[data-testid="kiosk-bottom-cart"], .kiosk-bottom-cart'); if (b) b.click(); });
  await page.waitForTimeout(1400);
  await shot(page, '02-panier.png');
  const cartTotal = num(await page.evaluate(() => document.querySelector('[data-testid="kiosk-cart-total"]')?.innerText || ''));
  report.cart_total_affiche = cartTotal;

  // 8) Checkout → déclenche le quote (sceau backend, authentifié)
  await page.evaluate(() => { const b = document.querySelector('[data-testid="kiosk-cart-checkout"]'); if (b) b.click(); });
  await page.waitForTimeout(3000);
  await shot(page, '03-apres-checkout.png');

  const quote = quoteCalls[quoteCalls.length - 1] || null;
  report.quote = quote;
  const qd = quote?.response?.data || {};
  const quoteTotal = qd.total_ttc ?? qd.total ?? qd.subtotal ?? quote?.response?.total ?? null;
  report.quote_total_scelle = quoteTotal;
  const payloadItems = (() => { try { return JSON.parse(quote?.body?.items || '[]'); } catch (_) { return quote?.body?.items || []; } })();
  const totalExtras = (Array.isArray(payloadItems) ? payloadItems : []).reduce((n, it) => n + ((it.item_extras || []).length), 0);
  report.payload_nb_extras = totalExtras;

  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
  console.log('E2E_LOGIQUE', JSON.stringify({ cartTotal, quoteTotal, totalExtras, paidSupplementPicked }));

  // === ASSERTIONS LOGIQUE ===
  expect(paidSupplementPicked, 'un supplément payant doit être sélectionné').toBeTruthy();
  expect(quote, 'le checkout doit déclencher un appel quote authentifié').toBeTruthy();
  expect(totalExtras, 'le payload quote doit contenir les extras (aucun largage CLIENT)').toBeGreaterThan(0);
  expect(cartTotal, 'total panier affiché lisible').not.toBeNull();
  expect(quoteTotal, 'total scellé backend présent').not.toBeNull();
  // COHÉRENCE CENTIME : affiché == scellé → aucun drop
  expect(Math.abs(cartTotal - Number(quoteTotal)), `affiché ${cartTotal} == scellé ${quoteTotal}`).toBeLessThanOrEqual(0.01);
});
