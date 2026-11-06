/**
 * [GARDE DE CONNEXION 2026-10-19 · owner « je rafraîchis plusieurs fois pour que ça fonctionne »] Les quatre situations qui
 * bloquaient un écran, rejouées dans un vrai navigateur (écran cuisine /kds et caisse /admin/pos-orders-tracker) :
 * session expirée, coupure réseau longue, nouvelle version publiée, fichier du site introuvable.
 *
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
 *     npx playwright test tests/Playwright/garde-connexion-2026-10-19.spec.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsChefOperator, loginAsPosOperator } = require('../e2e/helpers/login');

const SHOTS = path.join(__dirname, '..', 'captures', 'garde-connexion-2026-10-19');
fs.mkdirSync(SHOTS, { recursive: true });
test.setTimeout(240_000);
test.use({ viewport: { width: 1280, height: 800 } });

const pastille = (page) => page.locator('[data-testid="connexion-pastille"]');

async function ouvrirKds(page) {
  await loginAsChefOperator(page);
  await page.goto('/kds', { waitUntil: 'domcontentloaded' });
  await expect(pastille(page)).toBeVisible({ timeout: 20_000 });
}

test('pastille : Connecté → Reconnexion → Pas de connexion → Connecté, sans rien toucher', async ({ page, context }) => {
  await ouvrirKds(page);
  await expect(pastille(page)).toContainText('Connecté');
  await page.screenshot({ path: path.join(SHOTS, 'g1-connecte.png') });

  await context.setOffline(true);
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));
  await expect(pastille(page)).toContainText(/Reconnexion|Pas de connexion/, { timeout: 10_000 });
  await expect(pastille(page)).toContainText('Pas de connexion', { timeout: 30_000 });
  await page.screenshot({ path: path.join(SHOTS, 'g2-pas-de-connexion.png') });

  await context.setOffline(false);
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  await expect(pastille(page)).toContainText('Connecté', { timeout: 20_000 });
  await page.screenshot({ path: path.join(SHOTS, 'g3-reconnecte.png') });
});

test('coupure LONGUE puis retour : l\'écran cuisine se recharge tout seul', async ({ page, context }) => {
  await page.clock.install();
  await ouvrirKds(page);
  await page.evaluate(() => { window.__avantRecharge = true; });
  await context.setOffline(true);
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));
  await page.clock.fastForward(20_000);
  await page.clock.fastForward(100_000); // 1 min 40 de panne (seuil : 1 min 30)
  await context.setOffline(false);
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  await page.clock.fastForward(8_000);   // la sonde constate le retour
  const rechargement = page.waitForEvent('framenavigated', { timeout: 30_000 }).catch(() => null);
  await page.clock.fastForward(70_000);  // personne n'y touche depuis > 60 s
  await rechargement;
  await page.waitForLoadState('domcontentloaded');
  await expect(pastille(page)).toBeVisible({ timeout: 20_000 });
  const marque = await page.evaluate(() => window.__avantRecharge === true);
  expect(marque, 'la page a bien été rechargée (le marqueur posé avant a disparu)').toBe(false);
});

test('nouvelle version publiée : l\'écran libre se met à jour sans clic', async ({ page }) => {
  await page.clock.install();
  await ouvrirKds(page);
  await page.evaluate(() => { window.__avantRecharge = true; });
  // le serveur publie une nouvelle version : le manifeste change
  await page.route('**/mix-manifest.json**', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: '{"/js/app.js":"/js/app.js?id=NOUVELLE"}' }));
  const rechargement = page.waitForEvent('framenavigated', { timeout: 30_000 }).catch(() => null);
  await page.clock.fastForward(65_000);
  await page.clock.fastForward(70_000);
  await rechargement;
  await page.waitForLoadState('domcontentloaded');
  expect(await page.evaluate(() => window.__avantRecharge === true)).toBe(false);
});

test('nouvelle version mais caisse EN USAGE (fenêtre ouverte) : jamais rechargée sous les doigts', async ({ page }) => {
  await page.clock.install();
  await loginAsPosOperator(page);
  await page.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });
  await expect(pastille(page)).toBeVisible({ timeout: 20_000 });
  await page.evaluate(() => { window.__avantRecharge = true; const m = document.createElement('div'); m.className = 'modal show'; document.body.appendChild(m); });
  await page.route('**/mix-manifest.json**', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: '{"/js/app.js":"/js/app.js?id=NOUVELLE"}' }));
  await page.clock.fastForward(65_000);
  await page.clock.fastForward(130_000);
  expect(await page.evaluate(() => window.__avantRecharge === true), 'pas rechargée').toBe(true);
  await expect(page.locator('[data-testid="resilience-bandeau"]')).toBeVisible();
  await page.screenshot({ path: path.join(SHOTS, 'g4-bandeau-usage.png') });
});

test('fichier du site introuvable (chunk périmé) : rechargement immédiat', async ({ page }) => {
  await ouvrirKds(page);
  await page.evaluate(() => { window.__avantRecharge = true; });
  const rechargement = page.waitForEvent('framenavigated', { timeout: 15_000 });
  await page.evaluate(() => {
    const e = new Event('unhandledrejection');
    e.reason = new Error('Loading chunk 321 failed.');
    window.dispatchEvent(e);
  });
  await rechargement;
  await page.waitForLoadState('domcontentloaded');
  expect(await page.evaluate(() => window.__avantRecharge === true)).toBe(false);
});

test('session expirée : on arrive sur la connexion UTILISABLE, plus jamais figé derrière un écran sombre', async ({ page }) => {
  await ouvrirKds(page);
  await page.route('**/api/admin/kds-order**', (r) => r.fulfill({ status: 401, contentType: 'application/json', body: '{"message":"Unauthenticated."}' }));
  await page.route('**/api/auth/logout', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: '{}' }));
  await page.waitForURL(/\/login/, { timeout: 30_000 });
  await page.waitForTimeout(1500);
  const etat = await page.evaluate(() => {
    const ov = document.querySelector('[data-testid="session-expired-overlay"]');
    const champ = document.querySelector('input[type="email"], input[name="username"], input[type="text"]');
    let atteignable = false;
    if (champ) { const r = champ.getBoundingClientRect(); atteignable = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2) === champ; }
    return { overlay: !!ov, champ: !!champ, atteignable, url: location.pathname };
  });
  console.log('SESSION', JSON.stringify(etat));
  expect(etat.overlay).toBe(false);
  expect(etat.champ).toBe(true);
  expect(etat.atteignable, 'le champ de connexion peut être touché').toBe(true);
  await page.screenshot({ path: path.join(SHOTS, 'g5-connexion-apres-expiration.png') });
});
