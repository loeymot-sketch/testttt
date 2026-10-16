/**
 * [ETIQUETTES 2026-10-16] Mise en page : retour à la ligne, hauteur du ruban continu, réduction pour une
 * étiquette de hauteur fixe, et — surtout — JAMAIS de troncature silencieuse (sécurité alimentaire).
 */
import { describe, it, expect } from 'vitest';
import { wrapText, layoutLabel, SIZES, MIN_LABEL_DOTS } from '../../resources/js/helpers/labelLayout.js';
import { formatDlcDate, shelfLifeText, dlcLabelBlocks, testLabelBlocks } from '../../resources/js/helpers/dlcLabelBuilder.js';

/** Mesure factice : 0,55 × la taille de police par caractère. */
const measure = (text, font) => {
    const size = Number((font.match(/(\d+)px/) || [])[1] || 20);
    return Array.from(text).length * size * 0.55;
};

describe('wrapText', () => {
    it('coupe aux espaces sans dépasser la largeur', () => {
        const lines = wrapText('un deux trois quatre cinq six sept', 200, '700 30px x', measure);
        expect(lines.length).toBeGreaterThan(1);
        lines.forEach((l) => expect(measure(l, '700 30px x')).toBeLessThanOrEqual(200));
        expect(lines.join(' ')).toBe('un deux trois quatre cinq six sept');
    });

    it('un mot plus large que la ligne est coupé au caractère, sans rien perdre', () => {
        const lines = wrapText('Anticonstitutionnellement', 120, '700 30px x', measure);
        expect(lines.length).toBeGreaterThan(1);
        expect(lines.join('')).toBe('Anticonstitutionnellement');
    });

    it('respecte les retours à la ligne explicites', () => {
        expect(wrapText('A\nB', 500, '700 30px x', measure)).toEqual(['A', 'B']);
    });
});

describe('layoutLabel', () => {
    const blocks = [
        { t: 'band', left: 'N° A0032', right: '2/4' },
        { t: 'title', text: 'CAYENNE' },
        { t: 'line', text: 'Sauce : Algérienne' },
        { t: 'line', text: 'Crudités : Salade, Tomate', size: 'md' },
    ];

    it('ruban continu : la hauteur suit le contenu, jamais en dessous du minimum', () => {
        const r = layoutLabel(blocks, { width: 696, height: null, measure });
        expect(r.overflow).toBe(false);
        expect(r.scale).toBe(1);
        expect(r.height).toBeGreaterThanOrEqual(MIN_LABEL_DOTS);
        const small = layoutLabel([{ t: 'title', text: 'X' }], { width: 696, height: null, measure });
        expect(small.height).toBe(MIN_LABEL_DOTS);
    });

    it('plus de contenu → plus long', () => {
        const a = layoutLabel(blocks, { width: 696, measure });
        const b = layoutLabel([...blocks, { t: 'line', text: 'Sauce frites : Ketchup, Mayonnaise, Samouraï, Algérienne, Harissa' }], { width: 696, measure });
        expect(b.height).toBeGreaterThan(a.height);
    });

    it('hauteur fixe : réduit les polices pour faire tenir, et le dit', () => {
        const free = layoutLabel(blocks, { width: 696, height: null, measure });
        const fixed = layoutLabel(blocks, { width: 696, height: Math.floor(free.height * 0.85), measure });
        expect(fixed.overflow).toBe(false);
        expect(fixed.scale).toBeLessThan(1);
        expect(fixed.height).toBe(Math.floor(free.height * 0.85));
    });

    it('hauteur fixe impossible : overflow = true (on refuse d’imprimer plutôt que de tronquer)', () => {
        const r = layoutLabel(blocks, { width: 696, height: 80, measure });
        expect(r.overflow).toBe(true);
    });

    it('le bandeau part du bord haut et porte deux textes (numéro et k/N)', () => {
        const r = layoutLabel(blocks, { width: 696, measure });
        const band = r.commands.find((c) => c.op === 'rect' && c.x === 0);
        expect(band.y).toBe(0);
        expect(band.w).toBe(696);
        const texts = r.commands.filter((c) => c.op === 'text').map((c) => c.text);
        expect(texts).toContain('N° A0032');
        expect(texts).toContain('2/4');
    });

    it('un bandeau trop chargé réduit sa police au lieu de déborder', () => {
        const r = layoutLabel([{ t: 'band', left: 'N° ABCDEFGHIJKLMNOP', right: '10/12' }], { width: 696, measure });
        const t = r.commands.filter((c) => c.op === 'text');
        expect(t[0].size).toBeLessThan(SIZES.band);
        const right = t[1];
        expect(right.x + measure(right.text, right.font)).toBeLessThanOrEqual(696);
    });

    it('aucune ligne de texte ne dépasse la largeur de l’étiquette', () => {
        const long = [{ t: 'line', text: 'Sauces : Algérienne, Andalouse, Harissa, Samouraï, Ketchup, Mayonnaise, Barbecue, Blanche', size: 'md' }];
        const r = layoutLabel(long, { width: 696, measure });
        r.commands.filter((c) => c.op === 'text').forEach((c) => {
            expect(c.x + measure(c.text, c.font)).toBeLessThanOrEqual(696);
        });
        expect(r.commands.filter((c) => c.op === 'text').length).toBeGreaterThan(1);
    });
});

