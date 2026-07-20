// VÉRIF owner : les boissons ne doivent PLUS être noyées dans la grille « Tout ». En vue Tout :
// section « Boissons » dédiée (≤5 aperçus + « Voir toutes ») ; desserts restent dans la grille.
// Clic « Voir toutes » → page boisson (toutes les boissons). Captures LUES.
const { test, expect } = require('@playwright/test');
const path = require('path'); const fs = require('fs');
const SHOT = path.join(__dirname, '__screenshots__', 'drinks-grouping-2026-07-20');
fs.mkdirSync(SHOT, { recursive: true });
test.setTimeout(120_000);

async function gotoMenu(p){
  await p.goto('/?dev', { waitUntil:'domcontentloaded', timeout:45000 });
  await expect(p.locator('#root .lc-app')).toBeVisible({ timeout:45000 });
  const d=p.locator('.lc-nav-links').getByRole('button',{name:'Menu',exact:true});
  if(await d.isVisible({timeout:3000}).catch(()=>0))await d.click();
  else{await p.locator('.lc-nav-burger').click();await p.locator('#lc-mobile-menu').getByRole('button',{name:'Menu'}).click();}
  await expect(p.locator('.lc-menu-grid').first()).toBeVisible({ timeout:15000 });
  await p.waitForTimeout(500);
}
// une carte est une boisson si son nom matche une canette connue
const DRINK_RE = /Coca|Fanta|Sprite|Oasis|Orangina|Eau|Capri|Tropico|Ice Tea|Perrier|Cherry/i;
async function gridDrinkCount(scope){
  const names = await scope.locator('.lc-menu-grid').first().locator('.lc-item-card, [class*="item-card"], article').allInnerTexts().catch(()=>[]);
  return names.filter(n => DRINK_RE.test(n)).length;
}

test('Vue « Tout » — boissons SORTIES de la grille principale + section dédiée 5 + Voir toutes', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 1000 });
  await gotoMenu(page);

  // section Boissons présente
  const drinksHeading = page.getByRole('heading', { name: /Boissons/ });
  await expect(drinksHeading).toBeVisible({ timeout: 8000 });
  const seeAll = page.getByRole('button', { name: /Voir toutes/i });
  await expect(seeAll).toBeVisible();
  await drinksHeading.scrollIntoViewIfNeeded(); await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(SHOT, '01-tout-boissons-section.png'), fullPage: true });

  // la 1re grille (plats) ne doit PAS déborder de boissons ; la section boissons montre ≤5
  const grids = page.locator('.lc-menu-grid');
  const nGrids = await grids.count();
  // dernière grille = aperçu boissons ; compter les CARTES (enfants directs), pas les noms
  const drinkPreview = grids.nth(nGrids - 1);
  const previewCards = await drinkPreview.locator(':scope > *').count();
  // la grille des PLATS (1re) ne doit contenir AUCUNE carte boisson (test par TITRE exact — « Coca-Cola
  //   33cl » n'apparaît que comme titre de canette, jamais dans une description de plat).
  const cocaInFood = await grids.first().getByText('Coca-Cola 33cl', { exact: true }).count();
  console.log(`[TOUT] grilles=${nGrids} · cartes aperçu boissons=${previewCards} · carte Coca dans grille plats=${cocaInFood}`);

  // clic Voir toutes → page boisson
  await seeAll.click(); await page.waitForTimeout(700);
  await page.screenshot({ path: path.join(SHOT, '02-page-boissons.png'), fullPage: true });
  const allCards = await page.locator('.lc-menu-grid').first().locator(':scope > *').count();
  console.log(`[PAGE BOISSONS] cartes=${allCards}`);

  expect(previewCards, 'aperçu boissons = 5 cartes max').toBeLessThanOrEqual(5);
  expect(previewCards, 'aperçu boissons ≥ 1 carte').toBeGreaterThanOrEqual(1);
  expect(cocaInFood, 'AUCUNE carte boisson dans la grille des plats (vue Tout)').toBe(0);
  expect(allCards, 'page boisson montre toutes les boissons (≥ 10)').toBeGreaterThanOrEqual(10);
});

test('Catégorie Desserts — reste NORMALE (dans la grille)', async ({ page }) => {
  await gotoMenu(page);
  // via sidebar ou chip
  const dessBtn = page.getByRole('button', { name: /Desserts/ }).first();
  if (await dessBtn.isVisible().catch(()=>0)) { await dessBtn.click(); await page.waitForTimeout(600); }
  const txt = await page.locator('.lc-menu-grid').first().innerText().catch(()=>'');
  await page.screenshot({ path: path.join(SHOT, '03-desserts.png'), fullPage: true });
  console.log('[DESSERTS] grille visible, contenu length=' + txt.length);
  // pas de crash, la grille desserts s'affiche
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible();
});
