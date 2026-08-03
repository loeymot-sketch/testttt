// ============================================================================
// AUDIT ADVERSAIRE — pages PRINCIPALES + SECONDAIRES du site (mandat « toutes les pages »).
// Capture home, menu, fidélité, compte, + liens légaux (mentions/CGV/confidentialité) et « à propos »,
// pour analyse visuelle : labels bruts, layout cassé, contenu placeholder/faux, infos légales manquantes.
// Run : PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8899 \
//         npx playwright test tests/e2e/web-secondary-pages-audit-2026-07-20.spec.js --project=chromium
// ============================================================================
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'web-secondary-2026-07-20');
fs.mkdirSync(SHOT_DIR, { recursive: true });
const shot = (n) => path.join(SHOT_DIR, n);

test.describe.configure({ retries: 0 });
test.setTimeout(180_000);

async function gotoDev(page) {
  for (let i = 0; i < 3; i++) { try { await page.goto('/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 }); break; } catch (e) { await page.waitForTimeout(3_000); } }
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await expect(page.locator('.lc-nav')).toBeVisible({ timeout: 15_000 });
}
async function navClick(page, name) {
  const b = page.locator('.lc-nav-links').getByRole('button', { name, exact: true });
  if (await b.isVisible().catch(() => false)) { await b.click(); return true; }
  return false;
}
// détecte des labels bruts / placeholders visibles (defect signals)
async function rawLabelScan(page) {
  return page.evaluate(() => {
    const txt = document.body.innerText || '';
    const rawKey = (txt.match(/\b[a-z]+\.[a-z_]{3,}(\.[a-z_]+)*\b/g) || []).filter(s => !/lecayenne\.fr|\.com|\.png|\.js|www\./.test(s)).slice(0, 8);
    const placeholders = (txt.match(/(lorem ipsum|placeholder|TODO|à compléter|xxxxx|undefined|NaN|\{\{.*?\}\}|Label\.[A-Za-z]+)/gi) || []).slice(0, 8);
    return { rawKey, placeholders };
  });
}

const findings = [];
async function capturePage(page, label, file) {
  await page.waitForTimeout(600);
  const scan = await rawLabelScan(page);
  await page.screenshot({ path: shot(file), fullPage: true });
  const flag = scan.rawKey.length || scan.placeholders.length;
  console.log(`[${label}] rawKeys=${JSON.stringify(scan.rawKey)} placeholders=${JSON.stringify(scan.placeholders)}`);
  if (flag) findings.push({ label, ...scan });
}

test('AUDIT pages principales + secondaires — capture + scan labels/placeholders', async ({ page }) => {
  await gotoDev(page);
  await capturePage(page, 'HOME', '01-home.png');

  await navClick(page, 'Menu');
  await expect(page.locator('.lc-menu-grid')).toBeVisible({ timeout: 15_000 });
  await capturePage(page, 'MENU', '02-menu.png');

  if (await navClick(page, 'Fidélité')) { await capturePage(page, 'FIDELITE', '03-fidelite.png'); }

  if (await navClick(page, 'Commandes')) { await capturePage(page, 'COMMANDES', '04-commandes.png'); }

  // compte (modal « Se connecter »)
  const seConnecter = page.getByRole('button', { name: /Se connecter/ }).first();
  if (await seConnecter.isVisible().catch(() => false)) {
    await seConnecter.click(); await page.waitForTimeout(600);
    await capturePage(page, 'COMPTE', '05-compte.png');
    await page.keyboard.press('Escape').catch(() => {});
  }

  // pages légales via footer (liens du bas)
  for (const [name, file] of [[/Mentions légales/i, '06-mentions.png'], [/CGV/i, '07-cgv.png'], [/confidentialité/i, '08-confidentialite.png'], [/À propos|A propos/i, '09-apropos.png']]) {
    const link = page.getByRole('link', { name }).first().or(page.getByRole('button', { name }).first());
    if (await link.isVisible().catch(() => false)) {
      await link.click().catch(() => {});
      await page.waitForTimeout(700);
      await capturePage(page, String(name), file);
      // revenir en haut / home si une page s'est ouverte
      await page.keyboard.press('Escape').catch(() => {});
      const home = page.locator('.lc-nav-links').getByRole('button', { name: 'Accueil', exact: true });
      if (await home.isVisible().catch(() => false)) await home.click().catch(() => {});
      await page.waitForTimeout(300);
    }
  }

  console.log('[AUDIT findings]', JSON.stringify(findings));
});
