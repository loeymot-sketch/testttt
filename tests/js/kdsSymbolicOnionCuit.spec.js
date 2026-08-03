/**
 * kdsSymbolicOnionCuit.spec.js
 * -----------------------------------------------------------------------------
 * [OWNER8 2026-07-06] W3 §B — symbole cuisine « Oignons cuits » = O̲ (O + U+0332,
 * combining low line) affiché nativement dans {{ line.label }} (pas de v-html).
 *
 * Contrat jumeau STRICT avec le PHP KitchenTicketSymbolicFormatter :
 *  - entrée /oignon.*cuit|cuit.*oignon/ AVANT /oignon/ dans CRUDITE_TABLE
 *  - CRUDITE_ORDER = ['S','T','O','O̲'] → « STOO̲ » quand tout est présent
 *  - même string exact O + U+0332 des deux côtés (parité écran ↔ ticket)
 */
import { describe, it, expect } from 'vitest';
import { cruditeSymbol, buildSymbolic, symbolicMainLine } from '../../resources/js/helpers/kdsSymbolic.js';

const O_CUIT = 'O̲';

describe('kdsSymbolic — oignons cuits O̲ (OWNER8)', () => {
    it('cruditeSymbol : « Oignons cuits » → O̲ (O + U+0332), l\'oignon cru reste O', () => {
        expect(cruditeSymbol('Oignons cuits')).toBe(O_CUIT);
        expect(cruditeSymbol('oignon cuit')).toBe(O_CUIT);
        expect(cruditeSymbol('Cuit oignon')).toBe(O_CUIT); // ordre inverse couvert
        expect(cruditeSymbol('Oignon')).toBe('O');
        expect(cruditeSymbol('Oignons')).toBe('O');
    });

    it('le symbole est EXACTEMENT 2 code points : O (U+004F) + U+0332', () => {
        const sym = cruditeSymbol('Oignons cuits');
        expect([...sym].length).toBe(2);
        expect(sym.codePointAt(0)).toBe(0x4f);
        expect(sym.codePointAt(1)).toBe(0x0332);
    });

    it('ordre canonique STOO̲ : Salade+Tomate+Oignon+Oignons cuits', () => {
        const s = buildSymbolic({
            item_name: 'Tacos M',
            quantity: 1,
            composition_snapshot: {
                lines: [],
                addons: [],
                extras: [
                    { extra_name: 'Oignons cuits', line_total: 0 },
                    { extra_name: 'Tomate', line_total: 0 },
                    { extra_name: 'Salade', line_total: 0 },
                    { extra_name: 'Oignon', line_total: 0 },
                ],
            },
        });
        expect(s.crudites).toBe('STO' + O_CUIT);
    });

    it('cas owner réel : cuit SANS cru → STO̲ (pas de O cru fantôme)', () => {
        const s = buildSymbolic({
            item_name: 'Tacos M',
            quantity: 1,
            composition_snapshot: {
                lines: [],
                addons: [],
                extras: [
                    { extra_name: 'Salade', line_total: 0 },
                    { extra_name: 'Tomate', line_total: 0 },
                    { extra_name: 'Oignons cuits', line_total: 0 },
                ],
            },
        });
        expect(s.crudites).toBe('ST' + O_CUIT);
        expect(s.crudites).not.toContain('STO' + O_CUIT);
    });

    it('ligne 1 complète : le slot crudités porte O̲ (ex. G | TAC | M | STO̲)', () => {
        const line = symbolicMainLine({
            item_name: 'Tacos M',
            quantity: 1,
            composition_snapshot: {
                lines: [],
                addons: [],
                extras: [
                    { extra_name: 'Salade', line_total: 0 },
                    { extra_name: 'Tomate', line_total: 0 },
                    { extra_name: 'Oignons cuits', line_total: 0 },
                ],
            },
        });
        expect(line).toContain('ST' + O_CUIT);
    });

    it('« Oignons cuits » gratuit ne fuit PAS en supplément ; payant (Oignons frits) reste supplément O-free', () => {
        const s = buildSymbolic({
            item_name: 'Tacos M',
            quantity: 1,
            composition_snapshot: {
                lines: [],
                addons: [],
                extras: [
                    { extra_name: 'Oignons cuits', line_total: 0 },
                    { extra_name: 'Oignons frits', line_total: 0.9, unit_price: 0.9 },
                ],
            },
        });
        expect(s.crudites).toBe(O_CUIT);
        expect(s.supplements).toEqual(['+ Oignons frits']);
    });
});
