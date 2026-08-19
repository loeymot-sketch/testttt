import { describe, it, expect } from 'vitest';

/**
 * [OWNER 2026-08-19, 2ᵉ passe] « LES MENUS ON LES VOIT PLUS, LES BOISSONS ON LES VOIT PLUS »
 *
 * CE QUE LE REPLI A CASSÉ
 * -----------------------
 * Le repli du doublon de formule (826020f3c puis 5a3b85e0f) retirait la ligne de formule de
 * l'écran cuisine. Il retirait AUSSI, sans le dire, deux choses qu'elle seule portait :
 *
 *   1. la NATURE de la formule. Le badge du produit parent vient du canal `addons` du
 *      `composition_snapshot`. Or la caisse n'y scelle RIEN — mesuré en base : `"addons": []`
 *      sur 100 % des lignes. Le mot « MENU » ne venait donc QUE de la ligne repliée. Depuis
 *      le repli, un menu complet s'affiche « FRITES » : la cuisine ne sert plus la boisson.
 *   2. les consignes écrites uniquement sur la ligne de formule — mesuré en base : 5 formules
 *      dont la « Sauce frites : … » n'existe que là (commande 5544 : Andalouse), et 17 parents
 *      qui revendiquent un menu sans porter aucune consigne : badge VIDE, plus rien du tout.
 *
 * Le bandeau CUISSON n'a jamais été touché (il lit TOUTES les lignes, repli ou pas) — ce que
 * l'owner a confirmé lui-même : « en cuisson on le trouve toujours, c'est calculé comme frites ».
 *
 * Jumeau strict : tests/Unit/Hardware/KitchenFormuleVisibleTest.php — mêmes cas des deux côtés.
 */
