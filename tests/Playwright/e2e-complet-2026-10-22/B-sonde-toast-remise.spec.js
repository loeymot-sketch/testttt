/**
 * [E2E-COMPLET 2026-10-22 · VAGUE B] Sonde : le message de refus de la remise (toast d'erreur) est-il entièrement DANS la fenêtre ?
 * (La capture 09-remise-apres-clic-commande le montrait coupé à droite.) Mesure par getBoundingClientRect, plusieurs instants,
 * 3 tailles d'écran. Aucune vente créée (le serveur refuse la remise).
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../../e2e/helpers/login');
const { attachMegaAuditRecorder } = require('../../e2e/helpers/mega-audit-snap');

const SHOTS = path.resolve(__dirname, '../../e2e/__screenshots__/e2e-complet-B');
const OUT = path.join(SHOTS, '_resultats-sonde-toast.json');
const R = [];
test.setTimeout(240_000);

for (const [w, h] of [[1280, 720], [1440, 900], [1920, 1080]]) {
  test(`sonde toast remise ${w}x${h}`, async ({ page }) => {
    await page.setViewportSize({ width: w, height: h });
    const { snap } = attachMegaAuditRecorder(page, SHOTS);
    await loginAsPosOperator(page);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(2500);
    const ov = page.locator('[data-testid="cash-session-overlay"]');
    if (await ov.isVisible().catch(() => false)) { await page.locator('[data-testid="cash-session-close"]').click(); await page.waitForTimeout(400); }
    await page.evaluate(() => { const v = [...document.querySelectorAll('*')].find((e) => e.__vue_app__)?.__vue_app__; v?.config.globalProperties.$store.dispatch('posCart/resetCart'); });
    await page.locator('[data-testid="pos-category-tile"]', { hasText: 'Desserts' }).first().click();
    await page.waitForTimeout(900);
    await page.locator('button.pos-item-tile[aria-label^="Ajouter Tiramisu"]').first().click();
    await page.waitForFunction(() => document.querySelectorAll('.pos-v5-cart-item').length > 0, null, { timeout: 15_000 });
    await page.locator('[data-testid="pos-discount-toggle"]').click();
    await page.locator('[data-testid="pos-discount-input"]').fill('10');
    await page.locator('[data-testid="pos-discount-reason"]').fill('E2E-CAISSE sonde');
    await page.locator('[data-testid="pos-discount-apply"]').click();
    await page.waitForTimeout(1200);
    await page.locator('[data-testid="pos-v5-pay"]').click();
    const mesures = [];
    for (const t of [200, 600, 1200, 2500, 4000]) {
      await page.waitForTimeout(t - (mesures.length ? mesures[mesures.length - 1].t : 0));
      const m = await page.evaluate(() => {
        const toasts = [...document.querySelectorAll('.Vue-Toastification__toast')].map((e) => { const r = e.getBoundingClientRect(); return { txt: e.innerText.trim().slice(0, 60), left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), width: Math.round(r.width) }; });
        return { innerWidth, toasts, zoom: getComputedStyle(document.body).zoom };
      });
      mesures.push({ t, ...m });
      if (t === 1200) await snap(`17-toast-remise-${w}x${h}-1200ms`);
    }
    R.push({ w, h, mesures });
    fs.writeFileSync(OUT, JSON.stringify(R, null, 2));
    console.log('[SONDE-TOAST]', w, h, JSON.stringify(mesures));
    expect(mesures.some((m) => m.toasts.length > 0), 'un toast de refus est affiché').toBe(true);
  });
}
