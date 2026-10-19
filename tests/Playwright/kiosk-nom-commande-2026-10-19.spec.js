/**
 * [NOM-COMMANDE-BORNE 2026-10-19] Captures réelles de la borne : écran « paiement à la caisse » avec le
 * champ nom + clavier, puis l'écran « rendez-vous en caisse » sans bouton (retour accueil après 5 s).
 *
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
 *     npx playwright test tests/Playwright/kiosk-nom-commande-2026-10-19.spec.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const OUT = path.resolve(__dirname, '../captures/kiosk-nom-commande-2026-10-19');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n) });

test.setTimeout(120_000);
test.use({ viewport: { width: 1080, height: 1920 } });

test('borne : nom obligatoire avant de confirmer, puis écran sans bouton', async ({ page, request }) => {
  const login = await request.post(`${BASE}/api/auth/kiosk-login`, {
    headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
    data: { username: 'kiosk-lecayenne', password: 'kiosk123' },
  });
  const j = await login.json();
  expect(j.token).toBeTruthy();
  await page.addInitScript(({ token, machineId, branchId }) => {
    localStorage.setItem('vuex', JSON.stringify({ kioskCart: { kioskToken: token, kioskMachineId: machineId, branchId } }));
  }, { token: j.token, machineId: j.kiosk?.machine_id, branchId: j.kiosk?.branch_id || 1 });

  // Panier réel : un produit du vrai catalogue passé dans le wizard (aucune commande n'est créée).
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.locator('[data-testid="kiosk-idle-touch-btn"]').click({ timeout: 5000, force: true }).catch(() => {});
  await page.waitForTimeout(900);
  await page.locator('[data-testid="kiosk-order-type-takeaway"]').click({ timeout: 8000, force: true }).catch(() => {});
  await page.locator('.kiosk-product-card').first().waitFor({ timeout: 12000 });
  await page.waitForTimeout(700);
  await page.evaluate(() => { const c = document.querySelector('.kiosk-product-card'); if (c) c.click(); });
  await page.waitForTimeout(1800);
  for (let i = 0; i < 14; i++) {
    const titre = (await page.evaluate(() => (document.querySelector('.kiosk-step-content .kiosk-step-title')?.innerText || '').toLowerCase())).trim();
    await page.evaluate((stepTitle) => {
      const root = document.querySelector('.kiosk-step-content');
      if (!root) return;
      // « Viande 2 » est facultative : ne pas en choisir une seconde (le devis serait refusé, 422).
      if (/viande\s*2/.test(stepTitle || '')) return;
      const menuCards = Array.from(root.querySelectorAll('.kiosk-menu-card'));
      if (menuCards.length) {
        const none = menuCards.find((c) => /sans menu/i.test(c.innerText || ''));
        try { (none || menuCards[menuCards.length - 1]).click(); return; } catch (_) {}
      }
      const groups = Array.from(root.querySelectorAll('.kiosk-step-viande [role="group"]')).filter((n) => /choisir/i.test(n.innerText || ''));
      const p = groups[0]
        || root.querySelector('.kiosk-step-pain [role="radio"][aria-checked="false"]')
        || root.querySelector('.kiosk-step-sauce [role="checkbox"][aria-checked="false"]')
        || root.querySelector('.kiosk-generic-choice');
      if (p) { try { p.click(); } catch (_) {} }
    }, titre).catch(() => {});
    await page.waitForTimeout(300);
    const next = page.locator('.kiosk-btn-next').last();
    let adv = false;
    try {
      await expect(next).toBeEnabled({ timeout: 3000 });
      adv = await next.evaluate((node) => { const c = node.classList.contains('kiosk-btn-next--cart'); node.click(); return c; });
    } catch (_) {}
    await page.waitForTimeout(1200);
    if (adv) break;
  }
  await page.evaluate(() => { const b = document.querySelector('[data-testid="kiosk-bottom-cart"], .kiosk-bottom-cart'); if (b) b.click(); });
  await page.waitForTimeout(1400);
  await page.evaluate(() => { const b = document.querySelector('[data-testid="kiosk-cart-checkout"]'); if (b) b.click(); });
  await page.waitForTimeout(3000);
  // Étapes intermédiaires éventuelles (fidélité / upsell) : on avance jusqu'au paiement.
  for (let i = 0; i < 4 && !/\/kiosk\/payment/.test(page.url()); i++) {
    await page.evaluate(() => {
      const b = document.querySelector('[data-testid*="skip"], [data-testid*="no-thanks"], [data-testid*="continue"], [data-testid="kiosk-upsell-skip"], [data-testid="kiosk-loyalty-skip"]');
      if (b) b.click();
    });
    await page.waitForTimeout(1500);
  }
  console.log('URL_AVANT_PAIEMENT', page.url());
  await page.waitForTimeout(1500);
  await shot(page, 'n1-paiement-nom-clavier.png');

  const confirm = page.locator('[data-testid="kiosk-payment-counter-confirm"]');
  await expect(page.locator('[data-testid="kiosk-payment-name-input"]')).toBeVisible({ timeout: 15_000 });
  await expect(confirm).toBeDisabled();
  await expect(page.locator('[data-testid="kiosk-payment-name-hint"]')).toBeVisible();

  // Taper « Karim » au clavier virtuel de la borne (shift pour le K majuscule)
  const touche = (c) => page.locator('.ks-vkeyb__key', { hasText: new RegExp(`^${c}$`) }).first();
  await page.locator('[data-testid="kiosk-vkeyb-shift"]').click();
  await touche('K').click();
  for (const c of ['a', 'r', 'i', 'm']) await touche(c).click();
  await expect(page.locator('[data-testid="kiosk-payment-name-input"]')).toHaveValue('Karim');
  await shot(page, 'n2-nom-saisi.png');

  await page.locator('[data-testid="kiosk-vkeyb-submit"]').click();
  await page.waitForTimeout(500);
  await expect(confirm).toBeEnabled();
  await expect(page.locator('[data-testid="kiosk-payment-name-hint"]')).toHaveCount(0);
  await shot(page, 'n3-nom-valide-confirmer-actif.png');
});

test('borne : « rendez-vous en caisse » sans bouton, retour accueil après 5 s', async ({ page, request }) => {
  const login = await request.post(`${BASE}/api/auth/kiosk-login`, {
    headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
    data: { username: 'kiosk-lecayenne', password: 'kiosk123' },
  });
  const j = await login.json();
  await page.addInitScript(({ token, machineId, branchId }) => {
    localStorage.setItem('vuex', JSON.stringify({ kioskCart: { kioskToken: token, kioskMachineId: machineId, branchId } }));
  }, { token: j.token, machineId: j.kiosk?.machine_id, branchId: j.kiosk?.branch_id || 1 });

  await page.goto(`${BASE}/kiosk/cash-instruction?number=A0042&total=7.4`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-testid="kiosk-cash-order-number"]')).toBeVisible({ timeout: 15_000 });
  await shot(page, 'n4-cash-sans-bouton.png');
  await expect(page.locator('[data-testid="kiosk-cash-cta-understood"]')).toHaveCount(0);
  const t0 = Date.now();
  await page.waitForURL(/\/kiosk\/idle/, { timeout: 12_000 });
  const secondes = (Date.now() - t0) / 1000;
  console.log('RETOUR_ACCUEIL_APRES_S', secondes.toFixed(1));
  expect(secondes).toBeGreaterThanOrEqual(3.5);
  expect(secondes).toBeLessThanOrEqual(8);
});
