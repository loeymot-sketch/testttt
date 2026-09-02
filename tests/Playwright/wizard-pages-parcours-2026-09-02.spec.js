// [GOAL DASHBOARD-PILOTABLE 2026-09-02] Parcours réel « en tant qu'admin » sur la bibliothèque de
// pages de wizard : ouvrir une page et voir ses choix + prix, ouvrir le composeur d'une catégorie,
// sélectionner une page reliée (ses choix doivent s'afficher, pas un sélecteur de source vide),
// et ouvrir la bibliothèque depuis « Ajouter une page ».
//
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//   CAPTURE_DIR=/sortie npx playwright test tests/Playwright/wizard-pages-parcours-2026-09-02.spec.js
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { login } = require('../e2e/helpers/login');
const { clearFoodKingRateLimits } = require('../e2e/helpers/rate-limit');

const OUT = process.env.CAPTURE_DIR
    || path.join(__dirname, '..', '..', 'storage', 'captures', 'wizard-pages-parcours-2026-09-02');

test.describe.configure({ mode: 'serial' });
test.setTimeout(240_000);

test('la bibliothèque de pages se pilote depuis le Dashboard', async ({ page }) => {
    fs.mkdirSync(OUT, { recursive: true });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.emulateMedia({ reducedMotion: 'reduce' });

    clearFoodKingRateLimits();
    await login(page, process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr', process.env.E2E_ADMIN_PASS || '123456');

    // 1. Bibliothèque : ouvrir « Choisis tes garnitures » → ses choix et leurs prix sont éditables.
    await page.goto('/admin/wizard-pages', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-testid="wizard-pages-page"]');
    await page.getByText('Choisis tes garnitures', { exact: true }).first().click();
    await page.waitForSelector('[data-testid="wizard-page-choice-0"]');
    const choiceRows = await page.locator('[data-testid="wizard-page-choice-0"]').count();
    expect(choiceRows).toBeGreaterThan(0);
    await page.screenshot({ path: path.join(OUT, '01-page-garnitures.png'), fullPage: true });

    // 2. Composeur Tacos : sélectionner une page reliée → ses choix s'affichent (pas un sélecteur vide).
    await page.goto('/admin/categories/5/composer', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-testid="admin-composer-root"]');
    await page.waitForTimeout(2500);
    await page.getByText('Choisis tes garnitures', { exact: true }).first().click();
    await page.waitForSelector('[data-testid="composer-step-page-block"]', { timeout: 15_000 });
    await page.screenshot({ path: path.join(OUT, '02-composeur-page-reliee.png'), fullPage: true });

    // 3. « Ajouter une page » ouvre la bibliothèque, avec les pages déjà utilisées marquées.
    await page.locator('[data-testid="admin-composer-add-step"]').click();
    await page.waitForSelector('[data-testid="composer-page-library-modal"]');
    await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(OUT, '03-modale-bibliotheque.png'), fullPage: false });

    // 4. En-tête : ce que la caisse lit réellement + couverture produits.
    const runtime = await page.locator('[data-testid="admin-composer-runtime"]').innerText();
    fs.writeFileSync(path.join(OUT, 'runtime.txt'), runtime);
    expect(runtime).toContain('En caisse');
});

// Création → modification → suppression d'une page, par les boutons de l'écran. C'est la phrase du
// propriétaire prise au mot : « si demain j'ai une nouvelle catégorie, je crée les pages qu'il me
// faut ». On repart d'une base propre à la fin : la page d'essai est supprimée.
test('une page se cree, se modifie et se supprime depuis l ecran', async ({ page }) => {
    fs.mkdirSync(OUT, { recursive: true });
    await page.setViewportSize({ width: 1440, height: 1000 });
    const nom = `Essai parcours ${Date.now()}`;

    clearFoodKingRateLimits();
    await login(page, process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr', process.env.E2E_ADMIN_PASS || '123456');
    await page.goto('/admin/wizard-pages', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-testid="wizard-pages-page"]');

    await page.locator('[data-testid="wizard-pages-add"]').click();
    await page.locator('[data-testid="wizard-page-label"]').fill(nom);
    await page.locator('[data-testid="wizard-page-max"]').fill('3');
    await page.locator('[data-testid="wizard-page-add-choice"]').click();
    await page.locator('[data-testid="wizard-page-choice-name-0"]').fill('Cheddar');
    await page.locator('[data-testid="wizard-page-choice-price-0"]').fill('0.9');
    await page.locator('[data-testid="wizard-page-save"]').click();
    await expect(page.locator('[data-testid="wizard-pages-feedback"]')).toBeVisible({ timeout: 15_000 });

    // Elle est dans la bibliothèque, avec son choix — donc réutilisable par n'importe quelle catégorie.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-testid="wizard-pages-page"]');
    const ligne = page.getByText(nom, { exact: true }).first();
    await expect(ligne).toBeVisible();
    await ligne.click();
    await expect(page.locator('[data-testid="wizard-page-choice-name-0"]')).toHaveValue('Cheddar');
    await page.screenshot({ path: path.join(OUT, '04-page-creee.png'), fullPage: true });

    // Modification : le nom change et il tient après rechargement.
    await page.locator('[data-testid="wizard-page-label"]').fill(`${nom} modifiee`);
    await page.locator('[data-testid="wizard-page-save"]').click();
    await page.waitForTimeout(1200);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-testid="wizard-pages-page"]');
    await expect(page.getByText(`${nom} modifiee`, { exact: true }).first()).toBeVisible();

    // Suppression : la page d'essai disparaît (aucune catégorie ne l'utilise).
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByText(`${nom} modifiee`, { exact: true }).first().click();
    await page.locator('[data-testid="wizard-page-delete"]').click();
    await page.waitForTimeout(1500);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-testid="wizard-pages-page"]');
    await expect(page.getByText(`${nom} modifiee`, { exact: true })).toHaveCount(0);
});
