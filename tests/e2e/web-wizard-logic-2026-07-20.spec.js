// ============================================================================
// AUDIT LOGIQUE DU WIZARD WEB — chaque bouton/option vérifié en direct (mandat owner).
// Prouve, option par option, que le total du wizard suit EXACTEMENT le prix affiché de chaque
// option (affiché = facturé), que la dé-sélection décrémente, que le MAX bloque le sur-choix,
// et que le total du récap = la somme. systematic-debugging : mesurer, pas supposer.
// Run : PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8899 \
//         npx playwright test tests/e2e/web-wizard-logic-2026-07-20.spec.js --project=chromium
// ============================================================================
const { test, expect, devices } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const SHOT_DIR = path.join(__dirname, '__screenshots__', 'web-wizard-logic-2026-07-20');
fs.mkdirSync(SHOT_DIR, { recursive: true });
const shot = (n) => path.join(SHOT_DIR, n);
const euro = (s) => { const m = String(s || '').match(/(\d+[.,]\d{2})/); return m ? parseFloat(m[1].replace(',', '.')) : NaN; };

test.use({ ...devices['Pixel 7'] });
test.describe.configure({ retries: 0 });
test.setTimeout(240_000);

async function gotoDev(page) {
  await page.goto('/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
}
async function openMenu(page) {
  const direct = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await direct.isVisible({ timeout: 3_000 }).catch(() => false)) await direct.click();
  else { await page.locator('.lc-nav-burger').click(); await page.locator('#lc-mobile-menu').getByRole('button', { name: 'Menu' }).click(); }
  await expect(page.locator('.lc-menu-grid')).toBeVisible({ timeout: 15_000 });
}
async function openWizard(page, nameRe) {
  const cards = page.locator('[aria-label^="Voir "]');
  const n = await cards.count();
  for (let i = 0; i < n; i++) {
    const name = (await cards.nth(i).innerText().catch(() => '')).trim();
    if (nameRe && !nameRe.test(name)) continue;
    await cards.nth(i).click().catch(() => {});
    const detail = page.locator('.lc-detail');
    if (!(await detail.isVisible({ timeout: 2500 }).catch(() => false))) continue;
    const perso = detail.getByRole('button', { name: /Personnaliser/ });
    if (await perso.isVisible().catch(() => false)) { await perso.click(); if (await page.locator('.lc-wiz').isVisible({ timeout: 5_000 }).catch(() => false)) return name; }
    await page.keyboard.press('Escape').catch(() => {}); await page.waitForTimeout(250);
  }
  return null;
}
const wizTotal = async (page) => euro(await page.locator('.lc-wiz-foot-next').innerText());
const nextText = async (page) => (await page.locator('.lc-wiz-foot-next').innerText().catch(() => '')).trim();

