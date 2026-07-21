// [GOAL 2026-07-21] Preuve visuelle borne : crudités (B2), panier supplément (B1 UI), idle (B3).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const OUT_DIR = path.resolve(__dirname, '../../tests/captures/goal-borne-2026-07-21');
fs.mkdirSync(OUT_DIR, { recursive: true });

const shot = async (page, name) => {
  await page.screenshot({ path: path.join(OUT_DIR, name), fullPage: true }).catch(() => {});
};

async function gotoKiosk(page) {
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  // L'idle exige un TOUCH d'abord → révèle le sélecteur de type
  await page.locator('[data-testid="kiosk-idle-touch-btn"]').click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(900);
  await page.locator('[data-testid="kiosk-order-type-takeaway"]').click({ timeout: 8000 }).catch(() => {});
  // Attendre l'arrivée réelle du catalogue (carte produit)
  await page.locator('[data-testid^="kiosk-product-card-"]').first().waitFor({ timeout: 12000 }).catch(() => {});
  await page.waitForTimeout(500);
}

async function clickByText(page, text, maxLen = 40) {
  return await page.evaluate(({ needle, maxLen }) => {
    const lower = needle.toLowerCase();
    const all = Array.from(document.querySelectorAll('button, a, [role="button"], .btn, .kiosk-cta, .kiosk-category-tab'));
    for (const el of all) {
      const t = (el.innerText || el.textContent || '').toLowerCase().trim();
      if (t.length < maxLen && t.includes(lower)) { el.click(); return true; }
    }
    return false;
  }, { needle: text, maxLen });
}

test('borne — crudités + supplément panier + idle', async ({ page }) => {
  const report = { steps: [], notes: [] };
  await gotoKiosk(page);
  await shot(page, '01-catalogue.png');

  // Attendre le peuplement des cartes produit avant d'ouvrir
  await page.waitForSelector('[data-testid^="kiosk-product-card-"]', { timeout: 10000 }).catch(() => {});
  await page.waitForTimeout(600);
  // Ouvrir le 1er sandwich composable de la catégorie (data-testid réel)
  const opened = await page.evaluate(() => {
    const card = document.querySelector('[data-testid^="kiosk-product-card-"]');
    if (card) { try { card.click(); return card.getAttribute('data-testid'); } catch (_) {} }
    return false;
  });
  report.steps.push({ opened });
  await page.waitForTimeout(2200);
  await shot(page, '02-wizard-open.png');

  // Parcourir les étapes ; capturer celle des crudités/garnitures
  let garnitureShotDone = false;
  for (let i = 0; i < 7; i++) {
    const state = await page.evaluate(() => ({
      hasGarniture: !!document.querySelector('.kiosk-step-garnitures, .kiosk-garniture-row, .kiosk-garnitures-list'),
      hasSupplement: !!document.querySelector('[class*="supplement"], [class*="Supplement"]'),
      body: (document.body.innerText || '').slice(0, 120),
    }));
    if (state.hasGarniture && !garnitureShotDone) {
      await page.waitForTimeout(500);
      await shot(page, '03-crudites-garnitures.png');
      // désélectionner la 1ère crudité pour prouver l'état barré = retiré
      await page.evaluate(() => {
        const row = document.querySelector('.kiosk-garniture-row');
        if (row) row.click();
      });
      await page.waitForTimeout(500);
      await shot(page, '04-crudites-1-retiree.png');
      garnitureShotDone = true;
    }
    // sélectionner un supplément payant si présent
    await page.evaluate(() => {
      const rows = Array.from(document.querySelectorAll('[class*="supplement"] [role="button"], .kiosk-supplement-row, [data-testid*="supplement"]'));
      const first = rows.find(r => /0,90|0.90|€/.test(r.innerText || ''));
      if (first) first.click();
    }).catch(() => {});
    const advanced = await page.evaluate(() => {
      const next = document.querySelector('.kiosk-btn-next');
      if (next && !next.disabled) { next.click(); return true; }
      return false;
    });
    await page.waitForTimeout(1300);
    if (!advanced) break;
  }
  await shot(page, '05-after-wizard.png');

  // Panier — vérifier présence supplément + prix
  await clickByText(page, 'panier', 30).catch(() => {});
  await page.waitForTimeout(1000);
  await shot(page, '06-panier.png');
  const cartText = await page.evaluate(() => (document.body.innerText || '').slice(0, 2000));
  report.cart_has_supplement = /suppl|cheddar|viande|sauce|\+/i.test(cartText);

  // Idle modal (B3) — forcer via racine Vue
  const forced = await page.evaluate(() => {
    try {
      const el = document.querySelector('#app') || document.body.firstElementChild;
      const vue = el && (el.__vue_app__ || el.__vue__);
      // Fallback : émettre l'event idle si exposé
      window.dispatchEvent(new CustomEvent('kiosk-force-idle-warning'));
      return true;
    } catch (_) { return false; }
  });
  await page.waitForTimeout(800);
  const idleVisible = await page.evaluate(() => !!document.querySelector('.kiosk-inactivity-dialog, [data-testid="kiosk-inactivity-stay"]'));
  if (idleVisible) await shot(page, '07-idle-modal.png');
  report.idle_forced = forced;
  report.idle_visible = idleVisible;

  fs.writeFileSync(path.join(OUT_DIR, 'report.json'), JSON.stringify(report, null, 2));
  console.log('BORNE_CAPTURE_REPORT', JSON.stringify(report));
});