describe('chevauchement et rognage signalés (revue adversariale)', () => {
    it('un bandeau dont les deux textes ne tiennent plus côte à côte est signalé, même en ruban continu', () => {
        const r = layoutLabel([{ t: 'band', left: `N° ${'X'.repeat(60)}`, right: '1/3' }], { width: 696, measure });
        expect(r.overflow).toBe(true);
    });

    it('une paire dont le texte de droite déborde est signalée', () => {
        const r = layoutLabel([{ t: 'pair', left: 'Ouvert le', right: 'D'.repeat(60), size: 'md' }], { width: 696, measure });
        expect(r.overflow).toBe(true);
    });

    it('les cas normaux ne sont jamais signalés à tort', () => {
        const r = layoutLabel([
            { t: 'band', left: 'N° A0032', right: '12/12' },
            { t: 'pair', left: 'Ouvert le', right: 'ven 16/10/2026 14:32', size: 'md' },
        ], { width: 696, measure });
        expect(r.overflow).toBe(false);
    });
});

describe('étiquette DLC', () => {
    it('formate la date dans le fuseau du restaurant, 24 h, avec le jour', () => {
        // 16/10/2026 12:32 UTC = 14:32 à Paris (heure d'été)
        expect(formatDlcDate('2026-10-16T12:32:00Z')).toBe('ven 16/10/2026 14:32');
        // minuit : jamais « 24:00 »
        expect(formatDlcDate('2026-10-16T22:00:00Z')).toBe('sam 17/10/2026 00:00');
        expect(formatDlcDate('pas une date')).toBe('—');
    });

    it('durée de conservation lisible', () => {
        expect(shelfLifeText(48, 'hours')).toBe('48 h');
        expect(shelfLifeText(1, 'days')).toBe('1 jour');
        expect(shelfLifeText(3, 'days')).toBe('3 jours');
        expect(shelfLifeText(0, 'days')).toBe('');
    });

    it('contient produit, date d’ouverture et DLC', () => {
        const blocks = dlcLabelBlocks({
            name: 'Oignons émincés', openedAt: '2026-10-16T12:32:00Z', expiresAt: '2026-10-18T12:32:00Z',
            storage: '+4 °C', by: 'Karim', shelfValue: 2, shelfUnit: 'days',
        });
        const flat = JSON.stringify(blocks);
        expect(flat).toContain('OIGNONS ÉMINCÉS');
        expect(flat).toContain('ven 16/10/2026 14:32');
        expect(flat).toContain('dim 18/10/2026 14:32');
        expect(flat).toContain('À CONSOMMER AVANT');
        expect(flat).toContain('Conservation 2 jours · +4 °C · Par Karim');
    });

    it('l’étiquette de test porte le repère gauche/droite et des accents', () => {
        const flat = JSON.stringify(testLabelBlocks('c62', new Date('2026-10-16T12:00:00Z')));
        expect(flat).toContain('G <');
        expect(flat).toContain('> D');
        expect(flat).toContain('éèàçù Œuf');
    });
});
