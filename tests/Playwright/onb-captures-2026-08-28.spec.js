/**
 * [ONB 2026-08-28] Captures visuelles des écrans modifiés cette session.
 *
 * CLAUDE.md §6 : « Un test technique vert ne prouve PAS que l'UI est OK. »
 * Toute cette session s'est appuyée sur des bancs dont j'ai cassé la garde pour
 * vérifier qu'ils virent au rouge — c'est plus fort qu'un test vert seul, mais ce
 * n'est pas l'œil sur l'écran. Ce fichier corrige ce manque.
 *
 * L'écran le plus important à regarder est `/admin/items/import-carte` : je l'ai
 * construit entièrement sans jamais le voir rendu.
 *
 * LECTURE SEULE : aucune donnée n'est créée, aucun formulaire n'est soumis. On
 * ouvre, on attend le rendu, on capture.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsAdmin } = require('../e2e/helpers/login');

const SORTIE = path.join(__dirname, '..', 'captures', 'onb-2026-08-28');

/** Laisse la SPA finir de peindre avant la capture. */
async function poser(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1200);
}

test.describe('ONB · captures des écrans modifiés', () => {
    test.setTimeout(120_000);

    test('écran de connexion (message de repli traduit)', async ({ page }) => {
        await page.goto('/login', { waitUntil: 'domcontentloaded' });
        await poser(page);
        await page.screenshot({ path: path.join(SORTIE, '01-connexion.png'), fullPage: true });
    });

    test('import de carte par photo — ONB-04, écran neuf', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/items/import-carte', { waitUntil: 'domcontentloaded' });
        await poser(page);
        await page.screenshot({ path: path.join(SORTIE, '02-import-carte.png'), fullPage: true });

        // Contrôle minimal : le titre doit être rendu, pas une clé brute.
        const contenu = await page.content();
        expect(contenu).not.toContain('label.menu_import_title');
    });

    test('catalogue — tuiles et liste', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
        await poser(page);
        await page.screenshot({ path: path.join(SORTIE, '03-catalogue.png'), fullPage: true });
    });

    test('Studio catalogue', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/items/studio', { waitUntil: 'domcontentloaded' });
        await poser(page);
        await page.screenshot({ path: path.join(SORTIE, '04-studio.png'), fullPage: true });
    });

    test('imprimantes — statut normalisé', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/settings/printers', { waitUntil: 'domcontentloaded' });
        await poser(page);
        await page.screenshot({ path: path.join(SORTIE, '05-imprimantes.png'), fullPage: true });
    });

    test('réglages du site — écran rendu inenregistrable puis réparé', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/settings/site', { waitUntil: 'domcontentloaded' });
        await poser(page);
        await page.screenshot({ path: path.join(SORTIE, '06-reglages-site.png'), fullPage: true });
    });
});
