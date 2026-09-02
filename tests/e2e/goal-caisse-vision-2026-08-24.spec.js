// FoodKing E2E — GOAL CAISSE VISION (2026-08-24)
//
// Demande propriétaire (verbatim) :
//   « faudrait pas juste voir le total, vaut mieux voir si je clique sur voir tout
//     toute la liste … mettre même les noms de produits … si j'ai un client devant
//     moi, j'ai pas pris son nom, je peux voir ce qu'il a pris et toutes les
//     personnalisations qu'il a fait … surtout la rapidité et l'interface. »
//
// CE QUE CETTE SPEC REFUSE (chaque assertion = un défaut mesuré avant correctif) :
//   1. La carte de suivi ne montrait AUCUNE personnalisation — `SimpleOrderResource`
//      n'expédiait que item_name/quantity/instruction. Deux sandwichs identiques
//      commandés différemment étaient indistinguables.
//   2. Il n'existait pas de « voir tout » : la carte coupait à 3 lignes et le reste
//      exigeait un changement de page.
//   3. Une commande TÉLÉPHONE s'affichait « 🛒 Caisse » — indistinguable d'une vente
//      au comptoir, alors que le client n'est PAS là.
//
// Le scénario est monté sur des données RÉELLES (tinker), pas sur des fixtures
// injectées dans le composant : une fixture qui encode le bug ne prouve rien.

const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

/**
 * [2026-09-01] Plus aucun identifiant d'article figé dans les fixtures de cette spec.
 * Cliquet : tests/js/e2eFixturesSansIdentifiantCode.spec.js. Le NOM est la clé stable —
 * les identifiants, eux, ont dérivé (relevé du 2026-08-25 : 27 des 36 identifiants codés
 * en dur dans tests/e2e/ ne visaient plus aucun article existant). On résout donc l'article
 * à l'exécution, dans la même commande artisan que le semis.
 *
 * On ne filtre PAS sur `status = 5` : semer un HISTORIQUE de commandes doit pouvoir viser un
 * article devenu inactif depuis (« Sandwich Classique » et « Big Tacos » sont a status = 10).
 * `orderBy('id')` rend la resolution deterministe : « Sandwich Classique » existe en double
 * parmi les articles non supprimes.
 */
function phpItemId(nom) {
    const n = String(nom).replace(/'/g, "\\'");
    return `(int) DB::table('items')->whereNull('deleted_at')->where('name', '${n}')->orderBy('id')->value('id')`;
}


const REPO_ROOT = path.resolve(__dirname, '..', '..');
const SHOTS_DIR = path.join(REPO_ROOT, 'reports', 'goal-caisse-vision-2026-08-24', 'captures');
if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });

const TRACKER_URL = '/admin/pos-orders-tracker';
const TOKEN = 'GCV24';

const FATAL_ERR_FILTER =
    /(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|webp|woff)|GoogleAnalytics|gtag|workbox|Failed to load resource:|Pusher|Echo|Mixpanel|sentry|Manifest|AudioContext)/i;

function php(snippet) {
    const res = spawnSync('php', ['artisan', 'tinker', '--execute', snippet], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
        timeout: 60_000,
    });
    return (res.stdout || '') + (res.stderr || '');
}

/**
 * Sème une commande PORTANT UNE VRAIE COMPOSITION dans `composition_snapshot`
 * — la forme NF525 réelle produite par CompositionSnapshotBuilder, rôles
 * inversés compris (attribute_name = libellé, variation_name = valeur).
 */
