// ADVERSAIRE vague B — live re-checks: (1) occlusion numpad counter-collect @1440x900, (2) cash-overview bas de page
import { BASE, OUT, boot, snap, mkLogger, login } from './_d1-B-lib.mjs';

const L = mkLogger('red1');
const { browser, page, state } = await boot(); // 1440x900, fr-FR, channel chrome

await login(page, L);

// ── 1. POS → modal counter-collect → géométrie numpad vs footer sticky ──────
await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);
// fermer un éventuel dialog session auto-ouvert
const sessDlg = page.locator('[data-testid="cash-session-dialog"], .cash-session-dialog');
if (await sessDlg.count() && await sessDlg.first().isVisible().catch(() => false)) {
  L('dialog session auto-ouvert — tentative fermeture');
  const closeBtn = page.locator('[data-testid="cash-session-dialog"] button[aria-label*="ermer"], .cash-session-dialog__close, [data-testid="cash-session-close-x"]');
  if (await closeBtn.count()) await closeBtn.first().click().catch(() => {});
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(1000);
}
let btn = page.locator('[data-testid^="pos-shortcut-encaisser-"]').first();
if (!(await btn.count())) { L('FAIL aucun shortcut encaisser visible'); }
else {
  await btn.click();
  await page.waitForTimeout(2000);
  const modalVisible = await page.locator('[data-testid="pos-counter-collect-modal"]').isVisible().catch(() => false);
  L(`modal visible=${modalVisible}`);
  // mode espèces (défaut normalement)
  const cashMode = page.locator('[data-testid="pos-counter-collect-mode-cash"]');
  if (await cashMode.count()) { await cashMode.click().catch(() => {}); await page.waitForTimeout(600); }
  // géométrie
  const geo = await page.evaluate(() => {
    const r = (el) => { if (!el) return null; const b = el.getBoundingClientRect(); return { top: Math.round(b.top), bottom: Math.round(b.bottom), left: Math.round(b.left), height: Math.round(b.height) }; };
    const keys = [...document.querySelectorAll('.pos-v5-numpad__key')];
    const byLabel = {};
    for (const k of keys) {
      const t = (k.textContent || '').trim();
      byLabel[t || k.getAttribute('aria-label')] = r(k);
    }
    const footer = document.querySelector('.cc-modal-footer');
    const modal = document.querySelector('.cc-modal') || document.querySelector('[data-testid="pos-counter-collect-modal"]');
    const numpad = document.querySelector('.pos-v5-numpad');
    return {
      viewportH: window.innerHeight,
      modal: r(modal),
      numpad: r(numpad),
      footer: r(footer),
      keys: byLabel,
      modalScrollable: modal ? { scrollHeight: modal.scrollHeight, clientHeight: modal.clientHeight, scrollTop: modal.scrollTop } : null,
    };
  });
  L('GEO ' + JSON.stringify(geo));
  // verdict occlusion : une touche est occluse si son top >= footer.top (sticky recouvre) ou bottom > viewportH
  const f = geo.footer, vh = geo.viewportH;
  for (const [label, b] of Object.entries(geo.keys)) {
    if (!b) continue;
    const occluded = f ? (b.bottom > f.top) : (b.bottom > vh);
    const offscreen = b.top >= vh;
    if (occluded || offscreen) L(`KEY "${label}" top=${b.top} bottom=${b.bottom} → ${offscreen ? 'HORS-ÉCRAN' : 'DERRIÈRE footer sticky (footer.top=' + (f ? f.top : '?') + ')'}`);
  }
  await snap(page, state, 'red-01-numpad-occlusion');
  // test cliquabilité réelle de la touche "7" sans scroll préalable
  try {
    const seven = page.locator('.pos-v5-numpad__key', { hasText: /^7$/ }).first();
    await seven.click({ timeout: 3000, trial: true });
    L('touche 7: click trial OK (Playwright a peut-être auto-scrollé)');
  } catch (e) {
    L('touche 7: click trial FAIL → ' + e.message.split('\n')[0]);
  }
  // état du scroll après tentative (Playwright auto-scroll = preuve que c'était hors vue)
  const after = await page.evaluate(() => {
    const modal = document.querySelector('.cc-modal');
    return modal ? modal.scrollTop : null;
  });
  L(`modal.scrollTop après trial=${after}`);
  await page.locator('.cc-cancel-btn, [data-testid="pos-counter-collect-cancel"]').first().click().catch(() => {});
  await page.waitForTimeout(800);
}

// ── 2. cash-overview bas de page (trou de couverture ADV-B-04) ──────────────
await page.goto(BASE + '/admin/cash-overview', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);
await page.screenshot({ path: OUT + 'red-02-cash-overview-fullpage.png', fullPage: true });
L('SNAP red-02-cash-overview-fullpage (fullPage)');

L.flush();
await browser.close();
