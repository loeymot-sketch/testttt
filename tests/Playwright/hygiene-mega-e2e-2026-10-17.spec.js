/**
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier P0/P1] E2E réel : vrai serveur Laravel, vraie base, vrai bundle compilé.
 *
 * Rejoue sur la prod-like (3 fiches faites à la main, dont « Tomate » sans famille et « Poivron » à 0/+3 °C) :
 *   1. Contrôle du catalogue : durées à valider, allergènes à renseigner, fiche sans famille rangée, fiches à harmoniser,
 *      validation par famille (avec confirmation), et la validation tombe quand on change une durée ;
 *   2. Import CSV : modèle téléchargeable, aperçu sans écriture, import des seules lignes valides, « déjà présent » intact ;
 *   3. Équipements chauds : bain-marie créé à 63 °C, 60 °C = « pas assez chaud », actions du chaud seulement, courbe 14 jours ;
 *   4. Accueil : « À traiter maintenant » (nettoyage en retard) et boutons d'action sur les tuiles.
 *
 * Lancer : PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 CAPTURES=<dossier> \
 *   npx playwright test tests/Playwright/hygiene-mega-e2e-2026-10-17.spec.js --workers=1 --reporter=line
 * Prérequis : base e2e migrée, `npm run production` fait.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURES || path.join(__dirname, '..', 'captures', 'hygiene-mega-2026-10-17');
fs.mkdirSync(OUT, { recursive: true });

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
        if (/Failed to load resource/.test(t)) return;
        if (/WebSocket connection to .*:6001/.test(t)) return; // diffuseur temps réel (soketi) non lancé : bruit d'instrument
        bad.push(`console.error: ${t.slice(0, 200)}`);
    });
    return bad;
}

async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(900);
}

function tinker(php) {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', php], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    if (/error|exception/i.test(out)) throw new Error('tinker : ' + out);
    return out;
}

/** La prod : 3 fiches faites à la main (« Tomate » sans famille, « Poivron » à 0/+3 °C), aucun catalogue. */
function resetCatalogue() {
    tinker(`
        DB::table('dlc_label_products')->delete();
        foreach ([['Fromage râpé','Fromages & produits laitiers',['lait'],'0 à +4 °C'],['Poivron','Légumes & crudités',[],'0 à +3 °C'],['Tomate',null,[],'0 à +3 °C']] as [$n,$c,$a,$s])
            DB::table('dlc_label_products')->insert(['branch_id'=>1,'name'=>$n,'category'=>$c,'shelf_life_value'=>3,'shelf_life_unit'=>'days',
                'actions'=>json_encode([['key'=>'opened','unit'=>'days','value'=>3]]),'allergens'=>json_encode($a),'no_allergens'=>0,'storage'=>$s,
                'ask_original_dlc'=>0,'print_operator'=>1,'default_copies'=>1,'is_active'=>1,'sort_order'=>0,'created_at'=>now(),'updated_at'=>now()]);`);
}

