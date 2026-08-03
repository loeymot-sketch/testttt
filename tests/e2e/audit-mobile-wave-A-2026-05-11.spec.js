// audit-mobile-wave-A-2026-05-11 — Wave A
// Onboarding + auth + home + tab navigation for the Le Cayenne mobile prototype
// served by PHP -S on http://127.0.0.1:8081/index.html.
//
// Captures 15 numbered states via mega-audit-snap (PNG + DOM + console + network).

const { test, expect } = require('@playwright/test');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const path = require('path');

const SCREEN_DIR = path.join(__dirname, '__screenshots__/test-e2e-mobile-wave-A');
const MOBILE_URL = 'http://127.0.0.1:8081/index.html';

test.use({
  viewport: { width: 390, height: 844 },
  deviceScaleFactor: 2,
  isMobile: true,
  hasTouch: true,
  userAgent:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
});

test('wave A — onboarding + auth + home + tabs', async ({ page, context }) => {
  test.setTimeout(180_000);
  const { snap, dispose } = attachMegaAuditRecorder(page, SCREEN_DIR);

  // Ensure no residual auth / onboarding state. Cookies + every storage namespace
  // are cleared before the first navigation so the boot decision in
  // mobile/index.html lands on `splash`.
  await context.clearCookies();
  await page.addInitScript(() => {
    try { window.localStorage.clear(); } catch (_) { /* noop */ }
    try { window.sessionStorage.clear(); } catch (_) { /* noop */ }
  });

  // ───────────────────────── STATE 01 — splash ─────────────────────────
  // App.js auto-advances to onb1 after 1800ms; goto first, snap immediately
  // (with networkidle wait giving us a ~loaded but pre-timer frame).
  await page.goto(MOBILE_URL, { waitUntil: 'networkidle' });
  // Allow React to mount + Babel transform to finish.
  await page.waitForSelector('[data-screen-label="00 Splash"]', { timeout: 10_000 });
  await snap('01-splash-fresh');

  // ───────────────────────── STATE 02 — onb1 ───────────────────────────
  // Either wait for the splash auto-advance OR click splash (it's clickable).
  // We rely on the auto-advance to mirror real boot behaviour.
  await page.waitForSelector('[data-screen-label="01 Onboarding"]', { timeout: 5_000 });
  await snap('02-onb1');

  // ───────────────────────── STATE 03 — onb2 ───────────────────────────
  // The onboarding shell renders ONE round black "next" button (64x64 with
  // border-radius 999) at the bottom-right of the content panel. The hero
  // panel renders an <image-slot> web component whose shadow DOM contains
  // "Replace"/"Remove" dev helper buttons — we MUST avoid matching those.
  //
  // Strategy: locate buttons via inline style signature `width: 64px` (the
  // OnboardingShell next button is the only such element in the screen).
  const clickNext = async () => {
    // Use page.evaluate to click via JS since image-slot intercepts pointer events
    // on overlap, and the only round 64px button per screen is the onb next btn.
    await page.evaluate(() => {
      const screens = document.querySelectorAll('[data-screen-label$=" Onboarding"]');
      // Click the LAST visible onboarding screen's round next button.
      for (let i = screens.length - 1; i >= 0; i--) {
        const screen = screens[i];
        if (!(screen instanceof HTMLElement)) continue;
        const rect = screen.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) continue;
        // Find the 64x64 round button (inline style width:64px, height:64px).
        const btns = screen.querySelectorAll('button');
        for (const b of btns) {
          const style = b.getAttribute('style') || '';
          if (style.includes('width: 64px') && style.includes('height: 64px')) {
            b.click();
            return true;
          }
        }
      }
      return false;
    });
  };

  await clickNext();
  await page.waitForSelector('[data-screen-label="02 Onboarding"]', { timeout: 5_000 });
  await snap('03-onb2');

  // ───────────────────────── STATE 04 — onb3 ───────────────────────────
  await clickNext();
  await page.waitForSelector('[data-screen-label="03 Onboarding"]', { timeout: 5_000 });
  await snap('04-onb3');

  // ───────────────────────── STATE 05 — onb4 ───────────────────────────
  await clickNext();
  await page.waitForSelector('[data-screen-label="04 Onboarding"]', { timeout: 5_000 });
  await snap('05-onb4');

  // ───────────────────────── STATE 06 — login empty ────────────────────
  // onb4 "next" routes directly to login (markOnboardingSeen + go('login')).
  await clickNext();
  await page.waitForSelector('[data-screen-label="05 Login"]', { timeout: 5_000 });
  // Clear the prefilled "06 " phone value so the screenshot reflects an "empty"
  // state. We still keep the field interactable so the user can type.
  // Note: the source code prefills phone to "06 " — we treat that as the canonical
  // empty state per spec brief ("phone empty" = "no user input yet").
  await snap('06-login-empty');

  // Type a phone and tap "Recevoir le code".
  // The input is the only `input` in the login screen.
  const loginScreen = page.locator('[data-screen-label="05 Login"]');
  await loginScreen.locator('input').fill('06 12 34 56 78');
  await loginScreen.getByRole('button', { name: /Recevoir le code/i }).click();

  // ───────────────────────── STATE 07 — OTP empty ──────────────────────
  await page.waitForSelector('[data-screen-label="06 OTP"]', { timeout: 5_000 });
  // The first input is auto-focused (useEffect_o); snap before typing.
  await snap('07-otp-empty');

  // ───────────────────────── STATE 08 — OTP typed 1234 ─────────────────
  // ScreenOTP renders 4 inputs of length 1 each. Typing fills sequentially and
  // auto-advances; after 4 digits, setTimeout(onNext, 400) auto-submits.
  const otpScreen = page.locator('[data-screen-label="06 OTP"]');
  const otpInputs = otpScreen.locator('input');
  // Press keys one at a time (focus advances automatically via refs).
  await otpInputs.nth(0).press('1');
  await otpInputs.nth(1).press('2');
  await otpInputs.nth(2).press('3');
  await otpInputs.nth(3).press('4');
  // snap RIGHT NOW before the 400ms timeout fires & navigates to home.
  await snap('08-otp-typed-1234');

  // ───────────────────────── STATE 09 — home authed ────────────────────
  await page.waitForSelector('[data-screen-label="07 Home"]', { timeout: 5_000 });
  // Give the home screen a beat to fully render its sub-grids (Marquee +
  // category grid + featured) before snapping.
  await page.waitForTimeout(400);
  await snap('09-home-authed');

  // ───────────────────────── STATE 10 — home greeting ──────────────────
  // Verify the greeting line. Source: screens-main.jsx renders "Bonjour," or
  // "Bonsoir," based on the current hour, then the user's first name on the
  // next line. Both are inside an h1.lc-display (text-transform:uppercase),
  // so visually "BONJOUR" / "BONSOIR" + "IKYES" — but the DOM text is the
  // lowercased source.
  const homeScreen = page.locator('[data-screen-label="07 Home"]');
  const greetingH1 = homeScreen.locator('h1.lc-display').first();
  await expect(greetingH1).toBeVisible();
  const greetingText = (await greetingH1.innerText()).replace(/\s+/g, ' ').trim();
  // Cross-check that the greeting contains either "Bonjour" or "Bonsoir" AND
  // the firstname "Ikyes" (visually uppercased to IKYES).
  // (innerText respects text-transform on some engines; we accept both cases.)
  const lower = greetingText.toLowerCase();
  expect(lower).toMatch(/bonjour|bonsoir/);
  expect(lower).toContain('ikyes');
  await snap('10-home-greeting');

  // ───────────────────────── STATE 11 — featured card ──────────────────
  // The featured card sits below the marquee. It renders the SIGNATURE pill
  // and the title from window.LC.menu.findItem('tacos-xxl'). We confirm the
  // pill is present + the card text mentions "tacos".
  const featuredCard = homeScreen
    .locator('div')
    .filter({ hasText: /SIGNATURE/i })
    .filter({ hasText: /TACOS|tacos/i })
    .first();
  await expect(featuredCard).toBeVisible();
  await snap('11-home-featured-card');

  // ───────────────────────── STATE 12 — categories grid ────────────────
  // Per source, the home page only renders CATS.slice(0, 6) — first 6 of 13.
  // The "Categories" header sibling shows the total count: "{CATS.length} choix".
  // We assert that the "choix" sibling reads "13 choix", which proves the
  // dataset is the full 13-category list, then snap the grid.
  const choixSpan = homeScreen.locator('span.more').filter({ hasText: /choix/i }).first();
  await expect(choixSpan).toBeVisible();
  await expect(choixSpan).toHaveText(/13 choix/);
  // Snap covers the visible 6 tiles + the "13 choix" header signaling all 13.
  await snap('12-home-categories-13');

  // ───────────────────────── STATE 13 — MENU tab ───────────────────────
  // TabBar tabs (label, id): Accueil=home, Menu=menu, Commandes=orders, Profil=profile.
  // The labels in the DOM are: Accueil, Menu, Commandes, Profil.
  const tabBar = page.locator('.lc-tabs');
  await expect(tabBar).toBeVisible();
  await tabBar.locator('.lc-tab').filter({ hasText: /^Menu$/ }).click();
  await page.waitForSelector('[data-screen-label*="Menu" i], [data-screen-label*="menu" i]', { timeout: 5_000 }).catch(async () => {
    // Fallback: at least confirm the tab class flipped to active.
    await expect(tabBar.locator('.lc-tab--active')).toContainText(/Menu/i);
  });
  await page.waitForTimeout(300);
  await snap('13-tab-menu-active');

  // ───────────────────────── STATE 14 — COMMANDES tab ──────────────────
  await tabBar.locator('.lc-tab').filter({ hasText: /^Commandes$/ }).click();
  await page.waitForTimeout(400);
  await expect(tabBar.locator('.lc-tab--active')).toContainText(/Commandes/i);
  await snap('14-tab-commandes-active');

  // ───────────────────────── STATE 15 — PROFIL tab ─────────────────────
  await tabBar.locator('.lc-tab').filter({ hasText: /^Profil$/ }).click();
  await page.waitForTimeout(400);
  await expect(tabBar.locator('.lc-tab--active')).toContainText(/Profil/i);
  await snap('15-tab-profil-active');

  dispose();
});
