// FoodKing E2E — SUPERVISEUR CAISSE, VAGUE A : SUIVI DES COMMANDES
// ================================================================
// Phase CAPTURE UNIQUEMENT. Cette spec ne corrige rien, n'assainit rien et
// n'affirme rien : elle SÈME des données réelles, ouvre `/admin/pos-orders-tracker`
// et écrit le quartet d'artefacts (.png / .dom.html / .console.json / .network.json)
// pour dix états. Les constats sont imprimés en fin de course pour le rapport.
//
// Les assertions sont volontairement RARES : une capture qui échoue ne capture
// plus rien. Chaque état est protégé, et son résultat — atteint ou NON ATTEINT —
// est consigné plutôt que masqué.
//
// Préfixe EXCLUSIF de cette vague : `AUDA-` (d'autres vagues sèment en parallèle).

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
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const REPO_ROOT = path.resolve(__dirname, '..', '..');
const SHOTS_DIR = path.join(REPO_ROOT, 'tests', 'e2e', '__screenshots__', 'test-e2e-waveA');
const TRACKER_URL = '/admin/pos-orders-tracker';
const TOKEN = 'AUDA';

/** Constats accumulés — imprimés à la fin, repris tels quels dans le rapport. */
const constats = [];
function noter(etat, attendu, vu) {
    constats.push({ etat, attendu, vu });
    console.log(`\n[WAVE-A] ${etat}\n   attendu : ${attendu}\n   vu      : ${vu}`);
}

function php(snippet) {
    const res = spawnSync('php', ['artisan', 'tinker', '--execute', snippet], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
        timeout: 90_000,
    });
    return (res.stdout || '') + (res.stderr || '');
}

/**
 * Sème une commande avec sa composition dans `composition_snapshot` — la forme
 * NF525 réelle (attribute_name = libellé, variation_name = valeur), celle que
 * `CompositionCompactor` lit côté serveur.
 */
