// [GOAL DASHBOARD-CONTRÔLE 2026-09-02] Campagne dédiée aux trois surfaces corrigées :
// tableau de bord analytique, cockpit santé/sauvegarde, cockpit outbox.
//
// Ce n'est pas un album de captures : chaque écran doit servir zéro réponse >= 400 et zéro
// libellé i18n brut, et trois comportements précisément corrigés sont rejoués dans le
// navigateur — la période personnalisée du graphique des ventes, la carte sauvegarde qui
// lit la restauration de vérification, et le cockpit outbox qui ne garde plus son vert
// quand il ne mesure plus rien.
//
// Lancement (serveur déjà en route, jamais démarré par Playwright) :
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//   npx playwright test tests/Playwright/dashboard-controle-captures-2026-09-02.spec.js
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { login } = require('../e2e/helpers/login');
const { clearFoodKingRateLimits } = require('../e2e/helpers/rate-limit');

const OUT = process.env.CAPTURE_DIR
    || path.join(__dirname, '..', '..', 'storage', 'captures', 'dashboard-controle-2026-09-02');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '123456';

const RAW_I18N_RE = /\b(?:label|message|menu|button|admin|validation|placeholder)\.[a-z0-9_]+(?:\.[a-z0-9_]+)+\b/gi;

const PAGES = [
    { name: '01-dashboard', url: '/admin/dashboard' },
    { name: '02-cockpit-sante', url: '/admin/observability/system' },
    { name: '03-cockpit-outbox', url: '/admin/observability/outbox' },
];

test.describe.configure({ mode: 'serial' });
test.setTimeout(420_000);

test('captures et comportements — tableau de bord et cockpits', async ({ page }) => {
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

    for (const entry of PAGES) {
        consoleErrors = [];
        badResponses = [];
        const started = Date.now();
        let navError = null;
        try {
            await page.goto(entry.url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
            await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => {});
            await page.waitForTimeout(2000);
        } catch (error) {
            navError = String(error && error.message).slice(0, 300);
        }
        const bodyText = await page.evaluate(() => (document.body ? document.body.innerText : '')).catch(() => '');
        const rawKeys = Array.from(new Set(bodyText.match(RAW_I18N_RE) || []));
        await page.screenshot({ path: path.join(OUT, `${entry.name}.png`), fullPage: true }).catch(() => {});
        fs.writeFileSync(path.join(OUT, `${entry.name}.txt`), bodyText);
        summary.push({
            name: entry.name,
            url: entry.url,
            finalUrl: page.url(),
            title: await page.title().catch(() => ''),
            ms: Date.now() - started,
            navError,
            consoleErrors: consoleErrors.slice(0, 20),
            badResponses: badResponses.slice(0, 20),
            rawI18nKeys: rawKeys.slice(0, 30),
            textLength: bodyText.length,
        });
    }

    // --- Comportement 1 : la période personnalisée renvoie enfin des chiffres -----------
    // Mesuré avant correctif : le sélecteur envoyait « Sun Mar 01 2026 00:00:00 GMT+0100
    // (heure normale d'Europe centrale) », que le serveur refuse de lire. On rejoue ici la
    // requête telle que la page la fabrique, depuis le contexte authentifié de la page.
    // On passe par `window.axios` — l'instance que la page utilise réellement, avec son
    // intercepteur et son jeton Sanctum. Un `fetch()` nu partirait sans en-tête
    // d'autorisation et rendrait 401 : le banc mesurerait alors son propre défaut, pas
    // celui du produit.
    const periode = await page.evaluate(async () => {
        const appel = async (url) => {
            try {
                const r = await window.axios.get(url);

                return { status: r.status, data: r.data };
            } catch (e) {
                return { status: e && e.response ? e.response.status : 0, data: null };
            }
        };

        const reponses = {};
        for (const point of ['order-statistics', 'order-summary', 'sales-summary', 'customer-states']) {
            reponses[point] = (await appel(`admin/dashboard/${point}?first_date=2026-03-01&last_date=2026-03-31`)).status;
        }
        reponses['inverse_doit_refuser'] =
            (await appel('admin/dashboard/sales-summary?first_date=2026-03-31&last_date=2026-03-01')).status;

        const dst = await appel('admin/dashboard/sales-summary?first_date=2026-03-28&last_date=2026-03-31');
        reponses['dst_status'] = dst.status;
        reponses['dst_jours'] = dst.data && dst.data.data ? dst.data.data.per_day_labels : null;

        return reponses;
    });
    fs.writeFileSync(path.join(OUT, 'periode-personnalisee.json'), JSON.stringify(periode, null, 2));

    // --- Comportement 2 : la carte sauvegarde lit la restauration ----------------------
    await page.goto('/admin/observability/system', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => {});
    await page.waitForTimeout(1500);
    const carteSauvegarde = await page.evaluate(() => {
        const el = document.querySelector('[data-testid="system-health-sauvegarde"]');
        const drill = document.querySelector('[data-testid="system-health-restauration"]');

        return el ? { texte: el.innerText, restauration: drill ? drill.innerText : null } : null;
    });
    fs.writeFileSync(path.join(OUT, 'carte-sauvegarde.json'), JSON.stringify(carteSauvegarde, null, 2));

    fs.writeFileSync(path.join(OUT, 'summary.json'), JSON.stringify(summary, null, 2));

    // ------------------------- Ce qui fait échouer le test -----------------------------
    const httpFailures = summary
        .filter((e) => e.badResponses.length)
        .map((e) => `${e.name}: ${e.badResponses.join(' | ')}`);
    expect(httpFailures, 'réponses HTTP >= 400 sur les trois écrans').toEqual([]);

    const rawLabels = summary
        .filter((e) => e.rawI18nKeys.length)
        .map((e) => `${e.name}: ${e.rawI18nKeys.join(', ')}`);
    expect(rawLabels, 'libellés i18n bruts visibles').toEqual([]);

    const vides = summary.filter((e) => e.textLength < 200).map((e) => e.name);
    expect(vides, 'écrans rendus vides').toEqual([]);

    // La période personnalisée : les quatre points répondent, l'inversée est refusée.
    expect(periode['order-statistics'], 'order-statistics sur période choisie').toBe(200);
    expect(periode['order-summary'], 'order-summary sur période choisie').toBe(200);
    expect(periode['sales-summary'], 'sales-summary sur période choisie').toBe(200);
    expect(periode['customer-states'], 'customer-states sur période choisie').toBe(200);
    expect(periode['inverse_doit_refuser'], 'période inversée refusée').toBe(422);

    // Le passage à l'heure d'été : quatre jours demandés, quatre jours rendus.
    expect(periode['dst_jours'], 'jours civils autour du 29 mars')
        .toEqual(['2026-03-28', '2026-03-29', '2026-03-30', '2026-03-31']);

    // La carte sauvegarde nomme l'état de la restauration de vérification.
    expect(carteSauvegarde, 'carte sauvegarde présente').not.toBeNull();
    expect(carteSauvegarde.restauration, 'la carte doit dire où en est la restauration').toBeTruthy();
    expect(carteSauvegarde.texte).toMatch(/[Rr]estauration/);
});
