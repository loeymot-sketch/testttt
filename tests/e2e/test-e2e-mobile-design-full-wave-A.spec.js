// test-e2e-mobile-design-full-wave-A — Wave A : Mobile onboarding visuel
//
// Audit `mobile-design-full-2026-05-11` round-1, GStack main team.
// Covers : Splash → Onb1-4 → Login (empty + typed) → OTP (empty + typed) →
// Home post-login. 10 numbered states, each captured via mega-audit-snap
// (PNG + DOM + console + network) into
//   tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-A/
//
// Forked from tests/e2e/audit-mobile-wave-A-2026-05-11.spec.js. Differences :
//   - 10 states (no tab nav — split into Wave B)
//   - Explicit assertions per the design-full brief :
//       * lc-display class present on onb headlines
//       * Dots progression visible on onb screens
//       * Palette tokens resolvable on :root (--orange #FF5A1F, --yellow #FFD93D,
//         --ink #0A0A0A, --paper, --cream)
//       * No raw label leak (Label.*, kiosk.*, lecayenne.*) in visible text
//       * [data-screen-label] visible across all 10 states
//   - Phone typed = 06 42 79 98 84 (canonical FR mobile per brief 0642799884)
//
// Run :
//   npx playwright test --config=tests/mobile-e2e/playwright.config.js \
//     tests/e2e/test-e2e-mobile-design-full-wave-A.spec.js \
//     --reporter=list --workers=1 --timeout=120000

const { test, expect } = require('@playwright/test');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const path = require('path');

const SCREEN_DIR = path.join(__dirname, '__screenshots__/test-e2e-mobile-design-full-wave-A');
const MOBILE_URL = 'http://127.0.0.1:8081/index.html';

// Raw-label leak patterns the design audit forbids. Each onboarding / login /
// otp / home rendered text MUST NOT match any of these.
const RAW_LABEL_FORBIDDEN = [
  /\bLabel\.[A-Za-z0-9_.-]+/i,
  /\bkiosk\.[A-Za-z0-9_.-]+/i,
  /\blecayenne\.[A-Za-z0-9_.-]+/i,
  /\bundefined\b/,
  /\bnull\b/,
];

async function assertNoRawLabelLeak(scope, stateName) {
  // We scrape the visible inner text of the active screen and assert none of
  // the forbidden patterns appear. Comments / data attrs are ignored — we want
  // the rendered-to-user text only.
  const text = (await scope.innerText()).replace(/\s+/g, ' ').trim();
  for (const re of RAW_LABEL_FORBIDDEN) {
    expect(text, `${stateName} contains raw label leak matching ${re}`).not.toMatch(re);
  }
}

async function assertScreenLabelVisible(page, expectedLabel, stateName) {
  const screen = page.locator(`[data-screen-label="${expectedLabel}"]`);
  await expect(screen, `${stateName} expects data-screen-label="${expectedLabel}" to be visible`).toBeVisible();
  return screen;
}

async function assertPaletteTokens(page, stateName) {
  // Resolve CSS custom properties on :root. Spec brief allows either the exact
  // hex or any CSS-resolvable value (browsers normalize #FF5A1F → rgb(...)).
  const tokens = await page.evaluate(() => {
    const cs = getComputedStyle(document.documentElement);
    return {
      orange: cs.getPropertyValue('--orange').trim(),
      yellow: cs.getPropertyValue('--yellow').trim(),
      ink: cs.getPropertyValue('--ink').trim(),
      paper: cs.getPropertyValue('--paper').trim(),
      cream: cs.getPropertyValue('--cream').trim(),
    };
  });
  // Accept hex or rgb forms; required = non-empty.
  expect(tokens.orange.toUpperCase(), `${stateName} --orange`).toContain('FF5A1F');
  expect(tokens.yellow.toUpperCase(), `${stateName} --yellow`).toContain('FFD93D');
  expect(tokens.ink.toUpperCase(), `${stateName} --ink`).toContain('0A0A0A');
  expect(tokens.paper, `${stateName} --paper non-empty`).not.toBe('');
  expect(tokens.cream, `${stateName} --cream non-empty`).not.toBe('');
}

