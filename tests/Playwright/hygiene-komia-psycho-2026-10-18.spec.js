/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia ultra-deep + « améliore le max UI/UX psychologiquement »] E2E réel, de bout en bout, sur un restaurant NEUF :
 * le guide de mise en place avance à chaque geste réel (jamais coché à la main), les validations disent ce qu'il reste puis félicitent,
 * le repas témoin exige son service (jamais deviné), l'engagement du nettoyage est obligatoire, la journée en règle est chiffrée.
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/Playwright/hygiene-komia-psycho-2026-10-18.spec.js
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURES || path.join(__dirname, '..', 'captures', 'hygiene-komia-psycho-2026-10-18');
fs.mkdirSync(OUT, { recursive: true });
const WITNESS = 'E2E Poulet témoin';

function tinker(php) {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', php], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    if (/error|exception/i.test(out)) throw new Error('tinker : ' + out);
    return out;
}
const num = (php) => Number(tinker(php).trim().match(/-?\d+/)[0]);
const cleanup = () => tinker(`
    foreach (['hygiene_temperature_readings','hygiene_equipments','hygiene_task_completions','hygiene_task_templates','hygiene_alert_settings','hygiene_operator_pins'] as $t) DB::table($t)->delete();
    DB::table('label_print_logs')->where('kind', 'dlc')->delete();
    DB::table('dlc_label_products')->where('name', '${WITNESS}')->delete();`);

test.setTimeout(300_000);
test.beforeAll(() => {
    cleanup();
    // catalogue de départ non validé : l'étape « durées » doit rester à faire tant que personne n'a relu
    tinker(`DB::table('dlc_label_products')->update(['durations_checked_at' => null, 'duration_source' => 'Valeur de départ Cayenne']);`);
});
test.afterAll(() => { cleanup(); tinker(`DB::table('dlc_label_products')->update(['durations_checked_at' => now()]);`); });

