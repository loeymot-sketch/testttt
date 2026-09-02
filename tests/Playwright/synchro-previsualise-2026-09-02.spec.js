// [GOAL DASHBOARD-PILOTABLE 2026-09-02] « Synchroniser les produits » aligne les produits sur les
// pages de la catégorie : un prix saisi à la main sur un produit reprend celui de la page. C'était
// immédiat, sans aperçu ni retour arrière. Ce banc prouve qu'un écart déclenche désormais une
// prévisualisation nommant la ligne concernée, et qu'« Annuler » n'écrit rien.
//
// Le décor (un prix modifié sur une variation Tacos) est posé et retiré par le test lui-même via
// `php artisan tinker`, pour que la base de développement soit rendue telle qu'elle était.
//
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//   npx playwright test tests/Playwright/synchro-previsualise-2026-09-02.spec.js
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { login } = require('../e2e/helpers/login');
const { clearFoodKingRateLimits } = require('../e2e/helpers/rate-limit');

const REPO = path.join(__dirname, '..', '..');
const OUT = process.env.CAPTURE_DIR
    || path.join(REPO, 'storage', 'captures', 'synchro-previsualise-2026-09-02');

function tinker(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        cwd: REPO,
        encoding: 'utf8',
        timeout: 120_000,
    }).trim();
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(240_000);

test('un ecart de prix declenche un apercu avant d ecraser', async ({ page }) => {
    fs.mkdirSync(OUT, { recursive: true });

    // Décor : on désaligne UNE variation d'un produit Tacos par rapport à sa page.
    const setup = tinker(`
        $v = \\App\\Models\\ItemVariation::query()
            ->whereIn('item_id', \\App\\Models\\Item::withoutGlobalScopes()->where('item_category_id', 5)->pluck('id'))
            ->whereNull('deleted_at')->orderBy('id')->first();
        echo $v ? $v->id.'|'.$v->price.'|'.$v->name : 'AUCUNE';
        if ($v) { $v->forceFill(['price' => 7.77])->save(); }
    `);
    const [variationId, prixOrigine, nomChoix] = setup.split('\n').pop().split('|');
    expect(variationId, 'aucune variation Tacos en base').not.toBe('AUCUNE');

    try {
        await page.setViewportSize({ width: 1440, height: 1000 });
        clearFoodKingRateLimits();
        await login(page, process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr', process.env.E2E_ADMIN_PASS || '123456');

        await page.goto('/admin/categories/5/composer', { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('[data-testid="admin-composer-sync-products"]', { timeout: 30_000 });
        await page.locator('[data-testid="admin-composer-sync-products"]').click();

        // L'aperçu s'ouvre AVANT toute écriture, et nomme la ligne qui serait réécrite.
        const apercu = page.locator('[data-testid="composer-sync-preview"]');
        await expect(apercu).toBeVisible({ timeout: 30_000 });
        await expect(page.locator('[data-testid="composer-sync-preview-lines"]')).toContainText(nomChoix);
        await page.screenshot({ path: path.join(OUT, '01-apercu-synchro.png'), fullPage: false });

        // « Annuler » n'écrit rien : le prix désaligné est toujours là.
        await page.locator('[data-testid="composer-sync-cancel"]').click();
        await expect(apercu).toHaveCount(0);
        const apresAnnulation = tinker(`echo \\App\\Models\\ItemVariation::find(${variationId})->price;`).split('\n').pop();
        expect(Number(apresAnnulation), 'Annuler a quand même écrit').toBeCloseTo(7.77, 2);

        // Confirmer applique : le prix rejoint celui de la page.
        await page.locator('[data-testid="admin-composer-sync-products"]').click();
        await expect(apercu).toBeVisible({ timeout: 30_000 });
        await page.locator('[data-testid="composer-sync-confirm"]').click();
        await expect(apercu).toHaveCount(0, { timeout: 60_000 });
        await page.waitForTimeout(2000);
        const apresConfirmation = tinker(`echo \\App\\Models\\ItemVariation::find(${variationId})->price;`).split('\n').pop();
        expect(Number(apresConfirmation), 'confirmer n’a rien appliqué').not.toBeCloseTo(7.77, 2);
    } finally {
        // La base de développement est rendue telle qu'elle était.
        tinker(`\\App\\Models\\ItemVariation::find(${variationId})->forceFill(['price' => ${prixOrigine}])->save(); echo 'restaure';`);
    }
});
