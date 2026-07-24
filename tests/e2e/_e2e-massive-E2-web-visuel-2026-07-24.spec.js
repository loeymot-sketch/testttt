// =============================================================================
// E2E MASSIF 2026-07-24 — Dimension E2 = WEB surfaces VISUEL + mobile
// Cible LIVE : https://site-lecayenne.vercel.app  (Vercel → VPS backend)
// READ-ONLY : capture + prouve, ne corrige RIEN. Le lecteur (moi) LIT chaque PNG.
// chromium desktop (1440x900) + Pixel 7 (test.use device) — run --project=chromium.
//
// Run:
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test \
//     tests/e2e/_e2e-massive-E2-web-visuel-2026-07-24.spec.js --project=chromium \
//     --reporter=list --workers=1 --timeout=180000
// =============================================================================
const { test, expect, devices } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PLAYWRIGHT_BASE_URL || 'https://site-lecayenne.vercel.app';
const SHOT = path.join(__dirname, '__screenshots__', 'e2e-massive-E2');
fs.mkdirSync(SHOT, { recursive: true });
const shot = (n) => path.join(SHOT, n);
const saveObs = (name, data) => fs.writeFileSync(path.join(SHOT, `obs-${name}.json`), JSON.stringify(data, null, 2));

// Raw-label / leftover detectors run against rendered innerText.
const RAW_LABEL_RE = /\bkiosk\.[a-z]|\b[a-z]+\.[a-z]+\.[a-z]+\b(?![\w/@.-])|undefined€|€undefined|NaN|\{\{|\}\}|\[À COMPLÉTER\]|À COMPLÉTER|Label\.[A-Z]/;
function scanRawLabels(txt) {
  const hits = [];
  const lines = String(txt || '').split('\n');
  for (const ln of lines) {
    const l = ln.trim();
    if (!l) continue;
    if (RAW_LABEL_RE.test(l)) hits.push(l.slice(0, 120));
  }
  return [...new Set(hits)].slice(0, 20);
}

function track(page) {
  const consoleErrors = []; const netProblems = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200)); });
  page.on('response', (r) => { const s = r.status(); if (s >= 400) netProblems.push({ status: s, url: r.url().slice(0, 150) }); });
  page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.message).slice(0, 180)));
  return { consoleErrors, netProblems };
}

async function overflowOf(page) {
  return page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    innerWidth: window.innerWidth,
    horizontal: document.documentElement.scrollWidth > window.innerWidth + 2,
  }));
}
async function brokenImgs(page) {
  return page.evaluate(() => Array.from(document.querySelectorAll('img'))
    .filter((img) => img.complete && img.naturalWidth === 0)
    .map((img) => img.getAttribute('src')).slice(0, 20));
}
async function deadControls(page) {
  return page.evaluate(() => {
    const out = { emptyButtons: [], hashLinks: [] };
    document.querySelectorAll('button').forEach((b) => {
      const label = (b.textContent || '').trim() || b.getAttribute('aria-label') || b.getAttribute('title') || '';
      const hasIcon = b.querySelector('svg,img,i');
      if (!label && !hasIcon && b.offsetParent !== null) out.emptyButtons.push(b.outerHTML.slice(0, 80));
    });
    document.querySelectorAll('a[href]').forEach((a) => {
      const h = a.getAttribute('href');
      if ((h === '#' || h === '') && (a.textContent || '').trim()) out.hashLinks.push((a.textContent || '').trim().slice(0, 40));
    });
    return { emptyButtons: out.emptyButtons.slice(0, 10), hashLinks: [...new Set(out.hashLinks)].slice(0, 10) };
  });
}

async function gotoDev(page) {
  let lastErr = null;
  for (let i = 0; i < 3; i++) {
    try { await page.goto(BASE + '/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 }); lastErr = null; break; }
    catch (e) { lastErr = e; await page.waitForTimeout(3_000); }
  }
  if (lastErr) throw lastErr;
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await page.waitForTimeout(1_000);
  await page.evaluate(async () => { if (document.fonts && document.fonts.ready) { try { await document.fonts.ready; } catch (_e) {} } });
}
async function scrollThrough(page) {
  await page.evaluate(async () => {
    const step = window.innerHeight;
    for (let y = 0; y <= document.body.scrollHeight; y += step) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 220)); }
    window.scrollTo(0, 0);
  });
  await page.waitForTimeout(800);
}
async function openMenuDesktop(page) {
  await page.keyboard.press('Escape').catch(() => {});
  const direct = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await direct.isVisible({ timeout: 3_000 }).catch(() => false)) await direct.click();
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 15_000 });
  await page.waitForTimeout(500);
}

