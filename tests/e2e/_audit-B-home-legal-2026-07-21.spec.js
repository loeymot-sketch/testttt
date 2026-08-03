// =============================================================================
// _audit-B-home-legal-2026-07-21.spec.js
// WAVE B — GStack + adversarial E2E audit of the LIVE Vercel site.
// Home page (hero product image + "Suis-nous" social section) + the 5 legal
// pages (mentions / cgv / privacy / cookies / allergens).
//
// Capture full-page screenshots + console + network, assert required facts &
// absence of leftover placeholders. READ-ONLY audit — no code touched, no fix.
//
// Run:
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=https://site-lecayenne.vercel.app \
//   npx playwright test tests/e2e/_audit-B-home-legal-2026-07-21.spec.js --project=chromium
// =============================================================================

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '../..');
const SHOTS = path.join(repoRoot, 'tests/e2e/__screenshots__/audit-B-home-legal');
const DIAG = path.join(repoRoot, 'reports/test-e2e/session-deploy-validation-2026-07-21/round-1');
fs.mkdirSync(SHOTS, { recursive: true });
fs.mkdirSync(DIAG, { recursive: true });

const BASE = process.env.PLAYWRIGHT_BASE_URL || 'https://site-lecayenne.vercel.app';

// Shared diagnostics accumulator, dumped to JSON at the end.
const diag = {
  base: BASE,
  home: {},
  legal: {},
  network4xx5xx: [],
  consoleErrors: [],
};

function attachConsoleAndNetwork(page, surfaceLabel) {
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      diag.consoleErrors.push({ surface: surfaceLabel, text: msg.text() });
    }
  });
  page.on('response', (resp) => {
    const s = resp.status();
    if (s >= 400) {
      diag.network4xx5xx.push({ surface: surfaceLabel, status: s, url: resp.url() });
    }
  });
}

test.describe.configure({ mode: 'serial' });

test.use({ viewport: { width: 1440, height: 900 } });

// -----------------------------------------------------------------------------
// 1. HOME — hero image + social section
// -----------------------------------------------------------------------------
test('B1 — home /?dev : hero image + Suis-nous social section', async ({ page }) => {
  attachConsoleAndNetwork(page, 'home');

  await page.goto(`${BASE}/?dev`, { waitUntil: 'networkidle', timeout: 60_000 });
  // React + Babel-standalone compile in-browser; give the app time to mount.
  await page.waitForSelector('#root', { timeout: 30_000 });
  await page.waitForTimeout(2500);
  // Ensure any lazy imagery + fonts have settled.
  await page.evaluate(async () => {
    if (document.fonts && document.fonts.ready) { try { await document.fonts.ready; } catch (_e) {} }
  });
  await page.waitForLoadState('networkidle').catch(() => {});
  // Scroll through the whole page so lazy (loading="lazy") gallery images fire.
  await page.evaluate(async () => {
    const step = window.innerHeight;
    for (let y = 0; y <= document.body.scrollHeight; y += step) {
      window.scrollTo(0, y);
      await new Promise((r) => setTimeout(r, 250));
    }
    window.scrollTo(0, 0);
  });
  await page.waitForTimeout(1500);

  // --- Hero image (assets/menu/sandwich-cayenne.png, class lc-hero-art-svg) ---
  const hero = await page.evaluate(() => {
    const img = document.querySelector('img.lc-hero-art-svg') ||
                document.querySelector('.lc-hero-art img');
    if (!img) return { found: false };
    return {
      found: true,
      src: img.getAttribute('src'),
      currentSrc: img.currentSrc,
      naturalWidth: img.naturalWidth,
      naturalHeight: img.naturalHeight,
      displayNone: getComputedStyle(img).display === 'none',
      complete: img.complete,
    };
  });
  diag.home.hero = hero;

  // --- Facebook button ---
  const fbBtn = await page.evaluate(() => {
    const links = Array.from(document.querySelectorAll('a'));
    const btn = links.find((a) => /facebook\.com\/LeCayenne/i.test(a.href) &&
                                   /Facebook/i.test(a.textContent || ''));
    return btn ? { found: true, href: btn.href, text: (btn.textContent || '').trim() } : { found: false };
  });
  diag.home.facebookButton = fbBtn;

  // --- "Suis-nous" heading text present ---
  const suisNous = await page.evaluate(() => /Suis-nous/i.test(document.body.innerText));
  diag.home.suisNousText = suisNous;

  // --- 5 gallery tiles + their images loaded ---
  const gallery = await page.evaluate(() => {
    const tiles = Array.from(document.querySelectorAll('.lc-gallery-tile'));
    return {
      tileCount: tiles.length,
      tiles: tiles.map((a) => {
        const img = a.querySelector('img');
        return {
          href: a.href,
          src: img ? img.getAttribute('src') : null,
          naturalWidth: img ? img.naturalWidth : 0,
          naturalHeight: img ? img.naturalHeight : 0,
          displayNone: img ? getComputedStyle(img).display === 'none' : true,
        };
      }),
    };
  });
  diag.home.gallery = gallery;

  // --- horizontal scroll on body (layout break signal) ---
  const overflow = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    horizontalScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
  }));
  diag.home.overflow = overflow;

  // --- Any broken imgs across the whole home page ---
  const brokenImgs = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('img'))
      .filter((img) => img.complete && img.naturalWidth === 0)
      .map((img) => img.getAttribute('src'));
  });
  diag.home.brokenImgs = brokenImgs;

  // --- Captures ---
  await page.screenshot({ path: path.join(SHOTS, 'home-full.png'), fullPage: true });
  // Hero region (top of page)
  await page.screenshot({ path: path.join(SHOTS, 'home-hero-viewport.png'), fullPage: false });
  // Social region: scroll it into view and shoot
  const galleryEl = page.locator('.lc-gallery').first();
  if (await galleryEl.count()) {
    await galleryEl.scrollIntoViewIfNeeded();
    await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(SHOTS, 'home-social-viewport.png'), fullPage: false });
  }

  // Soft assertions (do not abort the run — this is an audit, we want all data).
  expect.soft(hero.found, 'hero <img> present').toBeTruthy();
  expect.soft(hero.naturalWidth, 'hero image loaded (naturalWidth>0)').toBeGreaterThan(0);
  expect.soft(fbBtn.found, 'Facebook button present').toBeTruthy();
  expect.soft(gallery.tileCount, '5 gallery tiles').toBe(5);
});

