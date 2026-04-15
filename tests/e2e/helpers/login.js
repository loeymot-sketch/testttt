/**
 * Login FoodKing — page /login a 3 boutons type="submit" (recherche header, Login, Subscribe).
 * Ne jamais utiliser button[type="submit"] (ambigu, strict mode Playwright).
 */
async function login(page, email, password) {
  await page.goto('/login');
  await page.locator('input[type="text"]').first().fill(email);
  await page.locator('input[type="password"]').fill(password);
  await page.getByRole('button', { name: /^login$/i }).click();
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
