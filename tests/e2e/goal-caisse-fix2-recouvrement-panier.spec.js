// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

/**
 * [FIX-2 · 2026-08-25] P0 — LE PANNEAU PANIER RECOUVRE DES COMMANDES CLIQUABLES.
 *
 * CE QUE CE FICHIER PROUVE, ET POURQUOI IL EXISTE
 * ----------------------------------------------
 * Trois correctifs successifs (19/08, 22/08, et le calcul d'origine) ont été posés sur
 * `.pos-v5-cart__head` dans `resources/css/pos-v5.css`. Chacun était juste au regard de SA
 * mesure, et chacun a annulé le précédent — parce que chacun a été calibré sur UN SEUL état :
 * un panier VIDE. À panier vide le pied du panier fait ~122 px ; à panier plein il en fait
 * ~306 px. Un plafond dimensionné contre 122 px explose à 306 px.
 *
 * Ce fichier refuse ce raccourci. Il mesure QUATRE combinaisons — {1366×768, 1024×600} ×
 * {panier vide, panier à 2 articles} — et sa porte principale n'est pas une hauteur, c'est
 * un TEST AU PIXEL : pour chaque commande cliquable du panneau panier, on demande au
 * navigateur qui reçoit réellement le clic au centre de la cible (`document.elementFromPoint`).
 * Si l'élément touché n'est pas la cible, alors l'élément VISIBLE n'est pas celui qui agit :
 * sur une caisse, un doigt qui vise « À emporter » atteint « supprimer la ligne ».
 *
 * UNITÉS — LE PIÈGE QUI A DÉJÀ FAUSSÉ UN CALCUL DE 11 % DANS CET AUDIT
 * -------------------------------------------------------------------
 * `resources/js/helpers/caisseZoom.js` pose `body { zoom: 0.9 }` sur la caisse. Il existe donc
 * DEUX unités à l'écran :
 *   · pixels ÉCRAN  — `getBoundingClientRect()`, `window.innerHeight`, `elementFromPoint(x, y)`
 *   · pixels CSS    — `clientHeight`, `scrollHeight`, `vh`, valeurs calculées
 * TOUT ce que ce fichier mesure et affirme est en PIXELS ÉCRAN, et rien d'autre. C'est le seul
 * espace de coordonnées où `getBoundingClientRect()` et `elementFromPoint()` se parlent : le
 * centre d'un rectangle y désigne bien le point que le navigateur va tester. Les valeurs en
 * pixels CSS ne sont relevées qu'à titre de diagnostic, et sont nommées `_css` sans exception.
 *
 * HORS-VUE ≠ RECOUVERT — la distinction qui décide de la gravité
 * -------------------------------------------------------------
 * Un contrôle dont le centre sort de la fenêtre visible de son conteneur défilant est
 * ATTEIGNABLE (il suffit de défiler) : ce n'est pas un piège au clic, c'est un coût
 * d'ergonomie. Un contrôle PEINT à l'écran dont le clic part ailleurs est un piège. Le test
 * ne les confond jamais :
 *   · `recouverts` doit être VIDE sur les 4 combinaisons          → la porte P0
 *   · le champ « Nom du client » doit être atteignable SANS geste → uniquement panier VIDE,
 *     aux 2 gabarits (mandat propriétaire : ce nom s'imprime sur le ticket cuisine)
 *
 * LANCEMENT
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 PLAYWRIGHT_WEB_SERVER_CMD="" \
 *   npx playwright test tests/e2e/goal-caisse-fix2-recouvrement-panier.spec.js --workers=1
 */

const IDENTIFIANT = process.env.POS_EMAIL || 'pos@lecayenne.fr';
const MOT_DE_PASSE = process.env.POS_PASSWORD || '123456';

/** Les deux postes de comptoir cités par le rapport de la vague B. */
const GABARITS = [
    { nom: '1366x768', width: 1366, height: 768 },
    { nom: '1024x600', width: 1024, height: 600 },
];

const SORTIE = path.join(__dirname, '__screenshots__', 'goal-caisse-fix2');
fs.mkdirSync(SORTIE, { recursive: true });

