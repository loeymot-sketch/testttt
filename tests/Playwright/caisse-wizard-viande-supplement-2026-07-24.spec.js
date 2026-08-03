// [GOAL 2026-07-24] Visuel du wizard caisse UNIFIÉ : viandes incluses → supplément sur les mêmes tuiles.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const BASE = 'http://127.0.0.1:8766';
const OUT = path.resolve(__dirname, '../../tests/captures/caisse-wizard-viande-2026-07-24');
const ITEM = JSON.parse(fs.readFileSync(path.join(OUT, 'item97-payload.json'), 'utf8'));

test('capture wizard viande UNIFIÉ (inclus + supplément)', async ({ page }) => {
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(400);
  await page.addStyleTag({ url: `${BASE}/css/pos-wizard.css` }).catch(() => {});
  const js = await page.evaluate(async (base) => (await fetch(base + '/js/pos-wizard.js')).text(), BASE);
  await page.evaluate((item) => {
    let modal = document.getElementById('item-variation-modal');
    if (!modal) {
      modal = document.createElement('div'); modal.id = 'item-variation-modal'; modal.className = 'modal';
      modal.innerHTML = '<div class="modal-dialog"><div class="modal-header"></div><div class="modal-body"></div><div class="modal-footer"></div></div>';
      document.body.appendChild(modal);
    }
    modal.setAttribute('data-pos-drinks-catalog', '[]');
    modal.setAttribute('data-wizard-item-data', JSON.stringify(item));
  }, ITEM);
  const instrumented = js.replace(/\}\)\(\);?\s*$/, 'window.__wizTest={open:openWizard,close:closeWizard};})();');
  await page.addScriptTag({ content: instrumented });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
  await page.waitForTimeout(300);
  await page.evaluate(() => {
    const modal = document.getElementById('item-variation-modal');
    modal.classList.add('active');
    if (!document.getElementById('pos-wizard-root') && window.__wizTest) window.__wizTest.open(modal);
  });
  await page.waitForTimeout(800);

  // Cliquer 2 viandes incluses (max=2 pour Tacos L) puis 1 en supplément
  const info = await page.evaluate(() => {
    const tiles = [...document.querySelectorAll('.viande-tile-add.plus')];
    const click = (el) => el && el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    if (tiles[0]) click(tiles[0]);          // incluse 1
    if (tiles[1]) click(tiles[1]);          // incluse 2
    if (tiles[2]) click(tiles[2]);          // 3ᵉ = supplément
    return {
      supplBadge: document.querySelector('.viande-suppl-badge')?.textContent || null,
      supplTags: [...document.querySelectorAll('.viande-tile-suppl-tag')].map(e => e.textContent),
      quota: document.querySelector('.quota-badge')?.textContent || null,
      instruction: document.querySelector('.wizard-instruction-summary')?.textContent || null,
    };
  });
  await page.waitForTimeout(400);
  const root = await page.$('#pos-wizard-root');
  if (root) await root.screenshot({ path: path.join(OUT, 'UNIFIE-viande-supplement.png') }).catch(() => {});
  fs.writeFileSync(path.join(OUT, 'unified-report.json'), JSON.stringify(info, null, 2));
  console.log('UNIFIE_WIZ', JSON.stringify(info));
});
