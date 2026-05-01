const { expect } = require('@playwright/test');

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
  await submit.click();

  // The SPA login submit can occasionally be swallowed while the page is still
  // hydrating in the local E2E harness. Retry once only if the form is still
  // visible on /login; invalid credentials still fail in the caller's URL assert.
  await page.waitForTimeout(750);
  if (/\/login(?:$|\?)/.test(page.url()) && await submit.isVisible().catch(() => false)) {
    await submit.click();
  }
}

async function loginAsKiosk(page, username = 'kiosk-lecayenne', password = 'kiosk123') {
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
    }
  }
}

module.exports = { login, loginAsKiosk };