function resetHygiene() {
    tinker(`
        foreach (['hygiene_temperature_readings','hygiene_oil_controls','hygiene_task_completions','hygiene_task_templates','hygiene_equipments'] as $t) DB::table($t)->delete();`);
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(180_000);

test.describe('Contrôle du catalogue', () => {
    test.beforeAll(() => resetCatalogue());

    test('durées à valider, allergènes, famille manquante, fiches à harmoniser — et chaque correction tient', async ({ page }) => {
        const bad = watch(page);
        await loginAsAdmin(page);
        await page.goto('/admin/labels/dlc', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-testid="dlc-installed-note"]')).toBeVisible({ timeout: 30_000 });
        await settle(page);

        const btn = page.locator('[data-testid="dlc-health-open"]');
        await expect(btn).toBeVisible();
        const count = Number(await page.locator('[data-testid="dlc-health-count"]').innerText());
        expect(count).toBeGreaterThan(90); // 92 durées de départ + allergènes + « Tomate » + les voisines
        await page.screenshot({ path: path.join(OUT, 'a1-ecran.png') });

        await btn.click();
        const modal = page.locator('[data-testid="dlc-health"]');
        await expect(modal).toBeVisible();
        await expect(modal.locator('[data-testid="dlc-health-starting"] .hc-n').first()).toHaveText('92');

        // « Tomate » : sans famille, la famille PROBABLE vient du catalogue
        await expect(modal.locator('[data-testid="dlc-health-category"]')).toContainText('Tomate');
        const tomate = modal.locator('.hc-row', { hasText: 'Tomate' }).locator('input[type="text"]');
        await expect(tomate).toHaveValue('Légumes & crudités');
        // « Poivron » 0/+3 °C face à « Poivrons coupés » 0/+4 °C
        await expect(modal.locator('[data-testid="dlc-health-neighbours"]')).toContainText('Poivron');
        await expect(modal.locator('[data-testid="dlc-health-neighbours"]')).toContainText('0 à +3 °C');
        await expect(modal.locator('[data-testid="dlc-health-neighbours"]')).toContainText('0 à +4 °C');
        // les allergènes à renseigner nomment les fiches faites à la main (« Tomate », « Poivron »), pas « Fromage râpé » (lait déclaré)
        const allergenNames = await modal.locator('[data-testid^="dlc-health-allergen-"]').allInnerTexts();
        expect(allergenNames).toEqual(expect.arrayContaining(['Tomate', 'Poivron']));
        expect(allergenNames).not.toContain('Fromage râpé');
        await page.screenshot({ path: path.join(OUT, 'a2-controle.png') });

        // 1) ranger « Tomate » : la section disparaît et la famille est bien enregistrée
        await modal.locator('[data-testid="dlc-health-classify"]').click();
        await expect(modal.locator('[data-testid="dlc-health-category"]')).toHaveCount(0, { timeout: 15_000 });
        const dbCat = tinker(`echo DB::table('dlc_label_products')->where('name','Tomate')->value('category');`);
        expect(dbCat).toContain('Légumes & crudités');

        // 2) valider une famille : bouton inactif tant que la confirmation n'est pas cochée
        const group = modal.locator('[data-testid="dlc-health-group-charcuterie"]');
        await group.locator('summary').click();
        const validate = modal.locator('[data-testid="dlc-health-validate-charcuterie"]');
        await expect(validate).toBeDisabled();
        await modal.locator('[data-testid="dlc-health-confirm"]').check();
        await expect(validate).toBeEnabled();
        await validate.click();
        await expect(modal.locator('[data-testid="dlc-health-starting"] .hc-n').first()).toHaveText('86', { timeout: 15_000 });
        const checked = Number(tinker(`echo DB::table('dlc_label_products')->whereNotNull('durations_checked_at')->count();`).trim());
        expect(checked).toBe(6);
        await page.screenshot({ path: path.join(OUT, 'a3-valide.png') });

        // 3) modifier une durée d'une fiche VALIDÉE : la validation tombe, la source devient celle du restaurant
        const firstCharc = tinker(`echo DB::table('dlc_label_products')->whereNotNull('durations_checked_at')->orderBy('id')->value('id');`).trim();
        await modal.locator('[data-testid="dlc-health-done"]').click();
        await page.locator('[data-testid="dlc-manage-toggle"]').click();
        await expect(page.locator(`[data-testid="dlc-row-ok-${firstCharc}"]`)).toBeVisible();
        await page.locator(`[data-testid="dlc-edit-${firstCharc}"]`).click();
        const editor = page.locator('[data-testid="dlc-editor"]');
        await expect(editor.locator('[data-testid="dlc-ed-durations-checked-at"]')).toContainText('Durées validées le');
        const valueInput = editor.locator('input[data-testid^="dlc-ed-value-"]').first();
        const was = Number(await valueInput.inputValue());
        await valueInput.fill(String(was + 1));
        await expect(editor.locator('[data-testid="dlc-ed-validate"]')).toBeVisible();
        await expect(editor.locator('[data-testid="dlc-ed-validate"]')).not.toBeChecked();
        await page.screenshot({ path: path.join(OUT, 'a4-edition.png') });
        await editor.locator('[data-testid="dlc-ed-save"]').click();
        await expect(editor).toHaveCount(0, { timeout: 15_000 });
        await expect(page.locator(`[data-testid="dlc-row-ok-${firstCharc}"]`)).toHaveCount(0);
        const row = tinker(`echo json_encode(DB::table('dlc_label_products')->where('id',${firstCharc})->first(['duration_source','durations_checked_at']), JSON_UNESCAPED_UNICODE);`);
        expect(row).toContain('"durations_checked_at":null');
        expect(row).toContain('Saisie du restaurant');
        expect(bad).toEqual([]);
    });
});

test.describe('Import CSV des produits', () => {
    test.beforeAll(() => resetCatalogue());

    test('modèle téléchargeable, aperçu sans écriture, import des seules lignes valides', async ({ page }) => {
        const bad = watch(page);
        await loginAsAdmin(page);
        await page.goto('/admin/labels/dlc', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-testid="dlc-grid"]')).toBeVisible({ timeout: 30_000 });
        await settle(page);
        const before = Number(tinker(`echo DB::table('dlc_label_products')->count();`).trim());

        await page.locator('[data-testid="dlc-manage-toggle"]').click();
        await page.locator('[data-testid="dlc-import-open"]').click();
        const dlg = page.locator('[data-testid="dlc-import"]');
        await expect(dlg).toBeVisible();

        // le fichier-modèle se télécharge pour de bon (CSV UTF-8 avec BOM, s'ouvre dans Excel)
        const [download] = await Promise.all([page.waitForEvent('download'), dlg.locator('[data-testid="dlc-import-template"]').click()]);
        expect(download.suggestedFilename()).toBe('modele-produits-dlc.csv');
        const tplPath = path.join(OUT, 'modele-produits-dlc.csv');
        await download.saveAs(tplPath);
        const tpl = fs.readFileSync(tplPath, 'utf8');
        expect(tpl.charCodeAt(0)).toBe(0xFEFF);
        expect(tpl).toMatch(/^﻿Produit;Famille;Conservation/);

        // un fichier d'Excel français : 1 nouveau, 1 déjà présent (casse différente), 1 sans conservation, 1 allergène inconnu
        const csv = [
            'Produit;Famille;Conservation;Après décongélation;Ouverture;Décongélation;Préparation;Cuisson;Sous vide;Congélation;Repas témoin;Réception;Allergènes;Mention;Source des durées',
            'Houmous maison;Sauces;0 à +4 °C;;;;3 j;;;;;;Sésame;Bac 4;Plan de maîtrise sanitaire',
            'FROMAGE RÂPÉ;;0 à +4 °C;;3 j;;;;;;;;;;',
            'Sans conservation;;;;;;24 h;;;;;;;;',
            'Pistache crème;;0 à +4 °C;;;;24 h;;;;;;Pistache;;',
        ].join('\r\n');
        const file = path.join(os.tmpdir(), `produits-e2e-${Date.now()}.csv`);
        fs.writeFileSync(file, '﻿' + csv);
        await dlg.locator('[data-testid="dlc-import-file"]').setInputFiles(file);
        await expect(dlg.locator('[data-testid="dlc-import-summary"]')).toContainText('1 à ajouter', { timeout: 15_000 });
        await expect(dlg.locator('[data-testid="dlc-import-summary"]')).toContainText('1 déjà présent');
        await expect(dlg.locator('[data-testid="dlc-import-summary"]')).toContainText('2 à corriger');
        await expect(dlg.locator('[data-testid="dlc-import-row-4"]')).toContainText('Conservation obligatoire');
        await expect(dlg.locator('[data-testid="dlc-import-row-5"]')).toContainText('Allergène inconnu : « Pistache »');
        await page.screenshot({ path: path.join(OUT, 'b1-apercu.png') });
        // l'aperçu n'a RIEN écrit
        expect(Number(tinker(`echo DB::table('dlc_label_products')->count();`).trim())).toBe(before);

        await dlg.locator('[data-testid="dlc-import-go"]').click();
        await expect(dlg).toHaveCount(0, { timeout: 15_000 });
        expect(Number(tinker(`echo DB::table('dlc_label_products')->count();`).trim())).toBe(before + 1);
        const saved = tinker(`echo json_encode(DB::table('dlc_label_products')->where('name','Houmous maison')->first(['category','storage','allergens','duration_source','durations_checked_at','note']), JSON_UNESCAPED_UNICODE);`);
        expect(saved).toContain('Sauces');
        expect(saved).toContain('sesame');
        expect(saved).toContain('Plan de maîtrise sanitaire');
        expect(saved).toContain('"durations_checked_at":null'); // l'import ne valide rien
        // « Fromage râpé » n'a pas été touché
        expect(tinker(`echo DB::table('dlc_label_products')->where('name','Fromage râpé')->value('shelf_life_value');`)).toContain('3');
        // la nouvelle fiche est dans la grille, prête à imprimer
        await settle(page);
        await page.fill('[data-testid="dlc-search"]', 'houmous');
        await expect(page.locator('[data-testid^="dlc-product-"]').first()).toContainText('Houmous maison');
        expect(bad).toEqual([]);
    });
});

test.describe('Équipements chauds et courbe', () => {
    test.beforeAll(() => resetHygiene());

    test('bain-marie à 63 °C : 60 °C = « pas assez chaud », actions du chaud, courbe 14 jours', async ({ page }) => {
        const bad = watch(page);
        await loginAsAdmin(page);
        await page.goto('/admin/hygiene/temperature', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-testid="tmp-setup"]')).toBeVisible({ timeout: 30_000 });
        await expect(page.locator('[data-testid="tmp-setup"]')).toContainText('bain-marie 63 °C ou plus');
        // 1 frigo, 1 congélateur, 1 bain-marie
        for (const k of ['fridge', 'freezer']) await page.locator(`[data-testid="tmp-setup-${k}-minus"]`).click(); // 4→3, 2→1
        for (let i = 0; i < 2; i++) await page.locator('[data-testid="tmp-setup-fridge-minus"]').click();        // 3→1
        await page.locator('[data-testid="tmp-setup-hot_holding-plus"]').click();
        await page.screenshot({ path: path.join(OUT, 'c1-demarrage.png') });
        await page.locator('[data-testid="tmp-setup-go"]').click();
        await expect(page.locator('[data-testid^="tmp-card-"]')).toHaveCount(3, { timeout: 15_000 });

        const bm = page.locator('.tmp-card', { hasText: 'Bain-marie' });
        await expect(bm).toContainText('≥ +63 °C');
        const bmId = (await bm.getAttribute('data-testid')).replace('tmp-card-', '');
        await page.locator(`[data-testid="tmp-take-${bmId}"]`).click();
        await page.locator(`[data-testid="tmp-input-${bmId}"]`).fill('60');
        await expect(page.locator(`[data-testid="tmp-verdict-${bmId}"]`)).toContainText('pas assez chaud');
        const actions = await page.locator(`[data-testid^="tmp-action-${bmId}-"]`).allInnerTexts();
        expect(actions.join('|')).toContain('Plats réchauffés');
        expect(actions.join('|')).not.toContain('Dégivrage');
        expect(actions.join('|')).not.toContain('Porte mal fermée');
        // sans action : le serveur refuse (le bouton « Valider » reste inactif)
        await expect(page.locator('[data-testid="tmp-submit"]')).toBeDisabled();
        await page.locator(`[data-testid="tmp-action-${bmId}-reheated"]`).click();
        await page.screenshot({ path: path.join(OUT, 'c2-bainmarie-60.png') });
        await expect(page.locator('[data-testid="tmp-submit"]')).toBeEnabled();
        await page.locator('[data-testid="tmp-submit"]').click();
        await expect(page.locator(`[data-testid="tmp-done-${bmId}"]`)).toContainText('60,0 °C', { timeout: 15_000 });
        await expect(page.locator(`[data-testid="tmp-done-${bmId}"]`)).toContainText('Pas assez chaud');
        const row = tinker(`echo json_encode(DB::table('hygiene_temperature_readings')->where('equipment_name','like','Bain-marie%')->first(['value_c','status','alert_reason','corrective_action','min_c','max_c']), JSON_UNESCAPED_UNICODE);`);
        expect(row).toContain('"status":"alert"');
        expect(row).toContain('too_cold');
        expect(row).toContain('reheated');

        // la courbe : deux relevés de plus, puis le graphique s'affiche avec le seuil
        await page.locator(`[data-testid="tmp-chart-toggle-${bmId}"]`).click();
        const chart = page.locator(`[data-testid="tmp-card-${bmId}"] [data-testid="tmp-chart"] svg`);
        await expect(chart).toBeVisible({ timeout: 15_000 });
        await expect(page.locator(`[data-testid="tmp-card-${bmId}"] [data-testid="tmp-chart-caption"]`)).toContainText(/1 relevé sur 14 jours.*60,0 °C.*1 écart/);
        await expect(chart.locator('line.tch-limit')).toHaveCount(1); // seuil bas seul
        await page.locator(`[data-testid="tmp-card-${bmId}"]`).scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(OUT, 'c3-courbe.png') });
        expect(bad).toEqual([]);
    });
});

