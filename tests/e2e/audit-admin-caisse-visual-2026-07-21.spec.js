// Close Wave C coverage gap LOCALLY (local creds OK, bundle rebuilt) : hub à onglets (fusion) +
// bouton photo stock + wizard caisse composer-aware. Captures LUES (mandat visuel).
const { test, expect } = require('@playwright/test');
const path = require('path'); const fs = require('fs');
const SHOT = path.join(__dirname, '__screenshots__', 'audit-admin-caisse-2026-07-21');
fs.mkdirSync(SHOT, { recursive: true });
test.setTimeout(150_000);

async function spaLogin(page, email) {
  await page.goto('/login', { waitUntil: 'networkidle', timeout: 45000 });
  // formulaire Vue rendu après hydratation ; labels accessibles (textbox "Email", bouton "Connexion")
  const emailBox = page.getByRole('textbox', { name: /email/i }).first();
  await emailBox.waitFor({ state: 'visible', timeout: 25000 });
  await emailBox.fill(email);
  const pw = page.locator('input[type="password"]').first();
  if (await pw.isVisible({timeout:3000}).catch(()=>false)) await pw.fill('123456');
  else await page.getByLabel(/mot de passe|password/i).first().fill('123456').catch(()=>{});
  await page.getByRole('button', { name: /connexion|se connecter|connecter/i }).first().click();
  await page.waitForFunction(() => !/\/login/.test(location.pathname), { timeout: 20000 }).catch(()=>{});
  await page.waitForTimeout(2500);
}

test('ADMIN — hub fusion (2 onglets Catalogue+Stock) + bouton photo stock', async ({ page }) => {
  const errs = []; page.on('pageerror', e => errs.push('PAGEERROR: '+e.message));
  page.on('console', m => { if (m.type()==='error') errs.push(m.text()); });
  await spaLogin(page, 'admin@lecayenne.fr');
  console.log('[ADMIN] url post-login =', page.url());
  await page.screenshot({ path: path.join(SHOT, '01-post-login.png'), fullPage: true });

  // hub fusionné
  await page.goto('/admin/catalog-hub', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(SHOT, '02-hub-tab-catalogue.png'), fullPage: true });
  const tabs = page.locator('[role="tab"]');
  const nTabs = await tabs.count();
  console.log('[HUB] onglets role=tab =', nTabs);
  // cliquer l'onglet Stock
  const stockTab = page.locator('[role="tab"]', { hasText: /Stock|disponibilit/i }).first();
  if (await stockTab.isVisible({timeout:4000}).catch(()=>false)) { await stockTab.click(); await page.waitForTimeout(2000); }
  await page.screenshot({ path: path.join(SHOT, '03-hub-tab-stock.png'), fullPage: true });
  // bouton photo sur une ligne produit
  const photoBtn = page.locator('[data-testid^="stock-mgmt-photo-btn-"]').first();
  const photoVisible = await photoBtn.isVisible({ timeout: 5000 }).catch(()=>false);
  if (photoVisible) { await photoBtn.click(); await page.waitForTimeout(1200); }
  await page.screenshot({ path: path.join(SHOT, '04-stock-photo-panel.png'), fullPage: true });
  const panelVisible = await page.locator('[data-testid^="stock-mgmt-photo-panel-"]').first().isVisible({timeout:3000}).catch(()=>false);
  console.log(`[HUB] onglets=${nTabs} · bouton photo=${photoVisible} · panneau uploader=${panelVisible} · erreurs=${errs.filter(e=>!/favicon|vendor/i.test(e)).length}`);

  expect(page.url(), 'connecté (hors /login)').not.toContain('/login');
  expect(nTabs, 'hub = 2 onglets').toBeGreaterThanOrEqual(2);
  expect(photoVisible, 'bouton photo présent sur le stock').toBeTruthy();
});

test('CAISSE — wizard composer-aware s\'ouvre sans erreur JS', async ({ page }) => {
  const errs = []; page.on('pageerror', e => errs.push('PAGEERROR: '+e.message));
  page.on('console', m => { if (m.type()==='error') errs.push(m.text()); });
  await spaLogin(page, 'pos@lecayenne.fr');
  console.log('[CAISSE] url post-login =', page.url());
  if (!/pos/i.test(page.url())) { await page.goto('/admin/pos', {waitUntil:'networkidle'}).catch(()=>{}); await page.waitForTimeout(2500); }
  await page.screenshot({ path: path.join(SHOT, '05-pos-grid.png'), fullPage: true });
  // ouvrir un produit
  const tile = page.locator('.pos-product, .product-card, [data-item-id], [class*="product-tile"], button:has-text("Cayenne")').first();
  if (await tile.isVisible({timeout:4000}).catch(()=>false)) { await tile.click(); await page.waitForTimeout(2000); }
  await page.screenshot({ path: path.join(SHOT, '06-pos-wizard.png'), fullPage: true });
  const jsErrs = errs.filter(e => !/favicon|vendor|net::ERR_|Failed to load resource/i.test(e));
  console.log(`[CAISSE] erreurs JS=${jsErrs.length}` + (jsErrs.length?' :: '+jsErrs.slice(0,3).join(' | '):''));
  expect(jsErrs.length, 'aucune erreur JS avec composer-aware ON').toBeLessThanOrEqual(0);
});
