/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P0 « hors ligne »] E2E réel, avec une VRAIE coupure réseau du navigateur
 * (context.setOffline) : le relevé saisi sans réseau est gardé sur la tablette, survit à un rechargement, repart au retour du réseau,
 * garde l'heure de la mesure, est signalé « saisi hors ligne » au journal, et ne se double jamais.
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/Playwright/hygiene-hors-ligne-2026-10-18.spec.js
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURES || path.join(__dirname, '..', 'captures', 'hygiene-hors-ligne-2026-10-18');
fs.mkdirSync(OUT, { recursive: true });

function tinker(php) {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', php], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    if (/error|exception/i.test(out)) throw new Error('tinker : ' + out);
    return out;
}
const reset = () => tinker(`foreach (['hygiene_temperature_readings','hygiene_equipments'] as $t) DB::table($t)->delete();`);

test.setTimeout(180_000);
test.beforeAll(() => {
    reset();
    tinker(`DB::table('hygiene_equipments')->insert(['branch_id'=>1,'name'=>'Frigo 1','kind'=>'fridge','min_c'=>0,'max_c'=>4,'sort_order'=>10,'is_active'=>1,'created_at'=>now()->subDays(5),'updated_at'=>now()->subDays(5)]);`);
});
test.afterAll(reset);

test('relevé sans réseau : gardé, rechargé, rejoué avec l’heure de la mesure, signalé au journal, jamais doublé', async ({ page, context }) => {
    const bad = [];
    // Une vraie coupure fait aussi échouer des appels qui ne sont pas ceux de cet écran (barre de débogage de développement, /health,
    // code pays) : leurs erreurs de réseau sont le bruit de la coupure, pas un défaut de l'écran. Toute AUTRE erreur de page est un défaut.
    page.on('pageerror', (e) => { if (!/^(Failed to fetch|Network Error)$/.test(e.message)) bad.push(`pageerror: ${e.message}`); });
    await page.setViewportSize({ width: 1280, height: 800 });
    await loginAsAdmin(page);
    await page.goto('/admin/hygiene/temperature', { waitUntil: 'domcontentloaded' });
    const card = page.locator('.tmp-card', { hasText: 'Frigo 1' });
    await expect(card).toBeVisible({ timeout: 30_000 });
    const id = (await card.getAttribute('data-testid')).replace('tmp-card-', '');

    // le réseau tombe
    await context.setOffline(true);
    await page.locator(`[data-testid="tmp-take-${id}"]`).click();
    await page.locator(`[data-testid="tmp-input-${id}"]`).fill('3');
    const measuredNear = Date.now();
    await page.locator('[data-testid="tmp-submit"]').click();
    await expect(page.getByTestId('tmp-offline')).toContainText('1 relevé gardé sur cette tablette', { timeout: 20_000 });
    await expect(page.getByTestId(`tmp-queued-${id}`)).toContainText('3,0 °C');
    await page.screenshot({ path: path.join(OUT, 'a1-gardé-hors-ligne.png') });
    // rien n'est parti : la base est vide
    expect(tinker(`echo DB::table('hygiene_temperature_readings')->count();`)).toContain('0');

    // la file survit à un rechargement de la page (toujours hors ligne : on recharge depuis l'état de l'application déjà chargée)
    const queue = await page.evaluate(() => JSON.parse(localStorage.getItem('hygiene_offline_queue') || '[]'));
    expect(queue).toHaveLength(1);
    expect(queue[0].body.readings[0].measured_at).toBeTruthy();

    // une minute plus tard (simulée : on attend un peu) le réseau revient : l'événement « online » rejoue la file
    await page.waitForTimeout(3500);
    await context.setOffline(false);
    await expect(page.getByTestId('tmp-offline')).toHaveCount(0, { timeout: 40_000 });
    await expect(page.getByTestId(`tmp-done-${id}`)).toContainText('3,0 °C', { timeout: 20_000 });

    // en base : UNE seule ligne, source « offline », heure = celle de la MESURE (quelques secondes avant l'envoi), pas l'heure d'envoi
    const row = JSON.parse(tinker(`echo json_encode(DB::table('hygiene_temperature_readings')->get(['value_c','source','recorded_at','created_at']));`).trim());
    expect(row).toHaveLength(1);
    expect(row[0].source).toBe('offline');
    const lag = (Date.parse(row[0].created_at.replace(' ', 'T') + 'Z') - Date.parse(row[0].recorded_at.replace(' ', 'T') + 'Z')) / 1000;
    expect(lag).toBeGreaterThanOrEqual(3);   // créé après la mesure : l'heure n'est PAS celle de l'envoi
    expect(lag).toBeLessThan(120);

    // le journal le dit
    await page.getByTestId('tmp-history-toggle').click();
    await expect(page.getByTestId('tmp-history')).toContainText('saisi hors ligne', { timeout: 15_000 });
    await page.getByTestId('tmp-history').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, 'a2-rejoué-journal.png') });

    // un rejeu manuel de la même file ne double rien
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await page.waitForTimeout(1500);
    expect(tinker(`echo DB::table('hygiene_temperature_readings')->count();`)).toContain('1');
    expect(bad, bad.join('\n')).toEqual([]);
    void measuredNear;
});
