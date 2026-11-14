import { describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { mount } from '@vue/test-utils';

import KdsOrderLine from '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue';

// [GOAL REMARQUES 2026-10-03 · T-1.1 R-071 + T-1.6 R-054] Propriétaire, 03/10 : « l'écran de cuisine
// d'agrandir les suppléments ». Le 02/10 les suppléments sont passés en blanc gras sur noir, mais en
// 15 px — PLUS PETITS que la ligne produit (18 px symbolique, 22 px en-tête) : le cuisinier les
// rate encore. Ici : un supplément s'écrit au moins aussi grand que le nom du produit, sans jamais
// couper un mot (régression C4-001), et une quantité > 1 ressort sur fond noir.

const FILE = path.resolve(__dirname, '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue');
const LINE = fs.readFileSync(FILE, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');

const rule = (selector) => {
    const esc = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const m = LINE.match(new RegExp(`(?:^|\\n|,\\s*)${esc}\\s*(?:,[^{]*)?\\{([^}]*)\\}`));
    return m ? m[1] : null;
};
const prop = (body, name) => {
    const m = body && body.match(new RegExp(`(?:^|[;\\s])${name}\\s*:\\s*([^;]+);`));
    return m ? m[1].trim() : null;
};
const px = (body, name) => parseFloat(prop(body, name));
const opts = { global: { mocks: { $t: (k) => k } } };

describe('KDS — suppléments agrandis (R-071)', () => {
    const supp = rule('.kds-line__supplement');

    it('un supplément est au moins aussi grand que le nom du produit, quel que soit le mode', () => {
        expect(supp).not.toBeNull();
        expect(px(supp, 'font-size')).toBeGreaterThanOrEqual(px(rule('.kds-line__symbolic-text'), 'font-size'));
        expect(px(supp, 'font-size')).toBeGreaterThanOrEqual(px(rule('.kds-line__name'), 'font-size'));
    });

    it('reste blanc, gras, sur cadre noir', () => {
        expect(prop(supp, 'color').toUpperCase()).toBe('#FFFFFF');
        expect(prop(supp, 'background').toUpperCase()).toBe('#000000');
        expect(Number(prop(supp, 'font-weight'))).toBeGreaterThanOrEqual(800);
    });

    it('passe à la ligne entre les mots, jamais au milieu d\'un mot ni hors de la fiche', () => {
        expect(prop(supp, 'white-space')).not.toBe('nowrap');
        expect(prop(supp, 'word-break') || 'normal').toBe('normal');
        expect(prop(supp, 'overflow-wrap') || 'normal').not.toBe('anywhere');
        expect(prop(supp, 'max-width')).toBe('100%');
    });
});

describe('KDS — quantité multiple sur fond noir (R-054)', () => {
    const line = (qty, type = 'symbolic-main') => mount(KdsOrderLine, { props: { line: { type, qty, label: 'CAY | P | STO' } }, ...opts });

    it('« 2 × » est marqué, « 1 × » ne l\'est pas — en symbolique comme en en-tête', () => {
        for (const type of ['symbolic-main', 'header']) {
            expect(line(2, type).find('.kds-line__qty').classes()).toContain('kds-line__qty--multi');
            expect(line(1, type).find('.kds-line__qty').classes()).not.toContain('kds-line__qty--multi');
        }
    });

    it('la marque est blanc sur noir', () => {
        const multi = rule('.kds-line__qty--multi');
        expect(multi).not.toBeNull();
        expect(prop(multi, 'color').toUpperCase()).toBe('#FFFFFF');
        expect(prop(multi, 'background').toUpperCase()).toBe('#000000');
    });
});
