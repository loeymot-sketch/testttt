const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { loginAsAdmin } = require('../e2e/helpers/login');

/**
 * Audit profond — tableau de bord + comptabilité (demande propriétaire 2026-08-29).
 *
 * L'instrument mesure ce qui a DÉJÀ échappé à ~9 000 assertions vertes dans ce dépôt
 * (cf. mémoire de session 2026-08-28) :
 *   - une icône dont la classe n'existe pas dans la police -> bouton VIDE, aucun test ne tombe
 *   - une clé i18n construite (`$t('menu.' + x)`) -> libellé brut, invisible aux sentinelles
 *   - un débordement de grille -> contenu coupé, le DOM reste correct
 *
 * D'où un relevé qui ne se contente pas du PNG : pour chaque page on mesure la géométrie
 * réelle (boîtes englobantes, largeurs de défilement) et le texte rendu.
 *
 * Les captures vont hors du dépôt (disque à 95 %, cf. [[reports-dir-fills-disk]]).
 */

const SORTIE = process.env.AUDIT_OUT || '/tmp/audit-dashboard';
const PAGES = JSON.parse(fs.readFileSync(process.env.AUDIT_ROUTES, 'utf8'));

fs.mkdirSync(SORTIE, { recursive: true });

/** Un libellé brut : « menu.orders », « Label.X » — du technique montré au client. */
const RE_LIBELLE_BRUT = /^[a-z][a-z0-9]*(\.[a-z0-9_]+){1,}$/i;

/**
 * Attend que la page ait FINI de se peindre, pas seulement que le réseau se taise.
 *
 * Premier jet de cet audit : `networkidle` + 1,6 s. Le catalogue produits a été capturé
 * pendant son spinner et relevé à « 225 caractères » — j'aurais signalé un écran vide qui
 * ne l'est pas. C'est exactement le piège « instrument avant produit » (CLAUDE.md §3ter).
 *
 * On attend donc que la quantité de texte rendu CESSE DE CHANGER sur deux mesures
 * consécutives : un signal produit par la page elle-même, pas une durée devinée.
 */
async function attendreStabilite(page, { pasMs = 700, maxMs = 25_000 } = {}) {
    const debut = Date.now();
    let precedent = -1;
    let stable = 0;
    while (Date.now() - debut < maxMs) {
        const taille = await page.evaluate(() => (document.body?.innerText || '').trim().length);
        const chargeEnCours = await page.evaluate(() => !!document.querySelector(
            '.spinner, .loading, [class*="spinner"], [class*="loader"], [class*="skeleton"]',
        ));
        if (taille === precedent && !chargeEnCours) {
            if (++stable >= 2) return { stabiliseEnMs: Date.now() - debut, taille };
        } else {
            stable = 0;
        }
        precedent = taille;
        await page.waitForTimeout(pasMs);
    }
    return { stabiliseEnMs: Date.now() - debut, taille: precedent, delaiDepasse: true };
}

