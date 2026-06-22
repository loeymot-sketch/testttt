const { expect } = require('@playwright/test');
const path = require('path');
const { execFileSync } = require('child_process');
const { clearFoodKingRateLimits } = require('./rate-limit');

/**
 * [iter15-mega-fix B-004 round-7 2026-05-10]
 * Sweep orphan AUDIT-/RED-TEAM-/ZZ-TEST-/TEST-/E2E- orders off the live KDS
 * pile before the mega specs run. Best-effort: logs failure but never throws,
 * so a missing tinker shell does not block the test run itself.
 *
 * [test-e2e fix A-015 round-4 2026-05-11] Optional `prefixes` arg lets a
 * caller restrict the sweep to its own wave-scoped token prefix(es). Default
 * (no arg / empty array) preserves the original "sweep all known fixture
 * prefixes" behaviour for back-compat.
 *
 * Round-3 root cause: Wave B's beforeAll cleanup unscoped-swept Wave A's
 * just-created AUDIT-RUSH-A-% rows mid-burst (parallel waves share the
 * AUDIT-% pattern). Pass `['AUDIT-RUSH-A-']` from Wave A and
 * `['AUDIT-RUSH-B-', 'AUDIT-KIOSK-WAVE-E-']` from Wave B to scope each
 * wave's sweep to its own rows.
 *
 * @param {string[]} [prefixes=[]] Token prefixes (without the trailing %).
 *   When empty, the artisan command falls back to its DEFAULT_TOKEN_PATTERNS.
 */
function cleanupOrphanTestOrders(prefixes = []) {
  try {
    const args = ['artisan', 'iter15:cleanup-test-orders', '--apply'];
    for (const p of (Array.isArray(prefixes) ? prefixes : [])) {
      if (typeof p === 'string' && p.length > 0) {
        args.push(`--token-prefix=${p}`);
      }
    }
    execFileSync('php', args, {
      cwd: path.resolve(__dirname, '../../..'),
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 15_000,
    });
  } catch (err) {
    // soft-fail — the spec assertions catch a polluted DB anyway
    console.warn('[iter15] cleanupOrphanTestOrders warning:', err?.message || err);
  }
}

/**
 * Login FoodKing — cible les ids du LoginComponent (évite le champ recherche header type="text").
 * Locale par défaut fr : libellé bouton « Connexion », pas « Login ».
 * Navigation post-login = Vue Router (SPA) : pas d’événement load fiable après le clic.
 */
async function login(page, email, password) {
  await page.goto('/login');
  await expect(page.locator('#formEmail')).toBeVisible({ timeout: 20_000 });
  await page.locator('#formEmail').fill(email);
  await page.locator('#formPassword').fill(password);
  const submit = page.getByRole('button', { name: /^(login|connexion)$/i });

  const clickAndAwaitApi = async () => {
    const loginResponse = page.waitForResponse(
      (res) =>
        res.request().method() === 'POST' &&
        /\/api\/auth\/login/i.test(res.url()),
      { timeout: 25_000 },
    );
    await submit.click();
    return loginResponse;
  };

  let resp = await clickAndAwaitApi();
  if (resp.status() !== 201) {
    await page.waitForTimeout(750);
    if (/\/login(?:$|\?)/.test(page.url()) && (await submit.isVisible().catch(() => false))) {
      resp = await clickAndAwaitApi();
    }
  }
  if (resp.status() !== 201) {
    const body = await resp.text().catch(() => '');
    throw new Error(`Login API failed: HTTP ${resp.status()} ${body.slice(0, 400)}`);
  }

  // Token + router.push run after axios resolves — do not navigate away before that.
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25_000 });
}

/**
 * Caissier : l’auth réussit souvent sur `/admin/dashboard` (landing role) — forcer la surface POS pour les E2E.
 */
async function loginAsPosOperator(
  page,
  email = process.env.E2E_POS_USER || 'pos@lecayenne.fr',
  password = process.env.E2E_POS_PASS || '123456',
) {
  clearFoodKingRateLimits();
  await login(page, email, password);
  // LoginComponent applique recursiveRouter(routes, permission) avec setTimeout(1000)
  // après router.push — un goto immédiat vers /admin/pos peut charger la SPA avant meta.access OK.
  await page.waitForTimeout(1200);
  if (!/\/admin\/pos(\/|$|\?)/.test(page.url())) {
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  }
  await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 25_000 });
}

const KDS_PATH_RE = /\/(kds|admin\/kitchen-display-system)(\/?|$|\?)/i;

/**
 * Chef / KDS : après auth la SPA peut atterrir sur le dashboard — ouvrir la surface KDS attendue par les E2E.
 */
async function loginAsChefOperator(
  page,
  email = process.env.E2E_CHEF_USER || 'chef@lecayenne.fr',
  password = process.env.E2E_CHEF_PASS || '123456',
) {
  clearFoodKingRateLimits();
  await login(page, email, password);
  await page.waitForTimeout(1200);
  if (!KDS_PATH_RE.test(page.url())) {
    await page.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });
  }
  await expect(page).toHaveURL(KDS_PATH_RE, { timeout: 25_000 });
}

async function loginAsKiosk(page, username = 'kiosk-lecayenne', password = 'kiosk123') {
  // [iter15-mega-fix D-001 2026-05-10] Wipe kiosk-login bucket before each test.
  // The kiosk surface auto-retries on mount (KioskLoginComponent.startAutoLogin)
  // and prior Wave-C aborts leave the limiter primed → Wave-D inherits 429.
  clearFoodKingRateLimits();
  await page.goto('/kiosk/login');
  // Kiosk may auto-login via server config or require manual login
  // Check if already on kiosk surface
  const url = page.url();
  if (/\/kiosk(?!\/login)/.test(url)) {
    return; // Already logged in
  }
  // Try to fill login form if it exists
  const usernameInput = page.locator('input[name="username"], input[type="text"]').first();
  if (await usernameInput.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await usernameInput.fill(username);
    const passwordInput = page.locator('input[type="password"]');
    if (await passwordInput.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await passwordInput.fill(password);
    }
    const submitBtn = page.getByRole('button', { name: /login|connexion|enter/i });
    if (await submitBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await submitBtn.click();
      await page.waitForURL((u) => !u.pathname.includes('/kiosk/login'), { timeout: 25_000 }).catch(() => {});
    }
  }
}

/**
 * Admin login — used by the iter15 mega-audit waves (A admin visual + D rupture cascade).
 * Default landing /admin/dashboard; specs may navigate further once auth completes.
 */
async function loginAsAdmin(
  page,
  email = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr',
  password = process.env.E2E_ADMIN_PASS || '123456',
) {
  clearFoodKingRateLimits();
  await login(page, email, password);
  await page.waitForTimeout(1200);
  if (!/\/admin(\/|$|\?)/.test(page.url())) {
    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
  }
  await expect(page).toHaveURL(/\/admin/, { timeout: 25_000 });
}

module.exports = { login, loginAsKiosk, loginAsPosOperator, loginAsChefOperator, loginAsAdmin, cleanupOrphanTestOrders };
