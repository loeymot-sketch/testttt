// Phase 3 — Le Cayenne mobile catalogue visual critical flow.
//
// Read-only browser coverage for the standalone mobile UI. The test deliberately
// does not place an order or call a write endpoint: it proves the visual path
// home → menu/category → product wizard → cart while preserving the backend
// pricing/order boundary.

const { test, expect } = require('@playwright/test');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const MOBILE_PATH = '/index.html?dev&catalog-visual-critical=1';
const REPO_ROOT = path.resolve(__dirname, '../..');
const canonicalPngFiles = [
  'sandwich-cayenne.png',
  'sandwich-supreme.png',
  'sandwich-mega.png',
  'sandwich-terminator.png',
  'tacos-cayenne.png',
  'galette.png',
  'cat-sandwich-cayenne.png',
  'cat-galette.png',
  'cat-tacos.png',
];
const sha = (value) => crypto.createHash('sha256').update(value).digest('hex');
const WRITE_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
const writeRequestsByPage = new WeakMap();
const canonicalProductImages = {
  cayenne: 'assets/menu/sandwich-cayenne.png',
  supreme: 'assets/menu/sandwich-supreme.png',
  mega: 'assets/menu/sandwich-mega.png',
  terminator: 'assets/menu/sandwich-terminator.png',
  'galette-normale': 'assets/menu/galette.png',
  'galette-cayenne': 'assets/menu/galette.png',
  'tacos-m': 'assets/menu/tacos-cayenne.png',
  'tacos-l': 'assets/menu/tacos-cayenne.png',
  'tacos-xl': 'assets/menu/tacos-cayenne.png',
};
const canonicalCategoryImages = {
  sandwichs: 'assets/menu/cat-sandwich-cayenne.png',
  galette: 'assets/menu/cat-galette.png',
  tacos: 'assets/menu/cat-tacos.png',
};
const canonicalDrinkImages = {
  coca: 'assets/menu/coca.png',
  'coca-zero': 'assets/menu/coca-zero.png',
  'coca-cherry': 'assets/menu/coca-cherry.png',
  tropico: 'assets/menu/tropico.png',
  'ice-tea-peche': 'assets/menu/lipton-peche.png',
  'fanta-citron': 'assets/menu/fanta-citron.png',
  fanta: 'assets/menu/fanta-orange.png',
  oasis: 'assets/menu/oasis.png',
  sprite: 'assets/menu/sprite.png',
  'eau-plate': 'assets/menu/eau.png',
  'capri-sun': 'assets/menu/capri-sun.png',
  'fuze-tea': 'assets/menu/fuze-tea.png',
};
const canonicalMeatImages = {
  'm-mexicanos': 'assets/menu/viande_mexicain.png',
  'm-cordon-bleu': 'assets/menu/viande_cordon.png',
  'm-viande-hachee': 'assets/menu/viande_hachee.png',
  'm-nuggets': 'assets/menu/viande_nuggets.png',
  'm-tenders': 'assets/menu/viande_tenders.png',
  'm-fricadelle': 'assets/menu/viande_fricandelle.png',
  'm-poulet-marine': 'assets/menu/viande_poulet.png',
};
const canonicalFormulaImages = {
  full: 'assets/menu/menu_complet.png',
  frites: 'assets/menu/frites.png',
  boisson: 'assets/menu/boisson.png',
};
const missingExactDrinkImages = {
  hawai: 'assets/menu/item-default.svg',
  perrier: 'assets/menu/item-default.svg',
};

function installReadOnlyRequestGuard(page) {
  const writeRequests = [];
  writeRequestsByPage.set(page, writeRequests);
  page.on('request', (request) => {
    if (WRITE_METHODS.has(request.method())) {
      writeRequests.push({ method: request.method(), url: request.url() });
    }
  });
}

test.beforeEach(async ({ page }) => {
  installReadOnlyRequestGuard(page);
});

test.afterEach(async ({ page }) => {
  expect(writeRequestsByPage.get(page) || [],
    'catalog visual E2E must never issue POST/PUT/PATCH/DELETE').toEqual([]);
});

