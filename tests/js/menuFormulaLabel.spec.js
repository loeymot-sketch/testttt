// [LIBELLÉ-FORMULE 2026-11-02] Le ticket à l'écran et la fiche de commande disaient « Menu (Frites + Boisson) »
// quel que soit le choix. Le rôle seul distingue menu complet / frites seules / boisson seule.
import { describe, it, expect } from 'vitest';
import { menuFormulaLabel } from '../../resources/js/helpers/menuFormulaLabel.js';
import { normalizeReceiptAddons } from '../../resources/js/helpers/posReceiptBuilder.js';

const addon = (role) => ({ role, addon_name: 'Menu (Frites + Boisson)', quantity: 1, line_total: 1.9 });

describe('menuFormulaLabel', () => {
    it('dit le vrai choix du client', () => {
        expect(menuFormulaLabel('menu_full')).toBe('Menu Frites + Boisson');
        expect(menuFormulaLabel('menu_frites')).toBe('Frites seules');
        expect(menuFormulaLabel('menu_boisson')).toBe('Boisson seule');
        expect(menuFormulaLabel(' MENU_BOISSON ')).toBe('Boisson seule');
    });
    it('ne touche pas aux suppléments hors formule', () => {
        expect(menuFormulaLabel('drink')).toBe('');
        expect(menuFormulaLabel('')).toBe('');
        expect(menuFormulaLabel(undefined)).toBe('');
    });
});

describe('normalizeReceiptAddons — libellé de formule', () => {
    it('trois choix, trois libellés différents, prix scellé intact', () => {
        const out = normalizeReceiptAddons([addon('menu_full'), addon('menu_frites'), addon('menu_boisson')]);
        expect(out.map((l) => l.name)).toEqual(['Menu Frites + Boisson', 'Frites seules', 'Boisson seule']);
        expect(out.every((l) => l.line_total === 1.9)).toBe(true);
    });
    it('un supplément sans rôle de formule garde son nom', () => {
        const out = normalizeReceiptAddons([{ role: 'drink', addon_name: 'Coca-Cola', quantity: 1, line_total: 1.2 }]);
        expect(out[0].name).toBe('Coca-Cola');
    });
});

describe('supplément portant déjà le nom de la boisson', () => {
    it('garde « Coca-Cola » même avec le rôle menu_boisson', () => {
        const out = normalizeReceiptAddons([{ role: 'menu_boisson', addon_name: 'Coca-Cola', quantity: 1, line_total: 1.2 }]);
        expect(out[0].name).toBe('Coca-Cola');
    });
});

import { estFormule, formuleDeLigne, resumeTechnique } from '../../resources/js/support/compositionCommande.js';

describe('suivi de caisse — la formule est mise en évidence', () => {
    const ligne = (nom) => ({ item_name: 'Tacos', options: [{ label: 'Viande 1', value: 'Mexicanos' }], extras: [{ name: 'Cheddar' }], addons: [{ name: nom }] });
    it('reconnaît les trois formules et rien d\'autre', () => {
        expect(estFormule({ name: 'Frites seules' })).toBe(true);
        expect(estFormule({ name: 'Boisson seule' })).toBe(true);
        expect(estFormule({ name: 'Menu Frites + Boisson' })).toBe(true);
        expect(estFormule({ name: 'Coca-Cola' })).toBe(false);
        expect(estFormule(null)).toBe(false);
    });
    it('formuleDeLigne rend le vrai choix', () => {
        expect(formuleDeLigne(ligne('Boisson seule'))).toBe('Boisson seule');
        expect(formuleDeLigne(ligne('Coca-Cola'))).toBe('');
    });
    it('le résumé de la carte ne répète pas la formule quand elle est affichée à part', () => {
        expect(resumeTechnique(ligne('Frites seules'), { sansFormule: true })).not.toContain('Frites seules');
        expect(resumeTechnique(ligne('Frites seules'))).toContain('+Frites seules');
        // une boisson nommée reste dans le texte
        expect(resumeTechnique(ligne('Coca-Cola'), { sansFormule: true })).toContain('+Coca-Cola');
    });
});