function seedOrdreComposee({ suffix, sourceSurface, statusInt, typeInt, lignes }) {
    const lignesPhp = lignes.map((l) => {
        const snap = JSON.stringify({
            schema_version: 1,
            lines: l.options || [],
            extras: l.extras || [],
            addons: l.addons || [],
        }).replace(/'/g, "\\'");
        return (
            `$i = new App\\Models\\OrderItem; ` +
            `$i->order_id = $o->id; $i->branch_id = 1; $i->item_id = ${phpItemId(l.itemName)}; ` +
            `$i->quantity = ${l.qty}; $i->price = 6.50; $i->total_price = ${(l.qty * 6.5).toFixed(2)}; ` +
            `$i->discount = 0; $i->tax_rate = '0'; $i->tax_amount = 0; $i->tax_type = 1; ` +
            `$i->item_variation_total = 0; $i->item_extra_total = 0; ` +
            `$i->composition_snapshot = json_decode('${snap}', true); ` +
            (l.instruction ? `$i->instruction = '${l.instruction.replace(/'/g, "\\'")}'; ` : '') +
            `$i->save(); `
        );
    }).join('');

    const snippet =
        `$o = new App\\Models\\Order; ` +
        `$o->order_serial_no = '${TOKEN}-${suffix}'; ` +
        `$o->user_id = 1; $o->branch_id = 1; ` +
        `$o->subtotal = 19.40; $o->total = 19.40; ` +
        `$o->order_type = ${typeInt}; ` +
        `$o->source_surface = '${sourceSurface}'; ` +
        `$o->order_datetime = now('UTC'); $o->preparation_time = 5; ` +
        `$o->is_advance_order = App\\Enums\\Ask::NO; ` +
        `$o->payment_method = 1; $o->payment_status = 10; ` +
        `$o->status = ${statusInt}; ` +
        `$o->business_date = now()->toDateString(); ` +
        `$o->save(); ` +
        lignesPhp +
        `echo $o->id;`;

    const out = php(snippet);
    const m = out.match(/(\d+)\s*$/);
    return m ? parseInt(m[1], 10) : null;
}

function cleanup() {
    php(
        `App\\Models\\Order::where('order_serial_no', 'like', '${TOKEN}-%')->withoutGlobalScopes()->each(function ($o) { ` +
        `App\\Models\\OrderItem::where('order_id', $o->id)->withoutGlobalScopes()->forceDelete(); ` +
        `DB::table('orders')->where('id', $o->id)->update(['fiscal_sequence_no' => null]); ` +
        `$o->forceDelete(); }); echo 'ok';`
    );
}

test.describe('GOAL CAISSE VISION — voir ce que le client a pris', () => {
    test.setTimeout(180_000);

    test.beforeAll(() => {
        cleanup();

        // Une commande comptoir richement composée : 4 lignes, dont une avec
        // extras + suppléments + allergie. C'est LE cas du propriétaire.
        seedOrdreComposee({
            suffix: 'COMPO',
            sourceSurface: 'pos',
            statusInt: 7,  // PREPARING
            typeInt: 15,   // POS
            lignes: [
                {
                    itemName: 'Cayenne', qty: 2,
                    options: [
                        { variation_id: 1, attribute_name: 'Pain', variation_name: 'Galette', quantity: 1 },
                        { variation_id: 2, attribute_name: 'Sauce', variation_name: 'Algerienne', quantity: 1 },
                        { variation_id: 3, attribute_name: 'Cuisson', variation_name: 'Bien cuit', quantity: 1 },
                    ],
                    extras: [
                        { extra_id: 1, extra_name: 'Cheddar', quantity: 2 },
                        { extra_id: 2, extra_name: 'Salade', quantity: 1 },
                    ],
                    instruction: 'Sans oignons - allergie',
                },
                {
                    itemName: 'Tacos M', qty: 1,
                    options: [{ variation_id: 4, attribute_name: 'Viande', variation_name: 'Poulet marine', quantity: 1 }],
                    addons: [{ addon_id: 1, addon_name: 'Frites', role: 'menu_frites', quantity: 1, line_total: 1.2 }],
                },
                { itemName: 'Cayenne', qty: 1, options: [{ variation_id: 5, attribute_name: 'Sauce', variation_name: 'Blanche', quantity: 1 }] },
                { itemName: 'Tacos M', qty: 3 },
            ],
        });

        // Une commande TÉLÉPHONE — le canal que le suivi confondait avec la caisse.
        seedOrdreComposee({
            suffix: 'TEL',
            sourceSurface: 'phone',
            statusInt: 7,
            typeInt: 15,
            lignes: [{ itemName: 'Cayenne', qty: 1, options: [{ variation_id: 6, attribute_name: 'Sauce', variation_name: 'Samourai', quantity: 1 }] }],
        });
    });

    test.afterAll(() => {
        cleanup();
    });

    test('la carte de suivi montre les personnalisations, et « Voir tout » ouvre le contenu complet', async ({ page }) => {
        const consoleErrors = [];
        page.on('console', (msg) => {
            if (msg.type() === 'error' && !FATAL_ERR_FILTER.test(msg.text())) consoleErrors.push(msg.text());
        });

        await loginAsAdmin(page);
        await page.goto(TRACKER_URL, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('.pos-tracker-card', { timeout: 30_000 });

        // ── 1. La composition est VISIBLE sur la carte, sans aucun geste ──────
        const carteCompo = page.locator('.pos-tracker-card', { hasText: 'Galette' }).first();
        await expect(carteCompo).toBeVisible({ timeout: 15_000 });
        const texteCarte = await carteCompo.innerText();
        expect(texteCarte).toContain('Galette');
        expect(texteCarte).toContain('Algerienne');
        expect(texteCarte).toContain('Cheddar');

        await page.screenshot({ path: path.join(SHOTS_DIR, '01-carte-avec-composition.png'), fullPage: false });

        // ── 2. « Voir tout » ouvre le contenu COMPLET, sans appel réseau ──────
        const requetes = [];
        page.on('request', (r) => requetes.push(r.url()));

        const voirTout = carteCompo.locator('[data-testid^="tracker-voir-tout-"]');
        await expect(voirTout).toBeVisible();
        await voirTout.click();

        const panneau = page.locator('[data-testid="tracker-contenu-overlay"]');
        await expect(panneau).toBeVisible({ timeout: 5_000 });

        // Les 4 lignes, pas les 3 de la carte.
        await expect(panneau.locator('[data-testid^="tracker-contenu-ligne-"]')).toHaveCount(4);

        const textePanneau = await panneau.innerText();
        // Toutes les personnalisations, en toutes lettres.
        for (const attendu of ['Galette', 'Algerienne', 'Bien cuit', 'Cheddar', 'Salade', 'Poulet marine', 'Frites', 'Blanche']) {
            expect(textePanneau, `« ${attendu} » doit apparaître dans le panneau`).toContain(attendu);
        }
        // L'allergie, jamais tronquée.
        expect(textePanneau).toContain('Sans oignons - allergie');
        // Aucun libellé technique / clé i18n non résolue / valeur JS crue.
        expect(textePanneau).not.toMatch(/undefined|\[object|label\.|pos\.tracker\./);

        await page.screenshot({ path: path.join(SHOTS_DIR, '02-panneau-voir-tout.png'), fullPage: false });

        // Ouvrir le panneau ne doit avoir déclenché AUCUNE requête applicative.
        const requetesApi = requetes.filter((u) => /\/api\/admin\//.test(u));
        expect(requetesApi, `ouvrir « Voir tout » a déclenché ${requetesApi.length} appel(s) API`).toHaveLength(0);

        // ── 3. Échap referme ─────────────────────────────────────────────────
        await page.keyboard.press('Escape');
        await expect(panneau).toBeHidden({ timeout: 5_000 });

        expect(consoleErrors, `erreurs console: ${consoleErrors.join(' | ')}`).toHaveLength(0);
    });

    // La composition a fait passer la ligne produit en `flex-wrap: wrap`. Il faut
    // prouver que les cartes SANS personnalisation n'ont pas bougé, et que rien ne
    // déborde sur les deux écrans réels du comptoir (PROJECT_BRAIN §2 : 1366×768
    // et 1024×600, ce dernier étant le gabarit le plus contraint).
    for (const { l, h } of [{ l: 1366, h: 768 }, { l: 1024, h: 600 }]) {
        test(`la mise en page tient en ${l}×${h}, sans débordement horizontal`, async ({ page }) => {
            await page.setViewportSize({ width: l, height: h });
            await loginAsAdmin(page);
            await page.goto(TRACKER_URL, { waitUntil: 'domcontentloaded' });
            await page.waitForSelector('.pos-tracker-card', { timeout: 30_000 });

            // Le corps de page ne défile jamais horizontalement.
            const deborde = await page.evaluate(() =>
                document.documentElement.scrollWidth > document.documentElement.clientWidth + 1
            );
            expect(deborde, `débordement horizontal à ${l}×${h}`).toBe(false);

            // Chaque ligne de composition reste dans sa carte (pas de fuite latérale).
            const fuites = await page.evaluate(() => {
                const out = [];
                document.querySelectorAll('.pos-tracker-card').forEach((carte) => {
                    const bc = carte.getBoundingClientRect();
                    carte.querySelectorAll('.pos-tracker-card-compo').forEach((c) => {
                        const bl = c.getBoundingClientRect();
                        if (bl.right > bc.right + 1) out.push(c.textContent.trim().slice(0, 30));
                    });
                });
                return out;
            });
            expect(fuites, `composition hors carte: ${fuites.join(' | ')}`).toHaveLength(0);

            await page.screenshot({ path: path.join(SHOTS_DIR, `04-mise-en-page-${l}x${h}.png`), fullPage: false });
        });
    }

    test('une commande téléphone n\'est plus confondue avec une vente au comptoir', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto(TRACKER_URL, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('.pos-tracker-card', { timeout: 30_000 });

        // Le pictogramme 📞 doit être présent sur le tableau…
        const carteTel = page.locator('.pos-tracker-card', { hasText: 'Samourai' }).first();
        await expect(carteTel).toBeVisible({ timeout: 15_000 });
        const puce = carteTel.locator('.pos-tracker-card-source');
        await expect(puce).toContainText('📞');

        // …et l'onglet de filtre « Téléphone » doit être apparu de lui-même.
        const onglets = page.locator('.pos-tracker-source-tab');
        await expect(onglets.filter({ hasText: 'Téléphone' })).toHaveCount(1);

        await page.screenshot({ path: path.join(SHOTS_DIR, '03-canal-telephone.png'), fullPage: false });
    });
});