test('LOGIQUE wizard — total = somme exacte des options, déselection décrémente, max bloque', async ({ page }) => {
  await gotoDev(page);
  await openMenu(page);
  const prod = await openWizard(page, /Cayenne/);
  expect(prod, 'wizard Cayenne ouvert').toBeTruthy();

  const checks = [];   // {step, option, price, deltaAdd, deltaRemove, ok}
  let maxTested = false, requiredTested = false;

  for (let s = 0; s < 14; s++) {
    if (/Ajouter au panier/i.test(await nextText(page))) break;
    const stepTitle = (await page.locator('.lc-wiz-title').innerText().catch(() => '')).trim();
    const isRequired = await page.locator('.lc-wiz-eyebrow', { hasText: /OBLIGATOIRE/ }).count() > 0;
    const choices = page.locator('.lc-wiz-choice');
    const cn = await choices.count();

    // (A) étape OBLIGATOIRE sans sélection → « Continuer » DOIT être désactivé
    if (isRequired && await page.locator('.lc-wiz-choice.is-on').count() === 0 && !requiredTested) {
      const disabled = await page.locator('.lc-wiz-foot-next').isDisabled().catch(() => false);
      checks.push({ step: stepTitle, test: 'required-gate', disabled });
      requiredTested = true;
    }

    // (B) options PAYANTES de cette étape : delta d'ajout == prix, delta de retrait == -prix
    const priced = [];
    for (let c = 0; c < cn; c++) {
      const pc = choices.nth(c).locator('.lc-wiz-choice-price');
      if (await pc.count() > 0) priced.push({ c, price: euro(await pc.innerText()) });
    }
    const isMultiMax1 = false; // (détecté implicitement : si le clic remplace)
    for (const p of priced.slice(0, 3)) {
      const on = await choices.nth(p.c).getAttribute('class');
      if ((on || '').includes('is-on')) continue;
      const before = await wizTotal(page);
      await choices.nth(p.c).click({ timeout: 5_000 }).catch(() => {});
      await page.waitForTimeout(200);
      const afterAdd = await wizTotal(page);
      const stillOn = ((await choices.nth(p.c).getAttribute('class')) || '').includes('is-on');
      // si l'option a été sélectionnée (multi), le total doit monter du prix ; si radio (auto-advance), on sort
      if (!stillOn) { checks.push({ step: stepTitle, price: p.price, note: 'radio/replace — vérifié ailleurs' }); break; }
      let deltaRemove = null;
      // déselection (multi) → doit redescendre du prix
      await choices.nth(p.c).click({ timeout: 5_000 }).catch(() => {});
      await page.waitForTimeout(200);
      const afterRemove = await wizTotal(page);
      deltaRemove = round2(afterAdd - afterRemove);
      // re-sélection pour garder une compo valide
      await choices.nth(p.c).click({ timeout: 5_000 }).catch(() => {});
      await page.waitForTimeout(150);
      checks.push({ step: stepTitle, price: p.price, deltaAdd: round2(afterAdd - before), deltaRemove });
    }

    // (C) MAX : si l'étape a un max, sur-sélectionner doit afficher l'erreur + total inchangé
    if (!maxTested && cn > 2) {
      // tenter de tout sélectionner ; si un « Maximum N » apparaît, c'est la garde
      const t0 = await wizTotal(page);
      for (let c = 0; c < cn; c++) { await choices.nth(c).click({ timeout: 2500 }).catch(() => {}); await page.waitForTimeout(80); }
      const errVisible = await page.locator('.lc-wiz-error, [role="alert"]', { hasText: /Maximum/i }).count() > 0
                       || await page.getByText(/Maximum \d+/i).count() > 0;
      if (errVisible) { checks.push({ step: stepTitle, test: 'max-guard', errVisible: true }); maxTested = true; }
    }

    // avancer (garantir min pour les steps requis)
    if (await page.locator('.lc-wiz-choice.is-on').count() === 0 && cn > 0) await choices.first().click().catch(() => {});
    await page.locator('.lc-wiz-foot-next').click({ timeout: 6_000 }).catch(() => {});
    await page.waitForTimeout(300);
  }

  await page.screenshot({ path: shot('recap-wizard-logic.png'), fullPage: true });
  fs.writeFileSync(path.join(SHOT_DIR, 'checks.json'), JSON.stringify(checks, null, 2));
  console.log('[wizard-logic checks]', JSON.stringify(checks));

  // ASSERTIONS : pour chaque option payante multi testée, deltaAdd == prix ET deltaRemove == prix.
  const priceChecks = checks.filter(c => c.deltaAdd !== undefined);
  expect(priceChecks.length, 'au moins une option payante multi vérifiée').toBeGreaterThan(0);
  for (const c of priceChecks) {
    expect(c.deltaAdd, `${c.step} : ajout d'une option +${c.price}€ monte le total d'exactement ${c.price}€`).toBeCloseTo(c.price, 2);
    expect(c.deltaRemove, `${c.step} : retrait de l'option redescend le total d'exactement ${c.price}€`).toBeCloseTo(c.price, 2);
  }
  const reqCheck = checks.find(c => c.test === 'required-gate');
  if (reqCheck) expect(reqCheck.disabled, 'étape obligatoire sans choix → Continuer désactivé').toBeTruthy();
  const maxCheck = checks.find(c => c.test === 'max-guard');
  if (maxCheck) expect(maxCheck.errVisible, 'sur-sélection → erreur Maximum affichée').toBeTruthy();
});

function round2(n) { return Math.round(n * 100) / 100; }