async function ouvrirLaCaisse(page) {
    // Le banc partage ce serveur avec d'autres chantiers : une reconstruction d'actifs en
    // cours rend ponctuellement une page d'erreur Ignition à la place du formulaire. On
    // recharge une fois plutôt que d'attribuer au produit une panne du banc.
    await page.goto('/login', { waitUntil: 'networkidle' });
    if (!(await page.locator('#formEmail').isVisible({ timeout: 8000 }).catch(() => false))) {
        await page.waitForTimeout(4000);
        await page.goto('/login', { waitUntil: 'networkidle' });
        await page.waitForSelector('#formEmail', { timeout: 20000 });
    }
    await page.fill('#formEmail', IDENTIFIANT);
    await page.fill('#formPassword, input[type="password"]', MOT_DE_PASSE);
    await page.click('button:has-text("Connexion")');
    await page.waitForTimeout(3500);
    await page.goto('/admin/pos', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-testid="pos-category-grid"]', { timeout: 30000 });
    await page.waitForTimeout(2500);
    // La debugbar est un artefact de développement : elle n'appartient pas à la caisse et
    // elle capterait des clics au bas de l'écran. On la retire AVANT toute mesure.
    await page.addStyleTag({ content: '.phpdebugbar{display:none !important}' });
    await page.waitForTimeout(300);
}

/**
 * Vide le panier s'il reste des lignes d'un test précédent. Le bouton de remise à zéro
 * n'existe que si le panier contient quelque chose.
 */
async function viderLePanier(page) {
    const reset = page.locator('[data-testid="pos-cart-reset"]');
    if (await reset.isVisible({ timeout: 1500 }).catch(() => false)) {
        await reset.click({ timeout: 4000 }).catch(() => {});
        await page.waitForTimeout(600);
        // Certaines remises à zéro passent par une confirmation.
        await page.locator('button:has-text("Oui"), button:has-text("Confirmer")').first()
            .click({ timeout: 1500 }).catch(() => {});
        await page.waitForTimeout(800);
    }
}

/**
 * Compose l'assistant produit comme le ferait un caissier : viande(s) jusqu'au quota, une
 * sauce, puis « Ajouter au panier ». L'assistant est en ZONE GELÉE (`public/js/pos-wizard.js`) :
 * il est PILOTÉ par des clics réels, jamais modifié, jamais court-circuité par une API.
 * Rendu de production = « S25-SinglePage » (badge `.quota-badge`, pastilles `.sauce-chip`).
 */
async function composerAssistant(page) {
    const bouton = page.locator('[data-action="add-to-cart"]').first();
    if (!(await bouton.isVisible({ timeout: 4000 }).catch(() => false))) {
        return 'pas-d-assistant';
    }

    const etat = () => page.evaluate(() => {
        const badge = document.querySelector('.viande-section .quota-badge');
        return {
            section_viande: !!document.querySelector('.viande-section'),
            quota_complet: badge ? badge.className.includes('complete') : null,
        };
    });

    const tuiles = page.locator('.wizard-viande-tile button[data-action="plus"]');
    for (let k = 0; k < 4; k += 1) {
        const e = await etat();
        if (!e.section_viande || e.quota_complet) break;
        if (!(await tuiles.count().catch(() => 0))) break;
        if (!(await tuiles.first().click({ timeout: 5000 }).then(() => true).catch(() => false))) break;
        await page.waitForTimeout(450);
    }

    const sauce = page.locator('.sauce-chip').first();
    if (await sauce.count().catch(() => 0)) {
        await sauce.click({ timeout: 4000 }).catch(() => {});
        await page.waitForTimeout(400);
    }

    await bouton.click({ timeout: 6000 }).catch(() => {});
    const referme = await bouton.waitFor({ state: 'hidden', timeout: 9000 })
        .then(() => true).catch(() => false);

    if (!referme) {
        // L'assistant REFUSE tant qu'une étape requise manque (garde LOCK 2026-07-04).
        // C'est le bon comportement produit : on sort sans forcer.
        await page.locator('[data-action="cancel-wizard"], .wizard-btn-cancel').first()
            .click({ timeout: 4000 }).catch(() => {});
        await page.keyboard.press('Escape').catch(() => {});
        await page.waitForTimeout(800);
        return 'assistant-refuse';
    }
    await page.waitForTimeout(1000);
    return 'assistant-valide';
}

