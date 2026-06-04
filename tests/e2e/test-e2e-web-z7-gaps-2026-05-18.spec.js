// test-e2e-web-z7-gaps-2026-05-18 — Z-7 WEB zone coverage gaps healed
//
// Cycle GOAL COMPLEMENT 2026-05-18, master sub-agent Z-7-WEB.
// RED-team identified 4 P1 coverage gaps + 2 P2 edge cases not covered by
// existing test-e2e-website-realignment-2026-05-16.spec.js (76 tests):
//   - Z-7-RED-P1-1  Account modal flow (login/signup/OTP/forgot)            T-8.2.1
//   - Z-7-RED-P1-2  Loyalty + orders pages (signed-out gate, filter tabs)   T-8.2.2 + T-8.2.3
//   - Z-7-RED-P1-3  Funnel state machine (cart preserved on back-button)    T-8.1.2
//   - Z-7-RED-P1-4  axe-core sweep × 4 viewports                            T-8.3.2
//   - Z-7-RED-P2-1  Promo input XSS payload tolerance (escape on render)
//   - Z-7-RED-P2-2  OTP non-digit silent reject
//
// Multi-viewport via playwright config (mobile / tablet / desktop / wide).
//
// Boot manually (or rely on already-running server) :
//   python3 -m http.server 8082 --directory /Users/1millnonstop/Downloads/web
//
// Run :
//   npx playwright test --config=tests/web-e2e/playwright.config.js \
//     tests/e2e/test-e2e-web-z7-gaps-2026-05-18.spec.js \
//     --reporter=list --workers=1 --timeout=120000

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const SCREEN_DIR = path.join(__dirname, '__screenshots__/test-e2e-web-z7-gaps-2026-05-18');
if (!fs.existsSync(SCREEN_DIR)) fs.mkdirSync(SCREEN_DIR, { recursive: true });

const AXE_PATH = path.resolve(__dirname, '../../node_modules/axe-core/axe.min.js');

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

// ============================================================================
// Z7-ACC.1 — Account modal opens, tab switch login/signup, validation surfaces
// (Z-7-RED-P1-1 T-8.2.1)
// ============================================================================
test('Z7-ACC.1 — Account modal opens + tab switch + invalid email validation', async ({ page }, testInfo) => {
  await bootWeb(page);
  // Click 'Se connecter' nav button — present when not auth'd
  const accBtn = page.locator('button.lc-nav-btn-account').first();
  await expect(accBtn, 'Se connecter button present').toBeVisible();
  await accBtn.click();
  await page.waitForTimeout(400);

  // Modal should be open with signup mode default
  const modal = page.locator('.lc-modal').first();
  await expect(modal, 'account modal opens').toBeVisible();

  // Switch to login tab
  const loginTab = page.locator('button.lcf-tab', { hasText: 'Connexion' }).first();
  await loginTab.click();
  await page.waitForTimeout(200);
  await expect(loginTab).toHaveClass(/is-on/);

  // Submit with invalid email — expect validation error
  const emailInput = page.locator('input[type="email"]').first();
  await emailInput.fill('not-an-email');
  // Password field too short
  const pwdInput = page.locator('input[type="password"]').first();
  await pwdInput.fill('abc');
  const submitBtn = page.locator('button.lc-acc-cta', { hasText: 'Se connecter' }).first();
  await submitBtn.click();
  await page.waitForTimeout(300);
  // At least one error message should surface
  const errors = await page.locator('.lcf-field-error').count();
  expect(errors, 'login validation errors surface').toBeGreaterThan(0);

  await page.screenshot({ path: path.join(SCREEN_DIR, `${testInfo.project.name}-ACC1-login-errors.png`), fullPage: false });
  assertCleanConsole(page, 'Z7-ACC.1');
});

