// =============================================================================
// REVALIDATION E2E LIVE 2026-07-23 — R5 pages légales (Vercel)
// mentions (E.DELICE SAS / SIRET / RCS Béthune / APE 5610C / 0 « À COMPLÉTER »),
// cgv, confidentialité, cookies, allergènes — chargées, texte réel, pas de label brut.
// READ-ONLY probe. Run: PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/revalidation-legal-2026-07-23.spec.js --project=chromium
// =============================================================================
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const BASE = 'https://site-lecayenne.vercel.app';
const SHOT = path.join(__dirname, '__screenshots__', 'revalidation-2026-07-23');
fs.mkdirSync(SHOT, { recursive: true });

test.use({ viewport: { width: 1440, height: 900 } });
test.describe.configure({ retries: 0 });
test.setTimeout(120_000);

const RAW_KEY_RE = /^[a-z]+(\.[a-z_]+){1,4}$/;
const PAGES = [
  { slug: 'mentions',  url: '/legal/mentions.html',  facts: ['E.DELICE', 'Béthune', '5610C'], siret: true },
  { slug: 'cgv',       url: '/legal/cgv.html',       facts: ['CM2C'] },
  { slug: 'privacy',   url: '/legal/privacy.html',   facts: ['OTP'] },
  { slug: 'cookies',   url: '/legal/cookies.html',   facts: ['cookie'] },
  { slug: 'allergens', url: '/legal/allergens.html', facts: ['halal'] },
];

const diag = {};

for (const P of PAGES) {
  test(`R5 — legal ${P.slug}`, async ({ page }) => {
    const resp = await page.goto(BASE + P.url, { waitUntil: 'networkidle', timeout: 45_000 });
    await page.waitForTimeout(500);
    const status = resp ? resp.status() : 0;
    const bodyText = await page.evaluate(() => document.body.innerText || '');
    const digits = bodyText.replace(/[\s.]/g, '');
    const aCompleter = (bodyText.match(/À COMPLÉTER/gi) || []).length;
    const missing = P.facts.filter(f => !new RegExp(f.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i').test(bodyText));
    const siretOk = P.siret ? /10417050100019/.test(digits) : null;
    const rawKeys = [...new Set(bodyText.split(/\s+/).filter(t => RAW_KEY_RE.test(t)))]
      .filter(k => !/\.(html|fr|net|com|php)$/.test(k));
    diag[P.slug] = { status, textLen: bodyText.length, aCompleter, missing, siretOk, rawKeys: rawKeys.slice(0, 8) };
    await page.screenshot({ path: path.join(SHOT, `R5-${P.slug}.png`), fullPage: true });
    console.log(`[R5:${P.slug}]`, JSON.stringify(diag[P.slug]));
    fs.writeFileSync(path.join(SHOT, 'obs-R5.json'), JSON.stringify(diag, null, 2));

    expect(status, `${P.slug} HTTP 200`).toBe(200);
    expect(bodyText.length, `${P.slug} contenu réel (>400 chars)`).toBeGreaterThan(400);
    expect(aCompleter, `${P.slug} zéro « À COMPLÉTER »`).toBe(0);
    expect(missing, `${P.slug} faits requis présents`).toEqual([]);
    if (P.siret) expect(siretOk, 'SIRET 104 170 501 00019 présent').toBeTruthy();
  });
}
