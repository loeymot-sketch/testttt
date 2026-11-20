const { test, expect } = require('@playwright/test');

test('account deletion is explicit, authenticated, server-backed and purges local identity', async ({ page }) => {
  let deleteRequest = null;

  await page.addInitScript(() => {
    localStorage.setItem('lecayenne.onboarding_seen', 'true');
    localStorage.setItem('lecayenne.auth', JSON.stringify({ token: 'e2e-account-token', phone: '0600000000', user_id: 999 }));
  });
  await page.route('**/api/profile', async route => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { id: 999, name: 'Client E2E', phone: '0600000000', loyalty_points: 0 } }),
    });
  });
  await page.route('**/api/delete-account', async route => {
    deleteRequest = route.request();
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ status: true, message: 'Compte supprimé.' }),
    });
  });

  await page.goto('/index.html?dev&account-deletion=1');
  await page.getByRole('tab', { name: 'Profil' }).click();
  await expect(page.getByTestId('profile-name')).toHaveText('Client E2E');
  await page.getByTestId('delete-account-btn').click();
  await expect(page.getByTestId('delete-account-confirm-btn')).toBeVisible();
  await page.getByTestId('delete-account-confirm-btn').click();

  await expect.poll(() => deleteRequest && deleteRequest.headers()['authorization']).toBe('Bearer e2e-account-token');
  await expect(page.getByRole('heading', { name: /Ton numéro/ })).toBeVisible();
  await expect(page.getByTestId('delete-account-btn')).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => localStorage.getItem('lecayenne.auth'))).toBeNull();
});
