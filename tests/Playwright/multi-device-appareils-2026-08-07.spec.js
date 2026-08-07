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
    for (const p of [pageCaisse, pageTablette]) {
        p.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    }

    await signIn(pageCaisse, baseURL, 'Caisse comptoir');
    await signIn(pageTablette, baseURL, 'Tablette salle');

    // LE POINT CRITIQUE : la caisse doit encore fonctionner après la connexion
    // de la tablette. Avant le correctif, cette navigation partait en 401 et
    // renvoyait l'utilisateur sur /login.
    await pageCaisse.goto(`${baseURL}/admin/profile/devices`, { waitUntil: 'networkidle' });
    expect(pageCaisse.url()).not.toContain('/login');

    await pageCaisse.waitForSelector('table tbody tr', { timeout: 15000 });
    const lignes = await pageCaisse.locator('table tbody tr').count();
    expect(lignes).toBeGreaterThanOrEqual(2);

    const contenu = await pageCaisse.locator('table').innerText();
    expect(contenu).toContain('Caisse comptoir');
    expect(contenu).toContain('Tablette salle');

    await pageCaisse.screenshot({ path: path.join(SHOTS, 'appareils-connectes.png'), fullPage: true });

    // Aucune erreur console (mandat de vérification visuelle du projet).
    const bruit = errors.filter((e) => !/favicon|ResizeObserver/i.test(e));
    expect(bruit, `erreurs console: ${bruit.join(' | ')}`).toHaveLength(0);

    await browser.close();
});
