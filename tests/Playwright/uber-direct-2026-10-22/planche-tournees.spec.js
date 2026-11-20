// [UBER-DIRECT 2026-10-22] Planche « Tournées » de la caisse : bascule Uber / nos livreurs, dans le VRAI navigateur, avec le bundle compilé.
// Le serveur est simulé (page.route) pour les routes /pos-delivery/board et /pos-delivery/uber/* : Uber n'est pas utilisable (droit
// `eats.deliveries` absent du compte) et un test ne doit jamais commander une vraie course. Les règles serveur sont prouvées par
// tests/Feature/UberDirect/UberDirectCaisseSwapTest.php ; ici on prouve ce que le caissier VOIT et TOUCHE.
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8768 PLAYWRIGHT_NO_WEB_SERVER=1 \
//     npx playwright test tests/Playwright/uber-direct-2026-10-22/planche-tournees.spec.js --workers=1 --retries=0
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../../reports/test-e2e/uber-direct-2026-10-22');
fs.mkdirSync(OUT, { recursive: true });

const stop = (o) => ({ id: 1, order_id: 9001, order_number: '9001', customer: 'Karim', address: '10 rue Test, Hénin-Beaumont', collect_method: 'paid_online', amount_due: 0, left_at: null, arrived_at: null, delivered_at: null, has_photo: false, dispatch: 'own', uber: null, uber_blocked: null, ...o });
let etat;
const planche = () => ({ status: true, enabled: true, uber_enabled: true, couriers: [], data: [{ wave_at: '2026-10-22 20:30:00', time: '20:30', date: '2026-10-22', is_today: true, day_label: "aujourd'hui", left: false, courier: 'Kiki', courier_id: 3, stops: etat }] });

async function ouvrir(page) {
  await loginAsPosOperator(page);
  await page.route('**/api/admin/pos-delivery/board', (r) => r.fulfill({ json: planche() }));
  await page.goto('/admin/pos');
  await page.waitForLoadState('networkidle');
  await page.locator('[data-testid="pos-delivery-board-open"]').click({ timeout: 20000 });
  await expect(page.locator('[data-testid="pos-delivery-board"]')).toBeVisible();
}

test('bascule : prix vu avant confirmation, puis arrêt Uber, puis retour à nos livreurs', async ({ page }) => {
  test.setTimeout(120_000);
  const erreurs = [];
  page.on('pageerror', (e) => erreurs.push(String(e.message || e)));
  etat = [
    stop({ id: 1, order_number: '9001', customer: 'Karim' }),
    stop({ id: 2, order_number: '9002', customer: 'Sonia', collect_method: 'cash', amount_due: 14.5, uber_blocked: 'À encaisser à la porte : Uber ne peut pas encaisser. Faites payer la commande avant de la confier à Uber.' }),
  ];
  const appels = [];
  await page.route('**/api/admin/pos-delivery/uber/quote', (r) => { appels.push('quote'); r.fulfill({ json: { status: true, ok: true, quote_id: 'dqt_1', fee: 7.42, eta_minutes: 25 } }); });
  await page.route('**/api/admin/pos-delivery/uber/dispatch', (r) => {
    appels.push('dispatch ' + r.request().postData());
    etat[0] = stop({ id: 1, dispatch: 'uber', uber: { status: 'pickup', tracking_url: 'https://uber.test/t/1', fee: 7.42, courier_name: 'Marc', bag_taken: false } });
    r.fulfill({ json: { status: true, ok: true } });
  });
  await page.route('**/api/admin/pos-delivery/uber/release', (r) => { appels.push('release'); etat[0] = stop({ id: 1 }); r.fulfill({ json: { status: true, ok: true } }); });
  await ouvrir(page);
  const planchePage = page.locator('[data-testid="pos-delivery-board"]');

  await expect(planchePage.locator('[data-testid="pos-delivery-use-uber"]').first()).toBeEnabled();
  await expect(planchePage.locator('[data-testid="pos-delivery-use-uber"]').nth(1)).toBeDisabled();
  await expect(planchePage.locator('[data-testid="pos-delivery-uber-reason"]')).toContainText('Uber ne peut pas encaisser');
  await page.screenshot({ path: path.join(OUT, '1-etat-initial.png') });

  await planchePage.locator('[data-testid="pos-delivery-use-uber"]').first().click();
  await expect(planchePage.locator('[data-testid="pos-delivery-uber-price"]')).toContainText('7,42 €');
  expect(appels).toEqual(['quote']); // le prix est demandé, rien n'est encore commandé
  await page.screenshot({ path: path.join(OUT, '2-prix-a-confirmer.png') });

  await planchePage.locator('[data-testid="pos-delivery-uber-confirm"]').click();
  await expect(planchePage.locator('[data-testid="pos-delivery-uber-badge"]')).toContainText('Coursier en route vers le restaurant');
  expect(appels[1]).toContain('"quote_id":"dqt_1"');
  await expect(planchePage.getByText('Marc')).toBeVisible();
  await page.screenshot({ path: path.join(OUT, '3-confie-a-uber.png') });

  await planchePage.locator('[data-testid="pos-delivery-use-own"]').click();
  await expect(planchePage.locator('[data-testid="pos-delivery-uber-badge"]')).toHaveCount(0);
  await expect(planchePage.getByText('🛵 Nos livreurs').first()).toBeVisible();
  expect(appels.at(-1)).toBe('release');
  await page.screenshot({ path: path.join(OUT, '4-rendu-a-nos-livreurs.png') });

  expect(erreurs, 'aucune erreur JS').toEqual([]);
});

for (const [w, h] of [[1280, 720], [1920, 1080]]) {
  test(`mise en page ${w}x${h} : aucun débordement horizontal, boutons touchables`, async ({ page }) => {
    await page.setViewportSize({ width: w, height: h });
    etat = [stop({ id: 1, dispatch: 'uber', uber: { status: 'dropoff', tracking_url: 'https://uber.test/t/1', fee: 7.42, courier_name: 'Marc-Antoine Delacroix', bag_taken: true } }), stop({ id: 2, customer: 'Sonia' })];
    await ouvrir(page);
    const carte = page.locator('[data-testid="pos-delivery-board"] > div');
    const d = await carte.evaluate((el) => ({ sw: el.scrollWidth, cw: el.clientWidth }));
    expect(d.sw).toBeLessThanOrEqual(d.cw + 1);
    // Le bouton est bien ce que touche le doigt (pas recouvert).
    const b = page.locator('[data-testid="pos-delivery-use-uber"]').first();
    const box = await b.boundingBox();
    const top = await page.evaluate(([x, y]) => { const e = document.elementFromPoint(x, y); return e && (e.closest('[data-testid="pos-delivery-use-uber"]') ? 'bouton' : e.tagName); }, [box.x + box.width / 2, box.y + box.height / 2]);
    expect(top).toBe('bouton');
    await page.screenshot({ path: path.join(OUT, `mise-en-page-${w}x${h}.png`) });
  });
}