function seedOrdre({
    suffix,
    sourceSurface,
    statusInt,
    typeInt,
    lignes,
    datetimeExpr = "now('UTC')",
    posName = null,
    posPhone = null,
    total = 19.4,
}) {
    const lignesPhp = lignes
        .map((l) => {
            const snap = JSON.stringify({
                schema_version: 1,
                lines: l.options || [],
                extras: l.extras || [],
                addons: l.addons || [],
            }).replace(/'/g, "\\'");
            const aQuelqueChose = (l.options || []).length || (l.extras || []).length || (l.addons || []).length;
            return (
                `$i = new App\\Models\\OrderItem; ` +
                `$i->order_id = $o->id; $i->branch_id = 1; $i->item_id = ${phpItemId(l.itemName)}; ` +
                `$i->quantity = ${l.qty}; $i->price = 6.50; $i->total_price = ${(l.qty * 6.5).toFixed(2)}; ` +
                `$i->discount = 0; $i->tax_rate = '0'; $i->tax_amount = 0; $i->tax_type = 1; ` +
                `$i->item_variation_total = 0; $i->item_extra_total = 0; ` +
                (aQuelqueChose ? `$i->composition_snapshot = json_decode('${snap}', true); ` : '') +
                (l.instruction ? `$i->instruction = '${l.instruction.replace(/'/g, "\\'")}'; ` : '') +
                `$i->save(); `
            );
        })
        .join('');

    const snippet =
        `$o = new App\\Models\\Order; ` +
        `$o->order_serial_no = '${TOKEN}-${suffix}'; ` +
        `$o->user_id = 1; $o->branch_id = 1; ` +
        `$o->subtotal = ${total.toFixed(2)}; $o->total = ${total.toFixed(2)}; ` +
        `$o->order_type = ${typeInt}; ` +
        `$o->source_surface = '${sourceSurface}'; ` +
        `$o->order_datetime = ${datetimeExpr}; ` +
        `$o->preparation_time = 5; ` +
        `$o->is_advance_order = App\\Enums\\Ask::NO; ` +
        `$o->payment_method = 1; $o->payment_status = 5; ` +
        `$o->status = ${statusInt}; ` +
        (posName ? `$o->pos_customer_name = '${posName.replace(/'/g, "\\'")}'; ` : '') +
        (posPhone ? `$o->pos_customer_phone = '${posPhone}'; ` : '') +
        `$o->business_date = now()->toDateString(); ` +
        `$o->save(); ` +
        lignesPhp +
        `echo 'SEEDED:' . $o->id;`;

    const out = php(snippet);
    const m = out.match(/SEEDED:(\d+)/);
    if (!m) console.warn(`[WAVE-A] semis ${suffix} KO — sortie tinker :\n${out.slice(-800)}`);
    return m ? parseInt(m[1], 10) : null;
}

function cleanup() {
    const out = php(
        `App\\Models\\Order::withoutGlobalScopes()->where('order_serial_no', 'like', '${TOKEN}-%')->get()->each(function ($o) { ` +
            `App\\Models\\OrderItem::withoutGlobalScopes()->where('order_id', $o->id)->forceDelete(); ` +
            `DB::table('orders')->where('id', $o->id)->update(['fiscal_sequence_no' => null]); ` +
            `$o->forceDelete(); }); echo 'CLEANED';`,
    );
    if (!/CLEANED/.test(out)) console.warn(`[WAVE-A] nettoyage KO :\n${out.slice(-800)}`);
}

// Composition riche : produits + sauces + extras + suppléments + instruction.
const LIGNES_RICHES = [
    {
        itemName: 'Cayenne',
        qty: 2,
        options: [
            { variation_id: 1, attribute_name: 'Pain', variation_name: 'Galette', quantity: 1 },
            { variation_id: 2, attribute_name: 'Sauce', variation_name: 'Algerienne', quantity: 1 },
            { variation_id: 3, attribute_name: 'Cuisson', variation_name: 'Bien cuit', quantity: 1 },
        ],
        extras: [
            { extra_id: 1, extra_name: 'Cheddar', quantity: 2 },
            { extra_id: 2, extra_name: 'Salade', quantity: 1 },
        ],
        instruction: 'Sans oignons - allergie arachide',
    },
    {
        itemName: 'Tacos M',
        qty: 1,
        options: [{ variation_id: 4, attribute_name: 'Viande', variation_name: 'Poulet marine', quantity: 1 }],
        addons: [{ addon_id: 1, addon_name: 'Frites', role: 'menu_frites', quantity: 1, line_total: 1.2 }],
    },
    { itemName: 'Sandwich Classique', qty: 1, options: [{ variation_id: 5, attribute_name: 'Sauce', variation_name: 'Blanche', quantity: 1 }] },
    { itemName: 'Big Tacos', qty: 3 },
];

test.describe('VAGUE A — suivi des commandes : capture des 10 états', () => {
    test.describe.configure({ mode: 'serial' });
    test.setTimeout(600_000);

    test.beforeAll(() => {
        fs.mkdirSync(SHOTS_DIR, { recursive: true });
        cleanup();

        // 1. Commande comptoir richement composée — le cas propriétaire.
        seedOrdre({ suffix: 'COMPO', sourceSurface: 'pos', statusInt: 7, typeInt: 15, lignes: LIGNES_RICHES });

        // 2. Commande à UNE ligne, AUCUNE personnalisation.
        seedOrdre({
            suffix: 'SIMPLE',
            sourceSurface: 'pos',
            statusInt: 7,
            typeInt: 15,
            total: 6.5,
            lignes: [{ itemName: 'Petite Frites', qty: 1 }],
        });

        // 3. Commande TÉLÉPHONE — le client n'est pas là. Numéro SCELLÉ sur la
        //    commande (`pos_customer_phone`) : la capture dira s'il ressort à l'écran.
        seedOrdre({
            suffix: 'TEL',
            sourceSurface: 'phone',
            statusInt: 7,
            typeInt: 15,
            total: 13.0,
            posName: 'Karim Bensalah',
            posPhone: '0612345678',
            lignes: [
                {
                    itemName: 'Cayenne',
                    qty: 1,
                    options: [{ variation_id: 6, attribute_name: 'Sauce', variation_name: 'Samourai', quantity: 1 }],
                    instruction: 'Rappeler avant de preparer',
                },
            ],
        });

        // 4. Commande PLATEFORME (Uber Eats).
        seedOrdre({
            suffix: 'UBER',
            sourceSurface: 'uber_eats',
            statusInt: 8,
            typeInt: 10,
            total: 24.9,
            posName: 'Sofia',
            lignes: [
                {
                    itemName: 'Big Tacos',
                    qty: 2,
                    options: [{ variation_id: 7, attribute_name: 'Sauce', variation_name: 'Harissa', quantity: 1 }],
                    extras: [{ extra_id: 3, extra_name: 'Emmental', quantity: 1 }],
                },
            ],
        });

        // 5. Commande EN SOUFFRANCE — antérieure à la journée de service.
        seedOrdre({
            suffix: 'SOUFFRANCE',
            sourceSurface: 'pos',
            statusInt: 7,
            typeInt: 15,
            total: 8.9,
            datetimeExpr: "now('UTC')->subDays(4)",
            lignes: [{ itemName: 'Tacos M', qty: 1 }],
        });
    });

    test.afterAll(() => {
        cleanup();
        console.log('\n[WAVE-A] ===== CONSTATS =====\n' + JSON.stringify(constats, null, 2));
    });

    test('capture des dix états du suivi', async ({ page }) => {
        const rec = attachMegaAuditRecorder(page, SHOTS_DIR);

        await page.setViewportSize({ width: 1366, height: 768 });
        await loginAsAdmin(page);
        await page.goto(TRACKER_URL, { waitUntil: 'domcontentloaded' });

        // ── GARDE ENVIRONNEMENT (coordination 2026-08-24) ────────────────────
        // Le worktree a déjà servi un `vendor/` amputé : l'application renvoyait
        // HTTP 200 en n'affichant qu'un avertissement PHP. Capturer ça, c'est
        // photographier une panne d'installation et l'imputer au produit. On
        // refuse de continuer plutôt que de produire une preuve mensongère.
        const htmlBrut = await page.content();
        const panne = /Warning:\s*require|Fatal error|Failed to open stream/i.exec(htmlBrut);
        if (panne) {
            throw new Error(
                `[WAVE-A] ENVIRONNEMENT CASSÉ — la page rend « ${panne[0]} ». ` +
                    `Aucune capture n'est prise : le défaut est l'installation (vendor/), pas le produit.`,
            );
        }

        await page.waitForSelector('.pos-tracker-grid', { timeout: 45_000 });
        await page.waitForSelector('.pos-tracker-card', { timeout: 45_000 }).catch(() => {});
        await page.waitForTimeout(1500);

        const carte = (serial) => page.locator('.pos-tracker-card').filter({ hasText: `#${TOKEN}-${serial}` }).first();

        // ── ÉTAT 01 — tableau au chargement, 1366×768 ────────────────────────
        {
            await rec.snap('01-tableau-1366x768');
            const nbCartes = await page.locator('.pos-tracker-card').count();
            const nbCol = await page.locator('.pos-tracker-col').count();
            const titres = (await page.locator('.pos-tracker-col-head h2').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim());
            const deborde = await page.evaluate(
                () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
            );
            noter(
                '01-tableau-1366x768',
                'le kanban complet, ses couloirs et les cartes semées, sans débordement horizontal',
                `${nbCol} couloirs [${titres.join(' | ')}], ${nbCartes} cartes, débordement horizontal = ${deborde}`,
            );
        }

        // ── ÉTAT 10 — un couloir VIDE (relevé AVANT tout filtre) ─────────────
        {
            const lanes = await page.evaluate(() => {
                const out = [];
                document.querySelectorAll('.pos-tracker-col').forEach((c) => {
                    const titre = (c.querySelector('.pos-tracker-col-head h2')?.textContent || '').replace(/\s+/g, ' ').trim();
                    const vide = c.querySelector('.pos-tracker-col-empty');
                    out.push({ titre, vide: !!vide, texteVide: vide ? vide.textContent.replace(/\s+/g, ' ').trim() : null });
                });
                return out;
            });
            const vides = lanes.filter((l) => l.vide);
            if (vides.length > 0) {
                await page.locator('.pos-tracker-col-empty').first().scrollIntoViewIfNeeded().catch(() => {});
                await page.waitForTimeout(300);
                await rec.snap('10-couloir-vide');
                noter(
                    '10-couloir-vide',
                    "un couloir sans carte affiche une icône + une phrase d'état vide en français",
                    `${vides.length} couloir(s) vide(s) : ${vides.map((v) => `« ${v.titre} » → « ${v.texteVide} »`).join(' ; ')}`,
                );
            } else {
                await rec.snap('10-couloir-vide');
                noter('10-couloir-vide', "un couloir vide avec son état vide", 'AUCUN couloir vide au chargement — état non atteint sans filtrer');
            }
        }

        // ── ÉTAT 02 — le même à 1024×600 ────────────────────────────────────
        {
            await page.setViewportSize({ width: 1024, height: 600 });
            await page.waitForTimeout(900);
            await rec.snap('02-tableau-1024x600');
            const mesures = await page.evaluate(() => {
                const doc = document.documentElement;
                const fuites = [];
                document.querySelectorAll('.pos-tracker-card').forEach((carteEl) => {
                    const bc = carteEl.getBoundingClientRect();
                    carteEl.querySelectorAll('.pos-tracker-card-compo, .pos-tracker-card-name, .pos-tracker-card-total').forEach((el) => {
                        const b = el.getBoundingClientRect();
                        if (b.right > bc.right + 1) fuites.push(el.textContent.trim().slice(0, 40));
                    });
                });
                const grille = document.querySelector('.pos-tracker-grid');
                return {
                    debordePage: doc.scrollWidth > doc.clientWidth + 1,
                    grilleScrollW: grille ? grille.scrollWidth : null,
                    grilleClientW: grille ? grille.clientWidth : null,
                    fuites,
                    barreH: document.querySelector('.pos-tracker-bar')?.getBoundingClientRect().height ?? null,
                };
            });
            noter(
                '02-tableau-1024x600',
                "le même tableau lisible sur l'écran contraint du comptoir, rien qui déborde de sa carte",
                `débordement page = ${mesures.debordePage}, grille ${mesures.grilleScrollW}px pour ${mesures.grilleClientW}px visibles, ` +
                    `en-tête ${mesures.barreH != null ? Math.round(mesures.barreH) : '?'}px de haut sur 600, ` +
                    `${mesures.fuites.length} élément(s) hors carte${mesures.fuites.length ? ' : ' + mesures.fuites.join(' | ') : ''}`,
            );
            await page.setViewportSize({ width: 1366, height: 768 });
            await page.waitForTimeout(700);
        }

        // ── ÉTAT 03 — carte à COMPOSITION RICHE ─────────────────────────────
        let carteCompoVisible = false;
        {
            const c = carte('COMPO');
            carteCompoVisible = await c.isVisible().catch(() => false);
            if (carteCompoVisible) {
                await c.scrollIntoViewIfNeeded().catch(() => {});
                await page.waitForTimeout(300);
                await rec.snap('03-carte-composition-riche');
                const texte = (await c.innerText()).replace(/\s+/g, ' ').trim();
                const nbLignes = await c.locator('.pos-tracker-card-items > li').count();
                const nbCompo = await c.locator('.pos-tracker-card-compo').count();
                const manquants = ['Galette', 'Algerienne', 'Bien cuit', 'Cheddar', 'Salade'].filter((m) => !texte.includes(m));
                noter(
                    '03-carte-composition-riche',
                    'produits + sauces + extras + suppléments + instruction lisibles directement sur la carte',
                    `${nbLignes} <li> dont ${nbCompo} résumé(s) de composition ; manquants sur la carte = ` +
                        `${manquants.length ? manquants.join(', ') : 'aucun'} ; texte = « ${texte.slice(0, 320)} »`,
                );
            } else {
                await rec.snap('03-carte-composition-riche');
                noter('03-carte-composition-riche', 'la carte AUDA-COMPO', 'CARTE INTROUVABLE sur le tableau — état non atteint');
            }
        }

        // ── ÉTAT 04 — « Voir tout » ouvert sur cette commande ────────────────
        {
            const c = carte('COMPO');
            const bouton = c.locator('[data-testid^="tracker-voir-tout-"]');
            const present = (await bouton.count()) > 0;
            if (present) {
                const requetes = [];
                const onReq = (r) => requetes.push(r.url());
                page.on('request', onReq);
                await bouton.first().click();
                const overlay = page.locator('[data-testid="tracker-contenu-overlay"]');
                await overlay.waitFor({ state: 'visible', timeout: 8_000 }).catch(() => {});
                await page.waitForTimeout(500);
                await rec.snap('04-voir-tout-ouvert');
                page.off('request', onReq);
                const ouvert = await overlay.isVisible().catch(() => false);
                const nbLignes = await overlay.locator('[data-testid^="tracker-contenu-ligne-"]').count();
                const texte = ouvert ? (await overlay.innerText()).replace(/\s+/g, ' ').trim() : '';
                const manquants = ['Galette', 'Algerienne', 'Bien cuit', 'Cheddar', 'Salade', 'Poulet marine', 'Frites', 'Blanche'].filter(
                    (m) => !texte.includes(m),
                );
                const brut = texte.match(/undefined|\[object|label\.[a-z_]+|pos\.tracker\.[a-z_]+/gi) || [];
                const api = requetes.filter((u) => /\/api\/admin\//.test(u));
                noter(
                    '04-voir-tout-ouvert',
                    'un panneau listant les 4 lignes et TOUTES les personnalisations, sans appel réseau ni libellé technique',
                    `panneau ouvert = ${ouvert}, ${nbLignes} ligne(s) listée(s), manquants = ${manquants.length ? manquants.join(', ') : 'aucun'}, ` +
                        `libellés bruts = ${brut.length ? brut.join(', ') : 'aucun'}, appels /api/admin/ pendant l'ouverture = ${api.length}`,
                );
                await page.keyboard.press('Escape');
                await page.waitForTimeout(400);
            } else {
                await rec.snap('04-voir-tout-ouvert');
                noter('04-voir-tout-ouvert', 'le bouton « Voir tout » sur la carte composée', 'BOUTON ABSENT sur AUDA-COMPO — état non atteint');
            }
        }

        // ── ÉTAT 05 — « Voir tout » sur une commande à UNE ligne sans perso ──
        {
            const c = carte('SIMPLE');
            const visible = await c.isVisible().catch(() => false);
            if (!visible) {
                await rec.snap('05-voir-tout-ligne-simple');
                noter('05-voir-tout-ligne-simple', 'la carte AUDA-SIMPLE', 'CARTE INTROUVABLE — état non atteint');
            } else {
                await c.scrollIntoViewIfNeeded().catch(() => {});
                const bouton = c.locator('[data-testid^="tracker-voir-tout-"]');
                const nbBoutons = await bouton.count();
                if (nbBoutons > 0) {
                    await bouton.first().click();
                    await page.locator('[data-testid="tracker-contenu-overlay"]').waitFor({ state: 'visible', timeout: 8_000 }).catch(() => {});
                    await page.waitForTimeout(400);
                    await rec.snap('05-voir-tout-ligne-simple');
                    const texte = (await page.locator('[data-testid="tracker-contenu-overlay"]').innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
                    noter(
                        '05-voir-tout-ligne-simple',
                        'soit le panneau ouvert sur une commande nue, soit l\'absence assumée du bouton',
                        `bouton PRÉSENT et panneau ouvert ; contenu = « ${texte.slice(0, 260)} »`,
                    );
                    await page.keyboard.press('Escape');
                    await page.waitForTimeout(300);
                } else {
                    await page.waitForTimeout(300);
                    await rec.snap('05-voir-tout-ligne-simple');
                    const texte = (await c.innerText()).replace(/\s+/g, ' ').trim();
                    noter(
                        '05-voir-tout-ligne-simple',
                        'le panneau « Voir tout » sur une commande à une seule ligne sans personnalisation',
                        `BOUTON « Voir tout » ABSENT (aDuContenuAVoir=false) → panneau INATTEIGNABLE sur cette commande ; ` +
                            `carte capturée à la place : « ${texte.slice(0, 260)} »`,
                    );
                }
            }
        }

        // ── ÉTAT 06 — carte TÉLÉPHONE ───────────────────────────────────────
        {
            const c = carte('TEL');
            const visible = await c.isVisible().catch(() => false);
            if (visible) {
                await c.scrollIntoViewIfNeeded().catch(() => {});
                await page.waitForTimeout(300);
                await rec.snap('06-carte-telephone');
                const texte = (await c.innerText()).replace(/\s+/g, ' ').trim();
                const puce = (await c.locator('.pos-tracker-card-source').first().innerText().catch(() => '')).trim();
                const titrePuce = await c.locator('.pos-tracker-card-source').first().getAttribute('title').catch(() => null);
                const lienTel = await c.locator('[data-testid^="tracker-customer-phone-"]').count();
                noter(
                    '06-carte-telephone',
                    'une puce 📞 identifiant le canal téléphone, et de quoi rappeler un client absent',
                    `puce = « ${puce} », title = « ${titrePuce} », lien téléphone cliquable = ${lienTel} ` +
                        `(0612345678 est pourtant scellé sur la commande) ; carte = « ${texte.slice(0, 260)} »`,
                );
            } else {
                await rec.snap('06-carte-telephone');
                noter('06-carte-telephone', 'la carte AUDA-TEL', 'CARTE INTROUVABLE — état non atteint');
            }
        }

        // ── ÉTAT 07 — carte PLATEFORME ──────────────────────────────────────
        {
            const c = carte('UBER');
            const visible = await c.isVisible().catch(() => false);
            if (visible) {
                await c.scrollIntoViewIfNeeded().catch(() => {});
                await page.waitForTimeout(300);
                await rec.snap('07-carte-plateforme');
                const texte = (await c.innerText()).replace(/\s+/g, ' ').trim();
                const puce = (await c.locator('.pos-tracker-card-source').first().innerText().catch(() => '')).trim();
                const titrePuce = await c.locator('.pos-tracker-card-source').first().getAttribute('title').catch(() => null);
                const promo = await c.locator('[data-testid^="tracker-promo-"]').count();
                noter(
                    '07-carte-plateforme',
                    'une puce 🛵 plateforme, distincte de la caisse, avec son action ticket promo',
                    `puce = « ${puce} », title = « ${titrePuce} », bouton ticket promo = ${promo} ; carte = « ${texte.slice(0, 260)} »`,
                );
            } else {
                await rec.snap('07-carte-plateforme');
                noter('07-carte-plateforme', 'la carte AUDA-UBER', 'CARTE INTROUVABLE — état non atteint');
            }
        }

        // ── ÉTAT 08 — filtre par canal ACTIVÉ sur « Téléphone » ─────────────
        {
            const onglets = page.locator('.pos-tracker-source-tab');
            const libelles = (await onglets.allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim());
            const ongletTel = onglets.filter({ hasText: 'Téléphone' });
            if ((await ongletTel.count()) > 0) {
                await ongletTel.first().click();
                await page.waitForTimeout(900);
                await rec.snap('08-filtre-telephone');
                const nbCartes = await page.locator('.pos-tracker-card').count();
                const serials = (await page.locator('.pos-tracker-card-num').allInnerTexts()).map((t) => t.trim());
                const selected = await ongletTel.first().getAttribute('aria-selected');
                const classe = await ongletTel.first().getAttribute('class');
                noter(
                    '08-filtre-telephone',
                    "l'onglet Téléphone actif ne laisse que les commandes téléphone sur le tableau",
                    `onglets présents = [${libelles.join(' | ')}] ; aria-selected=${selected}, class=« ${classe} » ; ` +
                        `${nbCartes} carte(s) restante(s) : ${serials.join(', ') || 'aucune'}`,
                );
                // retour à « Toutes » pour la suite
                await onglets.first().click();
                await page.waitForTimeout(700);
            } else {
                await rec.snap('08-filtre-telephone');
                noter(
                    '08-filtre-telephone',
                    "l'onglet « Téléphone » dans la barre de filtres",
                    `ONGLET ABSENT — onglets réellement présents = [${libelles.join(' | ')}] — état non atteint`,
                );
            }
        }

        // ── ÉTAT 09 — panneau « commandes en souffrance » ────────────────────
        {
            const pilule = page.locator('[data-testid="tracker-stale-pill"]');
            if ((await pilule.count()) > 0) {
                const texteP = (await pilule.first().innerText()).replace(/\s+/g, ' ').trim();
                await pilule.first().click();
                const panneau = page.locator('[data-testid="tracker-stale-panel"]');
                await panneau.waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
                await page.waitForTimeout(1500);
                await panneau.scrollIntoViewIfNeeded().catch(() => {});
                await page.waitForTimeout(400);
                await rec.snap('09-panneau-souffrance');
                const ouvert = await panneau.isVisible().catch(() => false);
                const nbRangs = await panneau.locator('[data-testid^="tracker-stale-row-"]').count();
                const texte = ouvert ? (await panneau.innerText()).replace(/\s+/g, ' ').trim() : '';
                const brut = texte.match(/undefined|all\.order\.status|label\.[a-z_]+|pos\.tracker\.[a-z_]+/gi) || [];
                const auda = texte.includes(`${TOKEN}-SOUFFRANCE`);
                noter(
                    '09-panneau-souffrance',
                    'le panneau des commandes non terminées antérieures au service, avec leurs statuts en français',
                    `pilule = « ${texteP} » ; panneau ouvert = ${ouvert}, ${nbRangs} rang(s) affiché(s), ` +
                        `AUDA-SOUFFRANCE visible = ${auda}, libellés bruts = ${brut.length ? brut.join(', ') : 'aucun'} ; ` +
                        `entête = « ${texte.slice(0, 220)} »`,
                );
                await pilule.first().click().catch(() => {});
                await page.waitForTimeout(400);
            } else {
                await rec.snap('09-panneau-souffrance');
                noter('09-panneau-souffrance', 'la pilule « en souffrance » puis son panneau', 'PILULE ABSENTE (staleMeta.count = 0) — état non atteint');
            }
        }

        // Le test ne juge pas ; il exige seulement que le tableau ait chargé.
        expect(await page.locator('.pos-tracker-grid').count()).toBeGreaterThan(0);
        rec.dispose();
    });
});
