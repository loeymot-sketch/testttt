/* REFUTER-1 repro of D-B1-05: duplicated id="name" + off-canvas drawer form submittable while closed. */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8767';
const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/cms-gestion-2026-06-10/reports/test-e2e/loyalty-validation-2026-06-12';
const EXE = '/Users/1millnonstop/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell';

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: fs.existsSync(EXE) ? EXE : undefined });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const posts = [];
  page.on('response', async r => {
    if (r.request().method() === 'POST' && r.url().includes('/api/admin/item')) {
      posts.push(`${r.status()} POST ${r.url().replace(BASE, '')}`);
    }
  });

  // login
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');

  await page.goto(BASE + '/admin/items', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);

  // 1) duplicate id check
  const dup = await page.evaluate(() => {
    const els = [...document.querySelectorAll('#name')];
    return els.map(e => ({
      testid: e.getAttribute('data-testid'),
      insideDrawer: !!e.closest('.drawer'),
      drawerActive: e.closest('.drawer') ? e.closest('.drawer').classList.contains('active') : null,
      rect: e.getBoundingClientRect().x,
      visibleInViewport: (() => { const r = e.getBoundingClientRect(); return r.x >= 0 && r.x < window.innerWidth; })(),
    }));
  });
  console.log('DUP-ID #name elements:', JSON.stringify(dup, null, 1));

  // also count other duplicated ids between drawer and filter
  const otherDups = await page.evaluate(() => {
    const ids = {};
    document.querySelectorAll('[id]').forEach(e => { ids[e.id] = (ids[e.id] || 0) + 1; });
    return Object.entries(ids).filter(([, n]) => n > 1).map(([id, n]) => `${id}x${n}`);
  });
  console.log('ALL duplicated ids on /admin/items:', otherDups.join(', '));

  // 2) drawer state proof: NOT active, screenshot
  const drawerState = await page.evaluate(() => {
    const d = document.querySelector('#sidebar.drawer');
    return { exists: !!d, active: d ? d.classList.contains('active') : null, cls: d ? d.className : null };
  });
  console.log('DRAWER state before:', JSON.stringify(drawerState));
  await page.screenshot({ path: OUT + '/refuter1-db105-before-fill.png' });

  // 3) fill FIRST #name in DOM + Enter (keyboard path: programmatic focus = what tab/label-for produces)
  const fillRes = await page.evaluate(() => {
    const el = document.querySelector('#name'); // first match in DOM
    el.focus();
    el.value = 'REFUTER1-GHOST';
    el.dispatchEvent(new Event('input', { bubbles: true }));
    return { focusedTestid: document.activeElement.getAttribute('data-testid'), insideDrawer: !!document.activeElement.closest('.drawer') };
  });
  console.log('FILLED first #name:', JSON.stringify(fillRes));
  await page.keyboard.press('Enter');
  await page.waitForTimeout(2500);
  console.log('POSTs to /api/admin/item after Enter:', JSON.stringify(posts));
  await page.screenshot({ path: OUT + '/refuter1-db105-after-enter.png' });

  // 4) label-for ambiguity: open the filter panel, click its "Nom" label, see where focus lands
  await page.evaluate(() => { const f = document.getElementById('item-filter'); if (f) f.style.display = 'block'; });
  await page.waitForTimeout(300);
  const labelTarget = await page.evaluate(() => {
    const labels = [...document.querySelectorAll('#item-filter label[for="name"]')];
    if (!labels.length) return { found: false };
    const target = document.getElementById('name');
    return { found: true, labelCount: labels.length, targetIsDrawerInput: !!target.closest('.drawer'), targetTestid: target.getAttribute('data-testid') };
  });
  console.log('LABEL-FOR check (filter label "name" resolves to):', JSON.stringify(labelTarget));

  // 5) keyboard-only reachability: is the off-canvas input tabbable (not inert, no tabindex=-1, no display:none)?
  const tabbable = await page.evaluate(() => {
    const el = document.querySelector('.drawer input#name');
    const st = getComputedStyle(el.closest('.drawer'));
    return { display: st.display, visibility: st.visibility, transform: st.transform.slice(0, 40), inert: el.closest('[inert]') !== null, tabIndex: el.tabIndex, ariaHidden: el.closest('[aria-hidden="true"]') !== null };
  });
  console.log('OFF-CANVAS drawer computed:', JSON.stringify(tabbable));

  await browser.close();
})().catch(e => { console.error('SCRIPT-FAIL', e); process.exit(1); });
