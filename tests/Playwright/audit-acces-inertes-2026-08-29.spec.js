const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const { login } = require('../e2e/helpers/login');

/**
 * Preuve COMPORTEMENTALE que trois permissions du SPA sont décoratives.
 *
 * Constat de lecture (vérifié en base le 2026-08-29) : `appService.recursiveRouter`
 * (`resources/js/services/appService.js:209`) ne pose `meta.access` que si
 * `meta.permissionUrl === permission.url` — comparaison de chaînes, exacte. Or :
 *
 *   `ingredients_manage`  -> colonne `url` = NULL          -> aucune correspondance
 *   `catalog.compose`     -> colonne `url` = NULL          -> aucune correspondance
 *   `items_create`        -> colonne `url` = 'items/create' -> la route déclare 'items_create'
 *
 * Sans correspondance, `meta.access` reste `undefined`, et la garde (`router/index.js:309`)
 * ne se déclenche que sur `access === false`. La page s'ouvre donc pour TOUT LE MONDE.
 *
 * Une lecture de code ne prouve pas un comportement : ce test ouvre réellement les pages
 * avec un compte restreint. Il distingue deux choses très différentes —
 *   - l'écran s'ouvre mais l'API répond 403  -> défaut d'expérience (page cassée, pas de refus clair)
 *   - l'écran s'ouvre ET l'API répond        -> trou de contrôle d'accès réel
 */

const SORTIE = process.env.AUDIT_OUT || '/tmp/audit-acces';
fs.mkdirSync(SORTIE, { recursive: true });

const PAGES_SOUS_PERMISSION_INERTE = [
    { cle: 'ingredients', url: '/admin/ingredients', cle_perm: 'ingredients_manage' },
    { cle: 'achat-scan', url: '/admin/purchasing/scan', cle_perm: 'items_create' },
    // Supervision — la permission déclarée est `dashboard`, la plus faible du back-office :
    // tout compte qui voit le tableau de bord passe. Et l'entrée EXISTE dans la barre
    // latérale (table `menus` id 33, « System Health » -> `observability/system`), donc ce
    // n'est pas une page qu'il faut deviner : elle est proposée au caissier.
    { cle: 'observabilite-systeme', url: '/admin/observability/system', cle_perm: 'dashboard' },
    { cle: 'observabilite-outbox', url: '/admin/observability/outbox', cle_perm: 'dashboard' },
];

/** Témoin : une page dont la permission, elle, correspond bien. */
const PAGE_TEMOIN = { cle: 'temoin-employes', url: '/admin/employees', cle_perm: 'employees' };

test.describe('Contrôle d\'accès — les permissions inertes', () => {
    test.describe.configure({ mode: 'serial', timeout: 240_000 });

    test('un compte restreint ouvre-t-il les pages qu\'il ne devrait pas ?', async ({ page }) => {
        const appels = [];
        page.on('response', (r) => {
            if (r.url().includes('/api/')) appels.push({ statut: r.status(), url: r.url() });
        });

        await login(page, process.env.E2E_POS_USER || 'pos@lecayenne.fr',
            process.env.E2E_POS_PASS || '123456');
        await page.waitForTimeout(2500);

        const resultats = [];
        for (const p of [...PAGES_SOUS_PERMISSION_INERTE, PAGE_TEMOIN]) {
            appels.length = 0;
            await page.goto(p.url, { waitUntil: 'networkidle', timeout: 60_000 }).catch(() => {});
            await page.waitForTimeout(3000);

            const etat = await page.evaluate(() => ({
                adresse: location.pathname,
                texte: (document.body.innerText || '').trim().slice(0, 200).replace(/\s+/g, ' '),
                taille: (document.body.innerText || '').trim().length,
            }));

            const refusApi = appels.filter((a) => a.statut === 401 || a.statut === 403);
            const restee = etat.adresse === p.url;

            resultats.push({
                ...p,
                adresseFinale: etat.adresse,
                pageOuverte: restee,
                apercu: etat.texte,
                appelsApi: appels.length,
                refusApi: refusApi.length,
                verdict: !restee
                    ? 'REDIRIGE — la garde a fonctionné'
                    : (refusApi.length > 0
                        ? 'OUVERTE mais API refuse — page cassée, pas de refus clair'
                        : 'OUVERTE et API répond — la permission ne protège rien'),
            });

            await page.screenshot({ path: `${SORTIE}/${p.cle}.png`, fullPage: true });
            // eslint-disable-next-line no-console
            console.log(`[acces] ${p.cle} (${p.cle_perm}) -> ${resultats.at(-1).verdict}`);
        }

        fs.writeFileSync(`${SORTIE}/acces.json`, JSON.stringify(resultats, null, 2));
        expect(resultats.length).toBe(5);
    });
});
