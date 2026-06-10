// test-e2e massive — Le Cayenne mobile · real browser E2E (Playwright + system Chrome)
// Capture quartet-lite: screenshot per screen + console-error sink + cross-surface numeric asserts.
// Deterministic seams: localStorage (lecayenne.*) for auth/cart, LC.dev.* for loyalty.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const SHOT = path.join(__dirname, '__screens__');
fs.mkdirSync(SHOT, { recursive: true });
const AUTH = { token: 'mock-v0-token', phone: '+33642799884', user_id: 12345 };
const errorsByTest = {};

function sinkFor(testInfo, page) {
  const sink = (errorsByTest[testInfo.title] = []);
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    const t = m.text();
    if (/favicon|unpkg\.com|integrity|net::ERR|Failed to load resource/i.test(t)) return; // static/vendor noise
    sink.push(t);
  });
  page.on('pageerror', (e) => sink.push('PAGEERROR: ' + (e && e.message)));
  return sink;
}

async function boot(page, opts = {}) {
  const { authed = true, cart = null } = opts;
  await page.addInitScript((o) => {
    try {
      if (o.authed) {
        localStorage.setItem('lecayenne.auth', JSON.stringify(o.AUTH));
        localStorage.setItem('lecayenne.onboarding_seen', JSON.stringify(true));
      } else {
        localStorage.removeItem('lecayenne.auth');
        localStorage.removeItem('lecayenne.onboarding_seen');
      }
      if (o.cart) localStorage.setItem('lecayenne.cart', JSON.stringify(o.cart));
    } catch (e) {}
  }, { authed, cart, AUTH });
  await page.goto('/');
  await page.waitForSelector('[data-screen-label]', { timeout: 30000 });
  await page.waitForTimeout(400); // settle in-browser Babel render
}
const shot = (page, name) => page.screenshot({ path: path.join(SHOT, name + '.png') });