// =============================================================================
// DESKTOP BLOCK — 1440x900
// =============================================================================
test.describe('E2 Desktop', () => {
  test.use({ viewport: { width: 1440, height: 900 } });
  test.describe.configure({ mode: 'serial', retries: 0 });
  test.setTimeout(180_000);

  // ---- D1 HOME -------------------------------------------------------------
  test('D1 — home : hero + sections + CTA + footer', async ({ page }) => {
    const t = track(page);
    await gotoDev(page);
    await scrollThrough(page);
    const obs = { surface: 'home-desktop' };

    obs.hero = await page.evaluate(() => {
      const img = document.querySelector('img.lc-hero-art-svg') || document.querySelector('.lc-hero-art img') || document.querySelector('.lc-hero img');
      return img ? { found: true, src: img.getAttribute('src'), naturalWidth: img.naturalWidth, complete: img.complete,
        displayNone: getComputedStyle(img).display === 'none' } : { found: false };
    });
    obs.facebook = await page.evaluate(() => {
      const a = Array.from(document.querySelectorAll('a')).find((x) => /facebook\.com/i.test(x.href));
      return a ? { found: true, href: a.href } : { found: false };
    });
    obs.suisNous = await page.evaluate(() => /Suis-?nous|Suivez/i.test(document.body.innerText));
    obs.gallery = await page.evaluate(() => document.querySelectorAll('.lc-gallery-tile').length);
    obs.ctas = await page.evaluate(() => Array.from(document.querySelectorAll('.lc-hero button, .lc-hero a')).map((b) => (b.textContent || '').trim()).filter(Boolean).slice(0, 8));
    obs.footer = await page.evaluate(() => {
      const f = document.querySelector('.lc-footer, footer');
      const txt = f ? (f.innerText || '').replace(/\s+/g, ' ').trim() : '';
      return { text: txt.slice(0, 600),
        hasPhone: /0[1-9](?:[ .]?\d{2}){4}|\+33/.test(txt),
        hasAddress: /rue|avenue|bd|boulevard|béthune|bruay|\d{5}/i.test(txt),
        hasHours: /lun|mar|mer|jeu|ven|sam|dim|h\d|\d{1,2}h|horaire|ouvert/i.test(txt) };
    });
    obs.overflow = await overflowOf(page);
    obs.brokenImgs = await brokenImgs(page);
    obs.rawLabels = scanRawLabels(await page.evaluate(() => document.body.innerText));
    obs.dead = await deadControls(page);
    obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;

    await page.screenshot({ path: shot('D1-home-full.png'), fullPage: true });
    await page.screenshot({ path: shot('D1-home-hero.png'), fullPage: false });
    const gal = page.locator('.lc-gallery, .lc-footer, footer').first();
    if (await gal.count()) { await gal.scrollIntoViewIfNeeded().catch(() => {}); await page.waitForTimeout(500); await page.screenshot({ path: shot('D1-home-footer.png'), fullPage: false }); }
    saveObs('D1-home', obs);
    console.log('[D1]', JSON.stringify({ hero: obs.hero.found, fb: obs.facebook.found, gallery: obs.gallery, footer: { p: obs.footer.hasPhone, a: obs.footer.hasAddress, h: obs.footer.hasHours }, overflow: obs.overflow.horizontal, broken: obs.brokenImgs.length, raw: obs.rawLabels.length, dead: obs.dead.emptyButtons.length + obs.dead.hashLinks.length }));
    expect.soft(obs.overflow.horizontal, 'no horizontal overflow home').toBeFalsy();
    expect.soft(obs.brokenImgs.length, 'no broken imgs home').toBe(0);
    expect.soft(obs.rawLabels, 'no raw labels home').toEqual([]);
  });

  // ---- D2 MENU -------------------------------------------------------------
  test('D2 — menu : boissons regroupées + 0 canette grille plats + desserts + recherche', async ({ page }) => {
    const t = track(page);
    await gotoDev(page); await openMenuDesktop(page);
    const obs = { surface: 'menu-desktop' };

    obs.drinksHeadingVisible = await page.getByRole('heading', { name: /Boissons/ }).isVisible({ timeout: 8_000 }).catch(() => false);
    obs.seeAllVisible = await page.getByRole('button', { name: /Voir toutes/i }).isVisible().catch(() => false);
    const grids = page.locator('.lc-menu-grid');
    obs.nGrids = await grids.count();
    obs.drinkPreviewCards = obs.nGrids ? await grids.nth(obs.nGrids - 1).locator(':scope > *').count().catch(() => 0) : 0;
    const CANS = ['Coca-Cola 33cl', 'Coca-Cola Zéro 33cl', 'Fanta Orange 33cl', 'Sprite 33cl', 'Oasis Tropical 33cl', 'Orangina 33cl', 'Perrier', 'Ice Tea'];
    obs.cansInFoodGrid = 0;
    for (const c of CANS) obs.cansInFoodGrid += await grids.first().getByText(c, { exact: false }).count().catch(() => 0);
    obs.categories = await page.evaluate(() => Array.from(document.querySelectorAll('.lc-menu-cat, .lc-cat-chip, [class*="cat"]')).map((e) => (e.textContent || '').trim()).filter((s) => s && s.length < 30).slice(0, 20));
    obs.searchPresent = await page.evaluate(() => !!document.querySelector('input[type="search"], input[placeholder*="echerch" i], .lc-search input, input[placeholder*="Rechercher" i]'));
    obs.dessertsMentioned = await page.evaluate(() => /Dessert|Tiramisu|Gaufre|Cookie|Muffin/i.test(document.body.innerText));

    if (obs.drinksHeadingVisible) { await page.getByRole('heading', { name: /Boissons/ }).scrollIntoViewIfNeeded().catch(() => {}); await page.waitForTimeout(300); }
    await page.screenshot({ path: shot('D2-menu-drinks-section.png'), fullPage: false });
    await page.screenshot({ path: shot('D2-menu-full.png'), fullPage: true });

    if (obs.searchPresent) {
      const s = page.locator('input[type="search"], input[placeholder*="echerch" i], .lc-search input, input[placeholder*="Rechercher" i]').first();
      await s.fill('tacos').catch(() => {});
      await page.waitForTimeout(700);
      obs.searchResults = await page.locator('[aria-label^="Voir "]').count().catch(() => 0);
      await page.screenshot({ path: shot('D2-menu-search-tacos.png'), fullPage: false });
      await s.fill('').catch(() => {});
      await page.waitForTimeout(400);
    }
    if (obs.seeAllVisible) {
      await page.getByRole('button', { name: /Voir toutes/i }).click().catch(() => {});
      await page.waitForTimeout(900);
      obs.allDrinkCards = await page.locator('.lc-menu-grid').first().locator(':scope > *').count().catch(() => 0);
      await page.screenshot({ path: shot('D2-menu-all-drinks.png'), fullPage: true });
    }
    obs.overflow = await overflowOf(page);
    obs.rawLabels = scanRawLabels(await page.evaluate(() => document.body.innerText));
    obs.brokenImgs = await brokenImgs(page);
    obs.dead = await deadControls(page);
    obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
    saveObs('D2-menu', obs);
    console.log('[D2]', JSON.stringify({ drinks: obs.drinksHeadingVisible, seeAll: obs.seeAllVisible, previews: obs.drinkPreviewCards, cansInFood: obs.cansInFoodGrid, allDrinks: obs.allDrinkCards, search: obs.searchPresent, searchRes: obs.searchResults, desserts: obs.dessertsMentioned, overflow: obs.overflow.horizontal, raw: obs.rawLabels.length }));
    expect.soft(obs.drinkPreviewCards, '5 drink previews').toBe(5);
    expect.soft(obs.cansInFoodGrid, '0 cans in food grid').toBe(0);
    expect.soft(obs.rawLabels, 'no raw labels menu').toEqual([]);
  });

  // ---- D3 LEGAL x5 ---------------------------------------------------------
  const LEGAL = [
    { slug: 'mentions', url: '/legal/mentions.html', must: ['E.DELICE', 'SIRET', 'RCS', 'APE'] },
    { slug: 'cgv', url: '/legal/cgv.html', must: ['CGV', 'commande'] },
    { slug: 'privacy', url: '/legal/privacy.html', must: ['donn', 'RGPD|CNIL'] },
    { slug: 'cookies', url: '/legal/cookies.html', must: ['cookie'] },
    { slug: 'allergens', url: '/legal/allergens.html', must: ['allerg'] },
  ];
  for (const L of LEGAL) {
    test(`D3 — legal ${L.slug}`, async ({ page }) => {
      const t = track(page);
      const resp = await page.goto(`${BASE}${L.url}`, { waitUntil: 'networkidle', timeout: 45_000 }).catch(() => null);
      await page.waitForTimeout(500);
      const obs = { surface: `legal-${L.slug}`, status: resp ? resp.status() : 0 };
      const bodyText = await page.evaluate(() => document.body.innerText || '');
      obs.textLen = bodyText.length;
      obs.aCompleter = (bodyText.match(/À COMPLÉTER/gi) || []).length;
      obs.missing = L.must.filter((f) => !new RegExp(f, 'i').test(bodyText));
      obs.rawLabels = scanRawLabels(bodyText);
      obs.overflow = await overflowOf(page);
      obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
      await page.screenshot({ path: shot(`D3-legal-${L.slug}.png`), fullPage: true });
      saveObs(`D3-legal-${L.slug}`, obs);
      console.log(`[D3:${L.slug}]`, JSON.stringify({ status: obs.status, len: obs.textLen, aCompleter: obs.aCompleter, missing: obs.missing, raw: obs.rawLabels.length, overflow: obs.overflow.horizontal }));
      expect.soft(obs.status, `${L.slug} 200`).toBe(200);
      expect.soft(obs.aCompleter, `${L.slug} no À COMPLÉTER`).toBe(0);
    });
  }

  // ---- D4 ACCOUNT / FIDÉLITÉ / COMMANDES -----------------------------------
  test('D4 — Fidélité + Commandes rendus (pas de page blanche)', async ({ page }) => {
    const t = track(page);
    await gotoDev(page);
    const obs = { surface: 'account-orders-desktop', routes: {} };
    for (const name of ['Fidélité', 'Commandes']) {
      const btn = page.locator('.lc-nav-links').getByRole('button', { name, exact: true });
      const clicked = await btn.isVisible({ timeout: 4_000 }).catch(() => false);
      if (clicked) { await btn.click().catch(() => {}); await page.waitForTimeout(1_200); }
      const info = await page.evaluate(() => {
        const app = document.querySelector('#root .lc-app');
        const txt = app ? (app.innerText || '').replace(/\s+/g, ' ').trim() : '';
        return { visibleTextLen: txt.length, sample: txt.slice(0, 200),
          childCount: app ? app.querySelectorAll('*').length : 0 };
      });
      obs.routes[name] = { clicked, ...info, rawLabels: scanRawLabels(await page.evaluate(() => document.body.innerText)) };
      await page.screenshot({ path: shot(`D4-${name}.png`), fullPage: true });
      console.log(`[D4:${name}]`, JSON.stringify({ clicked, len: info.visibleTextLen }));
    }
    obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
    saveObs('D4-account-orders', obs);
    for (const name of ['Fidélité', 'Commandes']) expect.soft(obs.routes[name].visibleTextLen, `${name} not blank`).toBeGreaterThan(40);
  });
});