async function assertCanonicalRuntimeImages(page) {
  const actual = await page.evaluate(({ productSlugs, categorySlugs, drinkSlugs, missingDrinkSlugs }) => {
    const menu = window.LC.menu;
    return {
      products: Object.fromEntries(productSlugs.map((slug) => {
        const item = menu.findItem(slug);
        return [slug, { image: item.image, hero: item.hero }];
      })),
      categories: Object.fromEntries(categorySlugs.map((slug) => [
        slug,
        menu.categories.find((category) => category.slug === slug).image,
      ])),
      pains: Object.fromEntries(['pain-galette'].map((id) => [
        id,
        menu.pains.find((pain) => pain.id === id).image,
      ])),
      drinks: Object.fromEntries(drinkSlugs.map((slug) => [slug, menu.findItem(slug).image])),
      missingDrinks: Object.fromEntries(missingDrinkSlugs.map((slug) => [slug, menu.findItem(slug).image])),
      meats: Object.fromEntries(menu.meats.map((meat) => [meat.id, meat.image])),
    };
  }, {
    productSlugs: Object.keys(canonicalProductImages),
    categorySlugs: Object.keys(canonicalCategoryImages),
    drinkSlugs: Object.keys(canonicalDrinkImages),
    missingDrinkSlugs: Object.keys(missingExactDrinkImages),
  });

  for (const [slug, expected] of Object.entries(canonicalProductImages)) {
    expect(actual.products[slug].image, `canonical product image: ${slug}`).toBe(expected);
    expect(actual.products[slug].hero, `canonical product hero: ${slug}`).toBe(expected);
  }
  for (const [slug, expected] of Object.entries(canonicalCategoryImages)) {
    expect(actual.categories[slug], `canonical category image: ${slug}`).toBe(expected);
  }
  expect(actual.pains['pain-galette'], 'canonical galette pain image')
    .toBe('assets/menu/galette.png');
  for (const [slug, expected] of Object.entries(canonicalDrinkImages)) {
    expect(actual.drinks[slug], `canonical drink image: ${slug}`).toBe(expected);
  }
  for (const [slug, expected] of Object.entries(missingExactDrinkImages)) {
    expect(actual.missingDrinks[slug], `missing exact drink stays neutral: ${slug}`).toBe(expected);
  }
  for (const [id, expected] of Object.entries(canonicalMeatImages)) {
    expect(actual.meats[id], `canonical meat image: ${id}`).toBe(expected);
  }
}

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

