// Phase 3 — Le Cayenne mobile catalogue visual critical flow.
//
// Read-only browser coverage for the standalone mobile UI. The test deliberately
// does not place an order or call a write endpoint: it proves the visual path
// home → menu/category → product wizard → cart while preserving the backend
// pricing/order boundary.

const { test, expect } = require('@playwright/test');

const MOBILE_PATH = '/index.html?dev&catalog-visual-critical=1';

async function bootMobile(page) {
  await page.addInitScript(() => {
    localStorage.setItem('lecayenne.onboarding_seen', 'true');
    localStorage.setItem('lecayenne.auth', JSON.stringify({
      token: 'catalog-visual-read-only',
      phone: '0365678291',
      user_id: 'catalog-visual-read-only',
    }));
    localStorage.setItem('lecayenne.dev', 'true');
    localStorage.setItem('lecayenne.cart', JSON.stringify([]));
  });
  await page.goto(MOBILE_PATH, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => (
    window.LC && window.LC.menu && window.LC.menu.items && window.LC.menu.items.length > 0
  ), { timeout: 30_000 });
  await page.waitForSelector('[data-screen-label="07 Home"]', { state: 'visible', timeout: 30_000 });
}

async function assertImageSlots(page, scope) {
  const slots = scope.locator('[data-image-slot]');
  for (let index = 0; index < await slots.count(); index += 1) {
    await slots.nth(index).scrollIntoViewIfNeeded();
  }
  // Product images are lazy-loaded. Give the browser one render turn after
  // bringing every slot into the iPhone viewport before inspecting pixels.
  await page.waitForTimeout(250);
  const result = await scope.locator('[data-image-slot]').evaluateAll((slots) => {
    const imageSlots = slots.map((slot) => {
      const image = slot.querySelector('img');
      return {
        src: slot.getAttribute('data-image-src') || null,
        state: slot.getAttribute('data-image-state'),
        hasLoadedImage: !!image && image.complete && image.naturalWidth > 0,
        alt: image ? image.getAttribute('alt') : null,
        insideAriaHidden: !!slot.closest('[aria-hidden="true"]'),
        text: (slot.textContent || '').trim(),
      };
    });
    return {
      broken: imageSlots.filter((slot) => slot.state === 'loaded' && !slot.hasLoadedImage),
      productAltMissing: imageSlots.filter((slot) => (
        slot.state === 'loaded' && !slot.insideAriaHidden && (!slot.alt || !slot.alt.trim())
      )),
      visibleEmojiFallback: imageSlots.filter((slot) => /[\u{1F300}-\u{1FAFF}]/u.test(slot.text)),
      nonNeutralFallback: imageSlots.filter((slot) => (
        slot.state === 'fallback' && slot.src && !slot.src.endsWith('/item-default.svg')
      )),
    };
  });

  expect(result.broken, 'every rendered image must load').toEqual([]);
  expect(result.productAltMissing, 'product images must expose an accessible alt').toEqual([]);
  expect(result.visibleEmojiFallback, 'image slots must never fall back to emoji').toEqual([]);
  expect(result.nonNeutralFallback, 'missing packshots may only use the neutral asset').toEqual([]);
}

async function assertNoHorizontalOverflow(page) {
  const dimensions = await page.evaluate(() => ({
    documentWidth: document.documentElement.scrollWidth,
    viewportWidth: document.documentElement.clientWidth,
  }));
  expect(dimensions.documentWidth, 'mobile UI must not overflow horizontally')
    .toBeLessThanOrEqual(dimensions.viewportWidth + 1);
}

async function assertBackendBranch(request) {
  const response = await request.get('http://127.0.0.1:8766/api/frontend/branch/show/1', {
    headers: { Accept: 'application/json', 'X-API-Key': 'b6d68vy2-m7g5-20r0-5275-h103w73453q120' },
  });
  expect(response.ok(), 'branch metadata must be readable from the backend').toBeTruthy();
  const payload = await response.json();
  const branch = payload && payload.data;
  expect(branch && Number(branch.id)).toBe(1);
  expect(String(branch.phone).replace(/\D/g, '')).toBe('0365678291');
  return branch;
}

