// [GOAL 2026-07-23] Capture wizard caisse (étape viande) — before/after redesign.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const BASE = 'http://127.0.0.1:8766';
const OUT = path.resolve(__dirname, '../../tests/captures/caisse-wizard-2026-07-23');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: true }).catch(() => {});

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);
  await page.fill('#formEmail', 'pos@lecayenne.fr');
  await page.fill('#formPassword', '123456');
  await page.click('button:has-text("Connexion")').catch(() => {});
  await page.waitForTimeout(3500);
}

test('capture wizard viande caisse', async ({ page }) => {
  const rep = {};
  await login(page);
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(5000);
  await shot(page, '00-caisse.png');

  // Category-first : cliquer une catégorie à viande (Tacos / Sandwich / Burger)
  const catClicked = await page.evaluate(() => {
    const els = [...document.querySelectorAll('button, [role="button"], .card, div, a')]
      .filter(e => /tacos|sandwich|burger|cayenne/i.test(e.innerText || '') && (e.innerText || '').length < 40);
    if (els.length) { try { els[0].click(); return (els[0].innerText || '').slice(0, 30); } catch (_) {} }
    return false;
  });
  rep.category = catClicked;
  await page.waitForTimeout(2500);
  await shot(page, '01-apres-categorie.png');

  // Cliquer un produit à viande (Tacos L / Cayenne / burger)
  const prodClicked = await page.evaluate(() => {
    const els = [...document.querySelectorAll('button, [role="button"], .card, div')]
      .filter(e => /tacos|cayenne|méga|mega|chicken|burger/i.test(e.innerText || '') && /€|\d/.test(e.innerText || '') && (e.innerText || '').length < 80);
    if (els.length) { try { els[0].click(); return (els[0].innerText || '').replace(/\s+/g, ' ').slice(0, 40); } catch (_) {} }
    return false;
  });
  rep.product = prodClicked;
  await page.waitForTimeout(3000);
  await shot(page, '02-wizard-ouvert.png');

  // Le wizard pos-wizard.js est un overlay. Détecter l'étape viande.
  const wiz = await page.evaluate(() => ({
    hasWizard: !!document.querySelector('.pos-wizard, #pos-wizard, [class*="wizard"]'),
    hasViande: !!document.querySelector('.wizard-viande-list, .wizard-viande-row, .viande-emoji'),
    viandeRows: document.querySelectorAll('.wizard-viande-row').length,
    stepText: (document.querySelector('.wizard-step-title, .wizard-title, h3, h4')?.innerText || '').slice(0, 50),
  }));
  rep.wizard = wiz;

  // Si le wizard n'est pas sur l'étape viande, tenter de naviguer (souvent single-page = tout visible)
  if (wiz.hasViande) await shot(page, '03-etape-viande.png');

  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(rep, null, 2));
  console.log('WIZ_CAPTURE', JSON.stringify(rep));
});