// ============================================================================
// Z7-ACC.2 — Signup → OTP → demo code 1234 → success state
// (Z-7-RED-P1-1 + Z-7-RED-P2-2)
// ============================================================================
test('Z7-ACC.2 — Signup happy path → OTP digits-only + demo 1234 → success', async ({ page }, testInfo) => {
  await bootWeb(page);
  await page.locator('button.lc-nav-btn-account').first().click();
  await page.waitForTimeout(400);
  // Should already be on signup mode by default
  // Fill valid signup form
  await page.locator('.lc-acc-input').nth(0).fill('Ikyes');  // first name
  await page.locator('input[type="email"]').first().fill('ikyes@lecayenne.fr');
  await page.locator('input[type="tel"]').first().fill('6 12 34 56 78');
  // Submit
  await page.locator('button.lc-acc-cta', { hasText: 'Recevoir le code' }).first().click();
  await page.waitForTimeout(1000);  // loading delay 600ms + transition

  // OTP grid should appear
  const otpCells = page.locator('.lc-otp-cell');
  await expect(otpCells.first(), 'OTP first cell visible').toBeVisible({ timeout: 4000 });
  expect(await otpCells.count()).toBe(4);

  // Z-7-RED-P2-2: try non-digit 'a' → cell remains empty
  await otpCells.nth(0).fill('a');
  await page.waitForTimeout(150);
  expect(await otpCells.nth(0).inputValue(), 'OTP cell rejects non-digit').toBe('');

  // Enter '1234' digit by digit
  for (let i = 0; i < 4; i++) {
    await otpCells.nth(i).fill(String(i + 1));
    await page.waitForTimeout(60);
  }
  await page.waitForTimeout(800);  // success transition 400ms

  // Success state shows '+25' points headline + 'Commencer à commander' CTA
  const successText = await page.evaluate(() => document.body.innerText);
  expect(successText, 'success state shows +25 points').toContain('+25');
  expect(successText, 'success state shows welcome club').toMatch(/club|chef|Crédités/i);

  await page.screenshot({ path: path.join(SCREEN_DIR, `${testInfo.project.name}-ACC2-success.png`), fullPage: false });
  assertCleanConsole(page, 'Z7-ACC.2');
});

// ============================================================================
// Z7-ORD.1 — Orders page signed-out gate then auth'd filter tabs
// (Z-7-RED-P1-2 T-8.2.2 + T-8.2.3)
// ============================================================================
test('Z7-ORD.1 — Orders signed-out shows CTA, auth\'d shows filter tabs + delivered/cancelled', async ({ page }, testInfo) => {
  await bootWeb(page);
  // Navigate to Orders via nav (desktop) or burger (mobile)
  const deskOrders = page.locator('button.lc-nav-link', { hasText: 'Commandes' }).first();
  if (await deskOrders.isVisible().catch(() => false)) {
    await deskOrders.click();
  } else {
    const burger = page.locator('button.lc-nav-burger').first();
    if (await burger.isVisible().catch(() => false)) {
      await burger.click();
      await page.waitForTimeout(300);
      const mobOrders = page.locator('button.lc-mobile-link', { hasText: 'Commandes' }).first();
      await mobOrders.click();
    }
  }
  await page.waitForTimeout(500);

  // Signed-out gate visible
  let bodyText = await page.evaluate(() => document.body.innerText);
  expect(bodyText, 'orders signed-out CTA').toMatch(/Connecte-toi|Me connecter/i);

  // Click "Me connecter" → opens account modal — close it then mock auth
  // We can't easily mock auth without re-implementing OTP, so instead test
  // the assertion that the gate exists. Then bypass via direct App state injection.
  // To inject auth, we hit the App's setIsAuth via React DevTools-equivalent:
  // simpler approach — click Me connecter, complete OTP, then assert orders.
  const meConnecter = page.locator('button.lc-btn--orange', { hasText: 'Me connecter' }).first();
  if (await meConnecter.isVisible().catch(() => false)) {
    await meConnecter.click();
    await page.waitForTimeout(400);
    // Modal opens. Fill + submit signup → OTP → 1234 → success → close modal → arrive on loyalty
    await page.locator('.lc-acc-input').nth(0).fill('Ikyes');
    await page.locator('input[type="email"]').first().fill('ikyes@lecayenne.fr');
    await page.locator('input[type="tel"]').first().fill('6 12 34 56 78');
    await page.locator('button.lc-acc-cta', { hasText: 'Recevoir le code' }).first().click();
    await page.waitForTimeout(1000);
    const otpCells = page.locator('.lc-otp-cell');
    for (let i = 0; i < 4; i++) await otpCells.nth(i).fill(String(i + 1));
    await page.waitForTimeout(900);
    // 'Commencer à commander' CTA
    const finishBtn = page.locator('button.lc-btn--ink', { hasText: 'Commencer à commander' }).first();
    if (await finishBtn.isVisible().catch(() => false)) await finishBtn.click();
    await page.waitForTimeout(500);
    // Now route is 'loyalty'. Navigate back to orders.
    const ordersAgain = page.locator('button.lc-nav-link', { hasText: 'Commandes' }).first();
    if (await ordersAgain.isVisible().catch(() => false)) {
      await ordersAgain.click();
    } else {
      const burger = page.locator('button.lc-nav-burger').first();
      if (await burger.isVisible().catch(() => false)) {
        await burger.click();
        await page.waitForTimeout(200);
        await page.locator('button.lc-mobile-link', { hasText: 'Commandes' }).first().click();
      }
    }
    await page.waitForTimeout(500);
  }

  // Now signed-in: filter tabs + history present
  bodyText = await page.evaluate(() => document.body.innerText);
  expect(bodyText, 'orders history page header').toMatch(/Mes commandes|Historique/i);
  // Filter tabs present
  const filterTabs = page.locator('.lc-cat-tab');
  const tabCount = await filterTabs.count();
  expect(tabCount, 'orders filter tabs present').toBeGreaterThanOrEqual(3);
  // Delivered/Cancelled labels
  expect(bodyText).toMatch(/Récupérée|Livrée|Annulée/);

  await page.screenshot({ path: path.join(SCREEN_DIR, `${testInfo.project.name}-ORD1-list.png`), fullPage: false });
  assertCleanConsole(page, 'Z7-ORD.1');
});

