/**
 * [MULTI-DEVICE 2026-08-07] Vérification visuelle + fonctionnelle réelle de
 * l'écran « Appareils connectés », et surtout de la propriété qui motivait
 * tout le correctif : se connecter sur un second appareil ne doit PAS éjecter
 * le premier.
 *
 * Le test simule les deux terminaux par deux contextes navigateur isolés
 * (stockages locaux séparés ⇒ deux `device_id` distincts), ce qui reproduit
 * fidèlement « une caisse + une tablette » sans matériel.
 */
const { test, expect, chromium } = require('@playwright/test');
const path = require('path');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '123456';
const SHOTS = path.join(__dirname, '..', 'captures', 'multi-device-2026-08-07');

async function signIn(page, baseURL, deviceLabel) {
    await page.goto(`${baseURL}/login`, { waitUntil: 'domcontentloaded' });

    // Nommer l'appareil AVANT la connexion : l'en-tête part avec le POST login
    // et le libellé est scellé sur le jeton émis.
    await page.evaluate((label) => {
        window.localStorage.setItem('foodking.device_label', label);
    }, deviceLabel);

    await page.reload({ waitUntil: 'networkidle' });

    // Sélecteurs relevés sur la page réelle : le champ e-mail est `type="text"`
    // (#formEmail), et le PREMIER `button[type=submit]` de la page est le
    // sélecteur de langue — viser le bouton par son libellé, pas par sa nature.
    await page.fill('#formEmail', ADMIN_EMAIL);
    await page.fill('#formPassword', ADMIN_PASS);
    await page.getByRole('button', { name: /connexion|log ?in/i }).click();
    await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30000 });
}

test('deux appareils restent connectés en même temps et sont listés', async ({ baseURL }) => {
    const browser = await chromium.launch();

    // Deux contextes = deux localStorage = deux appareils distincts.
    const caisse = await browser.newContext();
    const tablette = await browser.newContext();

    const pageCaisse = await caisse.newPage();
    const pageTablette = await tablette.newPage();

    const errors = [];
    const echecsReseau = [];
    for (const p of [pageCaisse, pageTablette]) {
        p.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
        // On capture aussi l'URL EXACTE de chaque requête échouée : le message
        // console (« Failed to load resource ») ne la porte pas, et sans elle on
        // ne peut pas distinguer une régression produit d'une sonde matérielle.
        p.on('requestfailed', (r) => {
            const erreur = r.failure()?.errorText || '';
            if (/ERR_CONNECTION_REFUSED/.test(erreur)) echecsReseau.push(r.url());
        });
    }

    await signIn(pageCaisse, baseURL, 'Caisse comptoir');
    await signIn(pageTablette, baseURL, 'Tablette salle');

    // LE POINT CRITIQUE : la caisse doit encore fonctionner après la connexion
    // de la tablette. Avant le correctif, cette navigation partait en 401 et
    // renvoyait l'utilisateur sur /login.
    await pageCaisse.goto(`${baseURL}/admin/profile/devices`, { waitUntil: 'networkidle' });
    expect(pageCaisse.url()).not.toContain('/login');

    // [REPLAN_6 2026-08-24] Cibler EXACTEMENT la table « Appareils connectés ».
    // `locator('table')` attrapait aussi les 6 tables injectées par la debugbar
    // (APP_DEBUG local) : violation strict-mode sur innerText ET comptage de
    // lignes faussement satisfait par des lignes de debug. Le scope ci-dessous
    // rend l'assertion PLUS stricte, jamais plus permissive.
    const tableAppareils = pageCaisse.locator('.table-responsive table.table');
    await expect(tableAppareils).toHaveCount(1);

    await tableAppareils.locator('tbody tr').first().waitFor({ timeout: 15000 });
    const lignes = await tableAppareils.locator('tbody tr').count();
    expect(lignes).toBeGreaterThanOrEqual(2);

    const contenu = await tableAppareils.innerText();
    expect(contenu).toContain('Caisse comptoir');
    expect(contenu).toContain('Tablette salle');

    await pageCaisse.screenshot({ path: path.join(SHOTS, 'appareils-connectes.png'), fullPage: true });

    // [REPLAN_6 2026-08-24] Sondes des PONTS D'IMPRESSION matériels : la caisse
    // interroge 127.0.0.1:9100/health (SAGA comptoir) et la cuisine 9101/health.
    // Sans pont physique branché (POS_SIMULATION_HARDWARE), ces sondes sont
    // refusées PAR CONSTRUCTION et l'application retombe sur window.print.
    // Elles sont les SEULES connexions refusées tolérées, et uniquement sur ces
    // URL exactes — toute autre connexion refusée reste un échec dur.
    const PONT_IMPRESSION = /^http:\/\/127\.0\.0\.1:(9100|9101)\/health\b/;
    const refusInattendus = echecsReseau.filter((u) => !PONT_IMPRESSION.test(u));
    expect(refusInattendus, `connexions refusées inattendues: ${refusInattendus.join(' | ')}`).toHaveLength(0);

    // Aucune erreur console (mandat de vérification visuelle du projet). La ligne
    // générique émise par une sonde de pont déjà innocentée ci-dessus est retirée
    // — et seulement elle, une par échec réseau prouvé sur l'allowlist.
    let refusInnocentes = echecsReseau.length - refusInattendus.length;
    const bruit = errors.filter((e) => {
        if (/favicon|ResizeObserver/i.test(e)) return false;
        if (refusInnocentes > 0 && /Failed to load resource: net::ERR_CONNECTION_REFUSED/.test(e)) {
            refusInnocentes -= 1;
            return false;
        }
        return true;
    });
    expect(bruit, `erreurs console: ${bruit.join(' | ')}`).toHaveLength(0);

    await browser.close();
});
