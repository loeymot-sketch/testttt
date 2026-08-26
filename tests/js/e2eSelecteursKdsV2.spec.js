import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — découverte W10 du 2026-08-25]
 *
 * LE DÉFAUT, ÉTABLI PAR OBSERVATION EN VOL
 * -----------------------------------------
 * La vague D échouait sur SYNC-1 : « la carte borne n'atterrit pas dans
 * `[data-kds-order-card="kiosk"]` ». Sonde en vol : la page **reçoit** bien la commande
 * (`source_surface="kiosk"`, `status=4`) et l'**affiche** (« NOUVELLE BORNE N°A0132 … EN ATTENTE
 * ENCAISSEMENT »). Mais aucune carte ne porte cet attribut, et le message « Aucune commande borne
 * en cours. » ne s'affiche pas non plus — preuve que la COLONNE ENTIÈRE n'est pas rendue.
 *
 * Cause : `KitchenDisplaySystemComponent.vue:137` rend `<KdsV2Grid v-if="useV2Layout">`, et
 * `useV2Layout` vaut **true par défaut**. `KdsV2Grid.vue` ne pose **aucun** `data-kds-order-card`.
 * L'ancien balisage en colonnes vit derrière `v-if="!useV2Layout"` : **mort depuis la refonte**.
 *
 * L'AMPLEUR — relevé du 2026-08-25
 * --------------------------------
 * **14 specs** affirment contre `data-kds-order-card`. **Aucune** ne force la V1 via
 * `localStorage['kds.v2_enabled']`. Seules **3** visent les sélecteurs V2.
 *
 * Les 14 testent donc une interface que personne ne voit. Comme pour l'en-tête d'idempotence,
 * l'échec ressemble à un défaut produit et envoie chercher au mauvais endroit.
 *
 * CE QUE CETTE SENTINELLE FAIT
 * -----------------------------
 * Elle ne migre rien : choisir ce qu'un test doit prouver (V1 forcée, V2 servie, ou les deux) est
 * une décision de conception, pas une réparation mécanique. Elle empêche la dette de croître et
 * rend la classe de défaut visible.
 */

const racineE2E = path.resolve(process.cwd(), 'tests/e2e');
const composantKds = path.resolve(
    process.cwd(),
    'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue',
);
const grilleV2 = path.resolve(
    process.cwd(),
    'resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue',
);

/** Plafond relevé le 2026-08-25 : 14 specs visent le balisage V1 sans forcer la V1. NE DOIT QUE DESCENDRE. */
const PLAFOND_SPECS_V1 = 14;

function listerSpecs(repertoire) {
    const sortie = [];
    for (const entree of fs.readdirSync(repertoire, { withFileTypes: true })) {
        if (entree.name === '__screenshots__' || entree.name === 'node_modules') continue;
        const complet = path.join(repertoire, entree.name);
        if (entree.isDirectory()) sortie.push(...listerSpecs(complet));
        else if (entree.name.endsWith('.spec.js')) sortie.push(complet);
    }
    return sortie;
}

const specsV1SansBascule = listerSpecs(racineE2E)
    .map((c) => ({ chemin: path.relative(process.cwd(), c), source: fs.readFileSync(c, 'utf8') }))
    .filter(({ source }) => source.includes('data-kds-order-card'))
    .filter(({ source }) => !/kds\.v2_enabled|useV2Layout|v2_enabled/.test(source))
    .map(({ chemin }) => chemin);

describe('KDS — les specs visent-elles l’interface réellement servie ?', () => {
    it('la V2 est bien la disposition par défaut', () => {
        const src = fs.readFileSync(composantKds, 'utf8');
        expect(src).toMatch(/<KdsV2Grid[\s\S]{0,80}v-if="useV2Layout"/);
    });

    it('la grille V2 ne pose aucun `data-kds-order-card`', () => {
        // C'est LE fait qui rend les 14 specs inopérantes. S'il change, elles redeviennent valides
        // et ce test doit être revu — pas contourné.
        const v2 = fs.readFileSync(grilleV2, 'utf8');
        expect(v2).not.toContain('data-kds-order-card');
    });

    it('le balisage V1 existe toujours, mais derrière la bascule', () => {
        const src = fs.readFileSync(composantKds, 'utf8');
        expect(src).toContain('data-kds-order-card="kiosk"');
    });

    it('ne laisse pas grandir le nombre de specs visant une interface non servie', () => {
        expect(
            specsV1SansBascule.length,
            `${specsV1SansBascule.length} specs affirment contre \`data-kds-order-card\` SANS forcer ` +
                `la disposition V1 (plafond ${PLAFOND_SPECS_V1}). Elles testent une interface que ` +
                `personne ne voit, et leur échec ressemble à un défaut produit :\n  ` +
                specsV1SansBascule.join('\n  ') +
                `\n\nDeux issues, au choix du propriétaire : viser les sélecteurs V2 ` +
                `(\`kds-cols-*\`, \`kds-scroll-*\`), ou forcer localStorage['kds.v2_enabled']=false.`,
        ).toBeLessThanOrEqual(PLAFOND_SPECS_V1);
    });

    it('documente qu’une poignée de specs vise déjà la V2', () => {
        const v2 = listerSpecs(racineE2E).filter((c) =>
            /kds-cols-|kds-scroll-|kds-served-reopen/.test(fs.readFileSync(c, 'utf8')),
        );
        expect(v2.length).toBeGreaterThan(0);
    });
});
