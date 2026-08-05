// REPRO P0 : page blanche borne sur étapes viandes/crudités (owner 2026-07-25).
// Capture l'ERREUR CONSOLE exacte (racine) + screenshot de l'étape blanche.
const { test, expect } = require('@playwright/test');
const path = require('path'); const fs = require('fs');
const SHOT = path.join(__dirname, '__screenshots__', 'borne-blank-repro-2026-07-25');
fs.mkdirSync(SHOT, { recursive: true });
test.setTimeout(180_000);

test('BORNE — reproduire page blanche viande/crudités + erreur console', async ({ page }) => {
  await page.setViewportSize({ width: 1080, height: 1920 });
  const errors = [];
  page.on('pageerror', e => errors.push('PAGEERROR: ' + e.message + (e.stack ? '\n' + e.stack.split('\n').slice(0,4).join('\n') : '')));
  page.on('console', m => { if (m.type() === 'error') errors.push('CONSOLE.ERROR: ' + m.text()); });

  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.waitForTimeout(2500);
  await page.mouse.click(540, 960); await page.waitForTimeout(2000); // idle touch
  await page.screenshot({ path: path.join(SHOT, '00-categories.png') });

  // ouvrir un produit AVEC viande + crudités (tacos ou sandwich à viande). Essaie plusieurs.
  const candidates = ['Tacos', 'Suprême', 'Mixte', 'Cayenne', 'Cheese', 'Big'];
  let opened = false;
  for (const name of candidates) {
    const tile = page.locator(`text=/${name}/i`).first();
    if (await tile.isVisible({ timeout: 2500 }).catch(()=>false)) {
      await tile.click(); await page.waitForTimeout(1500);
      // si un sous-menu catégorie s'ouvre, re-cliquer le produit
      if (await page.locator('text=/QUEL PAIN|QUELLE|QUEL |Ajouter au panier|VOUS COMPOSEZ/i').first().isVisible({ timeout: 3000 }).catch(()=>false)) { opened = true; break; }
      const tile2 = page.locator(`text=/${name}/i`).first();
      if (await tile2.isVisible({ timeout: 2000 }).catch(()=>false)) { await tile2.click(); await page.waitForTimeout(1500); opened = true; break; }
    }
  }
  console.log('[REPRO] produit ouvert=' + opened);
  await page.screenshot({ path: path.join(SHOT, '01-wizard-start.png'), fullPage: false });

  // avancer étape par étape, détecter une étape au CONTENU VIDE (blanche)
  let blankFound = false, blankStep = '';
  for (let s = 0; s < 14; s++) {
    await page.waitForTimeout(900);
    const body = await page.locator('body').innerText().catch(()=> '');
    // le contenu de l'étape = zone sous le stepper. Heuristique : titre d'étape visible mais 0 option/carte rendue.
    const stepTitle = (body.match(/QUELL?E?S?\s+[A-ZÀ-Ü ]+\?|QUEL\s+[A-ZÀ-Ü ]+\?/i) || [''])[0].trim();
    const choicesCount = await page.locator('.kiosk-choice, [role="radio"], [role="checkbox"], button.kiosk-card, .kiosk-viande-tile, .kiosk-garniture, .kiosk-option').count().catch(()=>0);
    const contentText = await page.locator('.kiosk-wizard-body, .kiosk-step, [class*="step-body"], main').innerText().catch(()=> body);
    const looksBlank = choicesCount === 0 && (contentText.replace(/\s/g,'').length < 40);
    console.log(`[REPRO] étape ${s}: titre="${stepTitle}" choix=${choicesCount} blanc=${looksBlank} err=${errors.length}`);
    if (looksBlank || errors.length > 0) {
      blankFound = true; blankStep = stepTitle || `étape#${s}`;
      await page.screenshot({ path: path.join(SHOT, `blank-${s}.png`) });
      break;
    }
    await page.screenshot({ path: path.join(SHOT, `step-${s}.png`) });
    // sélectionner 1er choix si radio, sinon Suivant
    const firstChoice = page.locator('.kiosk-choice, [role="radio"], .kiosk-viande-tile, button.kiosk-card').first();
    if (await firstChoice.isVisible({ timeout: 1000 }).catch(()=>false)) await firstChoice.click().catch(()=>{});
    const next = page.locator('button:has-text("Continuer"), button:has-text("Suivant"), [data-testid="wizard-next"]').last();
    if (await next.isVisible({ timeout: 1200 }).catch(()=>false)) await next.click().catch(()=>{});
  }

  console.log('[REPRO] ===== RÉSULTAT =====');
  console.log('[REPRO] page blanche=' + blankFound + ' à: ' + blankStep);
  console.log('[REPRO] ERREURS (' + errors.length + '):');
  errors.slice(0, 6).forEach(e => console.log('  ' + e));
  fs.writeFileSync(path.join(SHOT, 'errors.txt'), errors.join('\n\n'));
});
