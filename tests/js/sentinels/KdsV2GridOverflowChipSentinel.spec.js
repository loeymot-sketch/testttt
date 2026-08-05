import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-WAVE-N-KDS-OVERFLOW-001 → FK-KDS-SHOW-ALL-001 (2026-07-01) →
 *        FK-KDS-3CARDS-001 (2026-07-05) → FK-KDS-6CARDS-001 (2026-08-05)
 * @source Wave M M-KDS-6 F1 P0 (safety net) → show-all → 3 cartes (c70b1e518) →
 *         GOAL-8AXES /goal owner 2026-08-05 (révocation explicite du 3-cartes :
 *         « je veux que ça affiche six à la fois et encore on pourra se scroller
 *         horizontalement pour voir les autres commandes »).
 *
 * INVARIANT COURANT (KDS-6CARDS) :
 *  1. TOUTES les commandes actives sont rendues (visibleActiveOrders = activeOrders,
 *     aucun slice de rendu) dans un flux HORIZONTAL défilable ;
 *  2. 6 cartes par écran (KDS_VISIBLE_CARDS = 6, grid-auto-flow: column) ;
 *  3. la barre de défilement reste VISIBLE (pas d'auto-hide — secours souris si
 *     l'écran tactile lâche) + boutons ◀ ▶ larges ;
 *  4. le filet Wave M reste ACTIF : overflowActiveCount compte les commandes
 *     au-delà de l'écran (+N en attente) — aucune commande masquée en silence ;
 *  5. les raccourcis clavier [A]-[H] restent bornés aux cartes garanties à
 *     l'écran SANS scroll (shortcutOrders, régression P2-k).
 */
describe('KDS 6 cartes + flux horizontal + pastille overflow (FK-KDS-6CARDS-001)', () => {
    const gridPath = resolve(
        process.cwd(),
        'resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue',
    );
    const gridSource = readFileSync(gridPath, 'utf8');

    it('rend la file défilable (plafond de rendu KDS_RENDER_MAX) avec 6 par écran', () => {
        expect(gridSource).toMatch(/v-for="\(o,\s*idx\)\s+in\s+visibleActiveOrders"/);
        // Toutes les cartes jusqu'au plafond perf (24 = 4 écrans de 6) — plus
        // jamais un cap de 3 qui cachait la file au chef.
        expect(gridSource).toMatch(/visibleActiveOrders\s*\(\s*\)\s*\{[\s\S]*?slice\(\s*0\s*,\s*KDS_RENDER_MAX\s*\)/);
        expect(gridSource).toMatch(/KDS_VISIBLE_CARDS\s*=\s*6/);
        expect(gridSource).toMatch(/KDS_RENDER_MAX\s*=\s*24/);
    });

    it('flux horizontal défilable, une rangée pleine hauteur, barre visible', () => {
        const styleBlock = gridSource.match(/<style\s+scoped>[\s\S]*?<\/style>/);
        expect(styleBlock, 'style scoped attendu').not.toBeNull();
        const css = styleBlock[0];
        const gridCss = css.match(/\.kds-v2__grid\s*\{[^}]*\}/);
        expect(gridCss, 'bloc .kds-v2__grid attendu').not.toBeNull();
        const g = gridCss[0];
        expect(g).toMatch(/grid-auto-flow:\s*column/);
        expect(g).toMatch(/grid-template-rows:\s*1fr/);
        expect(g).toMatch(/align-items:\s*stretch/);
        expect(g).toMatch(/overflow-x:\s*auto/);
        // Barre visible : scrollbar stylée (jamais masquée).
        expect(css).toMatch(/::-webkit-scrollbar\s*\{/);
        expect(g).toMatch(/scrollbar-width:\s*auto/);
        // Boutons de défilement souris/tactile.
        expect(gridSource).toMatch(/data-testid="kds-scroll-left"/);
        expect(gridSource).toMatch(/data-testid="kds-scroll-right"/);
    });

    it('le filet overflow est ACTIF (+N au-delà de 6, jamais neutralisé)', () => {
        expect(gridSource).toMatch(/overflowActiveCount\s*\(\s*\)\s*\{[\s\S]*?this\.activeOrders\.length\s*-\s*KDS_VISIBLE_CARDS/);
        expect(gridSource).not.toMatch(/overflowActiveCount\s*\(\s*\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*return\s+0\s*;/);
        expect(gridSource).toMatch(/kds-overflow-chip/);
        expect(gridSource).toMatch(/v-if="overflowActiveCount\s*>\s*0"/);
    });

    it('raccourcis clavier bornés aux 6 cartes garanties visibles (P2-k)', () => {
        expect(gridSource).toMatch(/shortcutOrders\s*\(\s*\)\s*\{[\s\S]*?slice\(\s*0\s*,\s*KDS_VISIBLE_CARDS\s*\)/);
        expect(gridSource).toMatch(/idx\s*<\s*this\.shortcutOrders\.length/);
    });
});