async function assertWizardChoiceSurface(page, wizard, group, imagePrefix, expectedImages, label) {
  await expect(group, `${label} choice group should be visible`).toBeVisible();
  const emojiText = await group.evaluate((node) => /[\u{1F300}-\u{1FAFF}]/u.test(node.textContent || ''));
  expect(emojiText, `${label} choice group must not render food emoji`).toBe(false);

  const images = group.locator(`img[data-testid^="${imagePrefix}"]`);
  await expect(images, `${label} choices must expose real asset images`).toHaveCount(expectedImages.length);
  const loaded = await images.evaluateAll((nodes) => nodes.map((image) => ({
    src: image.getAttribute('src'),
    loaded: image.complete && image.naturalWidth > 0,
    errored: image.dataset.imageError === 'true',
  })));
  expect(loaded, `${label} choice images must load`).toEqual(expectedImages.map((src) => ({ src, loaded: true, errored: false })));
  await expect(wizard.locator('[data-image-slot][data-image-state="fallback"]'), `${label} must not create product image fallbacks`).toHaveCount(0);
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
  test('mobile server serves the exact canonical PNG bytes', async ({ request }) => {
    for (const file of canonicalPngFiles) {
      const source = fs.readFileSync(path.join(REPO_ROOT, 'public/images/menu', file));
      const response = await request.get(`/assets/menu/${file}`);
      expect(response.ok(), `canonical PNG response: ${file}`).toBeTruthy();
      expect(sha(await response.body()), `canonical PNG SHA: ${file}`)
        .toBe(sha(source));
    }
  });

test('home and menu expose real/neutral assets with compact allergen disclosure removed', async ({ page, request }) => {
    await bootMobile(page);
    await assertCanonicalRuntimeImages(page);
    await assertBackendBranch(request);

    const home = page.locator('[data-screen-label="07 Home"]');
    await assertImageSlots(page, home);
    await assertNoHorizontalOverflow(page);
    await expect(home.getByTestId('branch-phone')).toHaveText('03 65 67 82 91');
    await expect(home.locator('[data-testid="allergen-disclosure"]')).toHaveCount(0);
    await expect.poll(() => page.evaluate(() => (
      window.LC && window.LC.config && window.LC.menu
        ? Number(window.LC.config.branchId) === Number(window.LC.menu.branch.id)
        : false
    ))).toBe(true);

    await page.locator('[role="tab"][aria-label="Menu"]').click();
    const menu = page.locator('[data-screen-label="08 Menu"]');
    await expect(menu).toBeVisible();
    await expect(menu.getByText('NOUVEAU', { exact: true }).first()).toBeVisible();
    await expect(menu.locator('[data-testid="allergen-disclosure"]')).toHaveCount(0);

    await menu.getByRole('button', { name: 'Boissons', exact: true }).click();
    await expect(menu.getByText('Hawaï 33cl', { exact: true })).toBeVisible();
    await expect(menu.getByText('Perrier 33cl', { exact: true })).toBeVisible();
    await expect(menu.locator('[data-testid="allergen-disclosure"]')).toHaveCount(0);
    await assertImageSlots(page, menu);
    await assertNoHorizontalOverflow(page);
  });

  test('home Nouveautés prioritizes new arrivals and keeps every card navigable', async ({ page }) => {
    await bootMobile(page);

    const home = page.locator('[data-screen-label="07 Home"]');
    const newSection = home.locator('section[aria-labelledby="new-title"]');
    const newCards = newSection.locator('button.lc-home__new-card');

    await expect(newSection.getByRole('button', { name: 'Voir Tacos XL', exact: true }))
      .toBeVisible();
    const cardCount = await newCards.count();
    expect(cardCount, 'Nouveautés must contain at least one product').toBeGreaterThan(0);
    expect(cardCount, 'Nouveautés must remain capped at four products').toBeLessThanOrEqual(4);

    for (let index = 0; index < cardCount; index += 1) {
      const card = page.locator('[data-screen-label="07 Home"] section[aria-labelledby="new-title"] button.lc-home__new-card').nth(index);
      const label = await card.getAttribute('aria-label');
      expect(label, `Nouveautés card ${index + 1} needs an accessible destination label`).toMatch(/^Voir /);
      await card.click();
      await expect(page.locator('[data-screen-label^="09 Item Wizard"], [data-screen-label="09 Item Direct"]'),
        `Nouveautés card ${label} must open its product destination`).toBeVisible();
      await page.getByRole('button', { name: 'Fermer', exact: true }).first().click();
      await expect(page.locator('[data-screen-label="07 Home"]')).toBeVisible();
    }
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
      const meatGroup = wizard.getByRole('radiogroup', { name: /Choisis \d viandes/ });
      if (await meatGroup.isVisible().catch(() => false)) {
        await assertWizardChoiceSurface(page, wizard, meatGroup, 'meat-choice-image-', Object.values(canonicalMeatImages), 'meat');
      }
      const formulaGroup = wizard.getByRole('radiogroup', { name: 'Faire un menu' });
      if (await formulaGroup.isVisible().catch(() => false)) {
        await assertWizardChoiceSurface(page, wizard, formulaGroup, 'formula-choice-image-', Object.values(canonicalFormulaImages), 'formula');
        await expect(formulaGroup.locator('[role="img"][aria-label^="Sans formule"]'), 'non-product formula choice keeps its accessible neutral state').toHaveCount(1);
      }

      const addButton = wizard.getByRole('button', { name: /Ajouter au panier/ });
      if (await addButton.count() && await addButton.first().isVisible()) {
        await assertImageSlots(page, wizard);
        const allergenDisclosure = wizard.locator('[data-testid="allergen-disclosure"]');
        await expect(allergenDisclosure).toHaveCount(1);
        const allergenSummary = allergenDisclosure.locator('summary');
        const allergenRegion = allergenDisclosure.locator('[role="region"][aria-label^="Allergènes"]');
        await expect(allergenSummary).toHaveText(/Informations allergènes/);
        await expect(allergenRegion).toBeVisible();
        await expect(allergenDisclosure.locator('li')).not.toHaveCount(0);
        await expect(allergenDisclosure).not.toContainText(/[🌾🥛🥚🥜🌰🫘🐟🦐🦑🥬🍇🌿⚠️❗]/);
        await allergenSummary.focus();
        await expect(allergenSummary).toBeFocused();
        await allergenSummary.press('Enter');
        await expect(allergenRegion).toBeHidden();
        await allergenSummary.press('Enter');
        await expect(allergenRegion).toBeVisible();
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
    await expect(cart.locator('[data-testid="allergen-disclosure"]')).toHaveCount(0);
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

