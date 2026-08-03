// Mandat visuel §6 — split formule 3 pages (LOCK frozen). Parcours borne réel local :
// sandwich → étapes → PAGE 1 formule (3 cartes + prix) → PAGE 2 boissons dédiée → PAGE 3 sauce frites.
const { test, expect } = require('@playwright/test');
const path = require('path'); const fs = require('fs');
const SHOT = path.join(__dirname, '__screenshots__', 'formule-split-2026-07-22');
fs.mkdirSync(SHOT, { recursive: true });
test.setTimeout(180_000);

test('BORNE — formule 3 pages dédiées (captures)', async ({ page }) => {
  await page.setViewportSize({ width: 1080, height: 1920 });
  const errs = []; page.on('pageerror', e => errs.push(e.message));

  // borne locale (auto-login local bypass)
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.waitForTimeout(2500);
  // toucher pour démarrer (idle touch-anywhere)
  await page.mouse.click(540, 960); await page.waitForTimeout(2000);
  await page.screenshot({ path: path.join(SHOT, '00-categories.png') });

  // ouvrir un sandwich (Cayenne) — tuiles produit
  const prod = page.locator('text=/^Cayenne$/').first();
  if (await prod.isVisible({ timeout: 5000 }).catch(()=>0)) await prod.click();
  else {
    const sand = page.locator('text=/Sandwich/i').first();
    if (await sand.isVisible({ timeout: 3000 }).catch(()=>0)) { await sand.click(); await page.waitForTimeout(1200); }
    await page.locator('text=/^Cayenne$/').first().click({ timeout: 8000 });
  }
  await page.waitForTimeout(1800);

  // avancer les étapes jusqu'à la page FORMULE (sélectionner le 1er choix si requis)
  let sawFormule = false, sawBoisson = false, sawFrites = false;
  for (let s = 0; s < 16; s++) {
    await page.waitForTimeout(900);
    const body = await page.locator('body').innerText().catch(()=> '');
    const isFormule = /Menu complet/i.test(body) && !/QUELLE BOISSON/i.test(body) && !/SAUCE POUR LES FRITES/i.test(body);
    const isBoissonPage = /QUELLE BOISSON/i.test(body);
    const isFritesSauce = /SAUCE POUR LES FRITES/i.test(body);
    if (isFormule && !sawFormule) {
      sawFormule = true;
      await page.screenshot({ path: path.join(SHOT, '01-page1-formule.png') });
      // vérifier les 3 prix
      expect(body).toContain('2,50');
      await page.locator('text=/Menu complet/i').first().click(); await page.waitForTimeout(600);
    } else if (isBoissonPage && !sawBoisson) {
      sawBoisson = true;
      await page.screenshot({ path: path.join(SHOT, '02-page2-boissons.png') });
      await page.locator('text=/Coca/i').first().click(); await page.waitForTimeout(600);
    } else if (isFritesSauce && !sawFrites) {
      sawFrites = true;
      await page.screenshot({ path: path.join(SHOT, '03-page3-sauce-frites.png') });
      const opt = page.locator('text=/Ketchup|Mayonnaise|Sans sauce/i').first();
      if (await opt.isVisible({ timeout: 2000 }).catch(()=>0)) { await opt.click(); await page.waitForTimeout(500); }
    } else {
      // étape composition : choisir la 1re option cliquable si le CTA est gaté
      const anyChoice = page.locator('[role="radio"], [role="checkbox"], .kiosk-choice, button.kiosk-card').first();
      if (await anyChoice.isVisible({ timeout: 1200 }).catch(()=>0)) await anyChoice.click().catch(()=>{});
    }
    if (sawFormule && sawBoisson && sawFrites) break;
    // Continuer
    const next = page.locator('button:has-text("Continuer"), button:has-text("Suivant"), [data-testid="wizard-next"]').last();
    if (await next.isVisible({ timeout: 1500 }).catch(()=>0)) await next.click().catch(()=>{});
  }
  console.log(`[SPLIT] formule=${sawFormule} boissons=${sawBoisson} sauceFrites=${sawFrites} · erreurs JS=${errs.length}`);
  expect(sawFormule, 'page 1 formule vue').toBeTruthy();
  expect(sawBoisson, 'page 2 boissons dédiée vue').toBeTruthy();
  expect(sawFrites, 'page 3 sauce frites dédiée vue').toBeTruthy();
  expect(errs.filter(e=>!/ResizeObserver/i.test(e)).length, '0 erreur JS').toBe(0);
});
