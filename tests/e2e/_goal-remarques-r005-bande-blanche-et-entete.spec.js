/**
 * [GOAL REMARQUES 2026-10-03 · R-005] « 2 cm de blanc en bas de la caisse ».
 *
 * MESURE d'abord, correctif ensuite : combien de pixels séparent le bas du panneau ticket de la caisse
 * du bas de la fenêtre, à trois tailles d'écran réelles ? Quel élément porte un zoom ?
 *
 * Avant correctif : 72 px (1,9 cm) à 1280×720, 77 px à 1366×768, 108 px à 1920×1080, soit 10 % de la
 * hauteur. Cause : `zoom: 0.9` sur la page + hauteurs en `100vh`/`100dvh` (non zoomées) → 10 % d'écran
 * perdu. Après : 11 px aux trois tailles (la marge basse voulue, égale à la marge droite).
 *
 * TROUVAILLE DE LA MÊME MESURE : de 1440 à 1920 px de large, la colonne du titre de l'en-tête tombait à
 * 0 px et « Commande rapide » chevauchait les boutons (le correctif de juillet « ≤1439 px : on empile »
 * ne couvre pas ces largeurs). Après : colonne de 193 px, titre sur une ligne, boutons passés à la ligne.
 *
 * Sentinelle : rejouer avec `R005_PHASE=avant` sur un arbre d'avant-correctif pour re-prouver qu'elle mord.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsPosOperator } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../captures/goal-remarques-2026-10-03');
fs.mkdirSync(OUT, { recursive: true });
// Les assertions sont TOUJOURS actives (le défaut est corrigé). `R005_PHASE=avant` ne sert qu'à rejouer la
// mesure sur un arbre d'avant-correctif pour re-prouver que ce test mord : il écrit alors des captures
// « avant » et n'asserte pas.
const PHASE = process.env.R005_PHASE || 'apres';

const TAILLES = [
    { nom: '1280x720', width: 1280, height: 720 },
    { nom: '1366x768', width: 1366, height: 768 },
    { nom: '1920x1080', width: 1920, height: 1080 },
];

// Trouvaille de la mesure R-005 (capture 1920×1080) : l'en-tête « Caisse Le Cayenne / Commande rapide »
// se réduisait à une colonne de ~40 px et chevauchait la rangée de boutons. Le correctif de juillet
// (« ≤1439 px : on empile ») ne couvre pas les écrans plus larges, où la colonne d'actions en largeur
// `auto` prend toute la rangée.
const LARGEURS_ENTETE = [
    { nom: '1280x800', width: 1280, height: 800 },
    { nom: '1440x900', width: 1440, height: 900 },
    { nom: '1536x864', width: 1536, height: 864 },
    { nom: '1680x1050', width: 1680, height: 1050 },
    { nom: '1920x1080', width: 1920, height: 1080 },
];

test('En-tête de la caisse — le titre ne chevauche jamais la rangée de boutons', async ({ page }) => {
    await loginAsPosOperator(page);
    const rapport = [];
    for (const t of LARGEURS_ENTETE) {
        await page.setViewportSize({ width: t.width, height: t.height });
        await page.waitForTimeout(1200);
        const m = await page.evaluate(() => {
            const rect = (sel) => { const e = document.querySelector(sel); if (!e) return null; const r = e.getBoundingClientRect(); return { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height), r: Math.round(r.right), b: Math.round(r.bottom) }; };
            const identite = rect('.pos-v5-operator-bar__identity');
            const actions = rect('.pos-v5-operator-bar__actions');
            const titre = document.querySelector('.pos-v5-operator-bar__title');
            const lignesTitre = titre ? Math.round(titre.getBoundingClientRect().height / parseFloat(getComputedStyle(titre).lineHeight)) : null;
            const chevauche = !!(identite && actions) && identite.x < actions.r && identite.r > actions.x && identite.y < actions.b && identite.b > actions.y;
            return { identite, actions, lignesTitre, chevauche };
        });
        rapport.push({ taille: t.nom, ...m });
        await page.screenshot({ path: path.join(OUT, `entete-${PHASE}-${t.nom}.png`) });
        console.log('ENTETE', PHASE, t.nom, 'identite.w', m.identite && m.identite.w, 'lignesTitre', m.lignesTitre, 'chevauche', m.chevauche);
    }
    fs.writeFileSync(path.join(OUT, `entete-mesures-${PHASE}.json`), JSON.stringify(rapport, null, 2));
    if (PHASE === 'apres') {
        for (const m of rapport) {
            expect(m.chevauche, `${m.taille} : le titre ne chevauche pas les boutons`).toBe(false);
            expect(m.identite.w, `${m.taille} : la colonne du titre garde de la place`).toBeGreaterThanOrEqual(150);
            expect(m.lignesTitre, `${m.taille} : « Commande rapide » tient sur une ligne`).toBe(1);
        }
    }
});

test('R-005 — bande blanche en bas du panneau ticket de la caisse', async ({ page }) => {
    await loginAsPosOperator(page);
    const rapport = [];
    for (const t of TAILLES) {
        await page.setViewportSize({ width: t.width, height: t.height });
        await page.waitForTimeout(1500);
        const m = await page.evaluate(() => {
            const H = window.innerHeight;
            const cart = document.getElementById('pos-cart');
            const r = cart.getBoundingClientRect();
            // Tout ancêtre (ou l'élément lui-même) qui porte un zoom différent de 1.
            const zooms = [];
            for (let e = cart; e; e = e.parentElement) {
                const z = getComputedStyle(e).zoom;
                if (z && z !== '1' && z !== 'normal') {
                    zooms.push({ tag: e.tagName.toLowerCase(), id: e.id || null, classe: String(e.className).slice(0, 70), zoom: z });
                }
            }
            return {
                hauteurFenetre: H,
                hautPanneau: Math.round(r.top),
                basPanneau: Math.round(r.bottom),
                hauteurPanneau: Math.round(r.height),
                zooms,
                defilementVertical: document.documentElement.scrollHeight > H + 1,
            };
        });
        m.bandeBlanchePx = m.hauteurFenetre - m.basPanneau;
        m.bandeBlancheCm = Math.round((m.bandeBlanchePx / 96) * 2.54 * 10) / 10;
        rapport.push({ taille: t.nom, ...m });
        await page.screenshot({ path: path.join(OUT, `r005-${PHASE}-${t.nom}.png`) });
        console.log('R005', PHASE, t.nom, 'fenetre', m.hauteurFenetre, 'bas panneau', m.basPanneau, 'bande', m.bandeBlanchePx + 'px', m.bandeBlancheCm + 'cm', 'zooms', JSON.stringify(m.zooms), 'defil', m.defilementVertical);
    }
    fs.writeFileSync(path.join(OUT, `r005-mesures-${PHASE}.json`), JSON.stringify(rapport, null, 2));

    if (PHASE === 'apres') {
        for (const m of rapport) {
            // Le panneau descend presque jusqu'au bas de la fenêtre : il garde 12 px CSS de marge basse (=
            // la marge droite `md:right-3`), soit ~11 px à l'écran. Avant correctif : 72 / 77 / 108 px.
            expect(m.bandeBlanchePx, `${m.taille} : le panneau ticket doit atteindre le bas de la fenêtre`).toBeLessThanOrEqual(14);
            expect(m.defilementVertical, `${m.taille} : pas de défilement de page créé par le correctif`).toBe(false);
        }
    }
});
