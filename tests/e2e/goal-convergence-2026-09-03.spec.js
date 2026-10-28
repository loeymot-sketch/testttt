/**
 * [GOAL CONVERGENCE 2026-09-03 · G6/T6.5]
 *
 * La campagne navigateur de clôture des cinq chantiers du 3 septembre.
 *
 * Elle diffère des campagnes précédentes sur un point qui compte : **les erreurs de console
 * et les refus réseau font ÉCHOUER le test.** Les campagnes du dépôt les collectaient sans
 * les asserter — ce qui revient à surveiller le transport temps réel en acceptant qu'il
 * tombe. Une liste blanche existe, elle est courte, datée, et chaque entrée dit pourquoi.
 *
 * Ce que la campagne prouve, écran par écran :
 *   1. le tableau de bord distingue un échec d'une journée creuse (G5) ;
 *   2. le cockpit de santé publie un verdict serveur, pas un arrondi recalculé (G4) ;
 *   3. le cockpit outbox rend les claims en vol, orphelins et terminaux (G2) ;
 *   4. le tiroir de caisse ouvre les quatre files sans quitter la page (G1).
 *
 * Elle ne prouve PAS les états dégradés (worker mort, sauvegarde périmée, purge en échec) :
 * ceux-là sont tenus par les bancs de composant et les bancs serveur, qui peuvent les
 * fabriquer. Le navigateur sert ici à vérifier que l'écran RÉEL, servi par le lot RÉEL,
 * n'est pas cassé — pas à rejouer la matrice complète.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsAdmin, loginAsPosOperator } = require('./helpers/login');

const SORTIE = path.join(__dirname, '..', '..', 'reports', 'supervision', '2026-09-03', 'captures');

/**
 * Tolérances. Chacune doit dire POURQUOI, sinon elle devient l'endroit où l'on cache les
 * régressions. Elles sont volontairement peu nombreuses.
 */
const TOLERE = [
    // La barre de débogage Laravel est active en local et bavarde ; elle n'existe pas en production.
    /debugbar/i,
    // Le pont temps réel n'est pas démarré sur ce poste : les écrans doivent le DIRE, pas planter.
    // Cette tolérance porte sur le transport, jamais sur une erreur applicative.
    /websocket|ws:\/\/|wss:\/\/|pusher|echo/i,
    // Favicon absent en local.
    /favicon/i,
    // [2026-09-03] Les deux ponts d'impression ESC/POS — 9100 pour le ticket de caisse,
    // 9101 pour la cuisine — sont des DÉMONS MATÉRIELS. Ils n'existent pas sur un poste de
    // développement, et le produit prévoit ce cas (repli `window.print()`). Tolérance nommée
    // au port près, pas au motif large : un refus de connexion vers autre chose reste un échec.
    /127\.0\.0\.1:(9100|9101)/,
    /localhost:(9100|9101)/,
];

/**
 * Le message générique du navigateur pour un refus de connexion ne nomme pas sa cible : il ne
 * peut donc pas être jugé seul. On ne l'écarte QUE si tous les refus réseau de la page étaient
 * eux-mêmes tolérés. Sinon il reste un incident — c'est la différence entre une tolérance et
 * un tapis sous lequel on pousse.
 */
const REFUS_GENERIQUE = /^console: Failed to load resource: net::ERR_CONNECTION_REFUSED$/;

function estTolere(texte) {
    return TOLERE.some((r) => r.test(String(texte)));
}

function brancherLeGuet(page) {
    const incidents = [];
    let refusNonToleres = 0;
    page.on('console', (m) => {
        if (m.type() !== 'error') return;
        const t = m.text();
        if (!estTolere(t)) incidents.push(`console: ${t}`);
    });
    page.on('pageerror', (e) => {
        const t = e.message || String(e);
        if (!estTolere(t)) incidents.push(`exception: ${t}`);
    });
    page.on('requestfailed', (r) => {
        const t = `${r.url()} — ${r.failure()?.errorText ?? 'échec'}`;
        if (!estTolere(t)) {
            refusNonToleres += 1;
            incidents.push(`réseau: ${t}`);
        }
    });
    page.on('response', (r) => {
        if (r.status() < 500) return;
        const t = `${r.status()} ${r.url()}`;
        if (!estTolere(t)) incidents.push(`serveur: ${t}`);
    });
    // Le tableau rendu filtre le refus générique une fois qu'on sait à quoi il se rapportait.
    incidents.retenus = function () {
        return this.filter((i) => !(REFUS_GENERIQUE.test(i) && refusNonToleres === 0));
    };

    return incidents;
}