test.describe('Audit tableau de bord + comptabilité', () => {
    // Le délai couvre la boucle ENTIÈRE, pas une page. Premier essai à 180 s : la capture
    // s'est arrêtée à la 9e page sur 40, et comme le relevé n'était écrit qu'à la fin, les
    // huit pages déjà mesurées ont été perdues avec. D'où les deux corrections : un délai
    // dimensionné au nombre réel de pages, et une écriture incrémentale.
    test.describe.configure({ mode: 'serial', timeout: 45 * 60_000 });

    test('relève chaque page du tableau de bord', async ({ page }) => {
        const journal = [];

        const consoleErreurs = [];
        page.on('console', (m) => {
            if (m.type() === 'error') consoleErreurs.push(m.text().slice(0, 200));
        });
        const requetesEchouees = [];
        page.on('response', (r) => {
            if (r.status() >= 400) requetesEchouees.push(`${r.status()} ${r.url().slice(0, 130)}`);
        });

        await loginAsAdmin(page);

        for (const p of PAGES) {
            consoleErreurs.length = 0;
            requetesEchouees.length = 0;

            let erreurNav = null;
            try {
                await page.goto(p.url, { waitUntil: 'networkidle', timeout: 60_000 });
            } catch (e) {
                erreurNav = String(e.message).slice(0, 160);
            }
            const stabilite = await attendreStabilite(page);

            // La barre de débogage Laravel est un outil de développement : elle recouvre le
            // bas de l'écran et fausserait autant la capture que la mesure de débordement.
            await page.addStyleTag({
                content: '.phpdebugbar, #phpdebugbar, .phpdebugbar-closed { display: none !important; }',
            }).catch(() => {});

            const releve = await page.evaluate((RE_SRC) => {
                const RE = new RegExp(RE_SRC, 'i');
                const visible = (el) => {
                    const r = el.getBoundingClientRect();
                    const s = getComputedStyle(el);
                    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
                };

                // Boutons dont l'icône ne rend RIEN : la classe existe dans le HTML mais la
                // police ne la connaît pas. Le bouton est là, vide, et aucun test ne tombe.
                const boutonsVides = [];
                for (const b of document.querySelectorAll('button, a.btn, [role="button"]')) {
                    if (!visible(b)) continue;
                    const texte = (b.textContent || '').trim();
                    if (texte.length > 0) continue;
                    const aImage = b.querySelector('img, svg');
                    if (aImage) continue;
                    const icone = b.querySelector('i, span[class*="icon"], span[class*="fa"]');
                    if (!icone) continue;
                    const r = icone.getBoundingClientRect();
                    // Une icône qui rend a une largeur ; une classe inconnue rend 0.
                    if (r.width < 2 || r.height < 2) {
                        boutonsVides.push((icone.className || '').toString().slice(0, 60));
                    }
                }

                // Libellés bruts réellement AFFICHÉS (feuilles du DOM, visibles).
                const libellesBruts = [];
                for (const el of document.querySelectorAll('body *')) {
                    if (el.children.length !== 0 || !visible(el)) continue;
                    const t = (el.textContent || '').trim();
                    if (t && t.length < 60 && RE.test(t) && !t.includes(' ')) libellesBruts.push(t);
                }

                // Débordement horizontal : la page force un défilement latéral.
                const doc = document.documentElement;
                const deborde = doc.scrollWidth - doc.clientWidth;

                // Conteneurs qui coupent leur contenu sans permettre de le faire défiler.
                const contenusCoupes = [];
                for (const el of document.querySelectorAll('div, section, table, ul')) {
                    if (!visible(el)) continue;
                    const s = getComputedStyle(el);
                    const cache = s.overflowX === 'hidden' || s.overflow === 'hidden';
                    if (cache && el.scrollWidth - el.clientWidth > 12) {
                        contenusCoupes.push(`${el.tagName.toLowerCase()}.${(el.className || '').toString().split(' ')[0]} (+${el.scrollWidth - el.clientWidth}px)`);
                    }
                }

                /*
                 * Les indicateurs chiffrés, avec le libellé qui les accompagne.
                 *
                 * C'est le relevé qui permet de confronter deux écrans : « le même fait
                 * affiché deux fois doit donner le même nombre » (CLAUDE.md, priorité n°2).
                 * On lit le nombre RENDU, pas la requête qui le produit — c'est ce que
                 * l'exploitant a sous les yeux, et c'est la seule mesure qui tranche quand
                 * la lecture du code et la simulation en base se contredisent.
                 */
                const indicateurs = [];
                for (const el of document.querySelectorAll('h1,h2,h3,h4,h5,span,div,strong,b,td')) {
                    if (el.children.length !== 0 || !visible(el)) continue;
                    const t = (el.textContent || '').trim();
                    if (!/^[\d\s  ]{1,12}([.,]\d{1,2})?\s*(€|%)?$/.test(t) || t.length > 14) continue;
                    // Le libellé le plus proche : un frère, sinon le texte du parent.
                    const voisin = (el.previousElementSibling?.textContent
                        || el.nextElementSibling?.textContent
                        || el.parentElement?.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 46);
                    indicateurs.push({ valeur: t, pres_de: voisin });
                }

                const corps = (document.body.innerText || '').trim();
                return {
                    indicateurs: indicateurs.slice(0, 30),
                    titre: (document.querySelector('h1, .page-title, [class*="title"]')?.textContent || '').trim().slice(0, 80),
                    adresse: location.pathname,
                    tailleTexte: corps.length,
                    apercu: corps.slice(0, 260).replace(/\s+/g, ' '),
                    boutonsVides: [...new Set(boutonsVides)],
                    libellesBruts: [...new Set(libellesBruts)],
                    debordementPx: deborde > 2 ? deborde : 0,
                    contenusCoupes: [...new Set(contenusCoupes)].slice(0, 8),
                    nbBoutons: document.querySelectorAll('button, a.btn, [role="button"]').length,
                    nbLignesTableau: document.querySelectorAll('tbody tr').length,
                    // Un écran vide : ni tableau, ni carte, ni message d'état.
                    aTableau: !!document.querySelector('table'),
                    aEtatVide: /aucun|vide|no data|empty|rien/i.test(corps.slice(0, 1200)),
                };
            }, RE_LIBELLE_BRUT.source);

            const fichier = path.join(SORTIE, `${p.cle}.png`);
            await page.screenshot({ path: fichier, fullPage: true });

            journal.push({
                cle: p.cle,
                libelle: p.libelle,
                url: p.url,
                erreurNav,
                capture: fichier,
                stabilite,
                ...releve,
                consoleErreurs: [...new Set(consoleErreurs)].slice(0, 6),
                requetesEchouees: [...new Set(requetesEchouees)].slice(0, 8),
            });

            // Écriture à CHAQUE page : si la boucle est interrompue, ce qui a été mesuré
            // reste exploitable. Une capture perdue faute d'écriture est une capture à refaire.
            fs.writeFileSync(path.join(SORTIE, 'releve.json'), JSON.stringify(journal, null, 2));

            // eslint-disable-next-line no-console
            console.log(`[audit] ${p.cle} — ${releve.tailleTexte} car., ${releve.libellesBruts.length} libellés bruts, ${releve.boutonsVides.length} boutons vides, ${requetesEchouees.length} échecs`);
        }

        expect(journal.length).toBe(PAGES.length);
    });
});