/** Met AU MOINS `cible` articles au panier, par clics réels sur la grille. */
async function remplirLePanier(page, cible = 2) {
    const lignes = () => page.locator('.pos-v5-cart-item').count();
    const nbCategories = await page.locator('[data-testid="pos-category-tile"]').count();
    let ajoutes = await lignes();

    for (let c = 0; c < Math.min(nbCategories, 5) && ajoutes < cible; c += 1) {
        const retour = page.locator('[data-testid="pos-browse-back"]');
        if (await retour.isVisible({ timeout: 1000 }).catch(() => false)) {
            await retour.click({ timeout: 3000 }).catch(() => {});
            await page.waitForTimeout(700);
        }
        await page.locator('[data-testid="pos-category-tile"]').nth(c)
            .click({ timeout: 6000 }).catch(() => {});
        await page.waitForTimeout(1300);

        const produits = page.locator('button.pos-v5-tile[data-pos-item-id]:not([disabled])');
        const nbProduits = await produits.count().catch(() => 0);
        for (let i = 0; i < Math.min(nbProduits, 3) && ajoutes < cible; i += 1) {
            if ((await produits.count().catch(() => 0)) <= i) break;
            const avant = await lignes();
            await produits.nth(i).click({ timeout: 6000 }).catch(() => {});
            await page.waitForTimeout(1200);
            await composerAssistant(page);
            const apres = await lignes();
            if (apres > avant) {
                ajoutes = apres;
                break; // le composant revient de lui-même à la grille des catégories
            }
        }
    }
    return ajoutes;
}

/**
 * LA MESURE. Un seul aller-retour navigateur, tout en PIXELS ÉCRAN.
 *
 * Pour chaque commande cliquable du panneau panier on demande au navigateur qui reçoit le
 * clic au centre de la cible. Trois verdicts possibles, jamais confondus :
 *   · `atteint`   — `elementFromPoint` rend la cible (ou un nœud de sa lignée) : tout va bien
 *   · `hors_vue`  — le centre sort de la fenêtre visible d'un conteneur défilant, ou de
 *                   l'écran : la cible est atteignable après un geste, ce n'est pas un piège
 *   · `recouvert` — la cible est PEINTE dans sa fenêtre, et le clic part ailleurs : le piège
 */