test.describe('Phase 3 catalogue visuals', () => {
test('home and menu expose real/neutral assets with compact allergen disclosure removed', async ({ page, request }) => {
    await bootMobile(page);
    await assertBackendBranch(request);

    const home = page.locator('[data-screen-label="07 Home"]');
    await assertImageSlots(page, home);
    await assertNoHorizontalOverflow(page);
    await expect(home.getByTestId('branch-phone')).toHaveText('03 65 67 82 91');
    await expect(home.locator('[role="region"][aria-label^="Allergènes"]')).toHaveCount(0);
    await expect.poll(() => page.evaluate(() => (
      window.LC && window.LC.config && window.LC.menu
        ? Number(window.LC.config.branchId) === Number(window.LC.menu.branch.id)
        : false
    ))).toBe(true);

    await page.locator('[role="tab"][aria-label="Menu"]').click();
    const menu = page.locator('[data-screen-label="08 Menu"]');
    await expect(menu).toBeVisible();
    await expect(menu.getByText('NOUVEAU', { exact: true }).first()).toBeVisible();
    await expect(menu.locator('[role="region"][aria-label^="Allergènes"]')).toHaveCount(0);

    await menu.getByRole('button', { name: 'Boissons', exact: true }).click();
    await expect(menu.getByText('Hawaï 33cl', { exact: true })).toBeVisible();
    await expect(menu.getByText('Perrier 33cl', { exact: true })).toBeVisible();
    await expect(menu.locator('[role="region"][aria-label^="Allergènes"]')).toHaveCount(0);
    await assertImageSlots(page, menu);
    await assertNoHorizontalOverflow(page);
  });

  test('category → product personalization → cart keeps detail allergen disclosure and images', async ({ page }) => {
    await bootMobile(page);
    await page.locator('[role="tab"][aria-label="Menu"]').click();

    const menu = page.locator('[data-screen-label="08 Menu"]');
    await menu.getByRole('button', { name: 'Tacos', exact: true }).click();
    const tacosCard = menu.getByRole('button', { name: /Voir Tacos M/ }).first();
    await expect(tacosCard).toBeVisible();
    await tacosCard.click();

    const wizard = page.locator('[data-screen-label^="09 Item Wizard"]');
    await expect(wizard).toBeVisible();

    // Advance through the real wizard state machine without changing any price
    // or option rule. When a required step is empty, select its first canonical
    // choice; optional steps are simply advanced.
    for (let step = 0; step < 14; step += 1) {
      const addButton = wizard.getByRole('button', { name: /Ajouter au panier/ });
      if (await addButton.count() && await addButton.first().isVisible()) {
        await assertImageSlots(page, wizard);
        await expect(wizard.locator('[role="region"][aria-label^="Allergènes"]')).toHaveCount(1);
        await addButton.first().click();
        break;
      }

      const next = wizard.getByRole('button', { name: /^Suivant/ });
      await expect(next).toBeVisible();
      if (await next.isDisabled()) {
        const firstChoice = wizard.locator('[role="radio"], [role="checkbox"]')
          .filter({ hasNot: wizard.locator('[aria-disabled="true"]') })
          .first();
        await expect(firstChoice).toBeVisible();
        await firstChoice.click();
      }
      await expect(next).toBeEnabled();
      await next.click();
    }

    const cart = page.locator('[data-screen-label="10 Cart"]');
    await expect(cart).toBeVisible();
    await expect(cart.locator('h1')).toContainText('commande');
    await assertImageSlots(page, cart);
    await expect(cart.locator('[role="region"][aria-label^="Allergènes"]')).toHaveCount(0);
    await assertNoHorizontalOverflow(page);
  });

  test('home hero and category shortcuts open the contextual destination', async ({ page }) => {
    await bootMobile(page);
    const home = page.locator('[data-screen-label="07 Home"]');

    await home.getByRole('button', { name: 'Catégorie Boissons', exact: true }).click();
    const drinksMenu = page.locator('[data-screen-label="08 Menu"]');
    await expect(drinksMenu).toBeVisible();
    await expect(drinksMenu.getByRole('button', { name: 'Boissons', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(drinksMenu.getByText('Hawaï 33cl', { exact: true })).toBeVisible();
    await expect(drinksMenu.getByText('Cayenne', { exact: true })).toHaveCount(0);

    await page.locator('[role="tab"][aria-label="Accueil"]').click();
    const refreshedHome = page.locator('[data-screen-label="07 Home"]');
    await refreshedHome.getByRole('button', { name: /Commander Tacos L/ }).click();
    const wizard = page.locator('[data-screen-label^="09 Item Wizard"]');
    await expect(wizard).toBeVisible();
    await expect(wizard.getByText('8,90 €', { exact: true })).toBeVisible();
  });
});

