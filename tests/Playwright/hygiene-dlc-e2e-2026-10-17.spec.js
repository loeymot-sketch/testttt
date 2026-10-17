/**
 * [CAYENNE HYGIÈNE 2026-10-17] E2E réel : vrai serveur Laravel, vraie base, vrai bundle compilé (pas le harnais isolé).
 *
 * Reproduit la situation de la PRODUCTION : un restaurant avec 3 fiches DLC faites à la main (Fromage râpé, Poivron, Tomate),
 * qui ne recevait jamais le catalogue. Prouve : (1) le catalogue arrive à la 1re ouverture et une seule fois, sans toucher
 * aux 3 fiches ; (2) la recherche tolérante ; (3) la sélection multiple ; (4) chaque écran d'hygiène se charge sans erreur
 * réseau/console ni clé de traduction brute ; (5) le dossier PDF et le CSV se téléchargent pour de bon.
 *
 * Lancer : PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 CAPTURES=<dossier> \
 *   npx playwright test tests/Playwright/hygiene-dlc-e2e-2026-10-17.spec.js --workers=1 --reporter=line
 * Prérequis : base e2e migrée, 3 fiches DLC de départ, `npm run production` fait.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURES || path.join(__dirname, '..', 'captures', 'hygiene-dlc-2026-10-17');
fs.mkdirSync(OUT, { recursive: true });

/** Surveille ce qui ne doit jamais arriver : réponse 4xx/5xx sur l'API, erreur de page, erreur console. */
function watch(page) {
    const bad = [];
    page.on('response', (r) => {
        const u = r.url();
        if (r.status() >= 400 && /\/api\//.test(u)) bad.push(`${r.status()} ${r.request().method()} ${u.replace(/^https?:\/\/[^/]+/, '')}`);
    });
    page.on('pageerror', (e) => bad.push(`pageerror: ${e.message}`));
    page.on('console', (m) => {
        if (m.type() !== 'error') return;
        const t = m.text();
        if (/Failed to load resource/.test(t)) return; // déjà compté par la réponse réseau
        if (/WebSocket connection to .*:6001/.test(t)) return; // diffuseur temps réel (soketi) non lancé : bruit d'instrument, pas un défaut
        bad.push(`console.error: ${t.slice(0, 200)}`);
    });
    return bad;
}

async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(900);
}

async function search(page, q) {
    await page.fill('[data-testid="dlc-search"]', q);
    await page.waitForTimeout(250);
    return page.locator('[data-testid^="dlc-product-"]').evaluateAll((els) => els
        .filter((e) => /^dlc-product-\d+$/.test(e.getAttribute('data-testid')))
        .map((e) => e.innerText));
}


