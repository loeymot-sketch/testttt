// test-e2e-web-z7-demo-badges-2026-05-19 — WE-2 Wave-E
//
// Owner decision 2026-05-19 (G-WEB-1 reframed): web loyalty wallet UI is present
// but disconnected from POS (intentional V1 standalone). Customer-trust risk:
// customer sees +25 pts success modal → 347 pts balance → presents QR at POS →
// POS denies (disconnected). Path A heal = add "DÉMO V1" disclosure badges on
// (1) loyalty wallet head, (2) QR area, (3) signup-success modal.
//
// Naming pattern: test-e2e-web-z7-* matches tests/web-e2e/playwright.config.js
// testMatch entry — no config edit required.
//
// Boot manually (or rely on already-running server) :
//   python3 -m http.server 8082 --directory /Users/1millnonstop/Downloads/web
//
// Run :
//   npx playwright test --config=tests/web-e2e/playwright.config.js \
//     tests/e2e/test-e2e-web-z7-demo-badges-2026-05-19.spec.js \
//     --reporter=list --workers=1 --timeout=120000

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const SCREEN_DIR = path.join(__dirname, '__screenshots__/test-e2e-web-z7-demo-badges-2026-05-19');
if (!fs.existsSync(SCREEN_DIR)) fs.mkdirSync(SCREEN_DIR, { recursive: true });

async function bootWeb(page) {
  page._errors = [];
  page.on('pageerror', err => page._errors.push(err.message));
  page.on('console', msg => { if (msg.type() === 'error') page._errors.push(msg.text()); });
  await page.goto('/index.html', { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu && window.LC.menu.items && window.LC.menu.items.length > 0, { timeout: 30000 });
  await page.waitForTimeout(400);
}

function assertCleanConsole(page, label) {
  const real = (page._errors || []).filter(e => !/favicon|image-slots|net::ERR/i.test(e));
  expect(real, `${label} console errors: ${real.join('\n')}`).toHaveLength(0);
}

// Reused from Z7-ACC.2 / Z7-ORD.1 — drives Account modal signup→OTP→success.
// Leaves the App in isAuth=true state with the success modal still visible if
// onCloseSuccess=false; otherwise advances to loyalty route.
async function doSignupOtpFlow(page, { closeAfter = true } = {}) {
  await page.locator('button.lc-nav-btn-account').first().click();
  await page.waitForTimeout(400);
  await page.locator('.lc-acc-input').nth(0).fill('Ikyes');
  await page.locator('input[type="email"]').first().fill('ikyes@lecayenne.fr');
  await page.locator('input[type="tel"]').first().fill('6 12 34 56 78');
  await page.locator('button.lc-acc-cta', { hasText: 'Recevoir le code' }).first().click();
  await page.waitForTimeout(1000);
  const otpCells = page.locator('.lc-otp-cell');
  for (let i = 0; i < 4; i++) {
    await otpCells.nth(i).fill(String(i + 1));
    await page.waitForTimeout(60);
  }
  // Success modal renders after 400ms transition
  await page.waitForTimeout(900);
  if (closeAfter) {
    const finishBtn = page.locator('button.lc-btn--ink', { hasText: 'Commencer à commander' }).first();
    if (await finishBtn.isVisible().catch(() => false)) await finishBtn.click();
    await page.waitForTimeout(500);
  }
}

// ============================================================================
// WE2-LOY.head — DÉMO V1 badge visible in loyalty wallet head (auth'd state)
// ============================================================================
test('WE2-LOY.head — DÉMO V1 badge present in wallet head row', async ({ page }, testInfo) => {
  await bootWeb(page);
  // Sign up and arrive on loyalty route (App routes to loyalty after success)
  await doSignupOtpFlow(page, { closeAfter: true });

  // After signup, App routes to 'loyalty'. Confirm we're on the loyalty page.
  await page.waitForTimeout(500);
  const bodyText = await page.evaluate(() => document.body.innerText);
  expect(bodyText, 'on loyalty page after signup').toMatch(/Mon\s+(compte|solde)|Pepper Club/i);

  // Badge assertion: wallet head should now contain "DÉMO V1"
  const walletHead = page.locator('.lc-wallet-head').first();
  await expect(walletHead, 'wallet head visible').toBeVisible();
  const headText = await walletHead.innerText();
  expect(headText, 'wallet head contains DÉMO V1 badge').toMatch(/DÉMO\s*V1/i);

  // A11y: at least one element with aria-label mentioning démonstration
  const ariaCount = await page.locator('[aria-label*="démonstration" i]').count();
  expect(ariaCount, 'aria-label démonstration present').toBeGreaterThan(0);

  await page.screenshot({ path: path.join(SCREEN_DIR, `${testInfo.project.name}-LOY-head.png`), fullPage: false });
  assertCleanConsole(page, 'WE2-LOY.head');
});

// ============================================================================
// WE2-LOY.qr — DÉMO V1 badge visible adjacent to QR code area
// ============================================================================
test('WE2-LOY.qr — DÉMO V1 badge present in wallet QR area', async ({ page }, testInfo) => {
  await bootWeb(page);
  await doSignupOtpFlow(page, { closeAfter: true });
  await page.waitForTimeout(500);

  // QR area sits in .lc-wallet-code (parent of WebQR + ID + share btn)
  const qrArea = page.locator('.lc-wallet-code').first();
  await expect(qrArea, 'QR area visible').toBeVisible();
  const qrAreaText = await qrArea.innerText();
  expect(qrAreaText, 'QR area contains DÉMO V1 badge').toMatch(/DÉMO\s*V1/i);

  await page.screenshot({ path: path.join(SCREEN_DIR, `${testInfo.project.name}-LOY-qr.png`), fullPage: false });
  assertCleanConsole(page, 'WE2-LOY.qr');
});

// ============================================================================
// WE2-MOD.success — DÉMO V1 badge in signup success modal (+25 points)
// ============================================================================
test('WE2-MOD.success — DÉMO V1 badge visible in +25 success modal', async ({ page }, testInfo) => {
  await bootWeb(page);
  // Do NOT close — capture the success modal BEFORE Commencer à commander
  await doSignupOtpFlow(page, { closeAfter: false });

  // Success modal still visible — assert badge present
  const modal = page.locator('.lc-modal').first();
  await expect(modal, 'success modal still open').toBeVisible();
  const modalText = await modal.innerText();
  expect(modalText, 'success modal contains DÉMO V1').toMatch(/DÉMO\s*V1/i);
  expect(modalText, 'success modal still shows +25 points').toMatch(/\+25/);

  await page.screenshot({ path: path.join(SCREEN_DIR, `${testInfo.project.name}-MOD-success.png`), fullPage: false });
  assertCleanConsole(page, 'WE2-MOD.success');
});