import { collapseBundledAddonItems } from '../../resources/js/helpers/kdsBundledAddons';
import { claimedFormuleBadge, renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic';
import { sanitizeKdsInstruction } from '../../resources/js/helpers/kdsCustomization';

/** Commande 6598 — la sauce frites est chez le parent ET sur la ligne de formule. */
const PARENT_6598 = [
    'CAYENNE',
    'Pain Viandes : Poulet mariné - Salade, Tomate Sauce : Algérienne',
    '+ Menu (Frites + Boisson) (+2,50 €)',
    '↳ Sauce frites: Mayonnaise',
    'BOISSON: Coca-Cola 33cl',
].join('\n');

/** Commande 5544 — le parent ne porte AUCUNE sauce frites : elle vit sur la formule. */
const PARENT_5544 = [
    'CAYENNE',
    'Pain - Salade, Tomate, Oignons cuits Sauce : Algérienne',
    '+ Menu (Frites + Boisson) (+2,50 €)',
    'BOISSON: Hawaï 33cl',
    '[bien cuit svp]',
].join('\n');

const ligne = (item_name, instruction, quantity = 1) => ({
    item_name,
    instruction,
    quantity,
    composition_snapshot: { lines: [], addons: [], extras: [] },
});

/** Le badge tel qu'il s'affiche sur la carte cuisine (ligne `symbolic-menu`). */
const badge = (orderItem) => {
    const ligneMenu = renderItemSymbolic(orderItem).lines.find((l) => l.type === 'symbolic-menu');
    return ligneMenu ? ligneMenu.label : '';
};

describe('la formule reste visible en cuisine après le repli du doublon', () => {
    it('commande 5544 : la sauce frites de la ligne repliée arrive chez le parent', () => {
        const out = collapseBundledAddonItems([
            ligne('Cayenne', PARENT_5544),
            ligne('Menu (Frites + Boisson)', 'MENU\n↳ Sauce frites: Andalouse'),
        ]);

        expect(out).toHaveLength(1);
        expect(out[0].instruction).toContain('Sauce frites: Andalouse');
    });

    it('commande 5544 : le badge dit MENU là où il ne disait plus RIEN', () => {
        const out = collapseBundledAddonItems([
            ligne('Cayenne', PARENT_5544),
            ligne('Menu (Frites + Boisson)', 'MENU\n↳ Sauce frites: Andalouse'),
        ]);

        // Avant correctif : aucune ligne `symbolic-menu` — ni frites ni boisson préparées.
        expect(badge(out[0])).toBe('MENU : AND');
    });

    it('commande 6598 : le badge dit MENU et non FRITES', () => {
        const out = collapseBundledAddonItems([
            ligne('Cayenne', PARENT_6598),
            ligne('Menu (Frites + Boisson)', 'Sauce frites: Mayonnaise'),
            ligne('Coca-Cola 33cl', 'COCA-COLA 33CL'),
        ]);

        expect(out).toHaveLength(2);
        // Avant correctif : « FRITES : MAY » — un menu complet annoncé comme des frites seules.
        expect(badge(out[0])).toBe('MENU : MAY');
        expect(out[1].item_name).toBe('Coca-Cola 33cl');
    });

    it('la boisson de la formule reste lisible sur la carte du parent', () => {
        const out = collapseBundledAddonItems([
            ligne('Cayenne', PARENT_5544),
            ligne('Menu (Frites + Boisson)', 'MENU\n↳ Sauce frites: Andalouse'),
        ]);

        const rendu = JSON.stringify(renderItemSymbolic(out[0]).lines);
        expect(rendu).toContain('Hawaï 33cl');
    });

    it('pas de sauce frites en double quand le parent la porte déjà', () => {
        const out = collapseBundledAddonItems([
            ligne('Cayenne', PARENT_6598),
            ligne('Menu (Frites + Boisson)', 'Sauce frites: Mayonnaise'),
        ]);

        expect(out[0].instruction.match(/sauce\s*frites\s*:/gi)).toHaveLength(1);
    });

    it('la note libre du caissier reste la dernière ligne', () => {
        const out = collapseBundledAddonItems([
            ligne('Cayenne', PARENT_5544),
            ligne('Menu (Frites + Boisson)', 'MENU\n↳ Sauce frites: Andalouse'),
        ]);

        const lignes = out[0].instruction.split('\n');
        expect(lignes[lignes.length - 1].trim()).toBe('[bien cuit svp]');
    });

    it.each([
        ['+ Menu (Frites + Boisson) (+2,50 €)', 'MENU'],
        ['+ Menu complet', 'MENU'],
        ['+ Formule du midi (+3,00 €)', 'MENU'],
        ['+ Frites seules (+1,50 €)', 'FRITES'],
        ['+ Boisson Seule (+1,90 €)', 'BOISSON'],
        ['+ Cheddar (+0,90 €)', ''],
        ['Sauce : Algérienne', ''],
    ])('nature de la formule revendiquée : %s → %s', (claim, attendu) => {
        expect(claimedFormuleBadge(`CAYENNE\nPain\n${claim}`)).toBe(attendu);
    });

    it('une note libre ne fabrique pas de badge fantôme', () => {
        // La note du caissier est un <textarea> : elle peut contenir une ligne « + Frites ».
        expect(claimedFormuleBadge('CAYENNE\nPain\n[+ Frites\nMerci]')).toBe('');
    });

    it('le canal addon scellé garde la priorité sur la revendication', () => {
        // Borne : la formule EST scellée dans le snapshot — source la plus fiable. La
        // revendication n'est qu'un repli pour la caisse, qui ne scelle rien.
        const borne = {
            ...ligne('Cayenne', '+ Menu (Frites + Boisson) (+2,50 €)'),
            composition_snapshot: {
                lines: [],
                extras: [],
                addons: [{ role: 'menu_frites', addon_name: 'Frites seules' }],
            },
        };

        expect(badge(borne)).toBe('FRITES');
    });

    it('une formule commandée seule n\'est ni repliée ni déshabillée', () => {
        const seule = ligne('Menu (Frites + Boisson)', 'MENU\n↳ Sauce frites: Andalouse');

        const out = collapseBundledAddonItems([seule]);

        expect(out).toHaveLength(1);
        expect(out[0]).toBe(seule);
    });

    it('le legs ne mute jamais la ligne source', () => {
        const parent = ligne('Cayenne', PARENT_5544);
        const formule = ligne('Menu (Frites + Boisson)', 'MENU\n↳ Sauce frites: Andalouse');

        const out = collapseBundledAddonItems([parent, formule]);

        expect(parent.instruction).not.toContain('Andalouse');
        expect(out[0]).not.toBe(parent);
    });

    it('la revendication rendue par le badge ne reste pas aussi en note', () => {
        // Commande réelle 5135 : le badge disait « BOISSON » ET la note répétait
        // « + Boisson Seule » — le doublon que l'owner voulait justement voir disparaître.
        const out = collapseBundledAddonItems([
            ligne('Tacos', 'TACOS\nViandes : Poulet\n+ Boisson Seule (+2,00 €)'),
            ligne('Boisson Seule', 'BOISSON SEULE'),
        ]);

        expect(badge(out[0])).toBe('BOISSON');
        expect(sanitizeKdsInstruction(out[0].instruction, 'Tacos').trim()).toBe('');

        // Un supplément PAYANT, lui, reste une note : on ne droppe que ce que le badge rend.
        expect(sanitizeKdsInstruction('TACOS\n+ Cheddar (+0,90 €)', 'Tacos').trim()).toBe('+ Cheddar');
    });

    it('les options de frites de la formule ne disparaissent plus', () => {
        // « Grande Portion » et « Cheddar Fondu » sont des gestes de CUISINE : elles ne
        // vivent que sur la ligne de formule, le repli les effaçait toutes les deux.
        const out = collapseBundledAddonItems([
            ligne('Cayenne', 'CAYENNE\nPain Sauce : Algérienne\n+ Menu (Frites + Boisson) (+2,50 €)'),
            ligne('Menu (Frites + Boisson)', 'MENU\n↳ Grande Portion (+0,50 €)\n↳ Cheddar Fondu (+1,00 €)\n↳ Sauce frites: Ketchup, Mayonnaise'),
        ]);

        expect(badge(out[0])).toBe('MENU : KTP MAY');

        const rendu = JSON.stringify(renderItemSymbolic(out[0]).lines);
        expect(rendu).toContain('Grande Portion');
        expect(rendu).toContain('Cheddar Fondu');
    });

    it('deux menus, deux parents : chacun reçoit sa consigne', () => {
        const out = collapseBundledAddonItems([
            ligne('Cayenne', 'CAYENNE\nPain\n+ Menu (Frites + Boisson) (+2,50 €)'),
            ligne('Tacos', 'TACOS\n+ Menu (Frites + Boisson) (+2,50 €)'),
            ligne('Menu (Frites + Boisson)', 'Sauce frites: Andalouse'),
            ligne('Menu (Frites + Boisson)', 'Sauce frites: Ketchup'),
        ]);

        expect(out).toHaveLength(2);
        expect(badge(out[0])).toBe('MENU : AND');
        expect(badge(out[1])).toBe('MENU : KTP');
    });
});