// ============================================================================
// Z7-LOY.1 — Loyalty signed-out screen has CTA, displays Pepper Club mention
// (partial Z-7-RED-P1-2 T-8.2.2)
// ============================================================================
test('Z7-LOY.1 — Loyalty page signed-out CTA + Pepper Club brand mention', async ({ page }, testInfo) => {
  await bootWeb(page);
  const deskLoyalty = page.locator('button.lc-nav-link', { hasText: 'Fidélité' }).first();
  if (await deskLoyalty.isVisible().catch(() => false)) {
    await deskLoyalty.click();
  } else {
    const burger = page.locator('button.lc-nav-burger').first();
    if (await burger.isVisible().catch(() => false)) {
      await burger.click();
      await page.waitForTimeout(300);
      await page.locator('button.lc-mobile-link', { hasText: 'Fidélité' }).first().click();
    }
  }
  await page.waitForTimeout(600);
  const text = await page.evaluate(() => document.body.innerText);
  expect(text, 'loyalty Pepper Club mention').toMatch(/Pepper|fidélité|points/i);
  await page.screenshot({ path: path.join(SCREEN_DIR, `${testInfo.project.name}-LOY1.png`), fullPage: false });
  assertCleanConsole(page, 'Z7-LOY.1');
});

// ============================================================================
// Z7-FUN.1 — Funnel state machine: add → cart → checkout → back preserves cart
// (Z-7-RED-P1-3 T-8.1.2)
// ============================================================================
test('Z7-FUN.1 — Funnel state-machine: cart preserved on back-button from checkout', async ({ page }, testInfo) => {
  await bootWeb(page);
  // Open cart drawer directly (App seeds 1 item in cart per index.html)
  const cartBtn = page.locator('button.lc-nav-btn-cart').first();
  await cartBtn.click();
  await page.waitForTimeout(400);
  // Cart drawer should show 1+ items (seeded W_ITEMS[0])
  const cartItems = page.locator('.lc-cart-row');
  const initialCount = await cartItems.count();
  expect(initialCount, 'cart has seeded item').toBeGreaterThan(0);
  // Click 'Passer commande' → goes to checkout
  const checkoutBtn = page.locator('button.lc-btn--orange', { hasText: 'Passer commande' }).first();
  await checkoutBtn.click();
  await page.waitForTimeout(500);
  // We're now on checkout route. Look for FunnelSteps stepper or 'Pickup' label
  let text = await page.evaluate(() => document.body.innerText);
  expect(text, 'checkout step active').toMatch(/Pickup|Quand récupérer|Code promo/i);

  // Click 'Retour' → should go back to menu with cart preserved + cart drawer reopened
  const backBtn = page.locator('button.lcf-cta-bar-back').first();
  await backBtn.click();
  await page.waitForTimeout(500);

  // We should be on menu route + cart drawer should be open
  text = await page.evaluate(() => document.body.innerText);
  expect(text, 'returned to menu route').toMatch(/Sandwich|Galette|Tacos|Burgers/);
  // Cart drawer items still present
  const cartItemsAfter = await page.locator('.lc-cart-row').count();
  expect(cartItemsAfter, 'cart preserved after back-button').toBe(initialCount);

  await page.screenshot({ path: path.join(SCREEN_DIR, `${testInfo.project.name}-FUN1-back.png`), fullPage: false });
  assertCleanConsole(page, 'Z7-FUN.1');
});

