/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P1 « Qui ? sans code »] E2E réel : le responsable donne un code à une employée (écran « Codes de
 * l'équipe »), la tablette demande ce code pour signer à son nom (faux code refusé, bon code accepté), un relevé gardé HORS LIGNE reste signé
 * grâce au jeton (le code n'est jamais gardé), et cinq faux codes bloquent la personne.
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/Playwright/hygiene-code-equipe-2026-10-18.spec.js
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURES || path.join(__dirname, '..', 'captures', 'hygiene-code-equipe-2026-10-18');
fs.mkdirSync(OUT, { recursive: true });
const NAME = 'E2E Pin Sarah Lopez';

function tinker(php) {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', php], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    if (/error|exception/i.test(out)) throw new Error('tinker : ' + out);
    return out;
}
const cleanup = () => tinker(`
    $ids = DB::table('users')->where('name', '${NAME}')->pluck('id');
    DB::table('hygiene_operator_pins')->whereIn('user_id', $ids)->delete();
    foreach (['hygiene_temperature_readings','hygiene_equipments'] as $t) DB::table($t)->delete();
    DB::table('model_has_roles')->whereIn('model_id', $ids)->where('model_type', 'App\\\\Models\\\\User')->delete();
    DB::table('users')->whereIn('id', $ids)->delete();`);

test.setTimeout(240_000);
let uid;
test.beforeAll(() => {
    cleanup();
    uid = Number(tinker(`$u = App\\Models\\User::factory()->create(['name' => '${NAME}', 'branch_id' => 1]); $u->assignRole('Chef'); echo $u->id;`).trim().match(/\d+/)[0]);
    tinker(`DB::table('hygiene_equipments')->insert(['branch_id'=>1,'name'=>'Frigo 1','kind'=>'fridge','min_c'=>0,'max_c'=>4,'sort_order'=>10,'is_active'=>1,'created_at'=>now()->subDays(5),'updated_at'=>now()->subDays(5)]);`);
});
test.afterAll(cleanup);

test('code personnel : défini par le responsable, demandé sur la tablette, jeton hors ligne, blocage après 5 faux codes', async ({ page, context }) => {
    const bad = [];
    page.on('pageerror', (e) => { if (!/^(Failed to fetch|Network Error)$/.test(e.message)) bad.push(`pageerror: ${e.message}`); });
    await page.setViewportSize({ width: 1280, height: 800 });
    await loginAsAdmin(page);

    // 1. le responsable donne un code
    await page.goto('/admin/hygiene/home', { waitUntil: 'domcontentloaded' });
    await page.getByTestId('home-team-link').click({ timeout: 30_000 });
    await expect(page.getByTestId(`tm-state-${uid}`)).toHaveText('Sans code', { timeout: 20_000 });
    await page.screenshot({ path: path.join(OUT, 'a1-equipe-sans-code.png') });
    await page.getByTestId(`tm-set-${uid}`).click();
    await page.getByTestId(`tm-input-${uid}`).fill('4821');
    await page.getByTestId(`tm-confirm-${uid}`).fill('4821');
    await page.getByTestId(`tm-save-${uid}`).click();
    await expect(page.getByTestId('tm-message')).toContainText('enregistré', { timeout: 15_000 });
    await expect(page.getByTestId(`tm-state-${uid}`)).toHaveText('Code défini');
    await page.screenshot({ path: path.join(OUT, 'a2-equipe-code-defini.png') });
    // le code n'est jamais rendu : ni à l'écran, ni dans le stockage, ni en base en clair
    expect(await page.content()).not.toContain('4821');
    expect(tinker(`echo DB::table('hygiene_operator_pins')->where('user_id', ${uid})->value('pin_hash');`)).not.toContain('4821');

    // 2. la tablette : faux code refusé, bon code accepté
    await page.goto('/admin/hygiene/temperature', { waitUntil: 'domcontentloaded' });
    const card = page.locator('.tmp-card', { hasText: 'Frigo 1' });
    await expect(card).toBeVisible({ timeout: 30_000 });
    const eqId = (await card.getAttribute('data-testid')).replace('tmp-card-', '');
    const pick = async (code) => {
        await page.getByTestId('hy-operator-btn').click();
        await page.getByTestId(`hy-operator-${uid}`).click();
        await expect(page.getByTestId('hy-pin')).toBeVisible();
        await page.getByTestId('hy-pin-input').fill(code);
        await page.getByTestId('hy-pin-ok').click();
    };
    await pick('0000');
    await expect(page.getByTestId('hy-pin-error')).toContainText('4 essais restants', { timeout: 15_000 });
    await page.screenshot({ path: path.join(OUT, 'b1-faux-code.png') });
    await page.getByTestId('hy-pin-input').fill('4821');
    await page.getByTestId('hy-pin-ok').click();
    await expect(page.getByTestId('hy-operator-btn')).toContainText('E2E L.', { timeout: 15_000 });

    await page.locator(`[data-testid="tmp-take-${eqId}"]`).click();
    await page.locator(`[data-testid="tmp-input-${eqId}"]`).fill('3');
    await page.locator('[data-testid="tmp-submit"]').click();
    await expect(page.getByTestId(`tmp-done-${eqId}`)).toContainText('Sarah Lopez', { timeout: 15_000 });
    expect(tinker(`echo DB::table('hygiene_temperature_readings')->orderByDesc('id')->value('operator_name');`)).toContain(NAME);
    await page.screenshot({ path: path.join(OUT, 'b2-signe-sarah.png') });

    // 3. hors ligne : le jeton (valable 10 min) couvre la mesure, le code n'est jamais gardé
    await page.locator(`[data-testid="tmp-again-${eqId}"]`).click();
    await context.setOffline(true);
    await page.locator(`[data-testid="tmp-input-${eqId}"]`).fill('2,5');
    await page.locator('[data-testid="tmp-submit"]').click();
    await expect(page.getByTestId('tmp-offline')).toBeVisible({ timeout: 20_000 });
    const queue = await page.evaluate(() => localStorage.getItem('hygiene_offline_queue') || '');
    expect(queue).toContain('operator_token');
    expect(queue).not.toContain('4821');
    expect(queue).not.toContain('operator_pin');
    await page.waitForTimeout(2000);
    await context.setOffline(false);
    await expect(page.getByTestId('tmp-offline')).toHaveCount(0, { timeout: 40_000 });
    const rows = JSON.parse(tinker(`echo json_encode(DB::table('hygiene_temperature_readings')->orderBy('id')->get(['value_c','operator_name','source']));`).trim());
    expect(rows).toHaveLength(2);
    expect(rows.map((r) => r.operator_name)).toEqual([NAME, NAME]);
    expect(rows[1].source).toBe('offline');

    // 4. cinq faux codes : la personne est bloquée, même pour le bon code
    await page.reload();
    await expect(page.locator('.tmp-card', { hasText: 'Frigo 1' })).toBeVisible({ timeout: 30_000 });
    await pick('1111'); // 1er faux code ; la saisie du code reste ouverte pour les suivants
    await expect(page.getByTestId('hy-pin-error')).toContainText('incorrect', { timeout: 15_000 });
    for (let i = 0; i < 3; i += 1) {
        await page.getByTestId('hy-pin-input').fill('1111');
        await page.getByTestId('hy-pin-ok').click();
        await expect(page.getByTestId('hy-pin-error')).toContainText('incorrect', { timeout: 15_000 });
    }
    await page.getByTestId('hy-pin-input').fill('1111');
    await page.getByTestId('hy-pin-ok').click();
    await expect(page.getByTestId('hy-pin-error')).toContainText('bloqué', { timeout: 15_000 });
    await page.getByTestId('hy-pin-input').fill('4821');
    await page.getByTestId('hy-pin-ok').click();
    await expect(page.getByTestId('hy-pin-error')).toContainText('bloqué', { timeout: 15_000 });
    await page.screenshot({ path: path.join(OUT, 'c1-bloque.png') });

    expect(bad, bad.join('\n')).toEqual([]);
});
