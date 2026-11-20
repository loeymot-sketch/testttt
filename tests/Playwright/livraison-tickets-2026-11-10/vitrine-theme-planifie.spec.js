// [GOAL LIVRAISON-TICKETS 2026-11-10 · T-3.1.3 · porte visuelle] Écran Vitrine : l'admin programme un thème « Fêtes » qui
// couvre l'heure présente, enregistre le brouillon ; le serveur le reconnaît « En cours », l'aperçu téléphone prend ses couleurs
// et annonce le thème. Puis le thème est retiré et le brouillon ré-enregistré (base de dev laissée comme avant).
//
//   CAPTURE_DIR=… PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//     npx playwright test tests/Playwright/livraison-tickets-2026-11-10/vitrine-theme-planifie.spec.js --reporter=line --workers=1
const { test, expect } = require('@playwright/test');
const path = require('path');

const DIR = process.env.CAPTURE_DIR || '/tmp';
const ADMIN = { email: process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr', pass: process.env.E2E_ADMIN_PASS || '123456' };
const NOEL = { primary: '#B91C1C', on_primary: '#FFFFFF', accent: '#FDE68A', background: '#FFF7ED', surface: '#FFFFFF', text: '#111827', banner_bg: '#14532D', banner_text: '#FFFFFF' };

test.use({ viewport: { width: 1440, height: 1000 } });

const local = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;

test('vitrine : thème programmé en cours, vu par le serveur et l\'aperçu', async ({ page }) => {
  await page.goto('/login');
  await page.locator('#formEmail').fill(ADMIN.email);
  await page.locator('#formPassword').fill(ADMIN.pass);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/auth/login'), { timeout: 30_000 }),
    page.locator('button[type="submit"]').first().click(),
  ]);
  await page.waitForURL(/\/admin\//, { timeout: 30_000 });
  await page.goto('/admin/storefront');
  await expect(page.locator('[data-testid="sf-status"]')).toBeVisible({ timeout: 30_000 });

  const avant = await page.locator('[data-testid="sf-theme-row"]').count();
  const enabledAvant = await page.locator('[data-testid="sf-enabled"]').getAttribute('aria-pressed');
  if (enabledAvant !== 'true') await page.locator('[data-testid="sf-enabled"]').click();

  await page.locator('[data-testid="sf-new-theme"]').click();
  await page.locator('[data-testid="sf-t-name"]').fill('Fêtes (banc)');
  const now = new Date();
  await page.locator('[data-testid="sf-t-start"]').fill(local(new Date(now.getTime() - 3600e3)));
  await page.locator('[data-testid="sf-t-end"]').fill(local(new Date(now.getTime() + 3600e3)));
  for (const [k, v] of Object.entries(NOEL)) await page.locator(`[data-testid="sf-t-hex-${k}"]`).fill(v);
  await page.locator('[data-testid="sf-t-apply"]').click();
  await expect(page.locator('[data-testid="sf-theme-row"]')).toHaveCount(avant + 1);

  const sauvegarde = page.waitForResponse((r) => r.url().includes('/admin/storefront/settings') && r.request().method() === 'PUT', { timeout: 30_000 });
  await page.locator('[data-testid="sf-save-settings"]').click();
  const reponse = await sauvegarde;
  expect(reponse.status()).toBe(200);
  const corps = await reponse.json();
  expect(corps.data.preview.theme_name).toBe('Fêtes (banc)');
  expect(corps.data.preview.theme.primary).toBe('#B91C1C');
  expect(typeof corps.data.preview.next_change_at).toBe('string');

  const ligne = page.locator('[data-testid="sf-theme-row"]', { hasText: 'Fêtes (banc)' });
  await expect(ligne).toContainText('En cours');
  await expect(page.locator('[data-testid="sf-phone-theme"]')).toContainText('Fêtes (banc)');
  await page.locator('[data-testid="sf-phone"]').scrollIntoViewIfNeeded();
  await page.screenshot({ path: path.join(DIR, 'vitrine-theme-planifie.png'), fullPage: true });

  // Nettoyage : retirer le thème du banc, remettre l'interrupteur, ré-enregistrer.
  page.once('dialog', (d) => d.accept());
  await ligne.locator('button.sf-btn--ghost').click();
  if (enabledAvant !== 'true') await page.locator('[data-testid="sf-enabled"]').click();
  const nettoyage = page.waitForResponse((r) => r.url().includes('/admin/storefront/settings') && r.request().method() === 'PUT', { timeout: 30_000 });
  await page.locator('[data-testid="sf-save-settings"]').click();
  expect((await nettoyage).status()).toBe(200);
  await expect(page.locator('[data-testid="sf-theme-row"]')).toHaveCount(avant);
});