// -----------------------------------------------------------------------------
// 2. LEGAL PAGES
// -----------------------------------------------------------------------------
const FORBIDDEN = ['À COMPLÉTER', 'Matomo', 'Mailgun', 'Twilio'];

const LEGAL = [
  {
    slug: 'mentions',
    url: '/legal/mentions.html',
    mustContain: ['E.DELICE SAS', '10417050100019', 'FR19104170501'],
    // task-expected filled facts (may be missing on stale/partial deploy):
    expectFilled: { 'Forme juridique = SAS': /Forme juridique\s*:<\/strong>\s*SAS/i,
                    'RCS = Béthune': /RCS\s*:<\/strong>[^<]*Béthune/i,
                    'APE = 5610C': /5610C/ },
  },
  { slug: 'cgv', url: '/legal/cgv.html', mustContain: ['CM2C', 'cm2c.net'] },
  { slug: 'privacy', url: '/legal/privacy.html', mustContain: ['Vercel Inc', 'OTP', 'EEE'] },
  { slug: 'cookies', url: '/legal/cookies.html', mustContain: ['aucun cookie de mesure'] },
  { slug: 'allergens', url: '/legal/allergens.html', mustContain: ['halal'] },
];

for (const L of LEGAL) {
  test(`B2 — legal ${L.slug}`, async ({ page }) => {
    attachConsoleAndNetwork(page, `legal:${L.slug}`);
    const resp = await page.goto(`${BASE}${L.url}`, { waitUntil: 'networkidle', timeout: 45_000 });
    await page.waitForTimeout(600);

    const status = resp ? resp.status() : 0;
    const bodyText = await page.evaluate(() => document.body.innerText || '');
    const html = await page.content();

    // Visible forbidden placeholders (case-insensitive), scanning rendered text.
    const visiblePlaceholders = FORBIDDEN.filter((p) =>
      new RegExp(p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i').test(bodyText));

    // Count of À COMPLÉTER occurrences in visible text.
    const aCompleterCount = (bodyText.match(/À COMPLÉTER/gi) || []).length;

    // mustContain facts (in visible text).
    const missingFacts = (L.mustContain || []).filter((f) =>
      !new RegExp(f.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i').test(bodyText));

    // expectFilled (checked against HTML source to keep the <strong> anchor).
    const filledStatus = {};
    if (L.expectFilled) {
      for (const [label, re] of Object.entries(L.expectFilled)) {
        filledStatus[label] = re.test(html);
      }
    }

    const overflow = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      horizontalScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
    }));

    diag.legal[L.slug] = {
      status,
      visiblePlaceholders,
      aCompleterCount,
      missingFacts,
      filledStatus,
      overflow,
      textLen: bodyText.length,
    };

    await page.screenshot({ path: path.join(SHOTS, `legal-${L.slug}-full.png`), fullPage: true });

    expect.soft(status, `${L.slug} returns 200`).toBe(200);
    expect.soft(missingFacts, `${L.slug} required facts present`).toEqual([]);
  });
}

test.afterAll(async () => {
  fs.writeFileSync(path.join(DIAG, 'wave-B-diagnostics.json'), JSON.stringify(diag, null, 2));
  // eslint-disable-next-line no-console
  console.log('\n=== WAVE B DIAGNOSTICS ===\n' + JSON.stringify(diag, null, 2));
});
