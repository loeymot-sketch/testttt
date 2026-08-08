/**
 * [FLYER PROMO 2026-08-08] Accès depuis l'ÉCRAN DE CAISSE.
 *
 * L'exploitant voit arriver une commande d'une plateforme et veut imprimer le
 * ticket sans changer d'écran : un bouton dans la barre de la caisse ouvre une
 * fenêtre où il tape le prénom.
 *
 * Ce test vérifie le parcours réel, pas seulement la présence du bouton :
 * ouverture, saisie, création, et affichage du code EN GRAND (indispensable si
 * le papier ne sort pas — l'exploitant doit pouvoir le dicter).
 */
const { test, expect } = require('@playwright/test');
const path = require('path');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '123456';
const SHOTS = path.join(__dirname, '..', 'captures', 'promo-flyer-caisse-2026-08-08');

async function signIn(page, baseURL) {
    await page.goto(`${baseURL}/login`, { waitUntil: 'networkidle' });
    await page.fill('#formEmail', ADMIN_EMAIL);
    await page.fill('#formPassword', ADMIN_PASS);
    await page.getByRole('button', { name: /connexion|log ?in/i }).click();
    await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30000 });
}

test('caisse : le ticket promo s\'imprime depuis la barre, sans changer d\'ecran', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push('PAGEERROR: ' + e.message));

    await signIn(page, baseURL);
    await page.goto(`${baseURL}/admin/pos-orders-tracker`, { waitUntil: 'networkidle' });

    const bouton = page.getByTestId('pos-tracker-promo-flyer');
    await expect(bouton, 'Le bouton « Ticket promo » doit exister dans la barre de caisse').toBeVisible({ timeout: 20000 });

    await page.screenshot({ path: path.join(SHOTS, 'caisse-barre.png'), fullPage: false });

    await bouton.click();
    await expect(page.locator('#pfq-name')).toBeVisible({ timeout: 10000 });

    // Le champ doit être focalisé : en plein service, on tape directement.
    const focused = await page.evaluate(() => document.activeElement && document.activeElement.id);
    expect(focused, 'Le champ doit avoir le focus a l\'ouverture').toBe('pfq-name');

    await page.screenshot({ path: path.join(SHOTS, 'caisse-modale.png'), fullPage: false });

    await page.fill('#pfq-name', 'Camille');
    await page.getByRole('button', { name: /^Mme$/ }).click();
    await page.getByRole('button', { name: /imprimer le ticket/i }).click();

    const code = page.locator('.pfq-code');
    await expect(code).toBeVisible({ timeout: 25000 });

    const texte = (await code.innerText()).trim();
    expect(texte).toMatch(/^CAMILLE-[A-Z0-9]{4}$/);
    expect(texte.split('-')[1]).not.toMatch(/[01OILUV]/);

    await page.screenshot({ path: path.join(SHOTS, 'caisse-code-cree.png'), fullPage: false });

    // Le pont d'impression est absent en test : ses erreurs réseau sont
    // attendues et ne doivent pas faire échouer la vérification.
    const bruit = errors.filter((e) => !/favicon|ResizeObserver|9100|Failed to fetch|net::ERR/i.test(e));
    expect(bruit, `erreurs console: ${bruit.join(' | ')}`).toHaveLength(0);

    await context.close();
});

test('telephone : la meme fenetre reste utilisable a une main', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();

    await signIn(page, baseURL);
    await page.goto(`${baseURL}/admin/promo-flyer`, { waitUntil: 'networkidle' });
    await page.waitForSelector('#customer_name', { timeout: 15000 });

    // Le champ doit rester assez haut pour un doigt, et assez grand pour que
    // iOS ne zoome pas (ce qui décalerait tout l'écran).
    const box = await page.locator('#customer_name').boundingBox();
    expect(box.height, 'champ trop petit pour un usage debout').toBeGreaterThanOrEqual(40);

    const fontSize = await page.locator('#customer_name').evaluate(
        (el) => parseFloat(getComputedStyle(el).fontSize)
    );
    expect(fontSize, 'police < 16px : iOS zoomerait sur le champ').toBeGreaterThanOrEqual(16);

    await page.screenshot({ path: path.join(SHOTS, 'telephone.png'), fullPage: true });
    await context.close();
});
