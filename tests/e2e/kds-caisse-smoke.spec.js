// [SYNC/UI-E2E 2026-07-04 — KDS + caisse] Smoke e2e des 2 surfaces admin (auth). Protège contre les
// régressions « écran blanc / label brut / tableau muet » — utile pendant la refonte visuelle KDS en cours.
// NE modifie PAS les composants (test seul). Lancer :
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 npx playwright test tests/e2e/kds-caisse-smoke.spec.js
const { test, expect } = require('@playwright/test');

const RAW_LABEL = /kiosk\.[a-z]|admin\.[a-z]|Label\.[A-Za-z]|\{\{|undefined%|null%/;

async function loginAdmin(page) {
  await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => {});
  await page.waitForTimeout(1200);
  // Si on est sur le login, remplir + soumettre.
  const inputs = await page.$$('input');
  if (inputs.length >= 2) {
    await inputs[0].fill('admin@lecayenne.fr').catch(() => {});
    await inputs[1].fill('123456').catch(() => {});
    await page.getByRole('button', { name: /connexion/i }).click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(2500);
  }
}

test.describe('KDS + caisse — smoke admin', () => {
  test('le KDS rend le board (ou empty-state) sans label brut ni écran blanc', async ({ page }) => {
    await loginAdmin(page);
    await page.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded', timeout: 25000 });
    await page.waitForTimeout(3000);

    const body = await page.innerText('body');
    expect((body || '').trim().length).toBeGreaterThan(30); // Vue monté, pas blanc
    expect(body).not.toMatch(RAW_LABEL);
    // Soit des commandes, soit l'empty-state explicite « aucune commande » — jamais un vide muet.
    expect(body).toMatch(/commande|Le Cayenne|Historique|SYNC/i);
  });

  test('la caisse rend la grille + la file, sans label brut ni écran blanc', async ({ page }) => {
    await loginAdmin(page);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded', timeout: 25000 });
    await page.waitForTimeout(3000);

    const body = await page.innerText('body');
    expect((body || '').trim().length).toBeGreaterThan(30);
    expect(body).not.toMatch(RAW_LABEL);
    // Éléments-clés caisse : catégories produits OU le ticket/commande en cours.
    expect(body).toMatch(/Sandwich|Tacos|Burger|Bols|Commande|encaisser|Caisse/i);
  });
});
