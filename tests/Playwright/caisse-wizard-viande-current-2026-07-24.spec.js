// [GOAL 2026-07-24] Capture du wizard viande caisse ACTUEL (vraie donnée Tacos L) — comparaison avant refonte.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const BASE = 'http://127.0.0.1:8766';
const OUT = path.resolve(__dirname, '../../tests/captures/caisse-wizard-viande-2026-07-24');
const ITEM = JSON.parse(fs.readFileSync(path.join(OUT, 'item97-payload.json'), 'utf8'));

test('capture wizard viande caisse ACTUEL (Tacos L réel)', async ({ page }) => {
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(500);
  await page.addStyleTag({ url: `${BASE}/css/pos-wizard.css` }).catch(() => {});
  const js = await page.evaluate(async (base) => (await fetch(base + '/js/pos-wizard.js')).text(), BASE);

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
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
  await page.waitForTimeout(300);
  const info = await page.evaluate(() => {
    const modal = document.getElementById('item-variation-modal');
    modal.classList.add('active');
    try { if (!document.getElementById('pos-wizard-root') && window.__wizTest) window.__wizTest.open(modal); } catch (e) { return 'err:' + e.message; }
    const root = document.getElementById('pos-wizard-root');
    return {
      opened: !!root,
      viandeTiles: document.querySelectorAll('.wizard-viande-tile').length,
      viandeImgs: document.querySelectorAll('.wizard-viande-tile img.viande-img').length,
      supplToggle: !!document.querySelector('.viande-suppl-toggle'),
      supplSection: !!document.querySelector('.wizard-viande-suppl-section'),
      genericViandeSuppl: !!(root && /viande suppl/i.test(root.innerText) && document.querySelector('[data-type="supplement"]')),
      bodyText: root ? root.innerText.slice(0, 400) : '',
    };
  });
  await page.waitForTimeout(600);
  await page.screenshot({ path: path.join(OUT, 'ACTUEL-wizard-full.png'), fullPage: true }).catch(() => {});
  const root = await page.$('#pos-wizard-root');
  if (root) await root.screenshot({ path: path.join(OUT, 'ACTUEL-wizard-root.png') }).catch(() => {});
  fs.writeFileSync(path.join(OUT, 'current-report.json'), JSON.stringify(info, null, 2));
  console.log('CURRENT_WIZ', JSON.stringify({ ...info, bodyText: undefined }));
});
