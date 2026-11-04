/**
 * [FINITION 2026-11-04] Banc borne (1080×1920) :
 *  1. accueil : le sous-titre ne touche plus les badges, la phrase du bas ne passe plus sous les points ;
 *  2. prix « 7,00 € » (symbole à droite, réglage du restaurant = 10) et jamais « €7,00 ».
 * FP_ANCIEN=1 réinjecte les anciennes positions de l'accueil : le test 1 doit alors ÉCHOUER (preuve
 * que le banc mord). Le test 2 échouait avant le correctif de resources/js/helpers/kioskFormatPrice.js.
 *
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
 *     npx playwright test tests/Playwright/finition-borne-2026-11-04.spec.js --workers=1
 */
const { test, expect } = require('@playwright/test');

const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';

test.use({ viewport: { width: 1080, height: 1920 } });

async function borne(page, request) {
  const j = await (await request.post(`${BASE}/api/auth/kiosk-login`, {
    headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
    data: { username: 'kiosk-lecayenne', password: 'kiosk123' },
  })).json();
  expect(j.token).toBeTruthy();
  await page.addInitScript(({ token, machineId, branchId }) => {
    localStorage.setItem('vuex', JSON.stringify({ kioskCart: { kioskToken: token, kioskMachineId: machineId, branchId } }));
  }, { token: j.token, machineId: j.kiosk?.machine_id, branchId: j.kiosk?.branch_id || 1 });
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
}

test('accueil borne : aucun texte ne chevauche son voisin', async ({ page, request }) => {
  test.setTimeout(90_000);
  await borne(page, request);
  await page.locator('[data-testid="kiosk-idle-subtitle"]').waitFor({ timeout: 20_000 });
  if (process.env.FP_ANCIEN) {
    await page.addStyleTag({ content: '.cay-underline{margin:18px 0 14px!important}.cay-badges{top:1612px!important}.kiosk-idle-tap-hint{top:1858px!important}.kiosk-idle-footer{bottom:26px!important}' });
  }
  await page.waitForTimeout(1500);
  const b = await page.evaluate(() => {
    const r = (s) => { const e = document.querySelector(s); const x = e.getBoundingClientRect(); return { top: x.top, bottom: x.bottom }; };
    return { sub: r('[data-testid="kiosk-idle-subtitle"]'), badges: r('.cay-badges'), cta: r('[data-testid="kiosk-order-type-chooser"]'), hint: r('[data-testid="kiosk-idle-tap-hint"]'), dots: r('.kiosk-idle-footer') };
  });
  console.log(JSON.stringify(b));
  expect(b.sub.bottom, 'sous-titre / badges').toBeLessThanOrEqual(b.badges.top);
  expect(b.badges.bottom, 'badges / bouton').toBeLessThanOrEqual(b.cta.top);
  expect(b.cta.bottom, 'bouton / phrase').toBeLessThanOrEqual(b.hint.top);
  expect(b.hint.bottom, 'phrase / points').toBeLessThanOrEqual(b.dots.top);
  expect(b.dots.bottom).toBeLessThanOrEqual(1920);
});

test('catalogue borne : prix à la française « 7,00 € », jamais « €7,00 »', async ({ page, request }) => {
  test.setTimeout(90_000);
  await borne(page, request);
  await page.locator('[data-testid="kiosk-order-type-takeaway"]').waitFor({ timeout: 20_000 });
  await page.waitForTimeout(1500);
  await page.locator('[data-testid="kiosk-idle-touch-btn"]').click({ timeout: 8000, force: true }).catch(() => {});
  await page.waitForTimeout(1000);
  await page.locator('[data-testid="kiosk-order-type-takeaway"]').click({ timeout: 8000, force: true }).catch(() => {});
  await page.locator('.kiosk-product-card').first().waitFor({ timeout: 20_000 });
  await page.waitForTimeout(1000);
  const texte = await page.locator('body').innerText();
  const gauche = texte.match(/€\s?\d+[.,]\d{2}/g) || [];
  const droite = texte.match(/\d+,\d{2} €/g) || [];
  console.log('prix à droite =', droite.slice(0, 5), '· à gauche =', gauche.slice(0, 5));
  expect(gauche).toEqual([]);
  expect(droite.length).toBeGreaterThan(0);
});

test('catalogue borne : « Mon compte » panier vide garde le client au catalogue et lui explique pourquoi', async ({ page, request }) => {
  test.setTimeout(90_000);
  await borne(page, request);
  await page.locator('[data-testid="kiosk-order-type-takeaway"]').waitFor({ timeout: 20_000 });
  await page.waitForTimeout(1500);
  // Toujours un délai sur ces clics : l'élément disparaît à la navigation, et sans délai Playwright attend
  // indéfiniment (actionTimeout 0 dans ce dépôt).
  await page.locator('[data-testid="kiosk-idle-touch-btn"]').click({ timeout: 8000, force: true }).catch(() => {});
  await page.waitForTimeout(1000);
  await page.locator('[data-testid="kiosk-order-type-takeaway"]').click({ timeout: 8000, force: true }).catch(() => {});
  const compte = page.locator('[data-testid="kiosk-categories-top-account"]');
  await compte.waitFor({ timeout: 20_000 });
  await page.waitForTimeout(800);
  const avant = page.url();
  await compte.click({ timeout: 8000 });
  await page.waitForTimeout(1200);
  expect(page.url(), 'reste au catalogue').toBe(avant);
  await expect(page.locator('.kiosk-toast-msg').filter({ hasText: /compte fidélité vous sera proposé au panier/ })).toBeVisible();
});
