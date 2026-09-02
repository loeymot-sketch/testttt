// [GOAL DASHBOARD-CATALOGUE 2026-09-02] Captures « en tant qu'admin » des écrans de gestion
// catalogue : studio, liste produits, tiroir création, catégories, composeur (Tacos, Sandwichs),
// hub, fiche produit, attributs. Chaque écran est photographié plein cadre, avec relevé des
// erreurs console, des réponses HTTP ≥ 400 et des libellés i18n bruts visibles.
//
// Lancement (serveur déjà en route, jamais démarré par Playwright) :
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//   CAPTURE_DIR=/chemin/de/sortie npx playwright test tests/Playwright/dashboard-catalogue-captures-2026-09-02.spec.js
//
// Les PNG ne sont PAS commités (disque à 98 %) : la spec les régénère.
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { login } = require('../e2e/helpers/login');
const { clearFoodKingRateLimits } = require('../e2e/helpers/rate-limit');

const OUT = process.env.CAPTURE_DIR
    || path.join(__dirname, '..', '..', 'storage', 'captures', 'dashboard-catalogue-2026-09-02');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '123456';

const RAW_I18N_RE = /\b(?:label|message|menu|button|studio|validation|placeholder|composer)\.[a-z0-9_]+(?:\.[a-z0-9_]+)*\b/gi;

const PAGES = (process.env.CAPTURE_PAGES
    ? process.env.CAPTURE_PAGES.split(',').map((entry) => {
        const [name, url] = entry.split('=');
        return { name, url };
    })
    : [
        { name: '01-dashboard', url: '/admin/dashboard' },
        { name: '02-items-studio', url: '/admin/items/studio' },
        { name: '03-items-list', url: '/admin/items' },
        { name: '04-items-create-drawer', url: '/admin/items?create=1' },
        { name: '05-item-show-tacos-m', url: '/admin/items/show/26' },
        { name: '06-categories-list', url: '/admin/settings/item-categories' },
        { name: '07-composer-tacos', url: '/admin/categories/5/composer' },
        { name: '08-composer-sandwichs', url: '/admin/categories/1/composer' },
        { name: '09-composer-frites', url: '/admin/categories/7/composer' },
        { name: '10-catalog-hub', url: '/admin/catalog-hub' },
        { name: '11-item-attributes', url: '/admin/settings/item-attributes/list' },
        { name: '12-studio-tacos', url: '/admin/items/studio?item_category_id=5' },
    ]);

test.describe.configure({ mode: 'serial' });
test.setTimeout(420_000);

test('captures catalogue admin', async ({ page }) => {
    fs.mkdirSync(OUT, { recursive: true });
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });

    const summary = [];
    let consoleErrors = [];
    let badResponses = [];
    page.on('console', (msg) => {
        if (['error', 'warning'].includes(msg.type())) {
            consoleErrors.push(`${msg.type()}: ${msg.text().slice(0, 300)}`);
        }
    });
    page.on('pageerror', (err) => consoleErrors.push(`pageerror: ${String(err).slice(0, 300)}`));
    page.on('response', (res) => {
        if (res.status() >= 400) {
            badResponses.push(`${res.status()} ${res.request().method()} ${res.url()}`);
        }
    });

    clearFoodKingRateLimits();
    await login(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.waitForTimeout(1500);

    // Inventaire du menu latéral tel que l'admin le voit.
    const sidebar = await page.evaluate(() => Array.from(document.querySelectorAll('a[href]'))
        .map((a) => ({ href: a.getAttribute('href'), text: (a.textContent || '').trim().replace(/\s+/g, ' ') }))
        .filter((l) => l.href && l.href.startsWith('/admin')));
    fs.writeFileSync(path.join(OUT, 'sidebar-links.json'), JSON.stringify(sidebar, null, 2));

    for (const entry of PAGES) {
        consoleErrors = [];
        badResponses = [];
        const started = Date.now();
        let navError = null;
        try {
            await page.goto(entry.url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
            await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => {});
            await page.waitForTimeout(1500);
        } catch (error) {
            navError = String(error && error.message).slice(0, 300);
        }
        const finalUrl = page.url();
        const bodyText = await page.evaluate(() => document.body ? document.body.innerText : '').catch(() => '');
        const rawKeys = Array.from(new Set((bodyText.match(RAW_I18N_RE) || [])));
        const title = await page.title().catch(() => '');
        const shot = path.join(OUT, `${entry.name}.png`);
        await page.screenshot({ path: shot, fullPage: true }).catch(() => {});
        summary.push({
            name: entry.name,
            url: entry.url,
            finalUrl,
            title,
            ms: Date.now() - started,
            navError,
            consoleErrors: consoleErrors.slice(0, 20),
            badResponses: badResponses.slice(0, 20),
            rawI18nKeys: rawKeys.slice(0, 30),
            textLength: bodyText.length,
        });
        fs.writeFileSync(path.join(OUT, `${entry.name}.txt`), bodyText);
    }

    fs.writeFileSync(path.join(OUT, 'summary.json'), JSON.stringify(summary, null, 2));

    // La capture n'est pas qu'un album : ces deux constats-là échouent le test.
    // 1. Aucune page du catalogue ne doit servir une réponse HTTP >= 400.
    //    Seule exception, documentée : `GET .../composer/categories/{id}/profile` répond 404 quand la
    //    catégorie n'a PAS ENCORE de parcours. C'est la sémantique correcte et l'écran la traite
    //    (« Ajoutez une page pour commencer. »). Toute autre 4xx/5xx échoue le test.
    const ALLOWED = /^404 GET \S+\/api\/admin\/composer\/categories\/\d+\/profile$/;
    const httpFailures = summary
        .map((entry) => ({ ...entry, bad: entry.badResponses.filter((r) => !ALLOWED.test(r)) }))
        .filter((entry) => entry.bad.length)
        .map((entry) => `${entry.name}: ${entry.bad.join(' | ')}`);
    expect(httpFailures, 'réponses HTTP >= 400 sur les écrans catalogue').toEqual([]);

    // 2. Aucune clé i18n brute (« label.x.y ») ne doit être lisible à l'écran.
    const rawLabels = summary
        .filter((entry) => entry.rawI18nKeys.length)
        .map((entry) => `${entry.name}: ${entry.rawI18nKeys.join(', ')}`);
    expect(rawLabels, 'libellés i18n bruts visibles').toEqual([]);
});
