// DISPUTE round-1 Vague C — C3 : allergènes. Seul Tiramisu (51, cat 9 Desserts) a un pivot
// item_allergen (gluten,oeufs,lait) MAIS items.allergen_flags='[]'. Badge visible ? Détail ?
import { boot, snap, kioskBoot, startFlow, BASE, logLine, OUT } from './_d1-C-lib.mjs';
import fs from 'fs';

const L = (m) => logLine('_c3-log.txt', m);
const { browser, page, sink } = await boot();
await kioskBoot(page);
await startFlow(page);

await page.goto(BASE + '/kiosk/categories?cat=9', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
await snap(page, sink, 'c3-01-desserts-catalog');

// présence badge allergène sur les 3 desserts (49 Glace, 50 Tarte Daim, 51 Tiramisu)
for (const id of [49, 50, 51]) {
  const badge = page.locator(`[data-testid="kiosk-product-allergens-${id}"]`);
  const present = await badge.count();
  const txt = present ? (await badge.innerText({ timeout: 1200 }).catch(() => '?')).replace(/\n/g, ' ') : null;
  L(`allergen badge item ${id}: count=${present} text="${txt}"`);
}

// payload API menu : que renvoie le backend pour Tiramisu ? (source de vérité affichage)
const menuItem = await page.evaluate(async () => {
  try {
    const tok = JSON.parse(localStorage.vuex)?.kioskCart?.kioskToken;
    const res = await fetch('/api/frontend/menu', { headers: { Authorization: 'Bearer ' + tok, Accept: 'application/json' } });
    const j = await res.json();
    const flat = JSON.stringify(j).length;
    const all = (j?.data?.items || j?.items || j?.data || []);
    const arr = Array.isArray(all) ? all : (all.items || []);
    const find = (list) => Array.isArray(list) ? list.find(i => i?.name?.includes('Tiramisu')) : null;
    let tira = find(arr);
    if (!tira && j?.data?.categories) for (const c of j.data.categories) { tira = find(c.items); if (tira) break; }
    return { size: flat, tira: tira ? { id: tira.id, name: tira.name, allergens: tira.allergens, allergen_flags: tira.allergen_flags } : null };
  } catch (e) { return { error: String(e) }; }
});
L(`API menu Tiramisu: ${JSON.stringify(menuItem).slice(0, 500)}`);

// zoom sur la carte Tiramisu
const card = page.locator(`[data-testid="kiosk-product-add-51"]`).locator('..').locator('..');
await card.screenshot({ path: OUT + 'c3-02-tiramisu-card.png' }).catch(async () => {
  await page.locator('.kiosk-product-card', { hasText: 'Tiramisu' }).first().screenshot({ path: OUT + 'c3-02-tiramisu-card.png' }).catch(() => L('zoom card FAIL'));
});

// clic badge allergène (popover/détail ?)
const badge51 = page.locator('[data-testid="kiosk-product-allergens-51"]');
if (await badge51.count()) {
  await badge51.first().click().catch(() => {});
  await page.waitForTimeout(900);
  await snap(page, sink, 'c3-03-after-badge-click');
}

// ajoute Tiramisu au panier → détail allergènes sur la ligne panier ?
await page.locator('[data-testid="kiosk-product-add-51"]').click().catch(() => {});
await page.waitForTimeout(1200);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
await snap(page, sink, 'c3-04-cart-tiramisu');
const cartAllergens = await page.locator('[data-testid="kiosk-cart-item-allergens-0"]').innerText({ timeout: 1500 }).catch(() => 'ABSENT');
L(`cart line allergens: "${cartAllergens.replace(/\n/g, ' ')}"`);

fs.writeFileSync(OUT + '_c3-api.json', JSON.stringify(menuItem, null, 2));
await browser.close();
L('C3 done');