/** Remet le restaurant dans l'état de la production : 3 fiches faites à la main, aucun catalogue (base e2e locale uniquement). */
function resetToProdLikeState() {
    const php = `
        DB::table('dlc_label_products')->delete();
        foreach ([['Fromage râpé','Fromages & produits laitiers',['lait']],['Poivron','Légumes & crudités',[]],['Tomate',null,[]]] as [$n,$c,$a])
            DB::table('dlc_label_products')->insert(['branch_id'=>1,'name'=>$n,'category'=>$c,'shelf_life_value'=>3,'shelf_life_unit'=>'days',
                'actions'=>json_encode([['key'=>'opened','unit'=>'days','value'=>3]]),'allergens'=>json_encode($a),'no_allergens'=>0,'storage'=>'0 à +4 °C',
                'ask_original_dlc'=>0,'print_operator'=>1,'default_copies'=>1,'is_active'=>1,'sort_order'=>0,'created_at'=>now(),'updated_at'=>now()]);`;
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', php], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    if (/error|exception/i.test(out)) throw new Error('remise à zéro impossible : ' + out);
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(180_000);

test.describe('DLC · catalogue prêt à imprimer + recherche tolérante', () => {
    test.beforeAll(() => resetToProdLikeState());

    test('1re ouverture : le catalogue arrive tout seul, les 3 fiches faites à la main restent, et une seule fois', async ({ page }) => {
        const bad = watch(page);
        await loginAsAdmin(page);
        await page.goto('/admin/labels/dlc', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-testid="dlc-grid"]')).toBeVisible({ timeout: 30_000 });
        await expect(page.locator('[data-testid="dlc-installed-note"]')).toBeVisible({ timeout: 30_000 });
        await expect(page.locator('[data-testid="dlc-installed-note"]')).toContainText(/92 produits préconfigurés/);
        await settle(page);
        await page.screenshot({ path: path.join(OUT, 'e1-premiere-ouverture.png'), fullPage: false });

        const all = await page.locator('[data-testid^="dlc-product-"]').evaluateAll((els) => els
            .filter((e) => /^dlc-product-\d+$/.test(e.getAttribute('data-testid'))).map((e) => e.innerText));
        expect(all.length).toBe(95); // 92 du catalogue + 3 faites à la main
        for (const mine of ['Fromage râpé', 'Poivron\n', 'Tomate\n']) {
            expect(all.some((t) => t.includes(mine.trim())), `fiche conservée : ${mine}`).toBeTruthy();
        }

        // deuxième ouverture : rien de plus (idempotent), pas de nouvelle notice
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-testid="dlc-grid"]')).toBeVisible({ timeout: 30_000 });
        await settle(page);
        expect(await page.locator('[data-testid="dlc-installed-note"]').count()).toBe(0);
        const again = await page.locator('[data-testid^="dlc-product-"]').evaluateAll((els) => els.filter((e) => /^dlc-product-\d+$/.test(e.getAttribute('data-testid'))).length);
        expect(again).toBe(95);

        // les 9 familles sont toutes visibles (la rangée ne cache plus rien)
        const fam = await page.locator('.dlc-cat').evaluateAll((els) => els.map((e) => ({ t: e.innerText.replace(/\s+/g, ' '), r: e.getBoundingClientRect().right })));
        const vw = page.viewportSize().width;
        expect(fam.length).toBeGreaterThanOrEqual(10); // Tous + 9 familles
        expect(fam.every((f) => f.r <= vw), 'aucune famille hors écran').toBeTruthy();
        expect(bad).toEqual([]);
    });

    test('recherche : « ognion », « chompignion », « poivrons », « champi », « poulet » donnent des fiches prêtes', async ({ page }) => {
        const bad = watch(page);
        await loginAsAdmin(page);
        await page.goto('/admin/labels/dlc', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-testid="dlc-grid"]')).toBeVisible({ timeout: 30_000 });
        await settle(page);

        const cases = [
            ['ognion', /Oignons rouges émincés/, true],
            ['chompignion', /Champignons frais émincés/, true],
            ['poivrons', /Poivrons coupés/, false],
            ['champi', /Champignons cuits/, false],
            ['oignon', /Oignons ciselés/, false],
            ['tomate', /Tomates tranchées/, false],
            ['poulet', /Poulet mariné/, false],
        ];
        for (const [q, expected, fuzzy] of cases) {
            const found = await search(page, q);
            expect(found.some((t) => expected.test(t)), `« ${q} » → ${expected}`).toBeTruthy();
            expect(await page.locator('[data-testid="dlc-fuzzy-note"]').count(), `avertissement « proches » pour « ${q} »`).toBe(fuzzy ? 1 : 0);
            await page.screenshot({ path: path.join(OUT, `e2-recherche-${q}.png`), fullPage: false });
        }
        const none = await search(page, 'zzzzzz');
        expect(none).toEqual([]);
        await expect(page.locator('[data-testid="dlc-add-from-query"]')).toBeVisible();
        expect(await page.locator('[data-testid="dlc-install-from-query"]').count()).toBe(0); // rien à ajouter : tout est installé
        expect(bad).toEqual([]);
    });

    test('sélection multiple : on cherche, on coche, la sélection survit, le panier compte juste', async ({ page }) => {
        const bad = watch(page);
        await loginAsAdmin(page);
        await page.goto('/admin/labels/dlc', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-testid="dlc-grid"]')).toBeVisible({ timeout: 30_000 });
        await settle(page);
        await page.click('[data-testid="dlc-mode-list"]');
        for (const [q, nth] of [['oignon', 0], ['tomate', 0], ['poulet', 0], ['champi', 0]]) {
            await page.fill('[data-testid="dlc-search"]', q);
            await page.waitForTimeout(200);
            await page.locator('[data-testid^="dlc-product-"]').filter({ hasText: /./ }).nth(nth).click();
        }
        await page.fill('[data-testid="dlc-search"]', '');
        await expect(page.locator('[data-testid="dlc-basket-total"]')).toContainText(/4 étiquettes/);
        await page.screenshot({ path: path.join(OUT, 'e3-panier.png'), fullPage: false });
        await page.click('[data-testid="dlc-basket-clear"]');
        await expect(page.locator('[data-testid="dlc-basket"]')).toHaveCount(0);
        expect(bad).toEqual([]);
    });
});