async function auditerPanier(page, etiquette) {
    return page.evaluate((label) => {
        const panneau = document.querySelector('#pos-cart, .pos-v5-cart');
        const tete = document.querySelector('.pos-v5-cart__head');
        const corps = document.querySelector('.pos-v5-cart__body');
        const pied = document.querySelector('.pos-v5-cart__foot');
        const R = (el) => (el ? el.getBoundingClientRect() : null);
        const arrondi = (r) => (r ? {
            haut: Math.round(r.top), bas: Math.round(r.bottom),
            gauche: Math.round(r.left), droite: Math.round(r.right),
            hauteur: Math.round(r.height),
        } : null);

        /* Le zoom mesuré, pas supposé : rapport entre la boîte écran d'un élément et sa
           hauteur en pixels CSS. Sert uniquement à documenter le facteur dans la sortie —
           aucun calcul du test ne le traverse. */
        const facteurZoom = corps && corps.clientHeight
            ? Number((corps.getBoundingClientRect().height / corps.clientHeight).toFixed(3))
            : null;

        /**
         * Fenêtre visible commune : les ancêtres qui rognent RÉELLEMENT, ∩ l'écran.
         *
         * « Réellement » n'est pas une précaution de style. Le panneau panier est
         * `position: fixed` (règle `.pos-v4-cart-panel`), et son ancêtre `.pos-v5-shell` est en
         * `overflow: hidden` avec un bord droit 115 px à gauche du bord droit du panneau. Une
         * remontée naïve des ancêtres déclarait donc « hors vue » des contrôles parfaitement
         * visibles et cliquables — 2 faux positifs sur le premier passage. Un descendant
         * `fixed` n'est retenu que par un ancêtre qui crée un bloc conteneur (transform,
         * filter, perspective, contain) ; un `absolute`, que par un ancêtre positionné ou
         * transformé. On applique la règle plutôt que de la supposer.
         */
        function fenetreVisible(el) {
            let boite = { top: 0, left: 0, right: window.innerWidth, bottom: window.innerHeight };
            let pos = getComputedStyle(el).position;
            let p = el.parentElement;
            while (p && p !== document.documentElement) {
                const st = getComputedStyle(p);
                const creeBlocConteneur = st.transform !== 'none' || st.filter !== 'none'
                    || st.perspective !== 'none' || /paint|layout|strict|content/.test(st.contain || '')
                    || (st.willChange || '').includes('transform');
                const retient = pos === 'fixed'
                    ? creeBlocConteneur
                    : pos === 'absolute'
                        ? (st.position !== 'static' || creeBlocConteneur)
                        : true;
                if (retient) {
                    if (/(auto|scroll|hidden|clip)/.test(st.overflowY + ' ' + st.overflowX)) {
                        const r = p.getBoundingClientRect();
                        boite = {
                            top: Math.max(boite.top, r.top),
                            left: Math.max(boite.left, r.left),
                            right: Math.min(boite.right, r.right),
                            bottom: Math.min(boite.bottom, r.bottom),
                        };
                    }
                    pos = st.position; // l'ancêtre retenu devient le nouveau point d'ancrage
                }
                p = p.parentElement;
            }
            return boite;
        }

        function nommer(el) {
            if (!el) return 'néant';
            const id = el.getAttribute && (el.getAttribute('data-testid') || el.id);
            const cls = (el.className && typeof el.className === 'string')
                ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
            const txt = (el.innerText || el.placeholder || el.getAttribute?.('aria-label') || '')
                .replace(/\s+/g, ' ').trim().slice(0, 28);
            return `${el.tagName.toLowerCase()}${id ? '#' + id : ''}${cls}${txt ? ` « ${txt} »` : ''}`;
        }

        const cibles = [];
        const ajouter = (el, zone, role) => {
            if (!el) return;
            const r = el.getBoundingClientRect();
            if (r.width < 2 || r.height < 2) return;              // non rendu
            if (getComputedStyle(el).visibility === 'hidden') return;
            cibles.push({ el, zone, role, r });
        };

        if (tete) {
            tete.querySelectorAll('.pos-v5-segmented__item').forEach(
                (el, i) => ajouter(el, 'entete', `type-commande[${i}] ${(el.innerText || '').replace(/\s+/g, ' ').trim()}`));
            ajouter(tete.querySelector('[data-testid="pos-customer-name"]'), 'entete', 'champ-nom-client');
            ajouter(tete.querySelector('[data-testid="pos-customer-phone"]'), 'entete', 'champ-telephone');
            ajouter(tete.querySelector('[data-testid="pos-scheduled-at"]'), 'entete', 'champ-programmer');
        }
        if (corps) {
            corps.querySelectorAll('.pos-v5-cart-item').forEach((ligne, i) => {
                ligne.querySelectorAll('.pos-v5-qty__btn').forEach((b) => {
                    const quoi = b.classList.contains('pos-v5-qty__btn--trash') ? 'corbeille'
                        : b.classList.contains('pos-v5-qty__btn--plus') ? 'plus' : 'moins';
                    ajouter(b, 'corps', `ligne[${i}] ${quoi}`);
                });
                ajouter(ligne.querySelector('.pos-v5-cart-item__visual'), 'corps', `ligne[${i}] vignette`);
            });
        }
        if (pied) {
            ajouter(pied.querySelector('[data-testid="pos-discount-toggle"]'), 'pied', 'remise');
            const cta = pied.querySelector('button[type="submit"], .pos-v5-btn--primary, button.pos-v5-cta');
            ajouter(cta, 'pied', 'encaisser');
        }

        const atteints = [];
        const horsVue = [];
        const recouverts = [];

        for (const c of cibles) {
            const cx = c.r.left + c.r.width / 2;
            const cy = c.r.top + c.r.height / 2;
            const f = fenetreVisible(c.el);
            const dansLaFenetre = cx >= f.left + 1 && cx <= f.right - 1
                && cy >= f.top + 1 && cy <= f.bottom - 1;

            const fiche = {
                zone: c.zone, role: c.role,
                boite: arrondi(c.r),
                centre: { x: Math.round(cx), y: Math.round(cy) },
            };

            if (!dansLaFenetre) {
                fiche.fenetre_visible = {
                    haut: Math.round(f.top), bas: Math.round(f.bottom),
                    gauche: Math.round(f.left), droite: Math.round(f.right),
                };
                horsVue.push(fiche);
                continue;
            }

            const touche = document.elementFromPoint(cx, cy);
            const ok = touche && (touche === c.el || c.el.contains(touche) || touche.contains(c.el));
            fiche.element_touche = nommer(touche);
            if (ok) atteints.push(fiche); else recouverts.push(fiche);
        }

        return {
            etat: label,
            fenetre: { largeur: window.innerWidth, hauteur: window.innerHeight },
            facteur_zoom_mesure: facteurZoom,
            lignes_panier: document.querySelectorAll('.pos-v5-cart-item').length,
            /* Toutes ces boîtes sont en PIXELS ÉCRAN. */
            panneau: arrondi(R(panneau)),
            entete: arrondi(R(tete)),
            corps: arrondi(R(corps)),
            pied: arrondi(R(pied)),
            /* Diagnostic seulement — PIXELS CSS, jamais comparés aux boîtes ci-dessus. */
            entete_contenu_css: tete ? tete.scrollHeight : null,
            entete_fenetre_css: tete ? tete.clientHeight : null,
            entete_debordement_css: tete ? tete.scrollHeight - tete.clientHeight : null,
            entete_overflow_y: tete ? getComputedStyle(tete).overflowY : null,
            entete_max_height: tete ? getComputedStyle(tete).maxHeight : null,
            nb_cibles: cibles.length,
            atteints: atteints.map((f) => `${f.zone}/${f.role}`),
            hors_vue: horsVue,
            recouverts,
        };
    }, etiquette);
}

