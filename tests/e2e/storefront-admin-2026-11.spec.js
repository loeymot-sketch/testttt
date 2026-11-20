// [APP HYBRIDE W2a · 2026-11-03] Écran « Vitrine » en vrai navigateur : avant / refus de contraste / refus de dates /
// bandeau / publication / après, en 1440×900 et 390×844, puis lecture de l'API publique que l'application consommera.
//
// Écrit dans la base servie : à lancer UNIQUEMENT contre une COPIE (ex. foodking_hybride servie sur :8777), jamais :8766.
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8777 PLAYWRIGHT_NO_WEB_SERVER=1 SF_API_KEY=<MIX_API_KEY> \
//   SF_CAPTURE_DIR=/chemin/hors/depot npx playwright test tests/e2e/storefront-admin-2026-11.spec.js --reporter=line --retries=0
// Les captures ne sont PAS commitées (mémoire reports-dir-fills-disk) : la spec les régénère.
const { test, expect } = require('@playwright/test');
const os = require('os');
const path = require('path');

const OUT = process.env.SF_CAPTURE_DIR || path.join(os.tmpdir(), 'storefront-captures');
const EMAIL = process.env.SF_ADMIN_EMAIL || 'admin@lecayenne.fr';
const PASSWORD = process.env.SF_ADMIN_PASSWORD || '123456';
const RAW_LABEL = /\b(menu|label|message|button)\.[a-z_]{3,}\b|Label\.[A-Za-z]|undefined|\[object Object\]/;

