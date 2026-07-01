import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-WAVE-N-KDS-OVERFLOW-001 → FK-KDS-SHOW-ALL-001 (2026-07-01)
 * @source Wave M M-KDS-6 F1 P0 (safety net) → owner-gate levée : afficher TOUTES les commandes.
 *
 * HISTORIQUE. La grille KDS V2 plafonnait à 8 cartes (`slice(0, 8)`), les commandes 9+
 * étaient masquées ; une pastille « +N en attente » servait de filet de sécurité (chef
 * qui ne voit pas la file complète = risque opérationnel). L'owner a explicitement demandé
 * (2026-07-01) que l'écran cuisine affiche TOUTES les commandes ET tous les produits de
 * chaque commande, une grosse commande prenant plus de hauteur.
 *
 * NOUVEL INVARIANT (plus fort que l'ancien filet) : AUCUNE commande active n'est jamais
 * masquée ni tronquée. La grille rend `activeOrders` en entier (pas de `.slice(0, N)`),
 * elle DÉFILE (overflow-y:auto) et les cartes prennent la hauteur de leur contenu
 * (grid-auto-rows:min-content + align-items:start, PAS une grille à hauteur figée). Ainsi
 * le drop silencieux des 9+ que Wave M avait attrapé est éliminé à la racine.
 *
 * Anti-régression : si un `slice(0, N)` réapparaît sur activeOrders, ou si la grille
 * repasse en hauteur figée (grid-template-rows) sans défilement, des commandes/produits
 * redeviennent invisibles → ce test casse.
 */
describe('KDS Show-All — aucune commande masquée (FK-KDS-SHOW-ALL-001)', () => {
    const gridPath = resolve(
        process.cwd(),
        'resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue',
    );
    const gridSource = readFileSync(gridPath, 'utf8');

    it('rend TOUTES les commandes actives (aucun slice cap sur activeOrders)', () => {
        // La boucle des cartes doit itérer activeOrders EN ENTIER.
        expect(gridSource).toMatch(/v-for="\(o,\s*idx\)\s+in\s+activeOrders"/);
        // Garde anti-régression : plus aucun plafond .slice(0, N) sur activeOrders.
        expect(gridSource).not.toMatch(/activeOrders\.slice\(\s*0\s*,\s*\d+\s*\)/);
    });

    it('la grille DÉFILE et n\'est plus figée en 2 rangées', () => {
        const styleBlock = gridSource.match(/<style\s+scoped>[\s\S]*?<\/style>/);
        expect(styleBlock, 'style scoped attendu').not.toBeNull();
        const css = styleBlock[0];
        const gridCss = css.match(/\.kds-v2__grid\s*\{[^}]*\}/);
        expect(gridCss, 'bloc .kds-v2__grid attendu').not.toBeNull();
        const g = gridCss[0];
        // Défilement vertical (au lieu de cacher les commandes qui dépassent l'écran).
        expect(g).toMatch(/overflow-y:\s*auto/);
        // Cartes à hauteur de contenu (pas une grille 4×2 figée qui écrase/plafonne).
        expect(g).toMatch(/grid-auto-rows:\s*min-content/);
        expect(g).toMatch(/align-items:\s*start/);
        expect(g).not.toMatch(/grid-template-rows:\s*repeat/);
    });

    it('le compteur d\'overflow est neutralisé (plus de commande cachée)', () => {
        // overflowActiveCount reste défini (compat template) mais renvoie 0 : rien n'est masqué.
        expect(gridSource).toMatch(/overflowActiveCount\s*\(\s*\)\s*\{[\s\S]*?return\s+0\s*;/);
    });
});
