/**
 * [CAYENNE HYGIÈNE 2026-10-18 · demande du propriétaire n°1] E2E réel des favoris DLC : étoile, onglet, mémorisation après rechargement
 * (partagée par le serveur), préparation de la liste, sans erreur réseau. Aucune impression (pas d'imprimante : on s'arrête au panier).
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURES || path.join(__dirname, '..', 'captures', 'dlc-favoris-2026-10-18');
fs.mkdirSync(OUT, { recursive: true });
function tinker(php) {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', php], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    if (/error|exception/i.test(out)) throw new Error('tinker : ' + out);
    return out;
}
const reset = () => tinker(`DB::table('dlc_label_products')->update(['is_favorite' => 0]);`);

test.setTimeout(120_000);
test.beforeAll(reset);
test.afterAll(reset);

test('favoris DLC : étoile, onglet, mémorisés par le serveur, liste préparée d’un appui', async ({ page }) => {
    const bad = [];
    page.on('response', (r) => { if (r.status() >= 400 && /\/api\//.test(r.url())) bad.push(`${r.status()} ${r.url()}`); });
    page.on('pageerror', (e) => bad.push(`pageerror: ${e.message}`));
    await page.setViewportSize({ width: 1280, height: 800 });
    await loginAsAdmin(page);
    await page.goto('/admin/labels/dlc', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('dlc-fav-count')).toHaveText('0', { timeout: 30_000 });
    await page.getByTestId('dlc-cat-fav').click();
    await expect(page.getByTestId('dlc-fav-empty')).toBeVisible();
    await page.screenshot({ path: path.join(OUT, 'a1-aucun-favori.png') });

    await page.getByTestId('dlc-fav-go-all').click();
    const stars = page.locator('[data-testid^="dlc-fav-"][data-testid$="0"], [data-testid^="dlc-fav-"]').filter({ hasNot: page.locator('[data-testid="dlc-fav-count"]') });
    const first = page.locator('button.dlc-star').nth(0);
    const second = page.locator('button.dlc-star').nth(1);
    const names = [await page.locator('.dlc-tile-name').nth(0).innerText(), await page.locator('.dlc-tile-name').nth(1).innerText()];
    await first.click();
    await expect(first).toHaveAttribute('aria-pressed', 'true');
    await second.click();
    await expect(second).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('dlc-fav-count')).toHaveText('2');
    void stars;

    // mémorisé par le SERVEUR : rechargement complet
    await page.reload();
    await expect(page.getByTestId('dlc-fav-count')).toHaveText('2', { timeout: 30_000 });
    expect(tinker(`echo DB::table('dlc_label_products')->where('is_favorite', 1)->count();`)).toContain('2');

    await page.getByTestId('dlc-cat-fav').click();
    const shownNames = await page.locator('.dlc-tile-name').allInnerTexts();
    expect(shownNames.sort()).toEqual(names.sort());
    await page.screenshot({ path: path.join(OUT, 'a2-favoris.png') });

    // la liste préparée d'un appui : le panier annonce 2 étiquettes
    await page.getByTestId('dlc-fav-prepare').click();
    await expect(page.getByTestId('dlc-basket-total')).toContainText('2 étiquettes');
    await page.screenshot({ path: path.join(OUT, 'a3-liste-preparee.png') });
    expect(bad, bad.join('\n')).toEqual([]);
});