test('restaurant neuf : guide 0/5 → progression réelle → félicitation, récompenses honnêtes, repas témoin avec service', async ({ page }) => {
    const bad = [];
    page.on('response', (r) => { if (r.status() >= 400 && /\/api\//.test(r.url())) bad.push(`${r.status()} ${r.url()}`); });
    page.on('pageerror', (e) => { if (!/^(Failed to fetch|Network Error)$/.test(e.message)) bad.push(`pageerror: ${e.message}`); });
    await page.setViewportSize({ width: 1280, height: 900 });
    await loginAsAdmin(page);
    // UNE seule fois par onglet : un effacement à chaque navigation effacerait aussi la mémoire qu'on veut éprouver (instrument faux, pas produit faux)
    await page.addInitScript(() => { try { if (!window.sessionStorage.getItem('e2e-cleared')) { window.sessionStorage.setItem('e2e-cleared', '1'); window.localStorage.removeItem('hygiene.setup.dismissed'); } } catch (_) { /* ignoré */ } });

    // A. restaurant neuf : 0/5, une seule prochaine étape
    await page.goto('/admin/hygiene/home', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('home-setup-count')).toHaveText('0/5', { timeout: 30_000 });
    await expect(page.getByTestId('home-setup-title')).toHaveText('Mettons votre registre en route');
    await expect(page.getByTestId('home-setup-next')).toContainText('Créer vos frigos et congélateurs');
    await page.screenshot({ path: path.join(OUT, 'a1-guide-0-sur-5.png'), fullPage: true });

    // B. deux gestes réels (équipements + plan) → 2/5, 40 %
    tinker(`foreach (['Frigo 1','Frigo 2'] as $i => $n) DB::table('hygiene_equipments')->insert(['branch_id'=>1,'name'=>$n,'kind'=>'fridge','min_c'=>0,'max_c'=>4,'sort_order'=>10+$i,'is_active'=>1,'created_at'=>now()->subDays(5),'updated_at'=>now()]);`);
    tinker(`DB::table('hygiene_task_templates')->insert(['branch_id'=>1,'name'=>'E2E Salle','zone'=>'Salle','checklist'=>json_encode([['key'=>'k1','label'=>'Sols'],['key'=>'k2','label'=>'Tables']]),'frequency'=>'daily','sort_order'=>10,'is_active'=>1,'created_at'=>now()->subDays(5),'updated_at'=>now()]);`);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('home-setup-count')).toHaveText('2/5', { timeout: 30_000 });
    await expect(page.getByTestId('home-setup-bar')).toHaveAttribute('aria-valuenow', '2');
    await expect(page.getByTestId('home-setup-title')).toHaveText('Votre registre prend forme');
    await expect(page.getByTestId('home-setup-next')).toContainText('Valider vos durées de conservation');
    await expect(page.getByTestId('home-setup-step-equipment')).toHaveClass(/is-done/);
    await page.screenshot({ path: path.join(OUT, 'a2-guide-2-sur-5.png'), fullPage: true });

    // C. relevés : « plus que 1 », puis « complet, merci »
    await page.goto('/admin/hygiene/temperature', { waitUntil: 'domcontentloaded' });
    const cards = page.locator('.tmp-card');
    await expect(cards).toHaveCount(2, { timeout: 30_000 });
    const ids = [];
    for (const i of [0, 1]) ids.push((await cards.nth(i).getAttribute('data-testid')).replace('tmp-card-', ''));
    await page.getByTestId(`tmp-take-${ids[0]}`).click();
    await page.getByTestId(`tmp-input-${ids[0]}`).fill('3');
    await page.getByTestId('tmp-submit').click();
    await expect(page.getByText('1 relevé enregistré ✓ — plus que 1 équipement à relever.')).toBeVisible({ timeout: 20_000 });
    await page.screenshot({ path: path.join(OUT, 'c1-releve-plus-que-1.png') });
    await page.getByTestId(`tmp-take-${ids[1]}`).click();
    await page.getByTestId(`tmp-input-${ids[1]}`).fill('2');
    await page.getByTestId('tmp-submit').click();
    await expect(page.getByText(/1 relevé enregistré ✓ — relevé .* complet, merci !/)).toBeVisible({ timeout: 20_000 });
    await page.screenshot({ path: path.join(OUT, 'c2-releve-complet.png') });

    // D. nettoyage : l'engagement est OBLIGATOIRE, puis « bravo » à la dernière fiche
    const tplId = num(`echo DB::table('hygiene_task_templates')->value('id');`);
    await page.goto('/admin/hygiene/tasks', { waitUntil: 'domcontentloaded' });
    await page.getByTestId(`tsk-open-${tplId}`).click({ timeout: 30_000 });
    await page.getByTestId(`tsk-all-${tplId}`).click();
    await expect(page.getByTestId(`tsk-submit-${tplId}`)).toBeDisabled();
    await page.screenshot({ path: path.join(OUT, 'd1-nettoyage-engagement-requis.png') });
    await page.getByTestId(`tsk-attest-${tplId}`).click();
    await expect(page.getByTestId(`tsk-submit-${tplId}`)).toBeEnabled();
    await page.getByTestId(`tsk-submit-${tplId}`).click();
    await expect(page.getByText('Toutes les fiches du jour sont faites, bravo !')).toBeVisible({ timeout: 20_000 });
    await page.screenshot({ path: path.join(OUT, 'd2-nettoyage-bravo.png') });
    expect(num(`echo DB::table('hygiene_task_completions')->whereNotNull('attested_at')->count();`)).toBe(1);

    // E. repas témoin : la fiche s'ouvre (jamais d'impression à l'aveugle), le service est obligatoire
    tinker(`$id = DB::table('dlc_label_products')->insertGetId(['branch_id'=>1,'name'=>'${WITNESS}','category'=>'Plats','shelf_life_value'=>5,'shelf_life_unit'=>'days','actions'=>json_encode([['key'=>'witness','value'=>5,'unit'=>'days'],['key'=>'prepared','value'=>3,'unit'=>'days']]),'storage'=>'0 à +4 °C','is_active'=>1,'no_allergens'=>1,'sort_order'=>1,'duration_source'=>'Saisie du restaurant','durations_checked_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);`);
    await page.goto('/admin/labels/dlc', { waitUntil: 'domcontentloaded' });
    await page.getByPlaceholder(/Rechercher|chercher/i).first().fill('témoin').catch(() => {});
    const tile = page.locator('.dlc-tile', { hasText: WITNESS }).first();
    await expect(tile).toBeVisible({ timeout: 30_000 });
    await tile.click();
    await expect(page.getByTestId('dlc-sheet')).toBeVisible();
    await expect(page.getByTestId('dlc-sheet-slot-required')).toBeVisible();
    await expect(page.getByTestId('dlc-sheet-print')).toBeDisabled();
    expect(num(`echo DB::table('label_print_logs')->where('product_name', '${WITNESS}')->count();`)).toBe(0);
    await page.screenshot({ path: path.join(OUT, 'e1-temoin-service-requis.png') });
    await page.getByTestId('dlc-sheet-slot-noon').click();
    await expect(page.getByTestId('dlc-sheet-print')).toBeEnabled();
    await page.screenshot({ path: path.join(OUT, 'e2-temoin-midi.png') });
    const printed = page.waitForResponse((r) => /admin\/labels\/dlc\/print$/.test(r.url()) && r.request().method() === 'POST');
    await page.getByTestId('dlc-sheet-print').click();
    const res = await printed;
    expect(res.status()).toBe(200);
    expect((await res.json()).meal_slot).toBe('noon');
    expect(tinker(`echo DB::table('label_print_logs')->where('product_name', '${WITNESS}')->value('meal_slot');`)).toContain('noon');
    // la tablette confirme l'impression (pas d'imprimante en test : on confirme comme le ferait le retour de l'imprimante)
    tinker(`DB::table('label_print_logs')->where('product_name', '${WITNESS}')->update(['status' => 'printed', 'finished_at' => now()]);`);

    // F. durées relues et validées → tout est fait : félicitation + journée en règle chiffrée
    tinker(`DB::table('dlc_label_products')->update(['durations_checked_at' => now()]);`);
    await page.goto('/admin/hygiene/home', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('home-setup-title')).toContainText('Mise en place terminée', { timeout: 30_000 });
    await expect(page.getByTestId('home-setup-count')).toHaveText('5/5');
    await expect(page.getByTestId('home-setup-next')).toHaveCount(0);
    await expect(page.getByTestId('home-banner')).toContainText('Journée en règle ✓ · 2/2 relevés · 1/1 fiches de nettoyage');
    await page.screenshot({ path: path.join(OUT, 'f1-mise-en-place-terminee.png'), fullPage: true });

    // G. masqué = mémorisé après rechargement
    await page.getByTestId('home-setup-dismiss').click();
    await expect(page.getByTestId('home-setup')).toHaveCount(0);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('home-banner')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('home-setup')).toHaveCount(0);
    await page.screenshot({ path: path.join(OUT, 'g1-guide-masque.png'), fullPage: true });

    expect(bad, bad.join('\n')).toEqual([]);
});

test('téléphone 390 px : le guide tient dans l’écran, sans défilement horizontal', async ({ page }) => {
    cleanup();
    await page.setViewportSize({ width: 390, height: 844 });
    await loginAsAdmin(page);
    // UNE seule fois par onglet : un effacement à chaque navigation effacerait aussi la mémoire qu'on veut éprouver (instrument faux, pas produit faux)
    await page.addInitScript(() => { try { if (!window.sessionStorage.getItem('e2e-cleared')) { window.sessionStorage.setItem('e2e-cleared', '1'); window.localStorage.removeItem('hygiene.setup.dismissed'); } } catch (_) { /* ignoré */ } });
    await page.goto('/admin/hygiene/home', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('home-setup')).toBeVisible({ timeout: 30_000 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    await page.screenshot({ path: path.join(OUT, 'h1-mobile-guide.png'), fullPage: true });
});
