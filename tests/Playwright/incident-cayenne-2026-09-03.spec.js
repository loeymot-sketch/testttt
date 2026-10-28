// [INCIDENT 2026-09-03] « j'arrive pas à mettre un cayenne et j'ai choisi toute personnalisation »
// On ouvre la caisse, on choisit un Cayenne, on remplit CHAQUE étape du wizard, et on tente
// l'ajout au panier — en relevant tout ce que l'écran dit ou tait.
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { login } = require('../e2e/helpers/login');
const { clearFoodKingRateLimits } = require('../e2e/helpers/rate-limit');

const OUT = process.env.CAPTURE_DIR || path.join(__dirname, '..', '..', 'storage', 'captures', 'incident-cayenne');

test.setTimeout(240_000);

test('ajouter un Cayenne entièrement personnalisé', async ({ page }) => {
    fs.mkdirSync(OUT, { recursive: true });
    await page.setViewportSize({ width: 1440, height: 900 });

    const consoleErrs = [];
    const badHttp = [];
    page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) consoleErrs.push(`${m.type()}: ${m.text().slice(0, 250)}`); });
    page.on('pageerror', (e) => consoleErrs.push(`pageerror: ${String(e).slice(0, 250)}`));
    page.on('response', (r) => { if (r.status() >= 400) badHttp.push(`${r.status()} ${r.request().method()} ${r.url().slice(0, 160)}`); });

    clearFoodKingRateLimits();
    await login(page, process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr', process.env.E2E_ADMIN_PASS || '123456');
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => {});
    await page.waitForTimeout(2500);
    await page.screenshot({ path: path.join(OUT, '01-caisse.png'), fullPage: true });

    // La grille montre des CATÉGORIES : on passe par la recherche du menu.
    const recherche = page.locator('input[placeholder*="echerch"], input[placeholder*="Recherch"]').first();
    await recherche.click().catch(() => {});
    await recherche.fill('Cayenne').catch(() => {});
    await page.waitForTimeout(2000);
    await page.screenshot({ path: path.join(OUT, '02-recherche.png'), fullPage: true });

    const resultats = page.locator('text=/Cayenne/i');
    const nbResultats = await resultats.count().catch(() => 0);
    fs.writeFileSync(path.join(OUT, 'trace.txt'), `resultats Cayenne: ${nbResultats}\n`);
    await resultats.nth(Math.min(1, nbResultats - 1)).click({ timeout: 15_000 }).catch(async () => {
        await resultats.first().click({ timeout: 10_000 }).catch(() => {});
    });
    await page.waitForTimeout(3000);
    await page.screenshot({ path: path.join(OUT, '03-wizard-ouvert.png'), fullPage: true });

    // Remplir chaque étape : cliquer la 1re option de chaque groupe visible
    for (let tour = 0; tour < 8; tour++) {
        const options = page.locator('.wizard-step.active .choice, .wizard-step.active .option, .wizard-step.active label, .wizard-step.active .viande-btn');
        const n = await options.count().catch(() => 0);
        if (n > 0) { await options.first().click().catch(() => {}); }
        const suivant = page.locator('button:has-text("Suivant"), button:has-text("Continuer"), .wizard-next');
        if (await suivant.first().isVisible().catch(() => false)) {
            await suivant.first().click().catch(() => {});
            await page.waitForTimeout(700);
        } else { break; }
    }
    await page.screenshot({ path: path.join(OUT, '04-wizard-rempli.png'), fullPage: true });

    // Tenter l'ajout
    const ajouter = page.locator('button:has-text("Ajouter"), button:has-text("Valider"), .wizard-add-to-cart');
    const dispo = await ajouter.first().isVisible().catch(() => false);
    const actif = dispo ? await ajouter.first().isEnabled().catch(() => false) : false;
    if (dispo && actif) { await ajouter.first().click().catch(() => {}); }
    await page.waitForTimeout(2500);
    await page.screenshot({ path: path.join(OUT, '05-apres-ajout.png'), fullPage: true });

    const texte = await page.evaluate(() => (document.body ? document.body.innerText : '')).catch(() => '');
    fs.appendFileSync(path.join(OUT, 'trace.txt'),
        `bouton ajouter visible=${dispo} actif=${actif}\n` +
        `--- ERREURS CONSOLE ---\n${consoleErrs.join('\n')}\n` +
        `--- HTTP >= 400 ---\n${badHttp.join('\n')}\n` +
        `--- TEXTE ECRAN (extrait) ---\n${texte.slice(0, 3000)}\n`);
    expect(true).toBe(true);
});