test.use({
  viewport: { width: 390, height: 844 },
  deviceScaleFactor: 2,
  isMobile: true,
  hasTouch: true,
  userAgent:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
});

test('mobile-design-full wave A — splash → onb1-4 → login → otp → home (10 states)', async ({ page, context }) => {
  test.setTimeout(180_000);
  const { snap, dispose } = attachMegaAuditRecorder(page, SCREEN_DIR);

  // Fresh storage : cookies + every storage namespace cleared BEFORE first nav,
  // so the boot decision in mobile/index.html lands on `splash` (no auth, no
  // markOnboardingSeen flag).
  await context.clearCookies();
  await page.addInitScript(() => {
    try { window.localStorage.clear(); } catch (_) { /* noop */ }
    try { window.sessionStorage.clear(); } catch (_) { /* noop */ }
  });

  // ───────────────────────── STATE 01 — splash-fresh ─────────────────────────
  await page.goto(MOBILE_URL, { waitUntil: 'networkidle' });
  // Wait for React + Babel-standalone transpile + initial paint.
  const splash = await assertScreenLabelVisible(page, '00 Splash', 'state 01 splash');
  await assertPaletteTokens(page, 'state 01 splash');
  await snap('01-splash-fresh');

  // ───────────────────────── STATE 02 — onb1 (palette/discovery) ─────────────
  // App.js auto-advances splash → onb1 after 1800ms (timer in screens-main).
  const onb1 = await assertScreenLabelVisible(page, '01 Onboarding', 'state 02 onb1');
  // lc-display headline present (Anton font).
  await expect(onb1.locator('h1.lc-display').first(), 'state 02 onb1 has lc-display h1').toBeVisible();
  // Dots progression visible (count=4, active=0). The Dots component (defined
  // in mobile/shared.jsx) renders count=4 inside a flex container, where the
  // active dot has width:22px and the inactive dots have width:6px. Both share
  // height:6px + border-radius:999px. We count round nodes whose height is 6px
  // (uniquely identifying Dots — round 6px-high siblings of an onb action row).
  const onb1DotCount = await onb1.evaluate((el) => {
    return Array.from(el.querySelectorAll('div')).filter((d) => {
      const s = d.getAttribute('style') || '';
      return /border-radius: 999/.test(s) && /height: 6px/.test(s);
    }).length;
  });
  expect(onb1DotCount, 'state 02 onb1 has ≥ 4 progression dots').toBeGreaterThanOrEqual(4);
  await assertNoRawLabelLeak(onb1, 'state 02 onb1');
  await snap('02-onb1');

  // Round 64px next button. Same JS-click strategy as audit-mobile-wave-A.
  const clickNext = async () => {
    await page.evaluate(() => {
      const screens = document.querySelectorAll('[data-screen-label$=" Onboarding"]');
      for (let i = screens.length - 1; i >= 0; i--) {
        const screen = screens[i];
        if (!(screen instanceof HTMLElement)) continue;
        const rect = screen.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) continue;
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

  // ───────────────────────── STATE 03 — onb2 (paiement / vitesse) ────────────
  await clickNext();
  const onb2 = await assertScreenLabelVisible(page, '02 Onboarding', 'state 03 onb2');
  await expect(onb2.locator('h1.lc-display').first()).toBeVisible();
  await assertNoRawLabelLeak(onb2, 'state 03 onb2');
  await snap('03-onb2');

  // ───────────────────────── STATE 04 — onb3 (fidélité / pickup) ─────────────
  await clickNext();
  const onb3 = await assertScreenLabelVisible(page, '03 Onboarding', 'state 04 onb3');
  await expect(onb3.locator('h1.lc-display').first()).toBeVisible();
  await assertNoRawLabelLeak(onb3, 'state 04 onb3');
  await snap('04-onb3');

  // ───────────────────────── STATE 05 — onb4 (commencer CTA) ─────────────────
  await clickNext();
  const onb4 = await assertScreenLabelVisible(page, '04 Onboarding', 'state 05 onb4');
  await expect(onb4.locator('h1.lc-display').first()).toBeVisible();
  await assertNoRawLabelLeak(onb4, 'state 05 onb4');
  await snap('05-onb4');

  // ───────────────────────── STATE 06 — login empty ──────────────────────────
  // onb4 next → markOnboardingSeen + go('login').
  await clickNext();
  const loginScreen = await assertScreenLabelVisible(page, '05 Login', 'state 06 login empty');
  // +33 prefix label visible inside the field.
  await expect(loginScreen.getByText(/^\+33$/)).toBeVisible();
  // Phone input is present (and prefilled with "06 " by source — that is the
  // canonical "empty" state per the audit brief).
  await expect(loginScreen.locator('input')).toBeVisible();
  await assertNoRawLabelLeak(loginScreen, 'state 06 login empty');
  await snap('06-login-empty');

  // ───────────────────────── STATE 07 — login typed (FR mobile) ──────────────
  // Brief : 0642799884 typed → format with spaces for the field (06 42 79 98 84).
  const phoneInput = loginScreen.locator('input').first();
  await phoneInput.fill(''); // clear the "06 " prefill before typing.
  await phoneInput.type('06 42 79 98 84', { delay: 20 });
  // Sanity : input value matches what we typed.
  await expect(phoneInput).toHaveValue('06 42 79 98 84');
  await assertNoRawLabelLeak(loginScreen, 'state 07 login typed');
  await snap('07-login-typed-french-mobile');

  // Trigger OTP screen.
  await loginScreen.getByRole('button', { name: /Recevoir le code/i }).click();

  // ───────────────────────── STATE 08 — OTP empty ────────────────────────────
  const otpScreen = await assertScreenLabelVisible(page, '06 OTP', 'state 08 otp empty');
  // 4-digit OTP inputs visible.
  const otpInputs = otpScreen.locator('input');
  await expect(otpInputs).toHaveCount(4);
  // First input auto-focused, but all 4 should be empty at capture time.
  for (let i = 0; i < 4; i++) {
    await expect(otpInputs.nth(i)).toHaveValue('');
  }
  await assertNoRawLabelLeak(otpScreen, 'state 08 otp empty');
  await snap('08-otp-empty');

  // ───────────────────────── STATE 09 — OTP typed 1234 ───────────────────────
  // Type one digit per input ; ScreenOTP advances focus via refs and after 4
  // digits triggers setTimeout(onNext, 400). Snap BEFORE that timeout fires.
  await otpInputs.nth(0).press('1');
  await otpInputs.nth(1).press('2');
  await otpInputs.nth(2).press('3');
  await otpInputs.nth(3).press('4');
  // All 4 digits visible before the 400ms auto-advance.
  await expect(otpInputs.nth(0)).toHaveValue('1');
  await expect(otpInputs.nth(3)).toHaveValue('4');
  await snap('09-otp-typed-1234');

  // ───────────────────────── STATE 10 — home post-login ──────────────────────
  // The 400ms timer + state transition lands us on screen "07 Home".
  const homeScreen = await assertScreenLabelVisible(page, '07 Home', 'state 10 home post-login');
  // Give sub-grids a beat to paint (marquee + categories + featured card).
  await page.waitForTimeout(500);

  // Greeting line (Bonjour/Bonsoir + firstname) — h1.lc-display.
  const greetingH1 = homeScreen.locator('h1.lc-display').first();
  await expect(greetingH1).toBeVisible();
  const greetingText = (await greetingH1.innerText()).replace(/\s+/g, ' ').trim();
  const lower = greetingText.toLowerCase();
  expect(lower).toMatch(/bonjour|bonsoir/);

  // Tab bar visible.
  const tabBar = page.locator('.lc-tabs');
  await expect(tabBar, 'state 10 tab bar visible').toBeVisible();
  // The 4 expected tabs.
  for (const label of ['Accueil', 'Menu', 'Commandes', 'Profil']) {
    await expect(tabBar.locator('.lc-tab').filter({ hasText: new RegExp(`^${label}$`) })).toBeVisible();
  }

  await assertNoRawLabelLeak(homeScreen, 'state 10 home post-login');
  await snap('10-home-post-login');

  // Final palette token recheck — guards against accidental override during
  // the run (e.g. theme switch via console or component-injected :root rule).
  await assertPaletteTokens(page, 'final palette check');

  dispose();
});
