// ============================================================================
// PREUVE RÉELLE — TRAUMA « affiché ≠ payé » sur le WEB (supplément non compté au sealing).
// On ajoute un produit personnalisable AVEC un supplément PAYANT (viande +2,50 / supplément),
// et on vérifie que le prix est compté À L'IDENTIQUE à chaque étape affichée :
//   total wizard (récap) === total panier === total page paiement  (AUCUN drop).
// + capture récap (le supplément listé) + page paiement. Client-side robuste (menu statique).
// Run : PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8899 \
//         npx playwright test tests/e2e/mollie-web-supplement-calc-2026-07-20.spec.js --project=chromium
// ============================================================================
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'web-supplement-calc-2026-07-20');
fs.mkdirSync(SHOT_DIR, { recursive: true });
const shot = (n) => path.join(SHOT_DIR, n);

test.describe.configure({ retries: 0 });
test.setTimeout(180_000);

const euro = (s) => {
  const m = String(s || '').match(/(\d+[.,]\d{2})/);
  return m ? parseFloat(m[1].replace(',', '.')) : NaN;
};

async function gotoDev(page) {
  for (let i = 0; i < 3; i++) {
    try { await page.goto('/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 }); break; }
    catch (e) { await page.waitForTimeout(3_000); }
  }
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
}
async function openMenu(page) {
  const direct = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await direct.isVisible().catch(() => false)) await direct.click();
  else { await page.locator('.lc-nav-burger').click(); await page.locator('#lc-mobile-menu').getByRole('button', { name: 'Menu' }).click(); }
  await expect(page.locator('.lc-menu-grid')).toBeVisible({ timeout: 15_000 });
}

// Ouvre le 1er produit à WIZARD dont le nom matche nameRe : carte → détail → « Personnaliser » → wizard.
async function openAWizardProduct(page, nameRe = /.*/) {
  const cards = page.locator('[aria-label^="Voir "]');
  const n = await cards.count();
  for (let i = 0; i < n; i++) {
    const name = (await cards.nth(i).innerText().catch(() => '')).trim();
    if (!nameRe.test(name)) continue;
    await cards.nth(i).scrollIntoViewIfNeeded().catch(() => {});
    await cards.nth(i).click().catch(() => {});
    const detail = page.locator('.lc-detail');
    if (!(await detail.isVisible({ timeout: 2500 }).catch(() => false))) continue;
    const perso = detail.getByRole('button', { name: /Personnaliser/ });
    if (await perso.isVisible().catch(() => false)) {
      await perso.click();
      if (await page.locator('.lc-wiz').isVisible({ timeout: 5_000 }).catch(() => false)) return name || 'produit';
    }
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(250);
  }
  return null;
}

// Sweep « même logique par catégorie » : wizard+supplément → panier, total IDENTIQUE (drop-critique).
async function assertNoDropWizardToCart(page, nameRe, label) {
  await gotoDev(page);
  await openMenu(page);
  const prod = await openAWizardProduct(page, nameRe);
  test.skip(!prod, `aucun produit à wizard « ${label} » trouvé dans le menu`);
  const drive = await driveWizardAddSupplement(page);
  console.log(`[${label}] produit=${prod} addedPriced=${drive.addedPriced} recap=${drive.recapTotal}`);
  expect(drive.addedPriced, `${label} : un supplément payant doit être ajoutable`).toBeTruthy();
  const recapTotal = euro(await page.locator('.lc-wiz-foot-next').innerText());
  await page.screenshot({ path: shot(`cat-${label}-01-wizard.png`), fullPage: true });
  await page.locator('.lc-wiz-foot-next').click();
  await expect(page.locator('.lc-cart-drawer.is-open')).toBeVisible({ timeout: 10_000 });
  const cartTotal = euro(await page.locator('.lc-cart-totals-row.is-total b').innerText());
  await page.screenshot({ path: shot(`cat-${label}-02-panier.png`), fullPage: true });
  console.log(`[${label}] wizard=${recapTotal} panier=${cartTotal}`);
  expect(cartTotal, `${label} : panier === wizard (supplément non largué)`).toBeCloseTo(recapTotal, 2);
}

// Traverse le wizard, en cliquant un supplément PAYANT (.lc-wiz-choice-price) là où il existe.
async function driveWizardAddSupplement(page) {
  let addedPriced = false;
  for (let step = 0; step < 14; step++) {
    const next = page.locator('.lc-wiz-foot-next');
    const nextText = (await next.innerText().catch(() => '')).trim();
    if (/Ajouter au panier/i.test(nextText)) return { addedPriced, recapTotal: euro(nextText) };

    const choices = page.locator('.lc-wiz-choice');
    const cn = await choices.count();
    // 1) cliquer un choix PAYANT s'il en existe (supplément / viande sup.)
    for (let c = 0; c < cn; c++) {
      if (await choices.nth(c).locator('.lc-wiz-choice-price').count() > 0) {
        await choices.nth(c).click().catch(() => {}); addedPriced = true; break;
      }
    }
    // 2) garantir une sélection pour les étapes radio requises
    if (await page.locator('.lc-wiz-choice.is-on').count() === 0 && cn > 0) {
      await choices.first().click().catch(() => {});
    }
    await next.click({ timeout: 6_000 }).catch(() => {});
    await page.waitForTimeout(350);
  }
  return { addedPriced, recapTotal: NaN };
}

test('TRAUMA supplément — affiché === panier === paiement (aucun drop)', async ({ page }) => {
  await gotoDev(page);
  await openMenu(page);
  const prod = await openAWizardProduct(page);
  expect(prod, 'un produit à wizard doit s\'ouvrir').toBeTruthy();
  console.log('[produit]', prod);

  const drive = await driveWizardAddSupplement(page);
  console.log('[wizard]', JSON.stringify(drive));
  expect(drive.addedPriced, 'un supplément PAYANT doit avoir été ajouté').toBeTruthy();

  // total lu sur le bouton « Ajouter au panier » (récap)
  const recapTotal = euro(await page.locator('.lc-wiz-foot-next').innerText());
  await page.screenshot({ path: shot('01-wizard-recap-supplement.png'), fullPage: true });
  expect(recapTotal, 'total wizard doit être > 0').toBeGreaterThan(0);

  // ajouter au panier
  await page.locator('.lc-wiz-foot-next').click();
  await expect(page.locator('.lc-cart-drawer.is-open')).toBeVisible({ timeout: 10_000 });
  const cartTotal = euro(await page.locator('.lc-cart-totals-row.is-total b').innerText());
  console.log('[totaux] wizard=', recapTotal, ' panier=', cartTotal);
  await page.screenshot({ path: shot('02-panier-supplement.png'), fullPage: true });

  // → checkout → paiement
  await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Passer commande/ }).click();
  const checkoutCta = page.getByRole('button', { name: /Continuer vers paiement/ });
  for (let i = 0; i < 8; i++) {
    if (await checkoutCta.isVisible().catch(() => false)) break;
    const skip = page.getByRole('button', { name: 'Non merci' });
    if (await skip.isVisible().catch(() => false)) await skip.click();
    await page.waitForTimeout(400);
  }
  await checkoutCta.click();
  await expect(page.locator('.lcf-cta-bar-next')).toBeVisible({ timeout: 10_000 });
  const payTotal = euro(await page.locator('.lcf-cta-bar-next').innerText());
  console.log('[totaux] paiement=', payTotal);
  await page.screenshot({ path: shot('03-paiement-supplement.png'), fullPage: true });

  // LE CŒUR DU TRAUMA : les 3 totaux affichés doivent être IDENTIQUES (le supplément n'est
  // jamais « largué » entre wizard, panier et paiement). expected_total envoyé = payTotal.
  expect(cartTotal, 'panier === wizard').toBeCloseTo(recapTotal, 2);
  expect(payTotal, 'paiement === panier (aucun drop du supplément)').toBeCloseTo(cartTotal, 2);
});

