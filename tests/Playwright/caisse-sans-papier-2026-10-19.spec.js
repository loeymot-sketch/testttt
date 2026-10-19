/**
 * [NOTES-EN-GRAND 2026-10-19] Capture réelle de l'écran cuisine : la note du client doit être
 * grande et grasse. La commande 7591 est TEMPORAIREMENT datée d'aujourd'hui avec une note
 * (restaurée après la capture).
 *
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
 *     npx playwright test tests/Playwright/caisse-sans-papier-2026-10-19.spec.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsChefOperator } = require('../e2e/helpers/login');

const SHOTS = path.join(__dirname, '..', 'captures', 'caisse-sans-papier-2026-10-19');
fs.mkdirSync(SHOTS, { recursive: true });

test.setTimeout(180_000);
test.use({ viewport: { width: 1366, height: 800 } });

test('KDS : la note du client est grande et grasse', async ({ page }) => {
  await loginAsChefOperator(page);
  await page.goto('/kds', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(SHOTS, 'k1-kds-board.png') });

  const note = page.locator('.kds-line__instruction').first();
  await expect(note).toBeVisible({ timeout: 30_000 });
  await note.scrollIntoViewIfNeeded();
  const carte = page.locator('.kds-card', { has: note }).first();
  await carte.screenshot({ path: path.join(SHOTS, 'k2-kds-carte-note.png') });

  const st = await note.evaluate((el) => {
    const c = getComputedStyle(el);
    const prod = el.closest('.kds-card').querySelector('.kds-line__symbolic-text, .kds-line__name');
    return {
      size: parseFloat(c.fontSize), weight: Number(c.fontWeight), style: c.fontStyle,
      prodSize: prod ? parseFloat(getComputedStyle(prod).fontSize) : null, text: el.textContent.trim(),
    };
  });
  console.log('NOTE_STYLE', JSON.stringify(st));
  expect(st.size).toBeGreaterThanOrEqual(22);
  expect(st.weight).toBeGreaterThanOrEqual(800);
  expect(st.style).toBe('normal');
  expect(st.text).toMatch(/sans oeuf/i);
});

// [NOM-COMMANDE-BORNE 2026-10-19] La commande 7591 porte TEMPORAIREMENT le nom « Karim » (restaurée après).
test('caisse : le nom de la commande est en grand sur la carte, et la recherche le trouve', async ({ page }) => {
  const { loginAsPosOperator } = require('../e2e/helpers/login');
  await loginAsPosOperator(page);
  await page.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(3000);
  await page.screenshot({ path: path.join(SHOTS, 'c1-suivi-commandes.png') });

  const champ = page.locator('.pos-tracker-search input');
  await champ.fill('karim');
  await page.waitForTimeout(800);
  const cartes = page.locator('.pos-tracker-card-customer', { hasText: 'Karim' });
  await expect(cartes.first()).toBeVisible({ timeout: 15_000 });
  const st = await cartes.first().evaluate((el) => {
    const c = getComputedStyle(el);
    return { size: parseFloat(c.fontSize), weight: Number(c.fontWeight) };
  });
  console.log('NOM_CARTE', JSON.stringify(st));
  expect(st.size).toBeGreaterThanOrEqual(16);
  expect(st.weight).toBeGreaterThanOrEqual(800);
  await page.screenshot({ path: path.join(SHOTS, 'c2-recherche-karim.png') });

  await champ.fill('zzzzintrouvable');
  await page.waitForTimeout(800);
  await expect(page.locator('.pos-tracker-card-customer', { hasText: 'Karim' })).toHaveCount(0);
});

test('caisse : le champ de recherche annonce « N° ou client » en entier', async ({ page }) => {
  const { loginAsPosOperator } = require('../e2e/helpers/login');
  await loginAsPosOperator(page);
  await page.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2500);
  const champ = page.locator('.pos-tracker-search input');
  await expect(champ).toBeVisible({ timeout: 15_000 });
  // scrollWidth ne compte PAS le placeholder : on mesure sa largeur réelle avec la police du champ.
  const m = await champ.evaluate((el) => {
    const c = getComputedStyle(el);
    const ctx = document.createElement('canvas').getContext('2d');
    ctx.font = `${c.fontWeight} ${c.fontSize} ${c.fontFamily}`;
    const pad = parseFloat(c.paddingLeft) + parseFloat(c.paddingRight);
    return { w: el.clientWidth - pad, texte: ctx.measureText(el.placeholder).width, ph: el.placeholder };
  });
  console.log('RECHERCHE', JSON.stringify(m));
  expect(m.ph.toLowerCase()).toContain('client');
  expect(m.texte).toBeLessThanOrEqual(m.w); // le placeholder n'est plus coupé
  await page.screenshot({ path: path.join(SHOTS, 'c3-champ-recherche.png') });
});