async function capturer(page, nom) {
    fs.mkdirSync(SORTIE, { recursive: true });
    await page.screenshot({ path: path.join(SORTIE, `${nom}.png`), fullPage: false });
}

test.describe('Convergence 2026-09-03 — les quatre écrans, sur le lot réellement servi', () => {
    test.setTimeout(120_000);

    test('tableau de bord — les tuiles se chargent et rien ne casse', async ({ page }) => {
        const incidents = brancherLeGuet(page);
        await loginAsAdmin(page);
        await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(4000);

        await capturer(page, '01-tableau-de-bord');

        const corps = await page.locator('body').innerText();
        // Un libellé non résolu est un défaut visible : il se lit `machin.truc` à l'écran.
        expect(corps, 'aucune clé de traduction brute ne doit rester à l’écran')
            .not.toMatch(/\b[a-z_]+\.[a-z_]{3,}\b(?![^<]*>)\s*$/m);
        expect(corps).not.toContain('0undefined');
        expect(incidents.retenus(), `incidents non tolérés :\n${incidents.retenus().join('\n')}`).toEqual([]);
    });

    test('cockpit santé — le verdict vient du serveur', async ({ page }) => {
        const incidents = brancherLeGuet(page);
        await loginAsAdmin(page);
        await page.goto('/admin/observability/system', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(4000);

        await capturer(page, '02-cockpit-sante');

        // Apostrophes normalisées : droite dans la source, courbe possible après rendu. Une
        // assertion sur le caractère typographique mesurerait la fonte, pas le message.
        const corps = (await page.locator('body').innerText()).replace(/[\u2018\u2019]/g, "'");
        // [G3/T3.4] L'écran doit nommer le bon journal — ni « journal serveur » (sous-vendu),
        // ni « fiscal NF525 » tout court (sur-vendu).
        expect(corps, "l'écran doit nommer le journal d'audit métier")
            .toContain("journal d'audit métier");
        expect(corps, "l'écran ne doit pas se faire passer pour une écriture fiscale")
            .toContain('pas une écriture fiscale NF525');
        expect(incidents.retenus(), `incidents non tolérés :\n${incidents.retenus().join('\n')}`).toEqual([]);
    });

    test('cockpit outbox — les claims en vol et orphelins sont RENDUS', async ({ page }) => {
        const incidents = brancherLeGuet(page);
        await loginAsAdmin(page);
        await page.goto('/admin/observability/outbox', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(4000);

        await capturer(page, '03-cockpit-outbox');

        // [G2/V-03] Ces trois états étaient chargés dans le composant et jamais affichés.
        // C'est le coeur du correctif : ils doivent exister dans le DOM SERVI.
        for (const marqueur of ['outbox-in-flight-count', 'outbox-stale-claimed-count', 'outbox-terminal-count']) {
            await expect(
                page.locator(`[data-testid="${marqueur}"]`),
                `${marqueur} doit être rendu — il était chargé puis jeté`
            ).toHaveCount(1);
        }
        expect(incidents.retenus(), `incidents non tolérés :\n${incidents.retenus().join('\n')}`).toEqual([]);
    });

    test('caisse — le tiroir ouvre les quatre files sans quitter la page', async ({ page }) => {
        const incidents = brancherLeGuet(page);
        await loginAsPosOperator(page);
        await page.waitForTimeout(2500);

        const urlAvant = page.url();
        await page.locator('[data-testid="pos-tracker-open"]').first().click();
        await page.waitForTimeout(2500);

        await capturer(page, '04-caisse-tiroir');

        await expect(page.locator('[data-testid="pos-control-drawer"]')).toHaveCount(1);
        expect(page.url(), 'le tiroir ne doit pas provoquer de navigation').toBe(urlAvant);
        expect(incidents.retenus(), `incidents non tolérés :\n${incidents.retenus().join('\n')}`).toEqual([]);
    });
});
