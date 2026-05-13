// Ultra Goal Phase 0 baseline visual captures
// Lances captures rapides des surfaces critiques pour diff post-heal
// Date : 2026-05-13

const { test, expect } = require('@playwright/test');
const path = require('path');

const OUT_DIR = path.join(__dirname, '..', '..', 'reports', 'audit', 'ultra-goal-2026-05-13', 'baseline-visuals');

test.describe.configure({ mode: 'serial' });

test.use({ viewport: { width: 1440, height: 900 } });

async function snap(page, name) {
  await page.screenshot({ path: path.join(OUT_DIR, `${name}.png`), fullPage: true });
}

test('baseline-kiosk-idle', async ({ page }) => {
  await page.goto('/kiosk/idle', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(800);
  await snap(page, '01-kiosk-idle');
});

test('baseline-kiosk-order-setup', async ({ page }) => {
  await page.goto('/kiosk/order-setup', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(500);
  await snap(page, '02-kiosk-order-setup');
});

test('baseline-kiosk-categories', async ({ page }) => {
  await page.goto('/kiosk/categories', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(1500);
  await snap(page, '03-kiosk-categories');
});

test('baseline-login', async ({ page }) => {
  await page.goto('/login', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(500);
  await snap(page, '04-login');
});

test('baseline-admin-pos-v4', async ({ page }) => {
  // Login then navigate
  await page.goto('/login', { waitUntil: 'networkidle', timeout: 30000 });
  await page.fill('input[name="email"]', 'admin@lecayenne.fr').catch(() => {});
  await page.fill('input[name="password"]', '123456').catch(() => {});
  await page.click('button[type="submit"]').catch(() => {});
  await page.waitForTimeout(2000);
  await page.goto('/admin/pos-v4', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(1500);
  await snap(page, '05-admin-pos-v4');
});

test('baseline-admin-items', async ({ page }) => {
  await page.goto('/login', { waitUntil: 'networkidle' });
  await page.fill('input[name="email"]', 'admin@lecayenne.fr').catch(() => {});
  await page.fill('input[name="password"]', '123456').catch(() => {});
  await page.click('button[type="submit"]').catch(() => {});
  await page.waitForTimeout(1500);
  await page.goto('/admin/items', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(1500);
  await snap(page, '06-admin-items');
});

test('baseline-admin-categories', async ({ page }) => {
  await page.goto('/login', { waitUntil: 'networkidle' });
  await page.fill('input[name="email"]', 'admin@lecayenne.fr').catch(() => {});
  await page.fill('input[name="password"]', '123456').catch(() => {});
  await page.click('button[type="submit"]').catch(() => {});
  await page.waitForTimeout(1500);
  await page.goto('/admin/item-category', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(1500);
  await snap(page, '07-admin-item-category');
});

test('baseline-kds', async ({ page }) => {
  await page.goto('/login', { waitUntil: 'networkidle' });
  await page.fill('input[name="email"]', 'admin@lecayenne.fr').catch(() => {});
  await page.fill('input[name="password"]', '123456').catch(() => {});
  await page.click('button[type="submit"]').catch(() => {});
  await page.waitForTimeout(1500);
  await page.goto('/admin/kitchen-display-system', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(1500);
  await snap(page, '08-kds');
});

test('baseline-oss', async ({ page }) => {
  await page.goto('/order-status-screen', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(1500);
  await snap(page, '09-oss');
});
