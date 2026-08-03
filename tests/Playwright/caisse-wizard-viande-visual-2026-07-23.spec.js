// [GOAL 2026-07-23] Preuve visuelle pixel de la refonte étape VIANDE du wizard caisse.
// Charge le VRAI pos-wizard.css + pos-wizard.js (servis par :8766), instrumente __wizTest
// comme le harness vitest, ouvre le wizard sur un item à viandes, capture la grille viande.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const BASE = 'http://127.0.0.1:8766';
const OUT = path.resolve(__dirname, '../../tests/captures/caisse-wizard-2026-07-23');
fs.mkdirSync(OUT, { recursive: true });

const ITEM = {
  id: 22, name: 'Cayenne (2 viandes)', description: '', category_name: 'Sandwichs',
  convert_price: 8.4, currency_price: '€8.40', thumb: '',
  itemAttributes: [
    { id: 301, name: 'Viande 1', max_select: 2 },
    { id: 311, name: 'Sauce (1ère Gratuite)' },
    { id: 320, name: 'Type de Pain' },
  ],
  variations: {
    301: [
      { id: 9001, name: 'Poulet mariné', thumb: '/images/menu/viande-poulet.png' },
      { id: 9002, name: 'Merguez', thumb: '/images/menu/viande-merguez.png' },
      { id: 9003, name: 'Kefta', thumb: '/images/menu/viande-kefta.png' },
      { id: 9004, name: 'Cordon Bleu', thumb: '/images/menu/viande-cordon-bleu.png' },
      { id: 9005, name: 'Viande Hachée', thumb: '/images/menu/viande-hachee.png' },
      { id: 9006, name: 'Nuggets', thumb: '' },
      { id: 9007, name: 'Tenders', thumb: '' },
      { id: 9008, name: 'Escalope', thumb: '' },
    ],
    311: [
      { id: 9101, name: 'Algérienne', thumb: null },
      { id: 9102, name: 'Samouraï', thumb: null },
    ],
    320: [{ id: 9201, name: 'Pain', thumb: null }],
  },
  extras: [
    { id: 52, name: 'Salade', convert_price: 0, currency_price: '€0.00', thumb: null },
    { id: 53, name: 'Tomates', convert_price: 0, currency_price: '€0.00', thumb: null },
  ],
  addons: [
    { id: 1, addon_item_id: 200, addon_item_name: 'Menu (Frites + Boisson)', addon_item_convert_price: 2.5, addon_item_currency_price: '€2.50' },
    { id: 2, addon_item_id: 201, addon_item_name: 'Frites Seules', addon_item_convert_price: 1.9, addon_item_currency_price: '€1.90' },
    { id: 3, addon_item_id: 202, addon_item_name: 'Boisson Seule', addon_item_convert_price: 1.9, addon_item_currency_price: '€1.90' },
  ],
};

test('visuel grille viande caisse', async ({ page }) => {
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(500);
  // Charger le vrai CSS
  await page.addStyleTag({ url: `${BASE}/css/pos-wizard.css` }).catch(() => {});
  // Récupérer le vrai JS et l'instrumenter (comme posWizardHarness.js)
  const js = await page.evaluate(async (base) => {
    const r = await fetch(base + '/js/pos-wizard.js');
    return await r.text();
  }, BASE);
  // Monter le modal AVANT de booter le script (comme le harness)
  await page.evaluate((item) => {
    let modal = document.getElementById('item-variation-modal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'item-variation-modal';
      modal.className = 'modal';
      modal.innerHTML = '<div class="modal-dialog"><div class="modal-header"></div><div class="modal-body"></div><div class="modal-footer"></div></div>';
      document.body.appendChild(modal);
    }
    modal.setAttribute('data-pos-drinks-catalog', '[]');
    modal.setAttribute('data-wizard-item-data', JSON.stringify(item));
  }, ITEM);

  const instrumented = js.replace(/\}\)\(\);?\s*$/, 'window.__wizTest={open:openWizard,close:closeWizard};})();');
  await page.addScriptTag({ content: instrumented });
  await page.evaluate(() => { document.dispatchEvent(new Event('DOMContentLoaded')); });
  await page.waitForTimeout(300);

  const opened = await page.evaluate(() => {
    const modal = document.getElementById('item-variation-modal');
    modal.classList.add('active');
    try {
      if (!document.getElementById('pos-wizard-root') && window.__wizTest) window.__wizTest.open(modal);
      return !!document.getElementById('pos-wizard-root');
    } catch (e) { return 'err:' + e.message; }
  });

  await page.waitForTimeout(1200);
  const info = await page.evaluate(() => {
    const grid = document.querySelector('.wizard-viande-grid');
    const tiles = document.querySelectorAll('.wizard-viande-tile').length;
    const imgs = document.querySelectorAll('.wizard-viande-tile img.viande-img').length;
    if (grid) grid.scrollIntoView();
    return { gridPresent: !!grid, tiles, imgs };
  });
  await page.screenshot({ path: path.join(OUT, 'viande-grille-APRES.png'), fullPage: true }).catch(() => {});
  // Zoom sur la grille si présente
  const grid = await page.$('.wizard-viande-grid');
  if (grid) await grid.screenshot({ path: path.join(OUT, 'viande-grille-zoom.png') }).catch(() => {});

  fs.writeFileSync(path.join(OUT, 'visual-report.json'), JSON.stringify({ opened, ...info }, null, 2));
  console.log('VIANDE_VISUAL', JSON.stringify({ opened, ...info }));
});