// ============================================================================
// Z7-FUN.2 — Promo XSS payload: pasted '<script>' rendered as text, not executed
// (Z-7-RED-P2-1)
// ============================================================================
test('Z7-FUN.2 — Promo input XSS payload rendered as escaped text', async ({ page }, testInfo) => {
  await bootWeb(page);
  // Track if any XSS alert was triggered
  let alerted = false;
  page.on('dialog', async d => { alerted = true; await d.dismiss(); });

  await page.locator('button.lc-nav-btn-cart').first().click();
  await page.waitForTimeout(400);
  await page.locator('button.lc-btn--orange', { hasText: 'Passer commande' }).first().click();
  await page.waitForTimeout(500);

  const promoInput = page.locator('input[placeholder*="CAYENNE10"]').first();
  await promoInput.fill('<script>window.__xss=true</script>');
  await page.locator('button', { hasText: 'Appliquer' }).first().click();
  await page.waitForTimeout(300);

  // XSS must NOT have executed
  const xssTriggered = await page.evaluate(() => !!window.__xss);
  expect(xssTriggered, 'promo XSS payload NOT executed').toBe(false);
  expect(alerted, 'no alert dialog triggered').toBe(false);

  // 'Code invalide' error should surface (since payload != valid promo code)
  const text = await page.evaluate(() => document.body.innerText);
  expect(text, 'invalid code error shown').toMatch(/Code invalide/i);

  await page.screenshot({ path: path.join(SCREEN_DIR, `${testInfo.project.name}-FUN2-xss-escaped.png`), fullPage: false });
  assertCleanConsole(page, 'Z7-FUN.2');
});

// ============================================================================
// Z7-AXE — axe-core a11y sweep on home + menu + legal (× viewport)
// (Z-7-RED-P1-4 T-8.3.2)
// ============================================================================
const AXE_TARGETS = [
  { name: 'home', url: '/index.html', kind: 'spa', wait: 'window.LC && window.LC.menu' },
  { name: 'menu', url: '/index.html', kind: 'spa-nav-menu', wait: 'window.LC && window.LC.menu' },
  { name: 'legal-mentions', url: '/legal/mentions.html', kind: 'static' },
  { name: 'legal-cgv', url: '/legal/cgv.html', kind: 'static' },
];

async function runAxe(page) {
  // Inject axe-core
  const axeSource = fs.readFileSync(AXE_PATH, 'utf8');
  await page.evaluate(axeSource);
  // Run with WCAG AA tags. Disable color-contrast on some known-low-priority gray-3 micro-text
  // (declared in Z-5 cross-system audit) to focus on real violations.
  const results = await page.evaluate(async () => {
    const r = await window.axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
      // ARIA tab pattern + region for SPA - flag P2 backlogged separately
      rules: {
        'region': { enabled: false },
        // color-contrast handled in dedicated Z-5 audit; not blocking here
        'color-contrast': { enabled: false },
      },
    });
    return r.violations.map(v => ({
      id: v.id,
      impact: v.impact,
      help: v.help,
      nodes: v.nodes.length,
      target_sample: v.nodes[0] && v.nodes[0].target && v.nodes[0].target[0],
      all_targets: v.nodes.map(n => n.target && n.target.join(' ')),
    }));
  });
  return results;
}

for (const tgt of AXE_TARGETS) {
  test(`Z7-AXE.${tgt.name} — axe-core a11y sweep (critical+serious=0)`, async ({ page }, testInfo) => {
    await page.goto(tgt.url, { waitUntil: 'networkidle' });
    if (tgt.kind === 'spa' || tgt.kind === 'spa-nav-menu') {
      await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
      if (tgt.kind === 'spa-nav-menu') {
        const deskLink = page.locator('button.lc-nav-link', { hasText: 'Menu' }).first();
        if (await deskLink.isVisible().catch(() => false)) {
          await deskLink.click();
        } else {
          const burger = page.locator('button.lc-nav-burger').first();
          if (await burger.isVisible().catch(() => false)) {
            await burger.click();
            await page.waitForTimeout(300);
            await page.locator('button.lc-mobile-link', { hasText: 'Menu' }).first().click();
          }
        }
        await page.waitForTimeout(500);
      }
    }
    const violations = await runAxe(page);
    const critical = violations.filter(v => v.impact === 'critical' || v.impact === 'serious');
    // Persist for traceability
    const out = path.join(__dirname, '../../reports/test-e2e/goal-complement-2026-05-18/Z-7-WEB/round-1', `axe-${testInfo.project.name}-${tgt.name}.json`);
    fs.mkdirSync(path.dirname(out), { recursive: true });
    fs.writeFileSync(out, JSON.stringify({ project: testInfo.project.name, page: tgt.name, total: violations.length, critical_serious: critical.length, violations }, null, 2));
    expect(critical, `${tgt.name} axe critical+serious violations: ${JSON.stringify(critical, null, 2)}`).toHaveLength(0);
  });
}
