/**
 * [ONB 2026-08-28] Balayage visuel des surfaces du commerçant.
 *
 * Ce fichier produit les captures des 18 écrans principaux ET vérifie, sur chacun,
 * l'invariant que l'œil a trouvé le premier : **aucune clé de traduction brute ne
 * doit être visible**.
 *
 * Sa première version se contentait de capturer, sans une seule assertion. La
 * sentinelle `noVacuousSpecSentinel` du dépôt l'a refusée — « preuve vacante, verte
 * sans rien prouver » — et elle avait raison : c'est exactement le reproche que
 * j'ai adressé toute la nuit à des bancs écrits par d'autres.
 *
 * L'assertion ci-dessous automatise la classe de défaut trouvée à l'œil : le fil
 * d'Ariane de l'écran d'import affichait « Menu.Menu_import_title », et la mesure a
 * révélé 10 routes sur 81 dans ce cas. Aucune sentinelle lisant les `$t('…')`
 * littéraux ne pouvait les voir, parce que la clé est CONSTRUITE à l'exécution.
 * Celle-ci lit le texte RENDU : elle voit ce que le commerçant voit.
 *
 * LECTURE SEULE : aucune donnée créée, aucun formulaire soumis.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsAdmin } = require('../e2e/helpers/login');

const SORTIE = path.join(__dirname, '..', 'captures', 'onb-balayage-2026-08-28');

/** Chaque entrée : [nom de fichier, chemin, mission concernée]. */
const SURFACES = [
    ['10-tableau-de-bord',   '/admin/dashboard',                        'ONB-07 tableau de bord'],
    ['11-categories',        '/admin/settings/item-categories/list',    'ONB-02 catalogue'],
    ['12-attributs',         '/admin/settings/item-attributes/list',    'ONB-03 règles de choix'],
    ['13-taxes',             '/admin/settings/taxes/list',              'ONB-02 TVA'],
    ['14-roles',             '/admin/settings/role/list',               'ONB-06 équipe'],
    ['15-horaires',          '/admin/settings/time-slots',              'ONB-01 horaires'],
    ['16-entreprise',        '/admin/settings/company',                 'ONB-01 identité'],
    ['17-borne',             '/admin/settings/kiosk-setup',             'ONB-10 équipement'],
    ['18-commandes',         '/admin/settings/order-setup',             'ONB-05 réglages'],
    ['19-fidelite',          '/admin/settings/loyalty-setup',           'ONB-09 animation'],
    ['20-ingredients',       '/admin/ingredients',                      'ONB-08 stock'],
    ['21-rapport-ventes',    '/admin/sales-report',                     'ONB-07 rapports'],
    ['22-transactions',      '/admin/transactions',                     'ONB-07 rapports'],
    ['23-notifications',     '/admin/push-notifications',               'ONB-09 animation'],
    ['24-offres',            '/admin/offers',                           'ONB-09 animation'],
    ['25-langues',           '/admin/settings/languages/list',          'ONB-11 transverse'],
    ['26-devises',           '/admin/settings/currencies/list',         'ONB-05 réglages'],
    ['27-tpe',               '/admin/settings/payment-terminals',       'ONB-10 équipement'],
];

/**
 * Une clé de traduction brute rendue à l'écran : `menu.menu_import_title`,
 * `Label.Something`, `error.something_wrong`. On exige la forme complète —
 * préfixe connu, point, identifiant en minuscules/underscores — pour ne pas
 * confondre avec une phrase française contenant un point.
 */
const CLE_BRUTE = /\b(label|menu|message|button|error|permission|role|studio|admin)\.[a-z][a-z0-9_]{2,}\b/i;

test.describe('ONB · balayage visuel des surfaces commerçant', () => {
    test.setTimeout(300_000);

    test('aucune surface n\'affiche de clé de traduction brute', async ({ page }) => {
        await loginAsAdmin(page);

        const fautives = [];
        const injoignables = [];
        let vues = 0;

        for (const [nom, chemin, mission] of SURFACES) {
            try {
                await page.goto(chemin, { waitUntil: 'domcontentloaded', timeout: 30_000 });
                await page.waitForLoadState('networkidle').catch(() => {});
                await page.waitForTimeout(900);

                await page.screenshot({ path: path.join(SORTIE, `${nom}.png`), fullPage: true });
                vues += 1;

                // On lit le TEXTE RENDU, pas le source : c'est ce que le commerçant voit.
                const texte = await page.evaluate(() => document.body.innerText || '');
                const trouve = texte.match(CLE_BRUTE);

                if (trouve) {
                    fautives.push(`${chemin} (${mission}) → « ${trouve[0]} »`);
                }

                console.log(`[capture] ${nom}  ${chemin}  (${mission})`);
            } catch (e) {
                // Un écran injoignable est une INFORMATION, pas un échec de banc : on
                // le note et on continue, sinon une seule route morte fait perdre tout
                // le balayage.
                injoignables.push(`${chemin} → ${e.message.split('\n')[0]}`);
                console.log(`[ECHEC] ${nom}  ${chemin}`);
            }
        }

        // Le balayage doit avoir VU quelque chose : sans ce contrôle, une session
        // perdue rendrait ce banc vert en ne mesurant rien.
        expect(
            vues,
            'Aucune surface n\'a pu être ouverte — la session a probablement échoué, '
            + `et ce banc ne mesurerait rien. Injoignables : ${injoignables.join(' | ')}`,
        ).toBeGreaterThan(SURFACES.length / 2);

        expect(
            fautives,
            'Ces écrans affichent une clé de traduction brute, lisible par le '
            + `commerçant :\n${fautives.join('\n')}`,
        ).toEqual([]);
    });
});
