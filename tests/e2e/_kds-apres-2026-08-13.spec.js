/**
 * [KDS 2026-08-13] Mesure APRÈS refonte — le gain est-il réel ?
 *
 * On refait exactement la mesure d'avant : hauteur avant la première carte, nombre de rangées de
 * boutons au-dessus des commandes, et présence de tous les boutons dans UNE seule barre.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsChefOperator } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../captures/kds-2026-08-13');
fs.mkdirSync(OUT, { recursive: true });

test('KDS — la barre est unique et l\'espace est rendu aux commandes', async ({ page }) => {
    const erreurs = [];
    page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 250)));

    await loginAsChefOperator(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await page.waitForTimeout(7000);

    await page.screenshot({ path: path.join(OUT, 'apres-01-plein-ecran.png'), fullPage: false });

    const m = await page.evaluate(() => {
        const vh = window.innerHeight;
        const barre = document.querySelector('[data-testid="kds-toolbar"]');
        const bRect = barre ? barre.getBoundingClientRect() : null;

        // La première carte de commande. `.kds-card` est la classe RACINE réelle (KdsOrderCard.vue) :
        // viser le CTA puis remonter en `closest()` attrapait un conteneur intermédiaire et donnait
        // une mesure absurde (627 px, soit le bouton lui-même). On lit la carte, pas son bouton.
        const cartes = [...document.querySelectorAll('.kds-card')]
            .map((c) => c.getBoundingClientRect())
            .filter((r) => r.height > 0);
        const hautCarte = cartes.length ? Math.min(...cartes.map((r) => r.top)) : null;

        const dansLaBarre = (sel) => {
            const el = document.querySelector(sel);
            return !!(el && barre && barre.contains(el));
        };

        // Rangées distinctes de boutons situées au-dessus des commandes.
        const boutons = [...document.querySelectorAll('button')]
            .filter((b) => { const r = b.getBoundingClientRect(); return r.width > 0 && r.height > 0; })
            .map((b) => ({ testid: b.getAttribute('data-testid'), haut: Math.round(b.getBoundingClientRect().top) }));
        const limite = hautCarte === null ? 200 : hautCarte;
        const rangees = [...new Set(boutons.filter((b) => b.haut < limite - 4).map((b) => Math.round(b.haut / 14) * 14))].sort((a, b) => a - b);

        return {
            hauteurFenetre: vh,
            barrePresente: !!barre,
            hauteurBarre: bRect ? Math.round(bRect.height) : null,
            basDeLaBarre: bRect ? Math.round(bRect.bottom) : null,
            hautDeLaPremiereCarte: hautCarte === null ? null : Math.round(hautCarte),
            pourcentagePerduEnHaut: hautCarte === null ? null : Math.round((hautCarte / vh) * 100),
            rangeesDeBoutonsAuDessus: rangees,
            boutonsDansLaBarre: {
                rupture: dansLaBarre('[data-testid="kds-availability-panel-open"]'),
                historique: dansLaBarre('[data-testid="kds-history-button"]'),
                noms: dansLaBarre('[data-testid="kds-legend-toggle"]'),
                info: dansLaBarre('[data-testid="kds-bump-info-toggle"]'),
                colonnes4: dansLaBarre('[data-testid="kds-cols-4"]'),
                colonnes6: dansLaBarre('[data-testid="kds-cols-6"]'),
                colonnes8: dansLaBarre('[data-testid="kds-cols-8"]'),
            },
            bandeauPermanentVisible: !!document.getElementById('kds-bump-info'),
        };
    });

    console.log('[MESURE APRES] ' + JSON.stringify(m, null, 2));
    console.log('[ERREURS JS] ' + JSON.stringify(erreurs));
    fs.writeFileSync(path.join(OUT, 'mesure-apres.json'), JSON.stringify({ m, erreurs }, null, 2));

    // --- Ce qui doit être vrai, pas seulement joli ---
    expect(erreurs, 'aucune erreur JS sur l\'écran cuisine').toEqual([]);
    expect(m.barrePresente, 'la barre unique doit exister').toBe(true);

    // TOUS les boutons dans la MÊME barre — c'est la demande.
    for (const [nom, dedans] of Object.entries(m.boutonsDansLaBarre)) {
        expect(dedans, `le bouton « ${nom} » doit être dans la barre unique`).toBe(true);
    }

    // UNE seule rangée de boutons au-dessus des commandes (la barre admin exclue).
    expect(m.rangeesDeBoutonsAuDessus.length,
        `une seule rangée attendue au-dessus des commandes, trouvé : ${JSON.stringify(m.rangeesDeBoutonsAuDessus)}`)
        .toBeLessThanOrEqual(2); // barre admin + barre cuisine

    // Le bandeau d'information ne doit plus s'afficher d'office.
    expect(m.bandeauPermanentVisible, 'le bandeau « pastilles locales » ne doit plus être permanent').toBe(false);

    // LE GAIN, CHIFFRÉ.
    //
    // Mesuré avant refonte : la première commande ne commençait qu'à 165 px sur 720 — quatre
    // bandes empilées (barre admin, rangée de boutons, bandeau d'information, sélecteur de
    // colonnes). Après : 115 px. Le seuil verrouille ce gain : toute bande qu'on rajouterait
    // au-dessus des commandes fera rougir ce test, ce qui est exactement le but. Il est fixé
    // au-dessus de la valeur observée (marge de 15 px) pour ne pas casser sur un rendu de police
    // ou un pixel de bordure, mais bien en dessous des 165 px d'origine.
    expect(m.hautDeLaPremiereCarte,
        `la première commande doit rester haut dans l'écran (165 px avant refonte, ${m.hautDeLaPremiereCarte} px mesuré)`)
        .toBeLessThanOrEqual(130);
});
