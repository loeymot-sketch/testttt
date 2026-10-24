// [LIVRAISON 2026-10-20] Parcours NAVIGATEUR de la caisse : livraison pour un client inconnu, du téléphone au paiement.
//
// Pré-requis : serveur local (PLAYWRIGHT_BASE_URL), compte caisse de test (pos@lecayenne.fr), caisse OUVERTE, accès au
// réseau (la Base Adresse Nationale est interrogée pour de vrai : c'est le but). Laisse un client « TEST-LIVRAISON … »
// et une commande dans la base LOCALE — ne jamais lancer contre la production.
//
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//     npx playwright test tests/Playwright/livraison-caisse.spec.js --workers=1 --retries=0
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('../e2e/helpers/login');

async function ouvrirCaisseLivraisonActive(page) {
  await loginAsPosOperator(page);
  await page.goto('/admin/pos');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2500);
  const toggle = page.locator('[data-testid="pos-delivery-toggle"]');
  await expect(toggle).toBeVisible({ timeout: 15000 });
  if (/suspendue/.test(await toggle.innerText())) { await toggle.click(); await toggle.click(); await page.waitForTimeout(1500); }
  await expect(toggle).toContainText('active');
}

async function ajouterEauPlate(page) {
  await page.locator('button:visible', { hasText: /^\s*Boissons\s*$/ }).first().click({ timeout: 15000 });
  await page.locator('button[data-pos-item-id="58"]').click({ timeout: 15000 });
  await page.waitForTimeout(1000);
}

test('client inconnu : téléphone → adresse → devis 3,90 € → paiement espèces → commande créée', async ({ page }) => {
  test.setTimeout(120_000);
  const appels = [];
  page.on('response', (r) => {
    const m = r.request().method();
    if (m !== 'GET' && /pos-delivery|admin\/pos(\/quote)?$|delivery\/quote/.test(r.url())) appels.push(`${m} ${new URL(r.url()).pathname} ${r.status()}`);
  });
  await ouvrirCaisseLivraisonActive(page);

  await page.locator('label[for="delivery"]').click();
  const phone = '06' + String(Math.floor(10000000 + Math.random() * 89999999)).slice(0, 8);
  await page.locator('#pos-delivery-phone').fill(phone);
  await page.locator('#pos-delivery-name').fill('TEST-LIVRAISON Navigateur');
  await page.locator('#pos-delivery-address').fill('437 rue elie gruyelle henin');
  const suggestion = page.locator('#orderdelivery ul li').first();
  await expect(suggestion).toBeVisible({ timeout: 15000 });
  await suggestion.dispatchEvent('mousedown');
  await expect(page.locator('[data-testid="pos-delivery-quote"]')).toContainText('3,90', { timeout: 15000 });

  await ajouterEauPlate(page);
  await page.locator('button:visible', { hasText: /Commande\s*·\s*4,90/ }).first().click({ timeout: 15000 });
  await page.locator('button:visible', { hasText: /Espèces/ }).first().click({ timeout: 15000 });
  await page.locator('button:visible', { hasText: /^5$/ }).first().click();
  await page.locator('button:visible', { hasText: /Confirmer/ }).first().click();
  await expect.poll(() => appels.some((a) => a === 'POST /api/admin/pos 201'), { timeout: 20000 }).toBe(true);

  // Le serveur a rejoué chaque étape, dans l'ordre, sans erreur.
  expect(appels.filter((a) => /pos-delivery\/customer|pos-delivery\/address/.test(a)).every((a) => a.endsWith(' 200'))).toBe(true);
  expect(appels.filter((a) => /pos\/quote/.test(a)).every((a) => a.endsWith(' 200'))).toBe(true);
});

// Régression de mise en page (constatée le 2026-10-20) : en mode livraison avec un article au panier, le formulaire
// d'adresse se peignait PAR-DESSUS le total et le bouton d'encaissement (liste de suggestions en `position:absolute`
// qui forçait `overflow:visible` sur l'en-tête). Preuve par contact : ce que touche réellement le doigt.
for (const [w, h] of [[1280, 720], [1440, 900], [1920, 1080]]) {
  test(`mise en page livraison ${w}x${h} : le bouton Commande et le champ d'adresse restent touchables`, async ({ page }) => {
    test.setTimeout(90_000);
    await page.setViewportSize({ width: w, height: h });
    await ouvrirCaisseLivraisonActive(page);
    await ajouterEauPlate(page);
    await page.locator('label[for="delivery"]').click();
    await page.locator('#pos-delivery-phone').fill('0600000042');
    const adresse = page.locator('#pos-delivery-address');
    await adresse.scrollIntoViewIfNeeded();
    await adresse.fill('437 rue elie gruyelle henin');
    const suggestion = page.locator('#orderdelivery ul li').first();
    await expect(suggestion).toBeVisible({ timeout: 15000 });
    await suggestion.dispatchEvent('mousedown');
    await expect(page.locator('[data-testid="pos-delivery-quote"]')).toContainText('3,90', { timeout: 15000 });
    await page.waitForTimeout(600);

    const entete = await page.evaluate(() => getComputedStyle(document.querySelector('aside.pos-v5-cart > .pos-v5-cart__head')).overflowY);
    expect(entete, 'l\'en-tête doit défiler, jamais déborder').toBe('auto');

    const bouton = page.locator('button:visible', { hasText: /Commande\s*·/ }).first();
    const box = await bouton.boundingBox();
    expect(box.y + box.height).toBeLessThanOrEqual(h);
    const touche = await page.evaluate(([x, y]) => { const e = document.elementFromPoint(x, y); return e && e.closest('button') ? e.closest('button').innerText : ''; }, [box.x + box.width / 2, box.y + box.height / 2]);
    expect(touche).toContain('Commande');

    await adresse.scrollIntoViewIfNeeded();
    const adresseTouchee = await page.evaluate(() => { const el = document.getElementById('pos-delivery-address'); const b = el.getBoundingClientRect(); const e = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2); return e === el; });
    expect(adresseTouchee).toBe(true);
  });
}