test.describe('Accueil : rappels et boutons d’action', () => {
    test.beforeAll(() => {
        resetHygiene();
        // un nettoyage prévu à 00:01 chaque jour, jamais fait : en retard à toute heure (hors minuit-00:01)
        tinker(`
            DB::table('hygiene_task_templates')->insert(['branch_id'=>1,'name'=>'Hotte et filtres','zone'=>'Cuisine','how_to'=>'Dégraisser','checklist'=>json_encode([['key'=>'k1','label'=>'Filtres']]),
                'frequency'=>'daily','due_time'=>'00:01','sort_order'=>10,'is_active'=>1,'created_at'=>now()->subDays(3),'updated_at'=>now()->subDays(3)]);`);
    });

    test('« À traiter maintenant » nomme le retard, la tuile passe en alerte, chaque tuile dit son geste', async ({ page }) => {
        const bad = watch(page);
        await loginAsAdmin(page);
        await page.goto('/admin/hygiene', { waitUntil: 'domcontentloaded' });
        const rem = page.locator('[data-testid="home-reminders"]');
        await expect(rem).toBeVisible({ timeout: 30_000 });
        await expect(rem).toContainText('Nettoyage en retard : « Hotte et filtres » (prévu à 00:01).');
        await expect(page.locator('[data-testid="home-tile-tasks"]')).toHaveClass(/is-alert/);
        await expect(page.locator('[data-testid="home-cta-tasks"]')).toHaveText('Faire le nettoyage →');
        await expect(page.locator('[data-testid="home-cta-temperature"]')).toHaveText('Configurer →');
        await expect(page.locator('[data-testid="home-cta-dlc"]')).toHaveText('Imprimer une étiquette →');
        await page.screenshot({ path: path.join(OUT, 'd1-accueil.png') });
        // un appui sur le rappel mène à l'écran concerné
        await rem.locator('a', { hasText: 'Hotte et filtres' }).click();
        await expect(page).toHaveURL(/\/admin\/hygiene\/tasks/);
        expect(bad).toEqual([]);
    });
});