// « MÊME LOGIQUE PAR CATÉGORIE » — le supplément payant est compté à l'identique wizard→panier
// pour chaque famille, pas seulement les sandwichs (Bols/Tacos avaient des bugs propres passés).
test('Catégorie BOL — supplément compté à l\'identique (wizard === panier)', async ({ page }) => {
  await assertNoDropWizardToCart(page, /bol/i, 'BOL');
});
test('Catégorie TACOS — supplément compté à l\'identique (wizard === panier)', async ({ page }) => {
  await assertNoDropWizardToCart(page, /tacos/i, 'TACOS');
});

// ADVERSAIRE le plus dur : sur une étape MULTI, sélectionner 2 options PAYANTES et vérifier que le
// total augmente de la SOMME des deux (P1+P2). C'est LE bug « la 2e sauce/le 2e supplément s'annule »
// (past bug multi-sauce). Si le 2e est largué, T1 ≠ T0+P1+P2 → échec = bug réel attrapé.
test('ADVERSAIRE multi-options — la 2e option payante n\'est JAMAIS larguée (total = base+P1+P2)', async ({ page }) => {
  await gotoDev(page);
  await openMenu(page);
  const prod = await openAWizardProduct(page, /.*/);
  expect(prod, 'un produit à wizard doit s\'ouvrir').toBeTruthy();

  let result = null;
  for (let step = 0; step < 14 && !result; step++) {
    const next = page.locator('.lc-wiz-foot-next');
    const nextText = (await next.innerText().catch(() => '')).trim();
    if (/Ajouter au panier/i.test(nextText)) break;

    const choices = page.locator('.lc-wiz-choice');
    const cn = await choices.count();
    const priced = [];
    for (let c = 0; c < cn; c++) {
      const pc = choices.nth(c).locator('.lc-wiz-choice-price');
      if (await pc.count() > 0) priced.push({ idx: c, price: euro(await pc.innerText()) });
    }
    if (priced.length >= 2) {
      const T0 = euro(await next.innerText());
      await choices.nth(priced[0].idx).click(); await page.waitForTimeout(200);
      await choices.nth(priced[1].idx).click(); await page.waitForTimeout(200);
      // combien d'options PAYANTES sont réellement sélectionnées ? 2 ⇒ étape MULTI (cas cherché)
      const onPriced = await page.locator('.lc-wiz-choice.is-on .lc-wiz-choice-price').count();
      if (onPriced >= 2) {
        const T1 = euro(await next.innerText());
        result = { T0, P1: priced[0].price, P2: priced[1].price, T1, onPriced };
        break;
      }
      // sinon (radio : la 2e a remplacé la 1re) → passer à l'étape suivante
    }
    if (await page.locator('.lc-wiz-choice.is-on').count() === 0 && cn > 0) await choices.first().click();
    await next.click({ timeout: 6_000 }).catch(() => {});
    await page.waitForTimeout(300);
  }

  test.skip(!result, 'aucune étape multi à ≥2 options payantes trouvée sur ce produit');
  console.log('[multi-sum]', JSON.stringify(result));
  await page.screenshot({ path: shot('adv-multi-options.png'), fullPage: true });
  // LE test : le total après = base + P1 + P2 (les DEUX comptés, aucun largage)
  expect(result.T1, 'total doit inclure la SOMME des 2 options payantes (2e non larguée)')
    .toBeCloseTo(result.T0 + result.P1 + result.P2, 2);
});
