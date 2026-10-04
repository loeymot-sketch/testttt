import { describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

// [GOAL REMARQUES 2026-10-03 · T-3.1 R-012] Propriétaire, 16/08 : « dans les commandes de site Web, ça
// doit afficher en rouge parce que le bleu comme dernièrement c'est la détecte même pas ». Seul un
// liseré de 4 px était rouge ; « Accepter » et « Détails » restaient BLEUS (#2563a8). Les commandes web
// doivent se voir rouges : panneau teinté quand il y en a, boutons rouges, contraste AA.
const SRC = fs.readFileSync(path.resolve(__dirname, '../../resources/js/components/admin/pos/PosComponent.vue'), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');

const rule = (selector) => {
    const esc = selector.replace(/[.*+?^${}()|[\]\\:]/g, '\\$&');
    const m = SRC.match(new RegExp(`(?:^|\\n)${esc}\\s*\\{([^}]*)\\}`));
    return m ? m[1] : null;
};
const prop = (body, name) => {
    const m = body && body.match(new RegExp(`(?:^|[;\\s])${name}\\s*:\\s*([^;]+);`));
    return m ? m[1].trim() : null;
};
const lum = (hex) => {
    const h = hex.replace('#', '');
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255)
        .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => { const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };
const estRouge = (hex) => { const h = hex.replace('#', ''); const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)); return r > 150 && g < 80 && b < 80; };

describe('Caisse — commandes du site en ROUGE (R-012)', () => {
    it('« Accepter » est rouge plein, texte blanc lisible (AA)', () => {
        const b = rule('.pos-shortcuts__cta--web');
        const fond = prop(b, 'background');
        expect(estRouge(fond), `fond ${fond}`).toBe(true);
        expect(prop(b, 'color').toUpperCase()).toBe('#FFFFFF');
        expect(ratio('#FFFFFF', fond)).toBeGreaterThanOrEqual(4.5);
    });

    it('« Détails » est rouge (texte et contour), plus jamais bleu', () => {
        const b = rule('.pos-shortcuts__cta--web-details');
        expect(estRouge(prop(b, 'color')), prop(b, 'color')).toBe(true);
        expect(prop(b, 'border')).toMatch(/#[0-9a-f]{6}/i);
        expect(estRouge(prop(b, 'border').match(/#[0-9a-f]{6}/i)[0])).toBe(true);
    });

    it('le panneau web est teinté de rouge dès qu\'il contient une commande', () => {
        const b = rule('.pos-shortcuts__panel--web:not(.pos-shortcuts__panel--empty)');
        expect(b, 'règle de panneau non vide').not.toBeNull();
        const fond = prop(b, 'background');
        expect(fond).toMatch(/^#[0-9a-f]{6}$/i);
        expect(ratio(prop(rule('.pos-shortcuts__cta--web-details'), 'color'), fond)).toBeGreaterThanOrEqual(4.5);
    });

    it('plus aucun bleu « info » sur les boutons web', () => {
        for (const sel of ['.pos-shortcuts__cta--web', '.pos-shortcuts__cta--web-details']) {
            expect(rule(sel)).not.toMatch(/pos-v5-info|#2563a8/i);
        }
    });
});