// =============================================================================
// MOBILE BLOCK — Pixel 7
// =============================================================================
// Strip defaultBrowserType/browserName — Playwright forbids them inside a describe group.
const px7raw = devices['Pixel 7'] || { ...devices['Pixel 5'], viewport: { width: 412, height: 915 } };
const { defaultBrowserType: _dbt, browserName: _bn, ...px7 } = px7raw;
test.describe('E2 Mobile Pixel 7', () => {
  test.use({ ...px7 });
  test.describe.configure({ mode: 'serial', retries: 0 });
  test.setTimeout(180_000);

  test('M1 — nav mobile : burger → tiroir plein écran, 4 liens + panier + connexion, 0 débord', async ({ page }) => {
    const t = track(page);
    await gotoDev(page);
    await page.screenshot({ path: shot('M1-01-home-mobile.png'), fullPage: false });
    const obs = { device: 'Pixel 7', viewport: page.viewportSize() };
    obs.overflowClosed = await overflowOf(page);

    const burger = page.locator('.lc-nav-burger');
    obs.burgerVisible = await burger.isVisible({ timeout: 10_000 }).catch(() => false);
    await burger.tap().catch(async () => { await burger.click().catch(() => {}); });
    const drawer = page.locator('#lc-mobile-menu');
    obs.drawerOpens = await drawer.isVisible({ timeout: 6_000 }).catch(() => false);
    await page.waitForTimeout(600);
    await page.screenshot({ path: shot('M1-02-drawer-open.png'), fullPage: false });

    const box = await drawer.boundingBox().catch(() => null);
    const vp = page.viewportSize();
    obs.coverageW = box ? +(box.width / vp.width).toFixed(3) : 0;
    obs.coverageH = box ? +(box.height / vp.height).toFixed(3) : 0;

    const links = drawer.locator('.lc-mobile-link');
    obs.linkCount = await links.count();
    obs.links = [];
    for (let i = 0; i < obs.linkCount; i++) {
      const b = await links.nth(i).boundingBox().catch(() => null);
      const txt = (await links.nth(i).innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
      obs.links.push({ txt, h: b ? Math.round(b.height) : 0, w: b ? Math.round(b.width) : 0 });
    }
    // cart + login inside drawer or nav
    obs.hasCart = await page.evaluate(() => /panier/i.test(document.querySelector('#lc-mobile-menu')?.innerText || '') || !!document.querySelector('.lc-nav-cart, [aria-label*="anier" i]'));
    obs.hasLogin = await page.evaluate(() => /connexion|connecter|compte/i.test(document.querySelector('#lc-mobile-menu')?.innerText || ''));
    obs.overflowOpen = await overflowOf(page);
    obs.drawerRawLabels = scanRawLabels(await page.evaluate(() => document.querySelector('#lc-mobile-menu')?.innerText || ''));

    // tap Menu → grille menu
    const menuLink = drawer.locator('.lc-mobile-link', { hasText: 'Menu' }).first();
    await menuLink.tap().catch(async () => { await menuLink.click().catch(() => {}); });
    obs.menuGridAfterTap = await page.locator('.lc-menu-grid').first().isVisible({ timeout: 12_000 }).catch(() => false);
    await page.waitForTimeout(600);
    await page.screenshot({ path: shot('M1-03-after-tap-menu.png'), fullPage: false });
    obs.overflowMenu = await overflowOf(page);

    obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
    saveObs('M1-nav', obs);
    console.log('[M1]', JSON.stringify({ burger: obs.burgerVisible, drawer: obs.drawerOpens, covW: obs.coverageW, covH: obs.coverageH, links: obs.linkCount, minH: Math.min(...obs.links.map((l) => l.h)), cart: obs.hasCart, login: obs.hasLogin, ofClosed: obs.overflowClosed.horizontal, ofOpen: obs.overflowOpen.horizontal, menuTap: obs.menuGridAfterTap }));
    expect.soft(obs.linkCount, '4 links').toBe(4);
    expect.soft(obs.coverageW, 'drawer full width').toBeGreaterThanOrEqual(0.95);
    expect.soft(obs.overflowClosed.horizontal, 'no overflow closed').toBeFalsy();
    expect.soft(obs.overflowOpen.horizontal, 'no overflow open').toBeFalsy();
    for (const l of obs.links) expect.soft(l.h, `link ${l.txt} >=44`).toBeGreaterThanOrEqual(44);
  });

  test('M2 — menu mobile + une page légale mobile (débord)', async ({ page }) => {
    const t = track(page);
    await gotoDev(page);
    // open menu via burger
    await page.locator('.lc-nav-burger').tap().catch(() => {});
    await page.locator('#lc-mobile-menu .lc-mobile-link', { hasText: 'Menu' }).first().tap().catch(() => {});
    await page.locator('.lc-menu-grid').first().waitFor({ timeout: 12_000 }).catch(() => {});
    await page.waitForTimeout(600);
    const obs = { surface: 'menu-mobile' };
    obs.overflowMenu = await overflowOf(page);
    obs.rawLabels = scanRawLabels(await page.evaluate(() => document.body.innerText));
    obs.brokenImgs = await brokenImgs(page);
    await page.screenshot({ path: shot('M2-01-menu-mobile.png'), fullPage: false });

    // legal mobile
    const resp = await page.goto(`${BASE}/legal/mentions.html`, { waitUntil: 'networkidle', timeout: 40_000 }).catch(() => null);
    await page.waitForTimeout(500);
    obs.legalStatus = resp ? resp.status() : 0;
    obs.legalOverflow = await overflowOf(page);
    await page.screenshot({ path: shot('M2-02-legal-mobile.png'), fullPage: false });
    obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
    saveObs('M2-menu-legal', obs);
    console.log('[M2]', JSON.stringify({ ofMenu: obs.overflowMenu.horizontal, raw: obs.rawLabels.length, broken: obs.brokenImgs.length, legal: obs.legalStatus, ofLegal: obs.legalOverflow.horizontal }));
    expect.soft(obs.overflowMenu.horizontal, 'no overflow menu mobile').toBeFalsy();
    expect.soft(obs.legalOverflow.horizontal, 'no overflow legal mobile').toBeFalsy();
  });
});