const ECRANS = [
    ['home', '/admin/hygiene/home'], ['temperature', '/admin/hygiene/temperature'], ['tasks', '/admin/hygiene/tasks'],
    ['reception', '/admin/hygiene/reception'], ['trace', '/admin/hygiene/trace'], ['oil', '/admin/hygiene/oil'],
    ['report', '/admin/hygiene/report'],
];

test.describe('Hygiène · chaque écran se charge pour de bon', () => {
    for (const [name, url] of ECRANS) {
        test(`écran ${name}`, async ({ page }) => {
            const bad = watch(page);
            await loginAsAdmin(page);
            await page.goto(url, { waitUntil: 'domcontentloaded' });
            await settle(page);
            await page.waitForTimeout(800);
            await page.screenshot({ path: path.join(OUT, `h-${name}.png`), fullPage: true });
            const text = await page.locator('body').innerText();
            expect(text.length).toBeGreaterThan(80);
            expect(text, 'clé de traduction ou valeur brute visible').not.toMatch(/hygiene_(home|temperature|tasks|reception|oil|trace|report)|\bundefined\b|\bNaN\b|\[object /);
            expect(bad, `erreurs sur ${url}`).toEqual([]);
            // titre en français (le thème met « Capitalize » partout : « Dossier De Contrôle », « Samedi 17 Octobre »)
            const title = await page.locator('.db-card-title.hy-title').first().innerText();
            expect(title, 'casse du titre').not.toMatch(/\b(De|Du|Des|Sanitaire|Octobre|Novembre)\b.*\b(Contrôle|Octobre)\b|Dossier De/);
            if (name === 'home') {
                // tant qu'un domaine n'est pas configuré, jamais « Tout est en ordre »
                const unconfigured = await page.locator('.hy-tile.is-empty').count();
                const banner = await page.locator('[data-testid="home-banner"]').innerText();
                if (unconfigured > 0) expect(banner).not.toMatch(/Tout est en ordre/);
            }
        });
    }

    test('dossier de contrôle : le PDF et le CSV se téléchargent pour de bon', async ({ page }) => {
        const bad = watch(page);
        await loginAsAdmin(page);
        await page.goto('/admin/hygiene/report', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-testid="rep-kpis"]')).toBeVisible({ timeout: 30_000 });
        await settle(page);

        const [pdf] = await Promise.all([page.waitForEvent('download', { timeout: 60_000 }), page.click('[data-testid="rep-pdf"]')]);
        const pdfPath = path.join(OUT, 'dossier.pdf');
        await pdf.saveAs(pdfPath);
        const head = fs.readFileSync(pdfPath).subarray(0, 5).toString();
        expect(head).toBe('%PDF-');
        expect(fs.statSync(pdfPath).size).toBeGreaterThan(2000);

        const csvBtn = page.locator('[data-testid^="rep-csv-"]').first();
        const [csv] = await Promise.all([page.waitForEvent('download', { timeout: 30_000 }), csvBtn.click()]);
        const csvPath = path.join(OUT, 'export.csv');
        await csv.saveAs(csvPath);
        const csvText = fs.readFileSync(csvPath, 'utf8');
        expect(csvText.length).toBeGreaterThan(10);
        expect(csvText.split('\n')[0]).toMatch(/;|,/); // un en-tête de colonnes
        expect(bad).toEqual([]);
    });
});