const rapport = {};

for (const g of GABARITS) {
    test.describe(`panneau panier @ ${g.nom}`, () => {
        test.use({ viewport: { width: g.width, height: g.height } });
        test.describe.configure({ timeout: 300_000 });

        test(`aucune commande cliquable recouverte — panier vide ET panier plein @ ${g.nom}`, async ({ page }) => {
            await ouvrirLaCaisse(page);
            await viderLePanier(page);
            await page.waitForTimeout(500);

            const vide = await auditerPanier(page, `${g.nom}/panier-vide`);
            await page.locator('#pos-cart').screenshot({
                path: path.join(SORTIE, `${g.nom}-panier-vide.png`),
            }).catch(() => {});

            const ajoutes = await remplirLePanier(page, 2);
            await page.waitForTimeout(1000);
            const plein = await auditerPanier(page, `${g.nom}/panier-plein`);
            await page.locator('#pos-cart').screenshot({
                path: path.join(SORTIE, `${g.nom}-panier-plein.png`),
            }).catch(() => {});

            rapport[g.nom] = { vide, plein, articles_ajoutes: ajoutes };
            fs.writeFileSync(
                path.join(SORTIE, 'mesures.json'),
                JSON.stringify(rapport, null, 2),
            );
            console.log(`\n[FIX-2 ${g.nom}] ` + JSON.stringify({ vide, plein }, null, 2));

            // ── Le panier plein doit VRAIMENT être plein, sinon le test ne prouve rien ──
            expect(plein.lignes_panier,
                `le scénario exige ≥ 2 articles au panier ; ${plein.lignes_panier} obtenu(s)`)
                .toBeGreaterThanOrEqual(2);

            // ── PORTE P0 — l'élément visible DOIT être celui qui reçoit le clic ──
            const dire = (etat) => etat.recouverts
                .map((f) => `${f.zone}/${f.role} @(${f.centre.x},${f.centre.y}) → clic capté par ${f.element_touche}`)
                .join('\n      ');

            expect.soft(vide.recouverts.map((f) => `${f.zone}/${f.role}`),
                `[${g.nom} · PANIER VIDE] commandes peintes dont le clic part ailleurs :\n      ${dire(vide)}`)
                .toEqual([]);

            expect.soft(plein.recouverts.map((f) => `${f.zone}/${f.role}`),
                `[${g.nom} · PANIER PLEIN ${plein.lignes_panier} articles] commandes peintes dont le clic part ailleurs :\n      ${dire(plein)}`)
                .toEqual([]);

            // ── MANDAT PROPRIÉTAIRE — le nom s'imprime sur le ticket cuisine : sur un panier
            //    VIDE (début de vente), le champ doit être atteignable SANS aucun geste. ──
            const nomHorsVue = vide.hors_vue.find((f) => f.role === 'champ-nom-client');
            expect.soft(nomHorsVue,
                `[${g.nom} · PANIER VIDE] le champ « Nom du client » est hors de la fenêtre visible `
                + `${nomHorsVue ? JSON.stringify(nomHorsVue.fenetre_visible) : ''} — boîte `
                + `${nomHorsVue ? JSON.stringify(nomHorsVue.boite) : ''} : le caissier doit défiler `
                + 'pour l\'atteindre, alors qu\'il ouvre sa vente dessus')
                .toBeUndefined();

            expect.soft(vide.atteints,
                `[${g.nom} · PANIER VIDE] le champ « Nom du client » doit répondre au clic en son centre`)
                .toContain('entete/champ-nom-client');
        });
    });
}
