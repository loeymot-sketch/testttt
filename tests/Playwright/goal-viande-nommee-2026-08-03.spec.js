// [GOAL 2026-08-03 · LOCK_POS_WIZARD_TICKET_VIANDE_EN_PLUS] Preuves visuelles :
// (1) caisse — le panneau ticket single-page porte la ligne « Viandes en plus : <nom> »
//     après un supplément viande (harness offline, vrai pos-wizard.js) ;
// (2) borne — l'étape Viande propose le supplément EN CONTEXTE (tag +2,50 + CTA) au
//     dépassement du quota inclus.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';
const OUT = path.resolve(__dirname, '../../tests/captures/goal-viande-nommee-2026-08-03');
const ITEM = JSON.parse(fs.readFileSync(
  path.resolve(__dirname, '../../tests/captures/caisse-wizard-viande-2026-07-24/item97-payload.json'), 'utf8'));

test.beforeAll(() => { fs.mkdirSync(OUT, { recursive: true }); });

test('caisse — ticket preview porte « Viandes en plus : <nom> » après supplément', async ({ page }) => {
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

  const info = await page.evaluate(() => {
    const tiles = [...document.querySelectorAll('.viande-tile-add.plus')];
    const click = (el) => el && el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    if (tiles[0]) click(tiles[0]);          // incluse 1
    if (tiles[1]) click(tiles[1]);          // incluse 2
    if (tiles[2]) click(tiles[2]);          // 3ᵉ = supplément (viande DIFFÉRENTE)
    return {
      ticket: document.querySelector('.ticket-content')?.textContent || null,
      supplBadge: document.querySelector('.viande-suppl-badge')?.textContent || null,
    };
  });
  await page.waitForTimeout(400);
  const root = await page.$('#pos-wizard-root');
  if (root) await root.screenshot({ path: path.join(OUT, 'caisse-ticket-viande-nommee.png') }).catch(() => {});
  fs.writeFileSync(path.join(OUT, 'caisse-report.json'), JSON.stringify(info, null, 2));
  expect(info.ticket, 'panneau ticket présent').toBeTruthy();
  expect(info.ticket).toMatch(/Viandes en plus\s*:/i);
});

test('borne — étape viande : supplément proposé en contexte au-delà du quota', async ({ page }) => {
  // Drive réel borne (pattern goal-borne-drop-crudites-idle) : idle → à emporter → produit Tacos.
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.locator('[data-testid="kiosk-idle-touch-btn"]').click({ timeout: 5000, force: true }).catch(() => {});
  await page.waitForTimeout(1000);
  await page.locator('[data-testid="kiosk-order-type-takeaway"]').click({ timeout: 8000, force: true }).catch(() => {});
  await page.waitForTimeout(1500);
  // Onglet catégorie Tacos d'abord (le 1er écran liste d'autres produits), puis carte Tacos.
  await page.evaluate(() => {
    const tab = [...document.querySelectorAll('.kiosk-category-tab, [class*="category"] button, button')]
      .find(b => /tacos/i.test(b.textContent || '') && (b.textContent || '').length < 30);
    if (tab) tab.click();
  });
  await page.waitForTimeout(1200);
  const opened = await page.evaluate(() => {
    const cards = [...document.querySelectorAll('.kiosk-product-card')];
    const tacos = cards.find(c => /tacos/i.test(c.innerText)) || cards[0];
    if (tacos) { tacos.click(); return (tacos.innerText || '').slice(0, 40); }
    return null;
  });
  await page.waitForTimeout(1500);
  // Avancer jusqu'à l'étape Viande : à chaque étape, choisir la 1ʳᵉ option si rien n'est
  // sélectionné (pain/taille exigent un choix) puis « suivant ».
  for (let i = 0; i < 5; i++) {
    const onViande = await page.evaluate(() => !!document.querySelector('.kiosk-step-viande'));
    if (onViande) break;
    await page.evaluate(() => {
      const sel = document.querySelector('.kiosk-option-card.is-selected, .kiosk-option-card--selected, [class*="selected"]');
      if (!sel) { const opt = document.querySelector('.kiosk-option-card, .kiosk-generic-choice'); if (opt) opt.click(); }
    });
    await page.waitForTimeout(500);
    await page.evaluate(() => { const n = document.querySelector('.kiosk-btn-next'); if (n && !n.disabled) n.click(); });
    await page.waitForTimeout(900);
  }
  // Taper des viandes jusqu'au-delà du quota (les tuiles réelles, pas l'extra générique).
  const report = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('.kiosk-viande-card')];
    for (let k = 0; k < 4; k++) { const r = rows[k % Math.max(rows.length, 1)]; if (r) r.click(); }
    return { rows: rows.length };
  });
  await page.waitForTimeout(900);
  const state = await page.evaluate(() => ({
    cta: (document.querySelector('[data-testid="kiosk-viande-suppl-cta"]') || {}).textContent || null,
    tag: (document.querySelector('[data-testid="kiosk-viande-suppl-tag"]') || {}).textContent || null,
    badge: (document.querySelector('[data-testid="kiosk-viande-suppl-badge"]') || {}).textContent || null,
    stepTitle: (document.querySelector('.kiosk-step-title, h3') || {}).innerText || null,
  }));
  await page.screenshot({ path: path.join(OUT, 'borne-02-viande-quota.png'), fullPage: false });
  fs.writeFileSync(path.join(OUT, 'borne-report.json'), JSON.stringify({ opened, ...report, ...state }, null, 2));
  console.log('BORNE_VIANDE', JSON.stringify({ opened, ...report, ...state }));
});
