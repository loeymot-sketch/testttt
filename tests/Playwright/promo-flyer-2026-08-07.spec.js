/**
 * [FLYER PROMO UBER 2026-08-07] Vérification réelle de l'écran de saisie et de
 * l'écran de réglages, au format TÉLÉPHONE — c'est là que l'exploitant s'en
 * servira, debout, entre deux commandes.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '123456';
const SHOTS = path.join(__dirname, '..', 'captures', 'promo-flyer-2026-08-07');

async function signIn(page, baseURL) {
    await page.goto(`${baseURL}/login`, { waitUntil: 'networkidle' });
    await page.fill('#formEmail', ADMIN_EMAIL);
    await page.fill('#formPassword', ADMIN_PASS);
    await page.getByRole('button', { name: /connexion|log ?in/i }).click();
    await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30000 });
}

test('ticket promo : saisie du prenom et creation du code, sur telephone', async ({ browser, baseURL }) => {
    // iPhone-like : l'écran réel d'usage.
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();

    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

    await signIn(page, baseURL);
    await page.goto(`${baseURL}/admin/promo-flyer`, { waitUntil: 'networkidle' });
    expect(page.url()).not.toContain('/login');

    await page.waitForSelector('#customer_name', { timeout: 15000 });
    await page.screenshot({ path: path.join(SHOTS, 'saisie-mobile.png'), fullPage: true });

    // Création réelle d'un ticket.
    await page.fill('#customer_name', 'Camille');
    await page.getByRole('button', { name: /imprimer le ticket/i }).click();

    // Le code doit s'afficher en grand : si le ticket ne sort pas, l'exploitant
    // doit pouvoir le dicter au client.
    const codeBloc = page.locator('text=/^[A-Z]+-[A-Z0-9]{4}$/').first();
    await expect(codeBloc).toBeVisible({ timeout: 20000 });

    const code = (await codeBloc.innerText()).trim();
    expect(code).toMatch(/^CAMILLE-[A-Z0-9]{4}$/);
    // Caractères ambigus : illisibles sur papier thermique.
    expect(code.split('-')[1]).not.toMatch(/[01OILUV]/);

    await page.screenshot({ path: path.join(SHOTS, 'code-cree-mobile.png'), fullPage: true });

    // Le ticket doit apparaître dans l'historique, en attente d'impression.
    await expect(page.locator('table tbody tr').first()).toContainText('Camille');

    const bruit = errors.filter((e) => !/favicon|ResizeObserver|9100|Failed to fetch/i.test(e));
    expect(bruit, `erreurs console: ${bruit.join(' | ')}`).toHaveLength(0);

    await context.close();
});

test('ticket promo : ecran de reglages et apercu', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();

    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

    await signIn(page, baseURL);
    await page.goto(`${baseURL}/admin/promo-flyer/settings`, { waitUntil: 'networkidle' });
    expect(page.url()).not.toContain('/login');

    await page.waitForSelector('textarea', { timeout: 15000 });

    // L'aperçu doit montrer le rendu réel, code et pourcentage compris.
    const preview = await page.locator('pre').innerText();
    expect(preview).toContain('CAMILLE-7K2P');
    expect(preview).toContain('-10%');

    await page.screenshot({ path: path.join(SHOTS, 'reglages.png'), fullPage: true });

    const bruit = errors.filter((e) => !/favicon|ResizeObserver|9100|Failed to fetch/i.test(e));
    expect(bruit, `erreurs console: ${bruit.join(' | ')}`).toHaveLength(0);

    await context.close();
});
