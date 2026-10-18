/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P1 « relevé de suivi après écart »] E2E réel : un frigo à 9 °C avec « porte refermée » annonce la
 * nouvelle mesure ; passé le délai, l'accueil lève un rappel urgent et la carte passe en rouge ; une nouvelle mesure conforme clôt l'attente.
 * Le temps est avancé en reculant l'heure d'enregistrement du relevé d'écart en base (pas d'horloge truquée côté serveur).
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/Playwright/hygiene-suivi-ecart-2026-10-18.spec.js
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURES || path.join(__dirname, '..', 'captures', 'hygiene-suivi-ecart-2026-10-18');
fs.mkdirSync(OUT, { recursive: true });
function tinker(php) {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', php], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    if (/error|exception/i.test(out)) throw new Error('tinker : ' + out);
    return out;
}
const reset = () => tinker(`foreach (['hygiene_temperature_readings','hygiene_equipments'] as $t) DB::table($t)->delete();`);

test.setTimeout(120_000);
test.beforeAll(() => {
    reset();
    tinker(`DB::table('hygiene_equipments')->insert(['branch_id'=>1,'name'=>'Frigo 1','kind'=>'fridge','min_c'=>0,'max_c'=>4,'sort_order'=>10,'is_active'=>1,'created_at'=>now()->subDays(5),'updated_at'=>now()->subDays(5)]);`);
});
test.afterAll(reset);

test('écart traité par « porte refermée » : nouvelle mesure annoncée, rappel urgent passé le délai, clôturée par une mesure conforme', async ({ page }) => {
    const bad = [];
    page.on('pageerror', (e) => bad.push(`pageerror: ${e.message}`));
    await page.setViewportSize({ width: 1280, height: 800 });
    await loginAsAdmin(page);
    await page.goto('/admin/hygiene/temperature', { waitUntil: 'domcontentloaded' });
    const card = page.locator('.tmp-card', { hasText: 'Frigo 1' });
    await expect(card).toBeVisible({ timeout: 30_000 });
    const id = (await card.getAttribute('data-testid')).replace('tmp-card-', '');

    await page.locator(`[data-testid="tmp-take-${id}"]`).click();
    await page.locator(`[data-testid="tmp-input-${id}"]`).fill('9');
    await page.locator(`[data-testid="tmp-action-${id}-door_closed"]`).click();
    await expect(page.getByTestId(`tmp-followup-hint-${id}`)).toContainText('dans 30 minutes');
    await page.screenshot({ path: path.join(OUT, 'a1-annonce.png') });
    await page.locator('[data-testid="tmp-submit"]').click();
    await expect(page.getByTestId(`tmp-followup-${id}`)).toContainText('Nouvelle mesure à faire avant', { timeout: 15_000 });
    await page.screenshot({ path: path.join(OUT, 'a2-attendue.png') });

    // rien d'urgent sur l'accueil tant que le délai n'est pas passé
    await page.goto('/admin/hygiene/home', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('home-banner')).toBeVisible({ timeout: 30_000 });
    expect(await page.getByTestId('home-reminders').count() ? await page.getByTestId('home-reminders').innerText() : '').not.toContain('Contrôle de suite');

    // 45 minutes plus tard (le relevé d'écart est reculé en base)
    tinker(`DB::table('hygiene_temperature_readings')->update(['recorded_at' => now()->subMinutes(45)]);`);
    await page.reload();
    await expect(page.getByTestId('home-reminders')).toContainText('Contrôle de suite à faire : « Frigo 1 » était à 9 °C', { timeout: 30_000 });
    await expect(page.getByTestId('home-tile-temperature')).toHaveClass(/is-alert/);
    await page.screenshot({ path: path.join(OUT, 'b1-accueil-rappel.png') });

    await page.goto('/admin/hygiene/temperature', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId(`tmp-followup-${id}`)).toContainText('en retard', { timeout: 30_000 });
    await page.screenshot({ path: path.join(OUT, 'b2-carte-en-retard.png') });

    // la nouvelle mesure, conforme, clôt l'attente
    await page.locator(`[data-testid="tmp-again-${id}"]`).click();
    await page.locator(`[data-testid="tmp-input-${id}"]`).fill('3');
    await page.locator('[data-testid="tmp-submit"]').click();
    await expect(page.getByTestId(`tmp-followup-${id}`)).toHaveCount(0, { timeout: 15_000 });
    await page.goto('/admin/hygiene/home', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('home-banner')).toBeVisible({ timeout: 30_000 });
    const rem = (await page.getByTestId('home-reminders').count()) ? await page.getByTestId('home-reminders').innerText() : '';
    expect(rem).not.toContain('Contrôle de suite');
    expect(bad, bad.join('\n')).toEqual([]);
});
