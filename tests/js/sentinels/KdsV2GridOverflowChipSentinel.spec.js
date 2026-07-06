import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-WAVE-N-KDS-OVERFLOW-001 → FK-KDS-SHOW-ALL-001 (2026-07-01) → FK-KDS-3CARDS-001 (2026-07-05)
 * @source Wave M M-KDS-6 F1 P0 (safety net) → show-all 2026-07-01 → owner mandate 3 cartes (c70b1e518).
 *
 * HISTORIQUE. (1) Wave M : plafond `slice(0, 8)` + pastille « +N en attente ».
 * (2) KDS-SHOW-ALL 2026-07-01 : owner-gate levée, toutes les commandes rendues, grille
 * défilante, overflowActiveCount neutralisé (return 0).
 * (3) KDS-3CARDS c70b1e518 2026-07-05 : NOUVEAU mandat owner — l'écran affiche 3 commandes
 * MAX à la fois, chacune sur TOUTE la hauteur (grandes + lisibles, fini les cartes écrasées).
 * Les commandes 4+ attendent leur tour, signalées par la pastille « +N en attente ».
 *
 * INVARIANT COURANT : cap de rendu = 3 (visibleActiveOrders = activeOrders.slice(0, 3)),
 * une seule rangée pleine hauteur (grid-template-rows: 1fr + stretch, pas de scroll), ET
 * le filet de sécurité Wave M est OBLIGATOIREMENT actif : overflowActiveCount compte les
 * commandes en attente et la pastille .kds-overflow-chip est rendue dès qu'il y en a —
 * aucune commande ne peut être masquée SILENCIEUSEMENT.
 *
 * Anti-régression : si le cap change sans pastille, si overflowActiveCount repasse à 0
 * (drop silencieux des 4+), ou si la pastille disparaît du template → ce test casse.
 */
describe('KDS 3 cartes plein écran + pastille overflow (FK-KDS-3CARDS-001)', () => {
    const gridPath = resolve(
        process.cwd(),
        'resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue',
    );
    const gridSource = readFileSync(gridPath, 'utf8');

    it('rend 3 commandes actives max (visibleActiveOrders = activeOrders.slice(0, 3))', () => {
        // La boucle des cartes itère le cap visible, dérivé de activeOrders (jamais visibleOrders).
        expect(gridSource).toMatch(/v-for="\(o,\s*idx\)\s+in\s+visibleActiveOrders"/);
        expect(gridSource).toMatch(/visibleActiveOrders\s*\(\s*\)\s*\{[\s\S]*?return\s+this\.activeOrders\.slice\(\s*0\s*,\s*3\s*\)/);
    });

    it('une seule rangée pleine hauteur (3 cartes plein écran, pas de scroll)', () => {
        const styleBlock = gridSource.match(/<style\s+scoped>[\s\S]*?<\/style>/);
        expect(styleBlock, 'style scoped attendu').not.toBeNull();
        const css = styleBlock[0];
        const gridCss = css.match(/\.kds-v2__grid\s*\{[^}]*\}/);
        expect(gridCss, 'bloc .kds-v2__grid attendu').not.toBeNull();
        const g = gridCss[0];
        // Rangée unique qui remplit la hauteur → cartes plein écran, zéro vide en dessous.
        expect(g).toMatch(/grid-template-rows:\s*1fr/);
        expect(g).toMatch(/align-items:\s*stretch/);
        // 3 colonnes max.
        expect(g).toMatch(/grid-template-columns:\s*repeat\(3,/);
    });

    it('le filet de sécurité overflow est ACTIF (compteur réel + pastille rendue)', () => {
        // overflowActiveCount compte réellement les commandes en attente (pas de return 0 neutralisé).
        expect(gridSource).toMatch(/overflowActiveCount\s*\(\s*\)\s*\{[\s\S]*?this\.activeOrders\.length\s*-\s*3/);
        expect(gridSource).not.toMatch(/overflowActiveCount\s*\(\s*\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*return\s+0\s*;/);
        // La pastille « +N en attente » est présente dans le template, gardée par le compteur.
        expect(gridSource).toMatch(/kds-overflow-chip/);
        expect(gridSource).toMatch(/v-if="overflowActiveCount\s*>\s*0"/);
    });
});