test.describe('Le Cayenne mobile — massive E2E', () => {
  test('W0 smoke: authed boot lands on Home', async ({ page }, ti) => {
    const sink = sinkFor(ti, page);
    await boot(page);
    await expect.soft(page.locator('[data-screen-label="07 Home"]')).toBeVisible();
    await shot(page, '07-home');
    expect.soft(sink, 'console errors on Home').toEqual([]);
  });

  test('W0 fresh boot lands on Splash', async ({ page }, ti) => {
    const sink = sinkFor(ti, page);
    await boot(page, { authed: false });
    await expect.soft(page.locator('[data-screen-label="00 Splash"]')).toBeVisible();
    await shot(page, '00-splash');
    expect.soft(sink, 'console errors on Splash').toEqual([]);
  });

  test('W0 nav: Menu / Orders / Profile via TabBar', async ({ page }, ti) => {
    const sink = sinkFor(ti, page);
    await boot(page);
    await page.getByRole('tab', { name: 'Menu' }).click();
    await expect.soft(page.locator('[data-screen-label="08 Menu"]')).toBeVisible();
    await shot(page, '08-menu');
    await page.getByRole('tab', { name: 'Commandes' }).click();
    await expect.soft(page.locator('[data-screen-label="12 Orders"]')).toBeVisible();
    await shot(page, '12-orders');
    await page.getByRole('tab', { name: 'Profil' }).click();
    await expect.soft(page.locator('[data-screen-label="13 Profile"]')).toBeVisible();
    await shot(page, '13-profile');
    expect.soft(sink, 'console errors during nav').toEqual([]);
  });

  test('W0 item wizard opens from Menu', async ({ page }, ti) => {
    const sink = sinkFor(ti, page);
    await boot(page);
    await page.getByRole('tab', { name: 'Menu' }).click();
    await expect.soft(page.locator('[data-screen-label="08 Menu"]')).toBeVisible();
    await page.getByRole('button', { name: /^Voir / }).first().click();
    await expect.soft(page.locator('[data-screen-label^="09 Item"]')).toBeVisible();
    await shot(page, '09-item');
    expect.soft(sink, 'console errors in wizard').toEqual([]);
  });

  // A0 ship-blocker: what is shown == what is charged (T-1.1)
  test('A0 billing: promo -10% reaches the charge across cart/pay/confirm', async ({ page }, ti) => {
    const sink = sinkFor(ti, page);
    await boot(page, { cart: [{ id: 'seed-1', name: 'Tacos Test', price: 10, qty: 1, sups: [] }] });

    await page.getByRole('tab', { name: 'Menu' }).click();
    await expect.soft(page.locator('[data-screen-label="08 Menu"]')).toBeVisible();
    await page.getByRole('button', { name: /Voir le panier/ }).click();
    const cart = page.locator('[data-screen-label="10 Cart"]');
    await expect.soft(cart).toBeVisible();
    await expect.soft(cart).toContainText('10,00');
    await shot(page, '10-cart-prepromo');

    await page.getByTestId('cart-promo-input').fill('WELCOME10');
    await page.getByRole('button', { name: 'Appliquer le code promo' }).click();

    await expect.soft(page.getByTestId('cart-discount-amount')).toHaveText(/1,00\s*€/);
    await expect.soft(page.getByTestId('cart-subtotal-strike')).toHaveText(/10,00\s*€/);
    await expect.soft(cart, 'cart total after promo').toContainText('9,00');
    await shot(page, '10-cart-promo');

    await page.getByRole('button', { name: /Valider ma commande/ }).click();
    await expect.soft(page.getByText(/Total\s*9,00\s*€/)).toBeVisible();
    await shot(page, '10b-paymodal');

    await page.getByText('Payer à la caisse').click();
    const confirm = page.locator('[data-screen-label="11 Confirmation"]');
    await expect.soft(confirm).toBeVisible();
    await expect.soft(confirm, 'CHARGED total on confirmation').toContainText('9,00');
    await shot(page, '11-confirm');

    expect.soft(sink, 'console errors in billing flow').toEqual([]);
  });

  // Loyalty surface (T-2.2 progress copy)
  test('Loyalty: seeded balance renders on the loyalty screen', async ({ page }, ti) => {
    const sink = sinkFor(ti, page);
    await boot(page);
    await page.evaluate(() => window.LC.dev.seedAccount({ balance: 347 }));
    await page.getByRole('tab', { name: 'Profil' }).click();
    await expect.soft(page.locator('[data-screen-label="13 Profile"]')).toBeVisible();
    await page.getByRole('button', { name: /Carte fidélité/ }).click();
    const loy = page.locator('[data-screen-label="14 Loyalty"]');
    await expect.soft(loy).toBeVisible();
    await expect.soft(page.getByTestId('loyalty-balance')).toContainText('347');
    await shot(page, '14-loyalty');
    expect.soft(sink, 'console errors on loyalty').toEqual([]);
  });

  // ── Coverage: remaining screens (no pixel forgotten) ──────────────────────
  test('Coverage: onboarding -> Login -> OTP', async ({ page }, ti) => {
    const sink = sinkFor(ti, page);
    await boot(page, { authed: false });
    await expect.soft(page.locator('[data-screen-label="00 Splash"]')).toBeVisible();
    // splash auto-advances (~1.8s) to onb1; wait for "Passer" then skip to login (avoids stale-timer race)
    await page.getByRole('button', { name: 'Passer' }).first().click();
    await expect.soft(page.locator('[data-screen-label="05 Login"]')).toBeVisible();
    await shot(page, '05-login');
    // [a11y heal 2026-06-08] the login CTA is now disabled until a valid FR mobile
    // number is entered (was always-enabled) — fill the field first.
    await page.getByLabel(/Numéro de téléphone mobile/).fill('0612345678'); // valid = >=10 digits (screens-onboarding.jsx:201)
    await page.waitForTimeout(200);
    await page.getByRole('button', { name: /Recevoir le code/ }).click();
    await expect.soft(page.locator('[data-screen-label="06 OTP"]')).toBeVisible();
    await shot(page, '06-otp');
    expect.soft(sink, 'console errors onboarding/login/otp').toEqual([]);
  });

  test('Coverage: Stripe payment screen', async ({ page }, ti) => {
    const sink = sinkFor(ti, page);
    await boot(page, { cart: [{ id: 'seed-1', name: 'Tacos Test', price: 10, qty: 1, sups: [] }] });
    await page.getByRole('tab', { name: 'Menu' }).click();
    await page.getByRole('button', { name: /Voir le panier/ }).click();
    await page.getByRole('button', { name: /Valider ma commande/ }).click();
    await page.getByText('Payer maintenant').click();
    await expect.soft(page.locator('[data-screen-label="11b Stripe"]')).toBeVisible();
    await shot(page, '11b-stripe');
    expect.soft(sink, 'console errors stripe').toEqual([]);
  });

  test('Coverage: Order detail screen', async ({ page }, ti) => {
    const sink = sinkFor(ti, page);
    await boot(page);
    await page.getByRole('tab', { name: 'Commandes' }).click();
    await page.getByRole('button', { name: /Commande .* en cours/ }).click();
    await expect.soft(page.locator('[data-screen-label="12b Order detail"]')).toBeVisible();
    await shot(page, '12b-orderdetail');
    expect.soft(sink, 'console errors order detail').toEqual([]);
  });

  // ── T-4.1 order<->menu price parity (report-only; canonical value gated G3) ─
  test('T-4.1 parity probe (report; assertion pending G3)', async ({ page }, ti) => {
    sinkFor(ti, page);
    await boot(page);
    const parity = await page.evaluate(() => {
      try {
        const LC = window.LC; const out = [];
        const all = [].concat((LC.orders && LC.orders.active) || [], (LC.orders && LC.orders.history) || []);
        all.forEach((o) => (o.items || []).forEach((li) => {
          let m = null;
          try { m = LC.menu && LC.menu.findItem ? LC.menu.findItem(li.item_id) : null; } catch (e) {}
          const menuPrice = m ? m.price : null;
          const qty = li.qty || 1;
          const expected = menuPrice != null ? +(menuPrice * qty).toFixed(2) : null;
          out.push({ order: o.id, item_id: li.item_id, line_total: li.line_total, qty, menuPrice, expected,
            delta: (expected != null && li.line_total != null) ? +(li.line_total - expected).toFixed(2) : null });
        }));
        return out;
      } catch (e) { return [{ error: String(e) }]; }
    });
    fs.writeFileSync(path.join(SHOT, '_parity.json'), JSON.stringify(parity, null, 2));
    expect.soft(Array.isArray(parity), 'parity report produced').toBe(true);
  });

  // ── B dimension — LOCAL truth (persistence + integrity + idempotency) ──────
  // HONESTY: real backend / Pusher sync / NF525 fiscal chain are ABSENT in this
  // standalone prototype (GOAL §0.2) — attesting them would be fiction. We attest
  // what genuinely exists: localStorage persistence across reload + redeem idempotency.
  test('B-local: cart + loyalty balance persist across reload', async ({ page }, ti) => {
    const sink = sinkFor(ti, page);
    await boot(page); // authed, no seeded cart (so reload does not re-seed via init script)
    await page.evaluate(() => {
      window.LC.storage.setCart([{ id: 'p1', name: 'Persist', price: 7, qty: 2, sups: [] }]);
      window.LC.dev.seedAccount({ balance: 1234 });
    });
    await page.reload();
    await page.waitForSelector('[data-screen-label]', { timeout: 30000 });
    const persisted = await page.evaluate(() => ({
      cart: (window.LC.storage.getCart() || []).length,
      balance: window.LC.loyalty.account.balance,
    }));
    expect.soft(persisted.cart, 'cart persisted across reload').toBe(1);
    expect.soft(persisted.balance, 'loyalty balance persisted across reload').toBe(1234);
    expect.soft(sink, 'console errors on persistence').toEqual([]);
  });

  test('B-local: redeem idempotency — same key never double-debits', async ({ page }, ti) => {
    const sink = sinkFor(ti, page);
    await boot(page);
    const res = await page.evaluate(async () => {
      window.LC.dev.clearAll();
      window.LC.dev.seedAccount({ balance: 3000 });
      const before = window.LC.loyalty.account.balance;
      const r1 = await window.LC.dev.redeemReward(4, { idempotency_key: 'dup-key-1' }); // reward 4 = -500
      const r2 = await window.LC.dev.redeemReward(4, { idempotency_key: 'dup-key-1' }); // replay
      const after = window.LC.loyalty.account.balance;
      return { before, r1, r2, after };
    });
    expect.soft(res.before, 'seeded balance').toBe(3000);
    expect.soft(res.r1 && res.r1.ok, 'first redeem ok').toBe(true);
    expect.soft(res.r1 && res.r1.balance_after, 'first debit -500').toBe(2500);
    expect.soft(res.r2 && res.r2.replayed, 'second call is idempotent replay').toBe(true);
    expect.soft(res.r2 && res.r2.balance_after, 'replay returns same balance').toBe(2500);
    expect.soft(res.after, 'balance debited ONCE not twice').toBe(2500);
    expect.soft(sink, 'console errors on idempotency').toEqual([]);
  });

  test.afterAll(async () => {
    fs.writeFileSync(path.join(SHOT, '_console-errors.json'), JSON.stringify(errorsByTest, null, 2));
  });
});
