/**
 * [CAYENNE HYGIÈNE 2026-10-18 · owner : « un seul bouton dans la barre latérale »] E2E réel : UNE entrée dans la barre, qui ouvre le hub ;
 * chaque outil du module s'ouvre depuis le hub, la ligne de la barre reste allumée, et la barre de l'écran ramène au hub en un appui.
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/Playwright/hygiene-hub-2026-10-18.spec.js
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURES || path.join(__dirname, '..', 'captures', 'hygiene-hub-2026-10-18');
fs.mkdirSync(OUT, { recursive: true });

test.setTimeout(240_000);

// Les anciennes lignes de la barre : aucune ne doit y revenir
const GONE = ['Étiquettes commandes', 'Étiquettes DLC / hygiène', "Imprimante d'étiquettes", 'Températures', 'Nettoyage', 'Réception', 'Traçabilité', 'Huile', 'Dossier de contrôle', 'Codes de l’équipe'];

test('barre latérale : une seule entrée ; le hub ouvre chaque outil ; la ligne reste allumée ; retour au hub en un appui', async ({ page }) => {
    const bad = [];
    page.on('response', (r) => { if (r.status() >= 400 && /\/api\//.test(r.url())) bad.push(`${r.status()} ${r.url()}`); });
    page.on('pageerror', (e) => { if (!/^(Failed to fetch|Network Error)$/.test(e.message)) bad.push(`pageerror: ${e.message}`); });
    await page.setViewportSize({ width: 1280, height: 900 });
    await loginAsAdmin(page);

    await page.goto('/admin/hygiene/home', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('home-tools')).toBeVisible({ timeout: 30_000 });

    // 1. la barre : une seule ligne pour tout le module, et c'est elle qui est allumée
    const side = page.locator('.db-sidebar');
    const hubRow = side.locator('a.db-sidebar-nav-menu', { hasText: 'Hygiène & étiquettes' });
    await expect(hubRow).toHaveCount(1);
    await expect(hubRow).toHaveClass(/router-link-active/);
    // la ligne du hub est visible SANS défiler la barre (elle était hors écran quand elle fermait la liste)
    const hb = await hubRow.boundingBox();
    expect(hb.y + hb.height, 'la ligne du hub est hors de la fenêtre').toBeLessThanOrEqual(900);
    const links = side.locator('a.db-sidebar-nav-menu');
    const hrefs = await links.evaluateAll((els) => els.map((e) => e.getAttribute('href')));
    expect(hrefs.filter((h) => /\/admin\/(hygiene|labels)/.test(h || ''))).toEqual(['/admin/hygiene/home']);
    const sideText = await side.innerText();
    for (const old of GONE) expect(sideText, `« ${old} » est revenu dans la barre`).not.toContain(old);
    await page.screenshot({ path: path.join(OUT, 'h1-hub-desktop.png'), fullPage: true });
    await side.screenshot({ path: path.join(OUT, 'h2-barre-laterale.png') });
    await page.getByTestId('home-tools').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, 'h1b-hub-autres-outils.png') });

    // 2. chaque outil du module s'ouvre depuis le hub, la ligne reste allumée, le fil d'Ariane ramène au hub
    const tiles = [
        ['[data-testid="home-tile-temperature"]', '/admin/hygiene/temperature'],
        ['[data-testid="home-tile-tasks"]', '/admin/hygiene/tasks'],
        ['[data-testid="home-tile-dlc"]', '/admin/labels/dlc'],
        ['[data-testid="home-tile-reception"]', '/admin/hygiene/reception'],
        ['[data-testid="home-tile-trace"]', '/admin/hygiene/trace'],
        ['[data-testid="home-tile-oil"]', '/admin/hygiene/oil'],
        ['[data-testid="home-tile-report"]', '/admin/hygiene/report'],
        ['[data-testid="home-tool-labels-orders"]', '/admin/labels/orders'],
        ['[data-testid="home-tool-labels-printer"]', '/admin/labels/printer'],
        ['[data-testid="home-alerts-link"]', '/admin/hygiene/alerts'],
        ['[data-testid="home-team-link"]', '/admin/hygiene/team'],
    ];
    let shot = 0;
    for (const [sel, url] of tiles) {
        await page.goto('/admin/hygiene/home', { waitUntil: 'domcontentloaded' });
        await expect(page.getByTestId('home-tools')).toBeVisible({ timeout: 30_000 });
        await page.locator(sel).scrollIntoViewIfNeeded();
        await page.locator(sel).click();
        await expect(page).toHaveURL(new RegExp(url.replace(/\//g, '\\/') + '$'), { timeout: 30_000 });
        await expect(hubRow, `${url} : la ligne du hub n'est plus allumée`).toHaveClass(/router-link-active/);
        // la page s'ouvre en HAUT, même quand on a cliqué tout en bas du hub (le conteneur qui défile est .db-main, pas la fenêtre)
        expect(await page.evaluate(() => (document.querySelector('.db-main') || {}).scrollTop || 0), `${url} s'ouvre défilée`).toBe(0);
        const back = page.getByTestId('hub-bar-link');
        await expect(back, `${url} : pas de retour au hub en tête de l'écran`).toBeVisible();
        await expect(back).toHaveAttribute('href', '/admin/hygiene/home');
        const bb = await back.boundingBox();
        expect(bb.height, `${url} : retour au hub trop petit`).toBeGreaterThanOrEqual(44);
        if (['/admin/labels/printer', '/admin/hygiene/team'].includes(url)) await page.waitForLoadState('networkidle');
        if (['/admin/labels/printer', '/admin/hygiene/team'].includes(url)) await page.screenshot({ path: path.join(OUT, `h3-${++shot}-${url.split('/').pop()}.png`) });
        await back.click();
        await expect(page).toHaveURL(/\/admin\/hygiene\/home$/, { timeout: 30_000 });
        await expect(page.getByTestId('home-tools')).toBeVisible({ timeout: 30_000 });
    }

    // 3. une page qui n'est pas du module n'allume pas la ligne et n'a pas de retour au hub
    await page.goto('/admin/pos-orders', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.db-sidebar')).toBeVisible({ timeout: 30_000 });
    await expect(hubRow).not.toHaveClass(/router-link-active/);
    await expect(page.getByTestId('hub-bar-link')).toHaveCount(0);

    expect(bad, `erreurs réseau/JS : ${bad.join(' | ')}`).toEqual([]);
});

test('téléphone 390 px : les outils du hub sont lisibles, cibles tactiles >= 44 px, aucun débordement', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await loginAsAdmin(page);
    await page.goto('/admin/hygiene/home', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('home-tools')).toBeVisible({ timeout: 30_000 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, 'défilement horizontal').toBeLessThanOrEqual(0);
    for (const t of ['home-tool-labels-orders', 'home-tool-labels-printer', 'home-alerts-link', 'home-team-link']) {
        const box = await page.getByTestId(t).boundingBox();
        expect(box.height, `${t} trop petit`).toBeGreaterThanOrEqual(44);
        expect(box.width, `${t} déborde`).toBeLessThanOrEqual(390);
    }
    await page.getByTestId('home-tools').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, 'm1-hub-mobile.png'), fullPage: true });
});

// Relecture adverse 2026-10-18 : les écrans d'étiquettes n'importent pas hygiene.css ; chargés DIRECTEMENT (F5, favori, lien), la barre « retour »
// était un lien nu de 24 px. Les parcours ci-dessus partaient du hub (feuille déjà chargée) et masquaient le défaut.
test('chargement DIRECT (F5, favori) : la barre « retour au hub » est une pastille de 44 px sur chaque écran du module', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await loginAsAdmin(page);
    for (const url of ['/admin/labels/orders', '/admin/labels/printer', '/admin/labels/dlc', '/admin/hygiene/temperature', '/admin/hygiene/report', '/admin/hygiene/team']) {
        await page.goto(url, { waitUntil: 'domcontentloaded' });
        const back = page.getByTestId('hub-bar-link');
        await expect(back, `${url} : pas de barre`).toBeVisible({ timeout: 30_000 });
        const m = await back.evaluate((el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return { h: r.height, border: cs.borderTopWidth, radius: cs.borderTopLeftRadius }; });
        expect(m.h, `${url} chargé en direct : barre de ${m.h} px`).toBeGreaterThanOrEqual(44);
        expect(m.border, `${url} : pas de bordure (feuille de style absente)`).toBe('2px');
        if (url === '/admin/labels/printer') await page.screenshot({ path: path.join(OUT, 'd1-imprimante-chargement-direct.png') });
    }
});

test('téléphone 390 px : le tiroir de la barre se referme après un appui sur « Hygiène & étiquettes » et le hub est visible', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await loginAsAdmin(page);
    await page.goto('/admin/hygiene/temperature', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('hub-bar-link')).toBeVisible({ timeout: 30_000 });
    const drawerOpen = () => page.evaluate(() => document.querySelector('.db-sidebar').classList.contains('active'));
    expect(await drawerOpen()).toBe(false);
    await page.locator('button.db-header-nav').click();
    await expect.poll(drawerOpen).toBe(true);
    await page.screenshot({ path: path.join(OUT, 'm2-tiroir-ouvert.png') });
    await page.locator('.db-sidebar a.db-sidebar-nav-menu', { hasText: 'Hygiène & étiquettes' }).click();
    await expect(page).toHaveURL(/\/admin\/hygiene\/home$/, { timeout: 30_000 });
    await expect.poll(drawerOpen, { message: 'le tiroir est resté ouvert devant le hub' }).toBe(false);
    await expect(page.getByTestId('home-tools')).toBeVisible({ timeout: 30_000 });
    // le tiroir glisse (transition CSS) : mesurer la position FINALE, pas le milieu de l'animation
    await page.waitForTimeout(700);
    const x = await page.locator('.db-sidebar').evaluate((el) => el.getBoundingClientRect().right);
    expect(x, 'le tiroir recouvre encore le hub une fois la transition finie').toBeLessThanOrEqual(0);
    await page.screenshot({ path: path.join(OUT, 'm3-hub-apres-tiroir.png') });
});