function pad(n) { return String(n).padStart(2, '0'); }
function parisLocal(offsetHours) {
    // Heure de Paris au format d'un champ datetime-local (le navigateur de test est épinglé Europe/Paris).
    const d = new Date(Date.now() + offsetHours * 3600 * 1000);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

async function shot(page, name, width, height) {
    await page.setViewportSize({ width, height });
    await page.waitForTimeout(600);
    // Écran tel qu'on le voit (la page défile dans .db-main : `fullPage` ne capture que la fenêtre) …
    await page.screenshot({ path: path.join(OUT, `${name}.png`) });
    // … puis la suite, fenêtre par fenêtre, en faisant défiler le conteneur qui défile vraiment (une capture d'élément
    // y rend du blanc : constaté le 2026-11-03).
    const steps = await page.evaluate(() => {
        let el = document.querySelector('.sf');
        while (el && el !== document.body) {
            const st = getComputedStyle(el);
            if (/(auto|scroll)/.test(st.overflowY) && el.scrollHeight > el.clientHeight) break;
            el = el.parentElement;
        }
        const box = el && el !== document.body ? el : document.scrollingElement;
        window.__sfScroller = box;
        box.scrollTop = 0;
        return Math.ceil(box.scrollHeight / box.clientHeight);
    });
    for (let i = 1; i < Math.min(steps, 8); i += 1) {
        await page.evaluate((k) => { const b = window.__sfScroller; b.scrollTop = k * (b.clientHeight - 80); }, i);
        await page.waitForTimeout(250);
        await page.screenshot({ path: path.join(OUT, `${name}-suite${i}.png`) });
    }
    await page.evaluate(() => { window.__sfScroller.scrollTop = 0; });
}

test('vitrine : avant, refus affichés près du bouton, publication, après', async ({ page, request }) => {
    test.setTimeout(180_000);
    const consoleErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    page.on('pageerror', (e) => consoleErrors.push(String(e)));
    const failedRequests = [];
    page.on('requestfailed', (r) => failedRequests.push(`${r.failure() && r.failure().errorText} ${r.url()}`));

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/login');
    await page.fill('#formEmail', EMAIL);
    await page.fill('#formPassword', PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 60_000 });

    await page.goto('/admin/storefront');
    await expect(page.getByTestId('sf-status')).toBeVisible({ timeout: 60_000 });
    await expect(page.locator('.db-sidebar-nav-menu', { hasText: "Vitrine de l'app" })).toBeVisible();
    await shot(page, '01-avant-1440', 1440, 900);
    await shot(page, '02-avant-390', 390, 844);
    await page.setViewportSize({ width: 1440, height: 900 });

    // Vitrine active + couleurs personnalisées.
    if ((await page.getByTestId('sf-enabled').getAttribute('aria-pressed')) !== 'true') await page.getByTestId('sf-enabled').click();
    if ((await page.getByTestId('sf-custom-theme').getAttribute('aria-pressed')) !== 'true') await page.getByTestId('sf-custom-theme').click();

    // Refus de contraste : blanc sur l'orange de la marque.
    await page.getByTestId('sf-hex-on_primary').fill('#FFFFFF');
    const bad = page.locator('[data-testid="sf-contrast"] li.is-bad');
    await expect(bad).toHaveCount(1);
    await expect(bad).toContainText('3,11:1');
    await expect(page.getByTestId('sf-save-settings')).toBeDisabled();
    await bad.scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, '03-contraste-refuse-1440.png'), fullPage: false });
    await page.getByTestId('sf-hex-on_primary').fill('#0A0A0A');
    await expect(bad).toHaveCount(0);

    // Refus de dates côté serveur : affiché DANS le formulaire du bandeau, près de son bouton.
    await page.getByTestId('sf-new-banner').click();
    await page.getByTestId('sf-b-title').fill('Bandeau aux dates inversées');
    await page.getByTestId('sf-b-start').fill(parisLocal(48));
    await page.getByTestId('sf-b-end').fill(parisLocal(24));
    await page.getByTestId('sf-b-save').click();
    const bannerErrors = page.getByTestId('sf-banner-errors');
    await expect(bannerErrors).toContainText('La fin doit être après le début.');
    await bannerErrors.scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, '04-erreur-dates-1440.png'), fullPage: false });

    // Bandeau valide : offre flash, bouton vers la carte.
    const title = `Offre de Noël — dessert offert ${Date.now() % 10000}`;
    await page.getByTestId('sf-b-title').fill(title);
    await page.getByTestId('sf-b-body').fill('Un dessert offert pour toute commande dans l\'application.');
    await page.getByTestId('sf-b-type').selectOption('flash');
    await page.getByTestId('sf-b-start').fill('');
    await page.getByTestId('sf-b-end').fill(parisLocal(72));
    await page.getByTestId('sf-b-label').fill('Voir la carte');
    await page.getByTestId('sf-b-dest').selectOption('#menu');
    await page.getByTestId('sf-b-priority').fill('50');
    await page.getByTestId('sf-b-save').click();
    await expect(page.getByTestId('sf-editor')).toHaveCount(0);
    await expect(page.getByTestId('sf-unpublished')).toBeVisible();

    // Publier (enregistre d'abord le brouillon des couleurs, puis version +1).
    await page.getByTestId('sf-publish').click();
    await expect(page.getByTestId('sf-publish-msg')).toContainText('publiée', { timeout: 30_000 });
    await expect(page.getByTestId('sf-phone-banner').filter({ hasText: title })).toHaveCount(1);
    // Juste après publication, rien n'est en attente (régression MySQL : clés JSON réordonnées).
    await expect(page.getByTestId('sf-unpublished')).toHaveCount(0);
    await expect(page.getByTestId('sf-publish')).toBeDisabled();
    await shot(page, '05-apres-1440', 1440, 900);
    await shot(page, '06-apres-390', 390, 844);

    // L'application lira exactement ceci.
    const res = await request.get('/api/frontend/storefront', { headers: { 'x-api-key': process.env.SF_API_KEY || '' } });
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.enabled).toBe(true);
    const mine = body.banners.find((b) => b.title === title);
    expect(mine, 'le bandeau publié est absent de l\'API publique').toBeTruthy();
    expect(mine.type).toBe('flash');
    expect(mine.button).toEqual({ label: 'Voir la carte', link: '#menu' });
    expect(body.theme.on_primary).toBe('#0A0A0A');
    const etag = res.headers()['etag'];
    const again = await request.get('/api/frontend/storefront', { headers: { 'x-api-key': process.env.SF_API_KEY || '', 'If-None-Match': etag } });
    expect(again.status()).toBe(304);

    // Deuxième publication (message de livraison), puis retour à la version précédente en un clic.
    const key = { 'x-api-key': process.env.SF_API_KEY || '' };
    const v1 = body.version;
    await page.getByTestId('sf-msg-on').fill('Livraison lancée à Hénin-Beaumont !');
    await page.getByTestId('sf-publish').click();
    await expect(page.getByTestId('sf-publish-msg')).toContainText(`Version ${v1 + 1} publiée`, { timeout: 30_000 });
    let pub = await (await request.get('/api/frontend/storefront', { headers: key })).json();
    expect(pub.version).toBe(v1 + 1);
    expect(pub.delivery.message).toBe('Livraison lancée à Hénin-Beaumont !');

    page.once('dialog', (d) => d.accept());
    await page.getByTestId('sf-rollback').click();
    await expect(page.getByTestId('sf-publish-msg')).toContainText(`Version ${v1} republiée sous le numéro ${v1 + 2}`, { timeout: 30_000 });
    await expect(page.getByTestId('sf-msg-on')).toHaveValue(''); // le brouillon revient aussi
    pub = await (await request.get('/api/frontend/storefront', { headers: key })).json();
    expect(pub.version).toBe(v1 + 2);
    expect(pub.delivery.message).toBe('Livraison disponible.');
    expect(pub.banners.find((b) => b.title === title)).toBeTruthy();
    await shot(page, '07-retour-arriere-1440', 1440, 900);

    const text = await page.locator('body').innerText();
    expect(text.match(RAW_LABEL), 'étiquette brute visible').toBeNull();
    // Le seul journal d'erreur admis : le 422 VOULU du refus de dates (Chromium journalise toute réponse 4xx). Rien d'autre.
    const expected422 = consoleErrors.filter((m) => /status of 422/.test(m));
    expect(expected422).toHaveLength(1);
    // Environnement, pas produit : les ponts d'impression LOCAUX (127.0.0.1:9100 / :9101, sonde /health de la coquille admin,
    // helpers/kioskPrinter.js) et le diffuseur temps réel (:6001) n'existent pas sur la machine de développement.
    // Toute AUTRE requête en échec fait échouer le banc.
    const ENV_ONLY = /ERR_CONNECTION_REFUSED http:\/\/127\.0\.0\.1:(9100\/health|9101\/health|6001\/)/;
    const refused = failedRequests.filter((r) => ENV_ONLY.test(r));
    expect(failedRequests.filter((r) => !ENV_ONLY.test(r)), 'requêtes en échec hors environnement').toEqual([]);
    expect(consoleErrors.filter((m) => !/status of 422/.test(m) && !(refused.length && /ERR_CONNECTION_REFUSED/.test(m))), 'erreurs console').toEqual([]);
});
