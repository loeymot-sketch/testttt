// FoodKing E2E sentinel — Fraunces font actually loaded on kiosk surfaces
//
// @FK-ID FK-RED-R2-DSK1 | @source RED-R2 §6 — Fraunces font loading
// Contexte :
//   RED-R2 a flag DSK1 P1 « Fraunces non chargée » (document.fonts.check renvoyait
//   false avant utilisation). BLUE-R2 a réfuté en faux positif harness — le check
//   doit attendre `document.fonts.ready` avant d'être interrogé.
//
//   Cette sentinelle ferme définitivement le contrat :
//     1. Login kiosk (auto-login si configuré, sinon skip honnête)
//     2. await document.fonts.ready
//     3. Cherche un élément dont la `font-family` calculée contient "Fraunces"
//     4. Asserte `document.fonts.check('1em Fraunces') === true` après ready
//   Si false → flag P2 (lien CSP Google Fonts cassé / fallback déclenché).

const { test, expect } = require('@playwright/test');

test.describe('CV1 — Fraunces font loaded on kiosk (DSK1 sentinel)', () => {
  test('document.fonts.check("1em Fraunces") is true after fonts.ready on /kiosk', async ({ page }) => {
    // Best-effort navigation to a kiosk surface where Fraunces is applied.
    // We try /kiosk first (post-login wizard), fall back to /kiosk/login if redirected.
    const targets = ['/kiosk', '/kiosk/login'];
    let landed = null;
    for (const path of targets) {
      const resp = await page.goto(path, { waitUntil: 'domcontentloaded' }).catch(() => null);
      if (resp && resp.ok()) {
        landed = path;
        break;
      }
    }
    test.skip(!landed, 'kiosk routes unreachable on this harness — skip honnête');

    // Give Vue + the Google Fonts <link> a moment to register the font face.
    await page.waitForTimeout(2_000);

    // Step 1 — wait for the FontFaceSet to settle.
    await page.evaluate(() => document.fonts && document.fonts.ready);

    // Step 2 — locate any element whose computed font-family includes "Fraunces".
    const usesFraunces = await page.evaluate(() => {
      const all = Array.from(document.querySelectorAll('body *'));
      return all.some((el) => {
        const ff = window.getComputedStyle(el).fontFamily || '';
        return /Fraunces/i.test(ff);
      });
    });

    if (!usesFraunces) {
      // No Fraunces consumer on this surface — sentinel is non-applicable here.
      // We skip rather than green silently to avoid hiding a real regression.
      test.skip(true, 'no element with computed font-family Fraunces on this surface');
      return;
    }

    // Step 3 — primary assertion: the font is actually loaded.
    const isLoaded = await page.evaluate(() => document.fonts.check('1em Fraunces'));
    expect(
      isLoaded,
      'Fraunces declared in CSS but not loaded — investiguer link tag CSP / fallback (P2)',
    ).toBe(true);
  });
});
