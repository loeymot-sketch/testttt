// FoodKing E2E — Flow 6 : Staff-only routing (STAFF_ONLY_MODE=true)
// Vérifie la restructuration V1 : / redirige vers /login, /home /menu /offers bloqués,
// la borne /kiosk reste publique et autonome.
//
// Pré-requis : STAFF_ONLY_MODE=true + KIOSK_REQUIRE_MACHINE_LOGIN=false dans .env

const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/login');

test.describe('Staff-only routing — Restructuration V1', () => {
    test('Root / redirige vers /login (anonyme)', async ({ page }) => {
        await page.goto('/');
        await page.waitForURL(/\/login$/, { timeout: 10_000 });
        await expect(page).toHaveURL(/\/login$/);
    });

    test('/home redirige vers /login (anonyme, vitrine bloquée)', async ({ page }) => {
        await page.goto('/home');
        // Guard router pousse vers auth.login
        await page.waitForURL(/\/login$/, { timeout: 10_000 });
        await expect(page).toHaveURL(/\/login$/);
    });

    test('/menu redirige vers /login (anonyme, vitrine bloquée)', async ({ page }) => {
        await page.goto('/menu');
        await page.waitForURL(/\/login$/, { timeout: 10_000 });
        await expect(page).toHaveURL(/\/login$/);
    });

    test('/offers redirige vers /login (anonyme, vitrine bloquée)', async ({ page }) => {
        await page.goto('/offers');
        await page.waitForURL(/\/login$/, { timeout: 10_000 });
        await expect(page).toHaveURL(/\/login$/);
    });

    test('Page /login — Signup masqué en staff-only', async ({ page }) => {
        await page.goto('/login');
        // Le lien vers auth.signupPhone doit être absent (v-if="!staffOnlyMode")
        const signupLink = page.locator('a:has-text("Sign Up"), a:has-text("S\'inscrire")');
        await expect(signupLink).toHaveCount(0);
    });

    test('Kiosk /kiosk reste accessible (public autonome)', async ({ page }) => {
        await page.goto('/kiosk');
        // Doit NE PAS rediriger vers /login staff (exempt de staff-only).
        // Note : /kiosk/login est OK (auth machine locale, pas la vitrine client).
        await page.waitForURL(/\/kiosk/, { timeout: 10_000 });
        await expect(page).toHaveURL(/\/kiosk/);
        // Staff login = /login (sans préfixe). Kiosk login machine = /kiosk/login (OK).
        await expect(page).not.toHaveURL(/\/login$/);
    });

    test('Flag staffOnlyMode exposé dans window.foodkingConfig', async ({ page }) => {
        await page.goto('/login');
        const flag = await page.evaluate(() => {
            return window.foodkingConfig && window.foodkingConfig.staffOnlyMode;
        });
        expect(flag).toBe(true);
    });

    test('Flag kioskUsePosWizard exposé dans window.foodkingConfig', async ({ page }) => {
        await page.goto('/login');
        const flag = await page.evaluate(() => {
            return window.foodkingConfig && window.foodkingConfig.kioskUsePosWizard;
        });
        expect(flag).toBe(true);
    });

    test('Login admin → redirige vers admin.dashboard', async ({ page }) => {
        await login(page, 'admin@lecayenne.fr', '123456');
        // Le staff admin a defaultPermission.url ("dashboard") → /admin/dashboard
        await page.waitForURL(/\/admin\/(dashboard|pos|kitchen-display-system|kds-order|order-status-screen)/, { timeout: 15_000 });
        const url = page.url();
        expect(url).toMatch(/\/admin\//);
        expect(url).not.toMatch(/\/home$/);
    });
});
