import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import KdsV2Grid, { KDS_CHOIX_CARTES, KDS_CARTES_PAR_ECRAN_DEFAUT, KDS_RENDER_MAX } from '../../../resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue';

/**
 * @FK-ID FK-WAVE-N-KDS-OVERFLOW-001 → FK-KDS-SHOW-ALL-001 (2026-07-01) →
 *        FK-KDS-3CARDS-001 (2026-07-05) → FK-KDS-6CARDS-001 (2026-08-05) →
 *        FK-KDS-COLONNES-001 (2026-08-07)
 *
 * INVARIANT COURANT (KDS-COLONNES, owner 2026-08-07) :
 *  1. le nombre de cartes par écran est RÉGLABLE (4 / 6 / 8), défaut 4 ;
 *  2. la LARGEUR d'une carte est CONSTANTE — elle ne dépend plus du nombre de
 *     commandes. C'est le défaut signalé par l'owner : deux commandes occupaient
 *     tout l'écran, puis la troisième les réduisait d'un coup au sixième ;
 *  3. toutes les commandes restent rendues (jusqu'au plafond perf) dans un flux
 *     HORIZONTAL défilable, depuis n'importe quel réglage ;
 *  4. le filet Wave M reste ACTIF : « +N en attente » compte ce qui dépasse
 *     l'écran — aucune commande masquée en silence ;
 *  5. les raccourcis clavier restent bornés aux cartes garanties SANS scroll.
 *
 * ⚠️ Les points 1, 4 et 5 sont vérifiés en APPELANT le composant, pas en relisant
 * son source : une sentinelle en expression régulière reste verte alors que le
 * comportement a disparu — c'est la leçon la plus chère de ce projet.
 */
describe('KDS colonnes réglables + flux horizontal + pastille overflow (FK-KDS-COLONNES-001)', () => {
    const gridPath = resolve(
        process.cwd(),
        'resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue',
    );
    const gridSource = readFileSync(gridPath, 'utf8');

    /** Instancie les computed du composant sur une file donnée, sans monter le DOM. */
    const vm = (nbCommandes, cartesParEcran = KDS_CARTES_PAR_ECRAN_DEFAUT) => {
        const activeOrders = Array.from({ length: nbCommandes }, (_, i) => ({ id: i + 1 }));
        return {
            activeOrders,
            cartesParEcran,
            shortcutOrders: KdsV2Grid.computed.shortcutOrders.call({ activeOrders, cartesParEcran }),
            overflowActiveCount: KdsV2Grid.computed.overflowActiveCount.call({ activeOrders, cartesParEcran }),
            visibleActiveOrders: KdsV2Grid.computed.visibleActiveOrders.call({ activeOrders, cartesParEcran }),
        };
    };

    it('le nombre de cartes par écran est réglable, défaut 4', () => {
        expect(KDS_CHOIX_CARTES).toEqual([4, 6, 8]);
        expect(KDS_CARTES_PAR_ECRAN_DEFAUT).toBe(4);
        expect(KdsV2Grid.data().cartesParEcran).toBe(KDS_CARTES_PAR_ECRAN_DEFAUT);
        for (const n of KDS_CHOIX_CARTES) {
            expect(gridSource).toMatch(new RegExp(`kds-cols-\\$\\{n\\}|kds-cols-${n}`));
        }
    });

    /**
     * LE DÉFAUT OWNER — la largeur d'une carte ne doit plus dépendre du nombre de
     * commandes. Les règles `[data-count="1"]` / `[data-count="2"]` qui remettaient les
     * cartes à 100 % et 50 % sont la cause exacte du rétrécissement brutal à la 3ᵉ
     * commande : elles ne doivent pas revenir.
     */
    it('la largeur d’une carte ne dépend PAS du nombre de commandes', () => {
        expect(gridSource).not.toMatch(/\.kds-v2__grid\[data-count="1"\]\s*\{[^}]*grid-auto-columns/);
        expect(gridSource).not.toMatch(/\.kds-v2__grid\[data-count="2"\]\s*\{[^}]*grid-auto-columns/);
        // La largeur est pilotée par le RÉGLAGE (variable CSS), pas par la file.
        expect(gridSource).toMatch(/grid-auto-columns:\s*calc\([^;]*var\(--kds-cols/);
        expect(gridSource).toMatch(/'--kds-cols':\s*cartesParEcran/);
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
        expect(css).toMatch(/::-webkit-scrollbar\s*\{/);
        expect(g).toMatch(/scrollbar-width:\s*auto/);
        expect(gridSource).toMatch(/data-testid="kds-scroll-left"/);
        expect(gridSource).toMatch(/data-testid="kds-scroll-right"/);
    });

    /** Le filet overflow suit le RÉGLAGE : à 8 par écran, 8 commandes ne débordent pas. */
    it('la pastille « +N » compte ce qui dépasse le réglage courant', () => {
        expect(vm(10, 4).overflowActiveCount).toBe(6);
        expect(vm(10, 6).overflowActiveCount).toBe(4);
        expect(vm(10, 8).overflowActiveCount).toBe(2);
        expect(vm(8, 8).overflowActiveCount).toBe(0);
        expect(vm(2, 4).overflowActiveCount).toBe(0);
        expect(gridSource).toMatch(/kds-overflow-chip/);
        expect(gridSource).toMatch(/v-if="overflowActiveCount\s*>\s*0"/);
    });

    /** Les raccourcis ne doivent JAMAIS pouvoir bumper une commande hors de vue. */
    it('les raccourcis clavier restent bornés aux cartes visibles sans scroll', () => {
        expect(vm(10, 4).shortcutOrders).toHaveLength(4);
        expect(vm(10, 8).shortcutOrders).toHaveLength(8);
        expect(vm(3, 8).shortcutOrders).toHaveLength(3);
        expect(gridSource).toMatch(/idx\s*<\s*this\.shortcutOrders\.length/);
    });

    /** Toute la file reste rendue (jusqu'au plafond perf) : rien n'est masqué en silence. */
    it('toutes les commandes restent rendues jusqu’au plafond de perf', () => {
        expect(vm(10, 4).visibleActiveOrders).toHaveLength(10);
        expect(vm(KDS_RENDER_MAX + 30, 4).visibleActiveOrders).toHaveLength(KDS_RENDER_MAX);
    });
});
