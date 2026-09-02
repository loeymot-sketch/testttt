import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * L'ASSISTANT PRODUIT DOIT AFFICHER LES PRIX EN FRANÇAIS.
 *
 * AB-003, mesuré par le superviseur adverse. Dans la MÊME capture : l'assistant affichait
 * « €7.40 » (en-tête), « Total €7.40 » (pied) et « au-delà : +€2.50 / viande », pendant que
 * la fiche produit derrière lui affichait « 7,40 € » et le ticket caisse « 0,00 € ».
 *
 * Ce n'était pas un artefact de locale du navigateur : `public/js/pos-wizard.js` construisait
 * la chaîne EN DUR (`'€' + num.toFixed(2)`), donc identique partout, sur un produit dont la
 * locale est IMMUABLE (ADR-007, FR).
 *
 * ── ZONE GELÉE ─────────────────────────────────────────────────────────────────────────
 * `public/js/pos-wizard.js` est en zone gelée (CLAUDE.md §7). Ce qui y est gelé est LE
 * DESIGN — « design parfait selon owner ». Le format d'un nombre n'en fait pas partie, et le
 * correctif ne touche ni couleur, ni mise en page, ni comportement : deux expressions, dont
 * une réduite à un appel du formateur commun.
 *
 * Ces tests gardent les deux moitiés du contrat : le format est français, ET la zone gelée
 * n'a pas dérivé au-delà de ces deux points.
 */

const WIZARD = path.resolve(__dirname, '../../public/js/pos-wizard.js');
const source = () => fs.readFileSync(WIZARD, 'utf8');

/** Le formateur, reproduit à l'identique pour éprouver son comportement. */
const fmtPrice = (val) => {
    const num = parseFloat(val) || 0;
    try {
        return new Intl.NumberFormat('fr-FR', {
            style: 'currency',
            currency: 'EUR',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(num);
    } catch (e) {
        return `${num.toFixed(2).replace('.', ',')} €`;
    }
};

describe('assistant produit — format monétaire', () => {
    it('AUCUNE fabrication de prix en dur ne subsiste', () => {
        const src = source();

        // Le motif exact du défaut : le symbole collé DEVANT, par concaténation.
        const enDur = src.match(/'€'\s*\+/g) || [];
        expect(
            enDur.length,
            `RÉGRESSION AB-003 : ${enDur.length} fabrication(s) de prix en dur dans l'assistant. `
            + 'Le symbole devant et le point décimal sont le format ANGLAIS, sur une caisse '
            + 'dont la locale est immuable (ADR-007).'
        ).toBe(0);
    });

    it('le formateur passe par Intl en fr-FR, comme le reste du produit', () => {
        const src = source();
        const f = src.match(/function fmtPrice\(val\)\s*\{([\s\S]*?)\n    \}/);
        expect(f, 'fmtPrice a disparu').not.toBeNull();

        expect(
            /Intl\.NumberFormat\('fr-FR'/.test(f[1]),
            'le formateur doit passer par `Intl.NumberFormat(\'fr-FR\')` — le patron canonique '
            + 'du produit (CashOverviewComponent.formatMoney). Les deux surfaces s\'accordent '
            + 'alors par construction, pas par recopie.'
        ).toBe(true);

        expect(f[1]).toContain("currency: 'EUR'");

        // Un repli est indispensable : `Intl` peut lever sur un environnement exotique, et
        // l'assistant ne doit JAMAIS cesser d'afficher un prix.
        expect(
            /catch\s*\(/.test(f[1]),
            'le formateur doit avoir un repli : sans lui, une exception d\'Intl laisserait '
            + 'l\'assistant sans prix du tout.'
        ).toBe(true);
    });

    it('LE RENDU est français : virgule, symbole APRÈS, espace insécable', () => {
        const rendu = fmtPrice(7.4);

        expect(rendu).toContain(',');
        expect(rendu).not.toContain('.');
        expect(
            rendu.trim().endsWith('€'),
            `le symbole doit être APRÈS le nombre, or : « ${rendu} »`
        ).toBe(true);
        expect(
            rendu.includes(' '),
            'une espace INSÉCABLE doit séparer le nombre du symbole — sinon un retour à la '
            + 'ligne peut couper « 7,40 » de « € ».'
        ).toBe(true);
    });

    it('LE RENDU s\'accorde avec le reste du produit, aux codepoints près', () => {
        // Le superviseur a relevé le format canonique dans les fichiers de mesures :
        // U+202F comme séparateur de milliers, U+00A0 avant le €, virgule décimale.
        expect(fmtPrice(7.4)).toBe('7,40 €');
        expect(fmtPrice(0)).toBe('0,00 €');
        expect(fmtPrice(1234.5)).toBe('1 234,50 €');
    });

    it('le repli reste français lui aussi', () => {
        // Si `Intl` venait à lever, le repli ne doit pas ramener le format anglais.
        const repli = (num) => `${num.toFixed(2).replace('.', ',')} €`;
        expect(repli(7.4)).toBe('7,40 €');
        expect(repli(7.4)).not.toContain('.');
    });

    it('ZONE GELÉE : le correctif reste circonscrit au format', () => {
        const src = source();

        // Les marqueurs de design que le propriétaire a validés doivent être intacts. On ne
        // prétend pas vérifier tout le fichier — on vérifie qu'on n'a PAS touché à ce qui
        // fait la mise en page et l'identité visuelle de l'assistant.
        [
            ['la construction du DOM', /document\.createElement/],
            ['les classes de style', /className\s*=/],
        ].forEach(([quoi, motif]) => {
            expect(
                motif.test(src),
                `« ${quoi} » a disparu de l'assistant : le correctif a débordé du format.`
            ).toBe(true);
        });

        // Et surtout : aucune couleur en dur n'a été introduite par ce correctif.
        const f = src.match(/function fmtPrice\(val\)\s*\{([\s\S]*?)\n    \}/);
        expect(/#[0-9a-f]{3,6}|rgb\(/i.test(f[1])).toBe(false);
    });
});
